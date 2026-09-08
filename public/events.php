<?php
// public/events.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendar_link_generator.php';

require_login();
require_once __DIR__ . '/../includes/header.php';

$search_event = trim($_GET['search_event'] ?? '');
$search_date = trim($_GET['search_date'] ?? '');
$search_status = trim($_GET['search_status'] ?? '');
$search_delivery = trim($_GET['search_delivery'] ?? '');
$search_tipo = trim($_GET['search_tipo'] ?? ''); // <-- NUOVO FILTRO
$error = null;
$success_msg = $_GET['msg'] ?? null;

// --- Eliminazione ---
if (isset($_GET['delete']) && is_admin()) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM servizi_aggiuntivi_evento WHERE id_evento = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM dettagli_location_evento WHERE id_evento = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM eventi WHERE id_evento = ?")->execute([$id]);
        header('Location: events.php?msg=' . urlencode('Evento eliminato con successo!'));
        exit;
    } catch (PDOException $e) {
        $error = "Errore durante l'eliminazione: " . htmlspecialchars($e->getMessage());
    }
}

// --- Costruzione Query (corrette e pulite) ---
$sql_admin = "SELECT e.*, e.data_consegna, e.orario_consegna, e.path_google_drive, ae.nome_evento as tipo_evento,
c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.telefono AS cliente_telefono,
s.nome_stato, o.nome AS op_nome, o.cognome AS op_cognome, e.stato_lavorazione_fase
FROM eventi e
LEFT JOIN clienti c ON e.id_cliente = c.id_cliente
LEFT JOIN stati_evento s ON e.id_stato = s.id_stato
LEFT JOIN operatori o ON e.id_operatore = o.id_operatore
LEFT JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento";

$sql_op = "SELECT e.*, e.data_consegna, e.orario_consegna, e.path_google_drive, ae.nome_evento as tipo_evento,
c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.telefono AS cliente_telefono,
s.nome_stato, e.stato_lavorazione_fase
FROM eventi e
LEFT JOIN clienti c ON e.id_cliente = c.id_cliente
LEFT JOIN stati_evento s ON e.id_stato = s.id_stato
LEFT JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento";

$params = [];
$where = [];

if (!is_admin()) {
    $id_op = (int)($_SESSION['id_operatore'] ?? 0);
    $where[] = "e.id_operatore = ?";
    $params[] = $id_op;
    $sql = $sql_op;
} else {
    $sql = $sql_admin;
}

if ($search_event) {
    $where[] = "(e.note LIKE ? OR c.nome LIKE ? OR c.cognome LIKE ? OR ae.nome_evento LIKE ?)";
    $like = '%' . $search_event . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if ($search_date) {
    $where[] = "e.data_evento = ?";
    $params[] = $search_date;
}

if ($search_status) {
    $where[] = "e.id_stato = ?";
    $params[] = $search_status;
}

if ($search_tipo) { // <-- APPLICAZIONE FILTRO TIPO EVENTO IN QUERY
    $where[] = "e.id_anagrafica_evento = ?";
    $params[] = $search_tipo;
}

// conteggi stati (corretto)
$count_sql = "SELECT e.id_stato, COUNT(e.id_evento) as count
             FROM eventi e 
             LEFT JOIN stati_evento s ON e.id_stato = s.id_stato";
$count_where = [];
$count_params = [];

if (!is_admin()) {
    $count_where[] = "e.id_operatore = ?";
    $count_params[] = $id_op;
}
if ($count_where) {
    $count_sql .= " WHERE " . implode(" AND ", $count_where);
}
$count_sql .= " GROUP BY e.id_stato";

try {
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($count_params);
    $state_counts_raw = $stmt_count->fetchAll(PDO::FETCH_ASSOC);
    $state_counts = [];
    foreach ($state_counts_raw as $row) {
        $state_counts[$row['id_stato']] = (int)$row['count'];
    }
} catch (PDOException $e) {
    $state_counts = [];
}

$stati = $pdo->query("SELECT id_stato, nome_stato FROM stati_evento ORDER BY nome_stato")->fetchAll(PDO::FETCH_KEY_PAIR);
// RECUPERO LA LISTA DEI TIPI EVENTO PER IL FILTRO
$tipi_evento = $pdo->query("SELECT id_anagrafica_evento, nome_evento FROM anagrafica_eventi ORDER BY nome_evento")->fetchAll(PDO::FETCH_KEY_PAIR);

$album_ritirare_id = array_search('ALBUM DA RITIRARE', array_map('strtoupper', $stati));

if ($search_delivery && $album_ritirare_id) {
    if (!$search_status || (int)$search_status === (int)$album_ritirare_id) {
        if ($search_delivery === 'fissata') {
            $where[] = "e.data_consegna IS NOT NULL";
        } elseif ($search_delivery === 'da_fissare') {
            $where[] = "e.data_consegna IS NULL";
            if (!$search_status) {
                $where[] = "e.id_stato = ?";
                $params[] = $album_ritirare_id;
            }
        }
    }
}

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY CASE  WHEN ae.id_anagrafica_evento = 1 THEN 1 WHEN ae.id_anagrafica_evento = 2 THEN 2 WHEN ae.id_anagrafica_evento = 3 THEN 3 WHEN ae.id_anagrafica_evento = 5 THEN 4 WHEN ae.id_anagrafica_evento = 8 THEN 5 ELSE 100  END, e.data_evento ASC, ae.id_anagrafica_evento, e.orario_evento";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
    $tot = count($events);
} catch (PDOException $e) {
    $error = "Errore: " . htmlspecialchars($e->getMessage());
    $events = [];
    $tot = 0;
}

