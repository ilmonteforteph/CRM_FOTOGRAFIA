<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
$isAdmin = is_admin();
$current_op_id = current_user()['id_operatore'] ?? 0;

// Filtri esistenti
$filter_event = isset($_GET['event']) ? (int)$_GET['event'] : null;
$filter_type = $_GET['type'] ?? null;
$filter_category = isset($_GET['category']) ? (int)$_GET['category'] : null;

// NUOVI FILTRI: Mese e Anno
$filter_month = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : null;
$filter_year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : null;

// Anni disponibili per il filtro
$current_year = (int)date('Y');
$years = range($current_year, $current_year - 5); // Ultimi 6 anni

// Nomi dei mesi in italiano
$months_it = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

// Recupero Categorie per il filtro
try {
    $stmt_cats = $pdo->query("SELECT id_categoria, nome_categoria FROM categorie_movimento ORDER BY nome_categoria");
    $categories = $stmt_cats->fetchAll(PDO::FETCH_KEY_PAIR); // id => nome
} catch (PDOException $e) {
    error_log("Errore nel recupero categorie: " . $e->getMessage());
    $categories = [];
}

// ELIMINAZIONE (solo admin)
if ($isAdmin && isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    $id_movimento = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM movimentazioni WHERE id_movimento = ?");
        $stmt->execute([$id_movimento]);
        // Mantieni i filtri attuali nell'URL di reindirizzamento
        $redirect_params = array_filter([
            'event' => $filter_event, 
            'type' => $filter_type, 
            'category' => $filter_category,
            'month' => $filter_month, 
            'year' => $filter_year    
        ]);
        $redirect_query = http_build_query($redirect_params);
        $redirect_url = 'movements.php' . ($redirect_query ? '?' . $redirect_query . '&' : '?') . 'msg=' . urlencode('Movimento eliminato con successo!');
        header('Location: ' . $redirect_url);
        exit;
    } catch (PDOException $e) {
        $error = "Errore durante l'eliminazione: " . htmlspecialchars($e->getMessage());
    }
}

// COSTRUZIONE QUERY GENERALE
$where = ["1=1"];
$params = [];

if (!$isAdmin) {
    $where[] = "m.id_operatore = ?";
    $params[] = $current_op_id;
}
if ($filter_event) {
    $where[] = "m.id_evento = ?";
    $params[] = $filter_event;
}
if ($filter_type && in_array($filter_type, ['entrata', 'uscita'])) {
    $where[] = "m.tipo = ?";
    $params[] = $filter_type;
}
if ($filter_category) {
    $where[] = "m.id_categoria = ?";
    $params[] = $filter_category;
}

// AGGIUNTA FILTRO ANNO
if ($filter_year) {
    $where[] = "YEAR(m.data_movimento) = ?";
    $params[] = $filter_year;
}

// AGGIUNTA FILTRO MESE
if ($filter_month) {
    $where[] = "MONTH(m.data_movimento) = ?";
    $params[] = $filter_month;
}

$where_sql = implode(' AND ', $where);

// Base della query SQL per i dati
$sql_select_base = "
    SELECT 
        m.*, c.nome_categoria,
        o.nome AS operatore_nome, o.cognome AS operatore_cognome,
        CONCAT_WS(' --> ', CONCAT(cli.nome, ' ', cli.cognome), e.note) AS nome_evento
    FROM movimentazioni m
    LEFT JOIN categorie_movimento c ON m.id_categoria = c.id_categoria
    LEFT JOIN operatori o ON m.id_operatore = o.id_operatore
    LEFT JOIN eventi e ON m.id_evento = e.id_evento
    LEFT JOIN clienti cli ON e.id_cliente = cli.id_cliente
    WHERE {$where_sql}
";