function get_check_icon($status, $quantita = null, $title = '') {
    $icon = $status ? 'bi-check-circle-fill text-success' : 'bi-dash-circle text-muted';
    $q_text = $quantita && $status && $quantita > 0 ? " ({$quantita})" : '';
    $full_title = $title . ($status ? ' SI' : ' NO') . $q_text;
    return "<span title='{$full_title}'><i class='bi {$icon} me-1' style='font-size: 1.1em;'></i>{$title}{$q_text}</span>";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eventi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        /* Stili per adattare la tabella su dispositivi mobili (max 768px) */
        @media screen and (max-width: 768px) {
            /* Nasconde l'intestazione della tabella */
            .table-responsive table thead {
                border: none;
                clip: rect(0 0 0 0);
                height: 1px;
                margin: -1px;
                overflow: hidden;
                padding: 0;
                position: absolute;
                width: 1px;
            }

            /* Trasforma il corpo e le righe in blocchi */
            .table-responsive table tbody,
            .table-responsive table tr {
                display: block;
                width: 100%;
            }

            /* Stile per ciascuna riga del blocco */
            .table-responsive table tr {
                margin-bottom: 1rem;
                border: 1px solid #dee2e6;
                border-radius: 0.5rem;
                box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
                overflow: hidden; /* Importante per i bordi arrotondati */
            }
            
            /* Le righe aggiuntive (Fase lavorazione) le rendiamo più discrete */
            .table-responsive table tr:nth-child(even) { /* Righe pari, ovvero quelle della "fase lavorazione" */
                background-color: transparent !important; /* Rimuove il colore della riga superiore */
                border: none;
                box-shadow: none;
                margin-bottom: 1.5rem; /* Aumenta spazio sotto il blocco completo */
            }
            
            /* Trasforma le celle in blocchi */
            .table-responsive table td {
                display: block;
                text-align: right;
                padding-left: 50% !important; /* Spazio per l'etichetta */
                position: relative;
                border: none; /* Rimuove i bordi della cella */
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
            }

            /* Inserisce l'intestazione como pseudoelemento */
            .table-responsive table td::before {
                /* Contenuto dinamico preso da data-label */
                content: attr(data-label);
                position: absolute;
                left: 0.5rem;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #495057; /* Un colore scuro per l'etichetta */
            }
            
            /* Gestione specifica delle celle senza bisogno di etichetta */
            .table-responsive table tr td:first-child::before, /* L'ID */
            .table-responsive table tr:nth-child(even) td::before /* Rimuove l'etichetta dalla fase lavorazione */
            {
                content: none;
                display: none;
            }
            
            .table-responsive table tr td:first-child { /* Allinea l'ID a sinistra se è la prima cella */
                text-align: left;
                padding-left: 0.5rem !important;
                font-size: 1.2em;
                font-weight: bold;
                color: var(--bs-primary);
                padding-bottom: 0;
            }
            
            /* Adatta i campi di input e select per la visualizzazione a blocco */
            .editable-consegna, .editable-status, .editable-fase, .editable-orario {
                width: 100% !important; 
                max-width: 100% !important;
            }
            
            /* Centra il contenuto delle azioni */
            .table-responsive table td.text-center {
                text-align: center !important;
                padding-left: 0.5rem !important; /* Rimuove lo spazio extra */
            }
            
            .table-responsive table tr:nth-child(even) td {
                padding-left: 0.5rem !important;
            }
            .table-responsive table tr:nth-child(even) .ps-4 {
                padding-left: 0 !important;
            }
            .table-responsive table tr:nth-child(even) .editable-fase {
                width: 100% !important;
            }
            /* Assicura che la fase lavorazione torni a sinistra */
            .table-responsive table tr:nth-child(even) td:nth-child(2) {
                text-align: left;
            }
            /* Assicura che i campi data e ora di consegna siano allineati */
            .delivery-input-group {
                flex-direction: column;
                gap: 0.5rem;
            }
            .delivery-input-group > * {
                width: 100% !important;
            }
        }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h3 class="mb-2 mb-md-0">
            <i class="bi bi-calendar-event-fill me-2 text-primary"></i>Eventi
            <span class="badge bg-secondary"><?php echo $tot; ?></span>
        </h3>
        <?php if (is_admin()): ?>
            <a href="event_form.php" class="btn btn-success"><i class="bi bi-plus-circle"></i> Nuovo</a>
        <?php endif; ?>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card p-3 shadow-sm mb-4 bg-light border-0">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted"><i class="bi bi-search me-1"></i>Evento/Cliente</label>
                <input type="text" class="form-control" name="search_event" value="<?php echo htmlspecialchars($search_event); ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-date me-1"></i>Data Evento</span>
                </label>
                <input type="date" class="form-control" name="search_date" value="<?php echo htmlspecialchars($search_date); ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted"><i class="bi bi-tag-fill me-1"></i>Tipo Evento</label>
                <select name="search_tipo" class="form-select" onchange="this.form.submit()">
                    <option value="">Tutti</option>
                    <?php foreach ($tipi_evento as $id => $nome): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($id == $search_tipo) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($nome); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted"><i class="bi bi-flag-fill me-1"></i>Stato</label>
                <select name="search_status" class="form-select" onchange="this.form.submit()">
                    <option value="">Tutti</option>
                    <?php foreach ($stati as $id => $nome): 
                        $count = $state_counts[$id] ?? 0;
                        $display_name = htmlspecialchars($nome) . " ({$count})"; 
                    ?>
                        <option value="<?php echo $id; ?>" <?php echo ($id == $search_status) ? 'selected' : ''; ?>>
                            <?php echo $display_name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted"><i class="bi bi-calendar-check me-1"></i>Data Consegna</label>
                <select name="search_delivery" class="form-select" onchange="this.form.submit()">
                    <option value="">Tutti</option>
                    <option value="fissata" <?php echo ($search_delivery === 'fissata') ? 'selected' : ''; ?>>Data fissata</option>
                    <option value="da_fissare" <?php echo ($search_delivery === 'da_fissare') ? 'selected' : ''; ?>>Data da fissare</option>
                </select>
            </div>
            <div class="col-md-2 d-flex">
                <a href="events.php" class="btn btn-outline-secondary flex-grow-1"><i class="bi bi-arrow-repeat"></i> Resetta</a>
            </div>
        </form>
    </div>

    <?php if (empty($events)): ?>
        <div class="alert alert-warning text-center"><i class="bi bi-info-circle-fill me-2"></i>Nessun evento trovato.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle shadow-sm rounded-3 overflow-hidden" style="border-collapse:separate; border-spacing:0;">
                <thead class="table-primary">
     <tr>
      <th class="text-center">#</th>
      <th><i class="bi bi-calendar3"></i> Data</th>
      <th><i class="bi bi-clock"></i> Ora</th> <th><i class="bi bi-cloud-fill"></i> Drive</th> 
      <th><i class="bi bi-truck-flatbed"></i> Consegna</th>
      <th><i class="bi bi-person-fill"></i> Cliente</th>
      <th><i class="bi bi-tag-fill"></i> Tipo Evento</th>
      <th><i class="bi bi-flag"></i> Stato</th> <th class="text-center"><i class="bi bi-tools"></i> Azioni</th>
     </tr>
    </thead>
                <tbody>
                    <?php foreach ($events as $e): 
                        $stato = strtolower($e['nome_stato'] ?? '');
                        $data_consegna_display = '';
                        $consegna_class = 'text-muted';
                        if ($stato === 'album da ritirare') {
                            if (!empty($e['data_consegna'])) {
                                $data_consegna_display = date('d/m/Y', strtotime($e['data_consegna']));
                                $consegna_class = 'text-success fw-bold';
                            } else {
                                $data_consegna_display = 'DATA DA FISSARE';
                                $consegna_class = 'text-danger small';
                            }
                        }

                        // Colorazione riga
                        $rowClass = ''; 
                        $badgeClass = 'bg-secondary';
                        switch ($stato) {
                            case 'nuovo evento':
                            case 'inserito': $badgeClass='bg-info'; $rowClass='table-info'; break;
                            case 'completato':
                            case 'consegnato': $badgeClass='bg-success'; $rowClass='table-success'; break;
                            case 'annullato':
                            case 'cancellato': $badgeClass='bg-danger'; $rowClass='table-danger'; break;
                            case 'in lavorazione':
                            case 'in corso':
                            case 'stampato': $badgeClass='bg-warning text-dark'; $rowClass='table-info'; break;
                            case 'album da ritirare':
                            case 'chiuso': $badgeClass='bg-dark'; $rowClass='table-secondary'; break;
                            case 'attendo conferma cliente': $badgeClass='bg-info'; $rowClass='table-light'; break;
                            case 'preventivo': $badgeClass='bg-primary'; $rowClass='table-primary'; break;
                        }
                    ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td class="text-center" data-label="ID:"><?php echo $e['id_evento']; ?></td>
                            <td data-label="Data Evento:"><?php echo $e['data_evento'] ? date('d/m/Y', strtotime($e['data_evento'])) : '-'; ?></td>
                            <td data-label="Ora Evento:">
                                <input type="time"
                                       class="form-control form-control-sm editable-orario"
                                       style="width: 100px;"
                                       data-id="<?php echo $e['id_evento']; ?>"
                                       value="<?php echo htmlspecialchars($e['orario_evento'] ?? ''); ?>">
                                <span class="ms-2 text-success small d-none orario-saved">
                                    <i class="bi bi-check2-circle"></i> Salvato
                                </span>
                            </td>
                            <td class="text-center" data-label="Drive:">
                                <?php if (!empty($e['path_google_drive'])): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-info btn-copy-drive" 
                                            data-path="<?php echo htmlspecialchars($e['path_google_drive']); ?>"
                                            title="Copia percorso locale completo">
                                        <i class="bi bi-folder-fill"></i> Copia Path
                                    </button>
                                <?php else: ?>
                                    <i class="bi bi-slash-circle text-muted" title="Nessun path Drive impostato"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center small align-middle" data-label="Consegna:">
    <?php
    // Variabili necessarie (usiamo $e come variabile del ciclo come suggerito dal tuo snippet)
    $data_c = $e['data_consegna'] ?? null;
    $orario_c = $e['orario_consegna'] ?? '18:00:00'; // Usa 18:00 come default se non specificato
    $id_evento = $e['id_evento'] ?? 0;
    // Combinazione Nome e Cognome Cliente
    $nome_cliente = htmlspecialchars(($e['cliente_nome'] ?? '') . ' ' . ($e['cliente_cognome'] ?? ''));
    $nome_evento = htmlspecialchars($e['tipo_evento'] ?? 'Da Definire');
    $calendar_button = '';

    if (!empty($data_c)) {
        try {
            // Unione di data e orario di consegna
            $dt_consegna_string = $data_c . ' ' . $orario_c;
            $dt_consegna = new DateTime($dt_consegna_string, new DateTimeZone('Europe/Rome'));
            
            // Costruzione Titolo e Descrizione
            $titolo_c = 'CONSEGNA: Cliente ' . $nome_cliente . ' (Rif. ID: ' . $id_evento . ')';
            $descrizione_c = 'Preparazione finale e consegna al cliente. Evento Tipo: ' . $nome_evento; 
            
            $calendar_url_c = generate_google_calendar_url($titolo_c, $dt_consegna, 60, $descrizione_c); 
            
            // HTML del Tasto Calendar - Usiamo le stesse classi degli altri bottoni
            $calendar_button = '<a href="' . htmlspecialchars($calendar_url_c) . '" target="_blank" class="btn btn-sm btn-outline-danger" title="Aggiungi Consegna a Google Calendar"><i class="bi bi-calendar-plus-fill"></i></a>';
            
        } catch (Exception $e) {
            $calendar_button = '';
        }
    }
    ?>
    <div class="d-flex flex-column justify-content-center align-items-center gap-2">
        <div class="d-flex justify-content-center align-items-center gap-2 delivery-input-group">
            <input type="date"
                    class="form-control form-control-sm text-center editable-consegna-data <?php echo !empty($e['data_consegna']) ? 'border-success text-success fw-bold' : 'border-danger text-danger small'; ?>"
                    style="width: 150px;"
                    data-id="<?php echo $e['id_evento']; ?>"
                    value="<?php echo !empty($e['data_consegna']) ? htmlspecialchars(date('Y-m-d', strtotime($e['data_consegna']))) : ''; ?>">

             <input type="time"
                    class="form-control form-control-sm text-center editable-consegna-orario"
                    style="width: 100px;"
                    data-id="<?php echo $e['id_evento']; ?>"
                    value="<?php echo !empty($e['orario_consegna']) ? htmlspecialchars(date('H:i', strtotime($e['orario_consegna']))) : ''; ?>">
                     <span class="ms-2 text-success small d-none orario-saved">
                                    <i class="bi bi-check2-circle"></i> Salvato
                                </span>
        </div>
        
        <div class="d-flex justify-content-center align-items-center gap-2">
            <?php echo $calendar_button; ?>

            <button type="button"
                    class="btn btn-sm btn-outline-danger clear-consegna"
                    data-id="<?php echo $e['id_evento']; ?>"
                    title="Cancella data e ora di consegna">
                <i class="bi bi-x-circle"></i> Cancella
            </button>
        </div>
        
        <span class="ms-2 text-success small d-none consegna-saved">
            <i class="bi bi-check2-circle"></i> Salvato
        </span>
    </div>
</td>

        <td data-label="Cliente:">
          <?php if (!empty($e['id_cliente'])): ?>
            <a href="../public/client_form.php?id=<?php echo $e['id_cliente']; ?>" 
              class="text-decoration-none fw-semibold">
                <?php echo htmlspecialchars(($e['cliente_nome'] ?? '') . ' ' . ($e['cliente_cognome'] ?? '')); ?>
            </a>
          <?php else: ?>
            <?php echo htmlspecialchars(($e['cliente_nome'] ?? '') . ' ' . ($e['cliente_cognome'] ?? '')); ?>
          <?php endif; ?>

          <?php if (!empty($e['cliente_telefono'])): ?>
            <small class="text-muted d-block">
             <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($e['cliente_telefono']); ?>
            </small>
            <button type="button"
             class="btn btn-sm btn-outline-success mt-1 btn-whatsapp"
             data-id="<?php echo $e['id_evento']; ?>"
             data-nome="<?php echo htmlspecialchars($e['cliente_nome']); ?>"
             data-cognome="<?php echo htmlspecialchars($e['cliente_cognome']); ?>"
             data-telefono="<?php echo htmlspecialchars($e['cliente_telefono']); ?>"
             data-evento="<?php echo htmlspecialchars($e['tipo_evento']); ?>"
             data-dataevento="<?php echo htmlspecialchars($e['data_evento']); ?>"
             data-dataconsegna="<?php echo htmlspecialchars($e['data_consegna']); ?>"
             data-orarioconsegna="<?php echo htmlspecialchars($e['orario_consegna'] ? date('H:i', strtotime($e['orario_consegna'])) : ''); ?>"
             data-orarioevento="<?php echo htmlspecialchars($e['orario_evento'] ? date('H:i', strtotime($e['orario_evento'])) : ''); ?>"> <i class="bi bi-whatsapp"></i> WhatsApp
            </button>
          <?php endif; ?>
        </td>


                            <td data-label="Tipo Evento:"><?php echo htmlspecialchars($e['tipo_evento'] ?? '-'); ?></td>
                            
                            <td data-label="Stato:">
                                <?php
                                // Determina la classe del badge per applicarla al selettore
                                $statusClass = '';
                                switch (strtolower($e['nome_stato'] ?? '')) {
                                    case 'nuovo evento':
                                    case 'inserito': $statusClass='bg-info'; break;
                                    case 'completato':
                                    case 'consegnato': $statusClass='bg-success'; break;
                                    case 'annullato':
                                    case 'cancellato': $statusClass='bg-danger'; break;
                                    case 'in lavorazione':
                                    case 'in corso':
                                    case 'stampato': $statusClass='bg-warning text-dark'; break;
                                    case 'album da ritirare':
                                    case 'chiuso': $statusClass='bg-dark text-white'; break;
                                    case 'attendo conferma cliente': $statusClass='bg-info text-dark'; break;
                                    case 'preventivo': $statusClass='bg-primary text-white'; break;
                                    default: $statusClass='bg-secondary text-white'; break;
                                }
                                ?>
                                <select 
                                    class="form-select form-select-sm editable-status <?php echo $statusClass; ?>" 
                                    data-id="<?php echo $e['id_evento']; ?>" 
                                    data-current-id="<?php echo $e['id_stato']; ?>" 
                                    style="width: auto; min-width: min-content;"
                                >
                                    <?php foreach ($stati as $id_stato => $nome_stato): ?>
                                        <option 
                                            value="<?php echo $id_stato; ?>" 
                                            <?php echo ($id_stato == $e['id_stato']) ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($nome_stato); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="text-center text-nowrap" data-label="Azioni:">
                                <a href="event_form.php?id=<?php echo $e['id_evento']; ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-eye-fill"></i></a>
                                <?php if (is_admin()): ?>
                                    <a href="events.php?delete=<?php echo $e['id_evento']; ?>" onclick="return confirm('Confermi eliminazione evento?')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash-fill"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="<?php echo $rowClass; ?>">
                            <td></td>
                            <td colspan="8">
                                <div class="ps-4">
                                    <label class="small text-muted me-2"><i class="bi bi-gear-fill me-1"></i>Fase lavorazione:</label>
                                    <input type="text" 
                                            class="form-control form-control-sm d-inline-block editable-fase"
                                            style="width: 80%;"
                                            data-id="<?php echo $e['id_evento']; ?>"
                                            value="<?php echo htmlspecialchars($e['stato_lavorazione_fase'] ?? ''); ?>"
                                            placeholder="Aggiorna fase...">
                                    <span class="ms-2 text-success small d-none fase-saved"><i class="bi bi-check2-circle"></i> Salvato</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalWhatsApp" tabindex="-1" aria-labelledby="modalWhatsAppLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="modalWhatsAppLabel"><i class="bi bi-whatsapp me-2"></i>Invia Messaggio WhatsApp</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Scegli Template:</label>
          <select id="select-template" class="form-select">
            <option value="">-- Seleziona un template --</option>
            <?php
            try {
                $templates = $pdo->query("SELECT * FROM messaggi_template WHERE attivo = 1 ORDER BY titolo")->fetchAll();
                foreach ($templates as $t) {
                    echo '<option value="'.htmlspecialchars($t['messaggio']).'">'.htmlspecialchars($t['titolo']).'</option>';
                }
            } catch (PDOException $e) {
                // Gestione silenziosa in caso di errore DB
            }
            ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Anteprima messaggio:</label>
          <textarea id="msg-preview" class="form-control" rows="5"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a id="btn-send-wa" href="#" target="_blank" class="btn btn-success">
          <i class="bi bi-send"></i> Apri WhatsApp Web
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.editable-fase').forEach(input => {
        input.addEventListener('change', async function () {
            const id = this.dataset.id;
            const fase = this.value.trim();
            const saved = this.closest('div').querySelector('.fase-saved');
            this.classList.add('border-warning');
            try {
                const response = await fetch('../ajax/update_fase_lavorazione.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, fase })
                });
                const result = await response.json();
                
                if (result.success) {
                    this.classList.remove('border-warning');
                    this.classList.add('border-success');
                    saved.classList.remove('d-none');
                    setTimeout(() => { saved.classList.add('d-none'); this.classList.remove('border-success'); }, 1500);
                } else {
                    this.classList.add('border-danger');
                    alert('Errore: ' + result.error);
                }
            } catch {
                this.classList.add('border-danger');
                alert('Errore di connessione');
            }
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.editable-orario').forEach(input => {
        input.addEventListener('blur', async function () {
            const id = this.dataset.id;
            const orario = this.value.trim();
            const saved = this.closest('td').querySelector('.orario-saved');
            this.classList.add('border-warning');

            try {
                const response = await fetch('../ajax/update_orario_evento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, orario })
                });
                const result = await response.json();
                
                if (result.success) {
                    this.classList.remove('border-warning');
                    this.classList.add('border-success');
                    saved.classList.remove('d-none');
                    setTimeout(() => { saved.classList.add('d-none'); this.classList.remove('border-success'); }, 1500);
                } else {
                    this.classList.add('border-danger');
                    alert('Errore: ' + result.error);
                }
            } catch {
                this.classList.add('border-danger');
                alert('Errore di connessione');
            }
        });
    });
});