// --- LOGICA DI ESPORTAZIONE EXCEL (CSV) ---
if (isset($_GET['export']) && $_GET['export'] == 1) {
    
    // Eseguo la query completa (senza LIMIT)
    $stmt_export = $pdo->prepare($sql_select_base . " ORDER BY m.data_movimento ASC");
    $stmt_export->execute($params);
    $movimenti_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    // Calcolo totali per il footer del file
    $stmt_total = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrata' THEN importo ELSE 0 END) AS totale_entrate,
            SUM(CASE WHEN tipo = 'uscita' THEN importo ELSE 0 END) AS totale_uscite
        FROM movimentazioni m
        WHERE {$where_sql}
    ");
    $stmt_total->execute($params);
    $totals_export = $stmt_total->fetch();
    $saldo_export = ($totals_export['totale_entrate'] ?? 0) - ($totals_export['totale_uscite'] ?? 0);


    // Imposta le intestazioni per il download del file CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="movimentazioni_export_' . date('Ymd_His') . '.csv"');
    
    // Crea un puntatore a un file di output
    $output = fopen('php://output', 'w');

    // Funzione per la codifica UTF-8 per Excel (BOM)
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF))); 

    // --- SEZIONE FILTRI E INFORMAZIONI GENERALI ---
    fputcsv($output, ['Report Movimentazioni Filtrate'], ';');
    fputcsv($output, ['Generato il:', date('d/m/Y H:i:s')], ';');
    fputcsv($output, [], ';'); // Riga vuota

    fputcsv($output, ['--- FILTRI APPLICATI ---'], ';'); // Separatore

    $filter_descriptions = [];

    if ($filter_event) {
        // Recupera il nome completo dell'evento per la chiarezza nell'export
        $event_name_full = 'ID: ' . $filter_event;
        try {
            $stmt_event_name = $pdo->prepare("SELECT CONCAT_WS(' --> ', CONCAT(cli.nome, ' ', cli.cognome), e.note) FROM eventi e LEFT JOIN clienti cli ON e.id_cliente = cli.id_cliente WHERE e.id_evento = ?");
            $stmt_event_name->execute([$filter_event]);
            $event_name_full = $stmt_event_name->fetchColumn() ?? 'ID: ' . $filter_event;
        } catch (PDOException $e) {
            // Ignoro l'errore e uso l'ID
        }
        $filter_descriptions[] = ['Filtro Evento:', $event_name_full];
    }

    if ($filter_type) {
        $type_label = $filter_type == 'entrata' ? 'Entrate' : 'Uscite';
        $filter_descriptions[] = ['Filtro Tipo:', $type_label];
    }

    if ($filter_category) {
        $category_name = $categories[$filter_category] ?? 'Sconosciuta (ID: ' . $filter_category . ')';
        $filter_descriptions[] = ['Filtro Categoria:', $category_name];
    }

    if ($filter_month) {
        $month_name = $months_it[$filter_month] ?? 'Mese non valido';
        $filter_descriptions[] = ['Filtro Mese:', $month_name];
    }

    if ($filter_year) {
        $filter_descriptions[] = ['Filtro Anno:', $filter_year];
    }

    if (empty($filter_descriptions)) {
         fputcsv($output, ['Filtri Attivi:', 'Nessuno'], ';');
    } else {
        foreach ($filter_descriptions as $filter_row) {
            fputcsv($output, $filter_row, ';');
        }
    }

    fputcsv($output, ['------------------------'], ';'); // Separatore
    fputcsv($output, [], ';'); // Riga vuota prima dell'intestazione dei dati
    // --- FINE SEZIONE FILTRI ---


    // Intestazioni (Header) del CSV - Adattate alle colonne mostrate
    $headers = [
        'Data', 
        'Tipo', 
        'Importo', 
        'Laboratorio Riferimento', 
        'Categoria', 
        'Evento Collegato'
    ];
    if ($isAdmin) {
        $headers[] = 'Operatore';
    }
    
    fputcsv($output, $headers, ';'); // Intestazione dei dati

    // Righe dei dati
    foreach ($movimenti_export as $mov) {
        $row = [
            date('d/m/Y H:i', strtotime($mov['data_movimento'])),
            ucfirst($mov['tipo']),
            number_format($mov['importo'], 2, ',', '.'), // Formattazione numerica
            $mov['laboratorio_riferimento'] ?? 'N.D.',
            $mov['nome_categoria'] ?? 'N.D.',
            $mov['nome_evento'] ?? 'N.D.'
        ];
        if ($isAdmin) {
            $row[] = ($mov['operatore_nome'] ?? '') . ' ' . ($mov['operatore_cognome'] ?? '');
        }
        fputcsv($output, $row, ';');
    }
    
    // --- SEZIONE RIEPILOGO TOTALI ---
    fputcsv($output, [], ';'); // Riga vuota per separazione
    fputcsv($output, ['--- RIEPILOGO TOTALI FILTRATI ---'], ';');
    
    // Totale Entrate
    fputcsv($output, ['Totale Entrate:', '', number_format($totals_export['totale_entrate'] ?? 0, 2, ',', '.')], ';');
    
    // Totale Uscite
    fputcsv($output, ['Totale Uscite:', '', number_format($totals_export['totale_uscite'] ?? 0, 2, ',', '.')], ';');
    
    // Saldo Finale con indicatore
    $saldo_status = $saldo_export >= 0 ? 'POSITIVO' : 'NEGATIVO';
    fputcsv($output, ['SALDO FINALE:', '', number_format($saldo_export, 2, ',', '.'), $saldo_status], ';'); 

    fclose($output);
    exit; // Termina lo script dopo l'esportazione
}
// --- FINE LOGICA DI ESPORTAZIONE EXCEL ---


// --- LOGICA DI VISUALIZZAZIONE NORMALE (PARTE VISIVA HTML) ---

// 1. Recupero Dati per la tabella (con LIMIT)
$stmt = $pdo->prepare($sql_select_base . " ORDER BY m.data_movimento DESC LIMIT 200");
$stmt->execute($params);
$movimenti = $stmt->fetchAll();

// 2. Calcolo Totali (per la visualizzazione)
$stmt_total = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrata' THEN importo ELSE 0 END) AS totale_entrate,
        SUM(CASE WHEN tipo = 'uscita' THEN importo ELSE 0 END) AS totale_uscite
    FROM movimentazioni m
    WHERE {$where_sql}
");
$stmt_total->execute($params);
$totals = $stmt_total->fetch();

// Calcolo del Saldo Totale per la riga in fondo alla tabella
$saldo_totale_filtrato = ($totals['totale_entrate'] ?? 0) - ($totals['totale_uscite'] ?? 0);
$saldo_class_row = $saldo_totale_filtrato >= 0 ? 'table-success fw-bold' : 'table-danger fw-bold'; // Ho cambiato da table-info a table-success per coerenza


require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h3 class="mb-2 mb-md-0">
        <i class="bi bi-cash-coin text-success me-2"></i>Gestione Movimentazioni
    </h3>
    <a href="movement_form.php<?php echo $filter_event ? '?event_id=' . $filter_event : ''; ?>" 
        class="btn btn-success">
        <i class="bi bi-plus-circle"></i> Nuovo Movimento
    </a>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success d-flex align-items-center">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-lg border-0 text-white bg-success h-100">
            <div class="card-body">
                <h6 class="text-uppercase small mb-1"><i class="bi bi-arrow-down-circle me-1"></i>Entrate Totali</h6>
                <h4 class="mb-0">€ <?php echo number_format($totals['totale_entrate'] ?? 0, 2, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-lg border-0 text-white bg-danger h-100">
            <div class="card-body">
                <h6 class="text-uppercase small mb-1"><i class="bi bi-arrow-up-circle me-1"></i>Uscite Totali</h6>
                <h4 class="mb-0">€ <?php echo number_format($totals['totale_uscite'] ?? 0, 2, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php 
            $saldo = ($totals['totale_entrate'] ?? 0) - ($totals['totale_uscite'] ?? 0);
            $saldo_class = $saldo >= 0 ? 'bg-primary text-white' : 'bg-warning text-dark';
        ?>
        <div class="card shadow-lg border-0 <?php echo $saldo_class; ?> h-100">
            <div class="card-body">
                <h6 class="text-uppercase small mb-1"><i class="bi bi-balance-scale me-1"></i>Saldo Filtrato</h6>
                <h4 class="mb-0">€ <?php echo number_format($saldo, 2, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card p-3 mb-4 shadow-sm bg-light border-0">
    <form method="GET" class="row g-3 align-items-end">
        <?php 
        // Genera i campi hidden per tutti i filtri attuali
        $current_filters = array_filter([
            'event' => $filter_event, 
            'type' => $filter_type, 
            'category' => $filter_category,
            'month' => $filter_month, 
            'year' => $filter_year
        ]);
        foreach ($current_filters as $key => $value) {
            if ($key !== 'event' || !$filter_event) {
                echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
            }
        }
        if ($filter_event) {
            echo '<input type="hidden" name="event" value="' . $filter_event . '">';
        }
        
        ?>

        <div class="col-md-3 col-lg-2">
            <label for="filter_type" class="form-label small text-muted">
                <i class="bi bi-funnel me-1"></i>Tipo
            </label>
            <select name="type" id="filter_type" class="form-select">
                <option value="">Tutti</option>
                <option value="entrata" <?php echo $filter_type == 'entrata' ? 'selected' : ''; ?>>Entrate</option>
                <option value="uscita" <?php echo $filter_type == 'uscita' ? 'selected' : ''; ?>>Uscite</option>
            </select>
        </div>

        <div class="col-md-4 col-lg-3">
            <label for="filter_category" class="form-label small text-muted">
                <i class="bi bi-tags me-1"></i>Categoria
            </label>
            <select name="category" id="filter_category" class="form-select">
                <option value="">Tutte le categorie</option>
                <?php foreach ($categories as $id => $nome): ?>
                    <option value="<?php echo $id; ?>" <?php echo $filter_category == $id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nome); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3 col-lg-2">
            <label for="filter_month" class="form-label small text-muted">
                <i class="bi bi-calendar-check me-1"></i>Mese
            </label>
            <select name="month" id="filter_month" class="form-select">
                <option value="">Tutti i mesi</option>
                <?php foreach ($months_it as $id => $nome): ?>
                    <option value="<?php echo $id; ?>" <?php echo $filter_month == $id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nome); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2 col-lg-1">
            <label for="filter_year" class="form-label small text-muted">
                <i class="bi bi-calendar-event me-1"></i>Anno
            </label>
            <select name="year" id="filter_year" class="form-select">
                <option value="">Tutti</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3 col-lg-2 d-flex">
            <button type="submit" class="btn btn-primary me-2 flex-grow-1">
                <i class="bi bi-search"></i> Filtra
            </button>
            <a href="movements.php<?php echo $filter_event ? '?event=' . $filter_event : ''; ?>" class="btn btn-outline-secondary flex-grow-1" title="Azzera Filtri">
                <i class="bi bi-arrow-repeat"></i>
            </a>
        </div>
        
        <div class="col-md-12 col-lg-2 d-grid">
            <?php
            // Ricostruisce la query string con tutti i filtri attivi + il flag export
            $export_params = array_merge($current_filters, ['export' => 1]);
            $export_query = http_build_query($export_params);
            ?>
            <a href="movements.php?<?php echo $export_query; ?>" 
               class="btn btn-dark" title="Esporta i dati filtrati">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export Excel
            </a>
        </div>
    </form>
</div>

<?php if (!empty($current_filters)): ?>
<details class="mb-4 alert alert-info p-3 shadow-sm rounded-3">
    <summary class="fw-bold"><i class="bi bi-filter-square me-2"></i>Filtri applicati (Clicca per dettagli)</summary>
    <ul class="list-unstyled mb-0 mt-2 small">
        <?php 
        $display_filters = [];
        if ($filter_type) $display_filters[] = 'Tipo: ' . ($filter_type == 'entrata' ? 'Entrate' : 'Uscite');
        if ($filter_category) $display_filters[] = 'Categoria: ' . ($categories[$filter_category] ?? 'N.D.');
        if ($filter_month) $display_filters[] = 'Mese: ' . ($months_it[$filter_month] ?? 'N.D.');
        if ($filter_year) $display_filters[] = 'Anno: ' . $filter_year;

        // Recupera nome evento se filtrato
        if ($filter_event) {
            $event_name_html = 'ID: ' . $filter_event;
            try {
                $stmt_event_name = $pdo->prepare("SELECT CONCAT_WS(' --> ', CONCAT(cli.nome, ' ', cli.cognome), e.note) FROM eventi e LEFT JOIN clienti cli ON e.id_cliente = cli.id_cliente WHERE e.id_evento = ?");
                $stmt_event_name->execute([$filter_event]);
                $event_name_html = $stmt_event_name->fetchColumn() ?? 'ID: ' . $filter_event;
            } catch (PDOException $e) {}
            $display_filters[] = 'Evento Collegato: ' . htmlspecialchars($event_name_html);
        }

        foreach ($display_filters as $filter_text) {
            echo '<li>' . $filter_text . '</li>';
        }
        ?>
    </ul>
</details>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped table-hover align-middle rounded-3 overflow-hidden" style="border-collapse:separate; border-spacing:0;">
        <thead class="table-primary shadow-sm">
            <tr>
                <th><i class="bi bi-calendar3"></i> Data</th>
                <th><i class="bi bi-arrow-left-right"></i> Tipo</th>
                <th><i class="bi bi-currency-euro"></i> Importo</th>
                <th><i class="bi bi-box"></i> Laboratorio Riferimento</th>
                <th><i class="bi bi-tags"></i> Categoria</th>
                <th><i class="bi bi-calendar-event"></i> Evento Collegato</th>
                <?php if ($isAdmin): ?><th><i class="bi bi-person-gear"></i> Operatore</th><?php endif; ?>
                <th class="text-center"><i class="bi bi-tools"></i> Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($movimenti)): ?>
                <tr>
                    <td colspan="<?php echo $isAdmin ? 8 : 7; ?>" class="text-center text-muted py-4">
                        <i class="bi bi-info-circle me-1"></i>Nessuna movimentazione trovata con i filtri applicati.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($movimenti as $mov): ?>
                    <tr class="<?php echo $mov['tipo'] === 'entrata' ? 'table-success' : 'table-danger'; ?>">
                        <td><?php echo date('d/m/Y H:i', strtotime($mov['data_movimento'])); ?></td>
                        <td class="fw-bold text-capitalize"><?php echo htmlspecialchars($mov['tipo']); ?></td>
                        <td class="text-nowrap">€ <?php echo number_format($mov['importo'], 2, ',', '.'); ?></td>
                        <td><?php echo htmlspecialchars($mov['laboratorio_riferimento'] ?? 'N.D.'); ?></td> 
                        <td><?php echo htmlspecialchars($mov['nome_categoria'] ?? 'N.D.'); ?></td>
                        <td><?php echo htmlspecialchars($mov['nome_evento'] ?? 'N.D.'); ?></td> 
                        <?php if ($isAdmin): ?>
                            <td><?php echo htmlspecialchars(($mov['operatore_nome'] ?? '') . ' ' . ($mov['operatore_cognome'] ?? '')); ?></td>
                        <?php endif; ?>
                        <td class="text-center">
                            <a href="movement_form.php?id=<?php echo $mov['id_movimento']; ?>" 
                                class="btn btn-sm btn-outline-primary" title="Modifica">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <?php if ($isAdmin): ?>
                                <a href="movements.php?action=delete&id=<?php echo $mov['id_movimento']; ?>" 
                                    onclick="return confirm('Confermi eliminazione del movimento?');"
                                    class="btn btn-sm btn-outline-danger ms-1" title="Elimina">
                                    <i class="bi bi-trash-fill"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <tr class="<?php echo $saldo_class_row; ?> table-active"> <td colspan="2" class="text-end">**SALDO TOTALE FILTRATO**</td>
                    <td class="text-nowrap fs-5">
                        **€ <?php echo number_format($saldo_totale_filtrato, 2, ',', '.'); ?>**
                    </td>
                    <td colspan="<?php echo $isAdmin ? 5 : 4; ?>"></td> 
                </tr>
                
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.table th, .table td { vertical-align: middle; }
.btn-sm i { vertical-align: -1px; }
/* Nuovo stile per la riga saldo per renderla più visibile */
.table-active { border-top: 3px solid var(--bs-primary) !important; } 
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>