document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.editable-consegna-orario').forEach(input => {
        input.addEventListener('blur', async function () {
            const id = this.dataset.id;
            const orario = this.value.trim();
            const saved = this.closest('td').querySelector('.orario-saved');
            this.classList.add('border-warning');
            
            try {
                const response = await fetch('../ajax/update_orario_consegna.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, orario })
                });
                const result = await response.json();
                
                if (result.success) {
                    this.classList.remove('border-warning');
                    this.classList.add('border-success');
                    saved.classList.remove('d-none');
                    setTimeout(() => { saved.classList.add('d-none'); this.classList.remove('border-success'); }, 1500);
                } else {
                    this.classList.add('border-danger');
                    alert('Errore: ' + result.error);
                }
            } catch(error) {
                this.classList.add('border-danger');
                alert('Errore di connessione');
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {

    const saveConsegna = async (id, data, orario, input) => {
        const saved = input.closest('td').querySelector('.consegna-saved');
        const dataInput = input.closest('td').querySelector('.editable-consegna-data');
        const orarioInput = input.closest('td').querySelector('.editable-consegna-orario');
        
        dataInput.classList.remove('border-success', 'border-danger');
        orarioInput.classList.remove('border-success', 'border-danger');
        
        dataInput.classList.add('border-warning');
        orarioInput.classList.add('border-warning');

        try {
            const response = await fetch('../ajax/update_data_consegna.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ id, data, orario }) 
            });
            const result = await response.json();
            
            window.location.reload(); 

        } catch {
            dataInput.classList.add('border-danger');
            orarioInput.classList.add('border-danger');
            alert('Errore di connessione');
        }
    };

    document.querySelectorAll('.editable-consegna-data').forEach(input => {
        input.addEventListener('blur', function () {
            const id = this.dataset.id;
            const data = this.value;
            const orario = this.closest('td').querySelector('.editable-consegna-orario').value;
            saveConsegna(id, data, orario, this);
        });
    });
    
    document.querySelectorAll('.editable-consegna-orario').forEach(input => {
        input.addEventListener('blur', function () {
            const id = this.dataset.id;
            const orario = this.value;
            const data = this.closest('td').querySelector('.editable-consegna-data').value;
            saveConsegna(id, data, orario, this);
        });
    });

    document.querySelectorAll('.clear-consegna').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!confirm('Sei sicuro di voler cancellare la data e l\'ora di consegna?')) return;
            const id = this.dataset.id;
            const dataInput = this.closest('td').querySelector('.editable-consegna-data');
            const orarioInput = this.closest('td').querySelector('.editable-consegna-orario');
            
            dataInput.value = ''; 
            orarioInput.value = ''; 
            
            saveConsegna(id, '', '', this);
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('modalWhatsApp');
    const msgPreview = document.getElementById('msg-preview');
    const selectTemplate = document.getElementById('select-template');
    const btnSend = document.getElementById('btn-send-wa');

    let currentData = {};

    const updateWhatsAppLink = (text) => {
        const encoded = encodeURIComponent(text);
        const phone = (currentData.telefono || '').replace(/\D/g, ''); 
        if (phone) {
             btnSend.href = `https://wa.me/${phone}?text=${encoded}`;
             btnSend.classList.remove('disabled');
        } else {
             btnSend.href = "#";
             btnSend.classList.add('disabled');
        }
    };
    
    const formatDate = (dateString) => {
        if (!dateString) return '';
        try {
            return new Date(dateString + 'T00:00:00').toLocaleDateString('it-IT');
        } catch {
            return dateString;
        }
    };


    document.querySelectorAll('.btn-whatsapp').forEach(btn => {
        btn.addEventListener('click', () => {
            currentData = {
                nome: btn.dataset.nome,
                cognome: btn.dataset.cognome,
                telefono: btn.dataset.telefono,
                evento: btn.dataset.evento,
                dataevento: formatDate(btn.dataset.dataevento),
                dataconsegna: formatDate(btn.dataset.dataconsegna),
                orarioevento: btn.dataset.orarioevento || '', 
                orarioconsegna: btn.dataset.orarioconsegna || '' 
            };

            msgPreview.value = '';
            selectTemplate.value = '';
            updateWhatsAppLink(''); 

            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    });

    selectTemplate.addEventListener('change', () => {
        let text = selectTemplate.value || '';
        text = text.replace(/#NomeCliente/g, currentData.nome || '')
                    .replace(/#CognomeCliente/g, currentData.cognome || '')
                    .replace(/#TipoEvento/g, currentData.evento || '')
                    .replace(/#DataEvento/g, currentData.dataevento || '')
                    .replace(/#OrarioEvento/g, currentData.orarioevento || '')
                    .replace(/#DataConsegna/g, currentData.dataconsegna || '')
                    .replace(/#OrarioConsegna/g, currentData.orarioconsegna || ''); 

        msgPreview.value = text;
        updateWhatsAppLink(text);
    });

    msgPreview.addEventListener('input', () => {
        updateWhatsAppLink(msgPreview.value);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const PREFIX = 'A:\\Il Monteforte\\LAVORI IN CORSO\\0.RUNNING\\';

    document.querySelectorAll('.btn-copy-drive').forEach(btn => {
        btn.addEventListener('click', function () {
            const relativePath = this.dataset.path;
            const fullPath = PREFIX + relativePath;

            if (!navigator.clipboard) {
                 alert('Copia non supportata su connessioni non sicure (HTTP).');
                 return;
            }

            navigator.clipboard.writeText(fullPath).then(() => {
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check-lg"></i> Copiato!';
                this.classList.remove('btn-outline-info');
                this.classList.add('btn-success');
                
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.classList.remove('btn-success');
                    this.classList.add('btn-outline-info');
                }, 1500);

            }).catch(err => {
                console.error('Errore durante la copia: ', err);
                alert('Impossibile copiare il percorso. Controlla i permessi del browser.');
            });
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.editable-status').forEach(select => {
        select.addEventListener('change', async function () {
            const id = this.dataset.id;
            const new_status_id = this.value;
            const current_status_id = this.dataset.currentId;
            
            this.classList.add('border-warning');

            try {
                const response = await fetch('../ajax/update_status_evento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id, new_status_id })
                });
                
                const result = await response.json();

                if (result.success) {
                    this.classList.remove('border-warning');
                    this.classList.add('border-success');
                    this.dataset.currentId = new_status_id;
                    
                    setTimeout(() => { 
                        window.location.reload(); 
                    }, 500); 
                    
                } else {
                    this.classList.remove('border-warning');
                    this.classList.add('border-danger');
                    alert('Errore: ' + result.error);
                    this.value = current_status_id; 
                }
            } catch (error) {
                this.classList.remove('border-warning');
                this.classList.add('border-danger');
                alert('Errore di connessione o di sistema: ' + error.message);
                this.value = current_status_id; 
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>