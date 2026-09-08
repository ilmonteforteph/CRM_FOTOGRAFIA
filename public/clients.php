<?php
// public/clients.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$search_term = trim($_GET['search'] ?? '');
$error = $_GET['error'] ?? null;
$success_msg = $_GET['msg'] ?? null;

// Variabili per mantenere stato form quick add
$nome = $_POST['nome'] ?? '';
$cognome = $_POST['cognome'] ?? '';
$telefono = $_POST['telefono'] ?? '';
$settimane_gravidanza = $_POST['settimane_gravidanza'] ?? '';

// Variabili export panel
$export_step = $_GET['export_step'] ?? 'dates'; // 'dates' o 'confirm'
$ids_to_confirm = $_GET['ids'] ?? '';
$count_to_confirm = (int)($_GET['count'] ?? 0);
$date_start_export = $_GET['data_start'] ?? null;
$date_end_export = $_GET['data_end'] ?? null;
// NUOVA VARIABILE PER LA RICERCA NELL'EXPORT
$search_export_term = trim($_GET['search_export'] ?? '');

// --- CARICAMENTO DATI PER PROVENIENZA ---
$tipologie_provenienza = [];
$clienti_referenti = [];
$promozioni_disponibili = [];

try {
    $tipologie_provenienza = $pdo->query("SELECT id_provenienza, provenienza FROM tipologia_provenienza ORDER BY provenienza")->fetchAll(PDO::FETCH_ASSOC);
    $clienti_referenti = $pdo->query("SELECT id_cliente, nome, cognome FROM clienti ORDER BY cognome, nome")->fetchAll(PDO::FETCH_ASSOC);
    $promozioni_disponibili = $pdo->query("SELECT id_promozione, DESCRIZIONE FROM promozioni ORDER BY data_promo DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Attenzione: Errore durante il caricamento dei dati di provenienza. " . htmlspecialchars($e->getMessage());
}

// --- 1. INSERIMENTO RAPIDO (POST: quick_add) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_add') {
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $settimane_gravidanza = !empty($_POST['settimane_gravidanza']) ? (int)$_POST['settimane_gravidanza'] : null;

    $id_tipologia_provenienza = !empty($_POST['id_tipologia_provenienza']) ? (int)$_POST['id_tipologia_provenienza'] : null;
    $id_associativo = trim($_POST['id_associativo'] ?? '');
    $redirect_to = $_POST['redirect_to'] ?? null;

    if (empty($nome) || empty($cognome)) {
        $error = "Errore: Nome e Cognome sono campi obbligatori per l'inserimento rapido.";
    } elseif ($id_tipologia_provenienza) {
        $error = "Se è selezionata una provenienza, è obbligatorio specificare anche la fonte.";
    } elseif ($settimane_gravidanza && ($settimane_gravidanza < 1 || $settimane_gravidanza > 42)) {
        $error = "Le settimane di gravidanza devono essere comprese tra 1 e 42.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO clienti (nome, cognome, telefono, data_creazione) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$nome, $cognome, $telefono]);
            $new_client_id = $pdo->lastInsertId();

            if ($id_tipologia_provenienza && $id_associativo !== '') {
                // Inserisce la provenienza così com'è (gestione valore numerico o stringa a monte)
                $sql_prov = "INSERT INTO provenienza_cliente (id_cliente, id_tipologia_provenienza, id_associativo) VALUES (?, ?, ?)";
                $pdo->prepare($sql_prov)->execute([$new_client_id, $id_tipologia_provenienza, $id_associativo]);
            }

            if ($settimane_gravidanza) {
                $giorni_da_aggiungere = 280 - ($settimane_gravidanza * 7);
                $data_creazione = date('Y-m-d H:i:s');
                $dpp_date = new DateTime($data_creazione);
                $dpp_date->modify("+{$giorni_da_aggiungere} days");
                $dpp_formattata = $dpp_date->format('Y-m-d');

                $sql_grav = "INSERT INTO gravidanze_cliente (id_cliente, settimane_gravidanza_contatto, note_gravidanza, data_creazione, data_presunta_parto) VALUES (?, ?, ?, ?, ?)";
                $pdo->prepare($sql_grav)->execute([$new_client_id, $settimane_gravidanza, "Registrato tramite Quick Add", $data_creazione, $dpp_formattata]);
            }

            $pdo->commit();
            $success_msg = "Cliente {$nome} {$cognome} aggiunto con successo (ID: {$new_client_id}).";

            if ($redirect_to && strpos($redirect_to, 'http') === false) {
                $redirect_url = $redirect_to . "?id_cliente={$new_client_id}&msg=" . urlencode("Cliente '{$nome} {$cognome}' aggiunto e selezionato!");
                header("Location: {$redirect_url}");
                exit;
            }

            header("Location: clients.php?msg=" . urlencode($success_msg));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Errore durante l'inserimento del cliente: " . htmlspecialchars($e->getMessage());
        }
    }
}

// -----------------------------------------------------------------------------------
// ⭐ Logica Eliminazione Cliente (POST: delete)
// -----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $client_id_to_delete = (int)($_POST['id_cliente'] ?? 0);
    if ($client_id_to_delete > 0) {
        $pdo->beginTransaction();
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM eventi WHERE id_cliente = ?");
            $stmt_check->execute([$client_id_to_delete]);
            if ($stmt_check->fetchColumn() > 0) {
                $error = "Impossibile eliminare il cliente (ID: {$client_id_to_delete}). Sono presenti eventi associati.";
                $pdo->rollBack();
            } else {
                $pdo->prepare("DELETE FROM provenienza_cliente WHERE id_cliente = ?")->execute([$client_id_to_delete]);
                $pdo->prepare("DELETE FROM gravidanze_cliente WHERE id_cliente = ?")->execute([$client_id_to_delete]);
                $stmt_delete = $pdo->prepare("DELETE FROM clienti WHERE id_cliente = ?");
                $stmt_delete->execute([$client_id_to_delete]);
                $pdo->commit();
                $success_msg = "Cliente eliminato con successo (ID: {$client_id_to_delete}).";
                header("Location: clients.php?msg=" . urlencode($success_msg));
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Errore SQL durante l'eliminazione: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $error = "ID cliente non valido per l'eliminazione.";
    }
}
// -----------------------------------------------------------------------------------

// --- 3. Logica di MARCATURA MANUALE SINGOLA (POST: mark_exported) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_exported') {
    $id_cliente = (int)($_POST['id_cliente'] ?? 0);
    if ($id_cliente > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE clienti SET exported_to_google_contacts = 1 WHERE id_cliente = ?");
            $stmt->execute([$id_cliente]);
            $success_msg = "Cliente (ID: {$id_cliente}) marcato come esportato su Google Contatti.";
            header("Location: clients.php?msg=" . urlencode($success_msg));
            exit;
        } catch (PDOException $e) {
            $error = "Errore durante la marcatura: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $error = "ID cliente non valido per la marcatura.";
    }
}

// --- 4. Logica di CONFERMA ESPORTAZIONE MANUALE (POST: confirm_export) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_export') {
    $ids_to_mark = $_POST['confirmed_ids'] ?? '';
    $redirect_url_after_confirm = $_POST['redirect_url'] ?? null; // Recupera il campo di reindirizzamento

    $ids_array = explode(',', $ids_to_mark);
    $clean_ids_array = array_filter(array_map('trim', $ids_array), 'is_numeric');

    if (!empty($clean_ids_array)) {
        $count_marked = count($clean_ids_array);
        $placeholders = implode(',', array_fill(0, $count_marked, '?'));

        $pdo->beginTransaction();
        try {
            $sql_update = "UPDATE clienti SET exported_to_google_contacts = 1 WHERE id_cliente IN ({$placeholders})";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute($clean_ids_array);
            $pdo->commit();

            $success_msg = "Congratulazioni! Sono stati marcati {$count_marked} clienti come correttamente esportati su Google Contatti.";
            
            // Reindirizzamento alla URL specificata con il messaggio di successo
            if ($redirect_url_after_confirm) {
                 // Previene XSS e assicura che sia un URL relativo o della stessa pagina
                 $clean_redirect = filter_var($redirect_url_after_confirm, FILTER_SANITIZE_URL);
                 $final_redirect = $clean_redirect . (strpos($clean_redirect, '?') === false ? '?' : '&') . 'msg=' . urlencode($success_msg);
                 header("Location: {$final_redirect}");
                 exit;
            }

            header("Location: clients.php?msg=" . urlencode($success_msg));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Errore durante la marcatura dei contatti come esportati: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $error = "Nessun ID valido da marcare. Operazione annullata.";
    }
}
// -----------------------------------------------------------------------------------

// --- 5. Logica di Ricerca e Recupero Clienti (con provenienza/ultima gravidanza)
$clienti = [];
$totClienti = 0;

// Nota: costruisco una query base semplice e poi aggiungo dettagli tramite sottoquery per l'ultima gravidanza e provenienza.
try {
    $sql = "SELECT c.*,
                   (SELECT CONCAT(tp.provenienza, ' (',
                         CASE
                           WHEN tp.provenienza = 'CLIENTE' THEN (SELECT CONCAT(cc.nome, ' ', cc.cognome) FROM clienti cc WHERE cc.id_cliente = pc.id_associativo)
                           WHEN tp.provenienza = 'PROMOZIONE' THEN (SELECT p.DESCRIZIONE FROM promozioni p WHERE p.id_promozione = pc.id_associativo)
                           ELSE pc.id_associativo
                         END, ')')
                    FROM provenienza_cliente pc
                    JOIN tipologia_provenienza tp ON pc.id_tipologia_provenienza = tp.id_provenienza
                    WHERE pc.id_cliente = c.id_cliente
                    LIMIT 1) AS provenienza_dettaglio,
                   (SELECT gc.settimane_gravidanza_contatto FROM gravidanze_cliente gc WHERE gc.id_cliente = c.id_cliente ORDER BY gc.id_gravidanza DESC LIMIT 1) AS settimane_gravidanza_contatto
            FROM clienti c";

    $params = [];
    if (!empty($search_term)) {
        $sql .= " WHERE c.nome LIKE ? OR c.cognome LIKE ? OR c.citta LIKE ?";
        $like_param = '%' . $search_term . '%';
        $params = [$like_param, $like_param, $like_param];
    }

    $sql .= " ORDER BY c.cognome, c.nome";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totClienti = count($clienti);
} catch (PDOException $e) {
    $error = "Errore durante la ricerca: " . htmlspecialchars($e->getMessage());
    $clienti = [];
    $totClienti = 0;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-12 d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="mb-0"><i class="bi bi-people-fill me-2"></i>Gestione Clienti</h2>
        <div class="d-flex gap-2">
            <a href="#export-panel" class="btn btn-primary btn-sm" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo ($export_step !== 'dates') ? 'true' : 'false'; ?>" aria-controls="export-panel">
                <i class="bi bi-cloud-download me-1"></i> Gestione Export
            </a>
            <a href="#quick-add" class="btn btn-success btn-sm" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo ($error && isset($_POST['action']) && $_POST['action'] === 'quick_add') ? 'true' : 'false'; ?>" aria-controls="quick-add">
                <i class="bi bi-plus-lg me-1"></i> Aggiungi Veloce
            </a>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
<?php endif; ?>

<div id="export-panel" class="mb-4 collapse <?php echo ($export_step !== 'dates') ? 'show' : ''; ?>">
    <div class="card card-body shadow-sm bg-light">
        <h5 class="card-title text-primary mb-3">
            <i class="bi bi-cloud-download me-1"></i> Gestione Esportazione Contatti Google
        </h5>

        <?php if ($export_step === 'dates'): ?>
            <form method="get" action="clients.php" class="row g-3 align-items-end" id="formExport">
                <input type="hidden" name="export_step" value="preview">
                
                <div class="col-md-4 col-12">
                    <label for="data_start" class="form-label small">Data inizio (Opzionale)</label>
                    <input type="date" class="form-control form-control-sm" id="data_start" name="data_start" value="<?= htmlspecialchars($date_start_export ?? '') ?>">
                </div>
                <div class="col-md-4 col-12">
                    <label for="data_end" class="form-label small">Data fine (Opzionale)</label>
                    <input type="date" class="form-control form-control-sm" id="data_end" name="data_end" value="<?= htmlspecialchars($date_end_export ?? '') ?>">
                </div>
                
                <div class="col-md-4 col-12">
                    <label for="search_export" class="form-label small">Cerca (nome, cognome, città) - Opzionale</label>
                    <input type="text" class="form-control form-control-sm" id="search_export" name="search_export" value="<?= htmlspecialchars($search_export_term) ?>" placeholder="Se vuoto, sono richieste le date.">
                </div>
                
                <div class="col-12 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search"></i> Mostra contatti
                    </button>
                </div>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const dateStartInput = document.getElementById('data_start');
                const dateEndInput = document.getElementById('data_end');
                const searchExportInput = document.getElementById('search_export');
                const formExport = document.getElementById('formExport');

                /* Imposta automaticamente la data fine = oggi, se non già impostata.
                if (!dateEndInput.value) {
                    const today = new Date().toISOString().split('T')[0];
                    dateEndInput.value = today;
                }
				*/

                formExport.addEventListener('submit', function(e) {
                    const dataStart = dateStartInput.value.trim();
                    const dataEnd = dateEndInput.value.trim();
                    const searchTerm = searchExportInput.value.trim();

                    if (!dataStart && !dataEnd && !searchTerm) {
                        e.preventDefault();
                        alert('Devi specificare almeno l\'intervallo di date OPPURE un termine di ricerca per il nominativo/città.');
                    } else if (searchTerm && !dataStart && !dataEnd) {
                        // OK: Solo ricerca nominativo
                        return true;
                    } else if (dataStart && dataEnd && !searchTerm) {
                        // OK: Solo intervallo di date
                        return true;
                    } else if (dataStart && dataEnd && searchTerm) {
                        // OK: Intervallo di date E ricerca nominativo
                        return true;
                    } else if (dataStart && !dataEnd) {
                        e.preventDefault();
                        alert('Se specifichi la Data Inizio, devi specificare anche la Data Fine (che è precompilata a oggi, ma assicurati che sia presente).');
                    } else if (!dataStart && dataEnd) {
                        e.preventDefault();
                        alert('Se specifichi la Data Fine, devi specificare anche la Data Inizio.');
                    }
                });
            });
            </script>

        <?php elseif ($export_step === 'preview'):
            $data_start = trim($_GET['data_start'] ?? '');
            $data_end = trim($_GET['data_end'] ?? '');
            $search_export = trim($_GET['search_export'] ?? ''); // Recupero la ricerca

            // Inizializzo la query base
            $sql_export = "SELECT id_cliente, nome, cognome, telefono, email, citta, data_creazione 
                           FROM clienti 
                           WHERE exported_to_google_contacts = 0";
            
            $params_export = [];
            $conditions = [];

            // 1. Condizione per intervallo di date
            if (!empty($data_start) && !empty($data_end)) {
                $conditions[] = "DATE(data_creazione) BETWEEN ? AND ?";
                $params_export[] = $data_start;
                $params_export[] = $data_end;
            }
            
            // 2. Condizione per la ricerca (nome, cognome, città)
            if (!empty($search_export)) {
                $conditions[] = "(nome LIKE ? OR cognome LIKE ? OR citta LIKE ?)";
                $like_param = '%' . $search_export . '%';
                $params_export[] = $like_param;
                $params_export[] = $like_param;
                $params_export[] = $like_param;
            }

            // Aggiungo le condizioni alla query se ce ne sono
            if (!empty($conditions)) {
                $sql_export .= " AND " . implode(' AND ', $conditions);
            } else {
                 // Questo caso non dovrebbe succedere grazie alla validazione JS, ma è una safety
                 $error = "Nessun filtro specificato (date o nominativo). Tornare indietro e specificarne uno.";
                 $clienti_export = [];
                 $count = 0;
            }

            $sql_export .= " ORDER BY cognome, nome";
            
            if (!$error) {
                try {
                    $stmt = $pdo->prepare($sql_export);
                    $stmt->execute($params_export);
                    $clienti_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $count = count($clienti_export);
                } catch (PDOException $e) {
                    $error = "Errore durante il recupero dei contatti da esportare: " . htmlspecialchars($e->getMessage());
                    $clienti_export = [];
                    $count = 0;
                }
            }
        ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
                <a href="clients.php" class="btn btn-outline-secondary btn-sm">← Torna a Inizio Export</a>
            <?php else: ?>
                <p>
                    <strong>Intervallo:</strong> 
                    <?php if (!empty($data_start)): ?>
                        <?= htmlspecialchars($data_start) ?> → <?= htmlspecialchars($data_end) ?>
                    <?php else: ?>
                        Tutte le date
                    <?php endif; ?>
                    <?php if (!empty($search_export)): ?>
                        (Filtro Nominativo/Città: **<?= htmlspecialchars($search_export) ?>**)
                    <?php endif; ?>
                </p>

                <?php if ($count === 0): ?>
                    <div class="alert alert-info">Nessun contatto da esportare con i filtri specificati.</div>
                    <a href="clients.php" class="btn btn-outline-secondary btn-sm">← Torna a Inizio Export</a>
                <?php else: ?>
                    <div class="alert alert-warning">
                        Trovati <strong><?= $count ?></strong> contatti non ancora esportati.
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Cognome</th>
                                    <th>Telefono</th>
                                    <th>Email</th>
                                    <th>Città</th>
                                    <th>Data creazione</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clienti_export as $cl): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cl['nome']) ?></td>
                                    <td><?= htmlspecialchars($cl['cognome']) ?></td>
                                    <td><?= htmlspecialchars($cl['telefono']) ?></td>
                                    <td><?= htmlspecialchars($cl['email']) ?></td>
                                    <td><?= htmlspecialchars($cl['citta']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($cl['data_creazione'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php 
                    $ids = implode(',', array_column($clienti_export, 'id_cliente')); 
                    // Parametri di reindirizzamento per mantenere il contesto dopo la conferma
                    $export_params = "&data_start=" . urlencode($data_start) . "&data_end=" . urlencode($data_end) . "&search_export=" . urlencode($search_export) . "&ids=" . urlencode($ids); 
                    $redirect_url_value = "clients.php?export_step=preview&data_start=" . urlencode($data_start) . "&data_end=" . urlencode($data_end) . "&search_export=" . urlencode($search_export);
                    ?>
                    
                    <div class="d-flex flex-wrap gap-2">
                        <a href="export_contacts.php?action=export<?= $export_params ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-file-earmark-arrow-down"></i> Esporta CSV
                        </a>
                        <form method="post" action="clients.php">
                            <input type="hidden" name="action" value="confirm_export">
                            <input type="hidden" name="confirmed_ids" value="<?= htmlspecialchars($ids) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url_value) ?>">
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-check2-circle"></i> Conferma importazione Google
                            </button>
                        </form>
                        <a href="clients.php" class="btn btn-outline-secondary btn-sm">Annulla / Torna a Inizio Export</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>


<div class="collapse <?php echo ($error && isset($_POST['action']) && $_POST['action'] === 'quick_add') ? 'show' : ''; ?>" id="quick-add">
    <div class="card card-body mb-4 bg-light shadow-sm">
        <h5 class="card-title text-success"><i class="bi bi-person-plus-fill me-1"></i> Nuovo Cliente Rapido</h5>
        <form method="post" action="clients.php" id="quickAddForm" novalidate>
            <input type="hidden" name="action" value="quick_add">
            <div class="row g-3">
                <div class="col-md-3 col-12">
                    <label for="nome_quick" class="form-label small">Nome *</label>
                    <input type="text" class="form-control form-control-sm" id="nome_quick" name="nome" value="<?php echo htmlspecialchars($nome ?? ''); ?>" required>
                </div>
                <div class="col-md-3 col-12">
                    <label for="cognome_quick" class="form-label small">Cognome *</label>
                    <input type="text" class="form-control form-control-sm" id="cognome_quick" name="cognome" value="<?php echo htmlspecialchars($cognome ?? ''); ?>" required>
                </div>
                <div class="col-md-3 col-12">
                    <label for="telefono_quick" class="form-label small">Telefono</label>
                    <input type="tel" class="form-control form-control-sm" id="telefono_quick" name="telefono" value="<?php echo htmlspecialchars($telefono ?? ''); ?>">
                </div>
                <div class="col-md-3 col-12">
                    <label for="settimane_gravidanza_quick" class="form-label small">Settimane Gravidanza</label>
                    <input type="number" step="1" min="1" max="42" class="form-control form-control-sm" id="settimane_gravidanza_quick" name="settimane_gravidanza" value="<?php echo htmlspecialchars($settimane_gravidanza ?? ''); ?>">
                </div>

                <div class="col-md-4 col-12">
                    <label for="id_tipologia_provenienza_quick" class="form-label small">Provenienza</label>
                    <select class="form-select form-select-sm" id="id_tipologia_provenienza_quick" name="id_tipologia_provenienza">
                        <option value="">-- Nessuna --</option>
                        <?php foreach ($tipologie_provenienza as $tipo): ?>
                            <?php $provenienza_nome = htmlspecialchars(strtoupper($tipo['provenienza'])); ?>
                            <?php $selected_tipo = (isset($_POST['id_tipologia_provenienza']) && (int)$_POST['id_tipologia_provenienza'] == $tipo['id_provenienza']) ? 'selected' : ''; ?>
                            <option value="<?php echo (int)$tipo['id_provenienza']; ?>" data-tipo="<?php echo $provenienza_nome; ?>" <?php echo $selected_tipo; ?>>
                                <?php echo $provenienza_nome; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-8 col-12">
                    <label for="id_associativo_select" class="form-label small">Fonte specifica</label>
                    <div id="sorgente-container-quick">
                        <input type="hidden" id="id_associativo_hidden" name="id_associativo" value="<?php echo htmlspecialchars($_POST['id_associativo'] ?? ''); ?>">

                        <select class="form-select form-select-sm sorgente-select-quick" id="sorgente_CLIENTE_quick" style="display: none;" disabled>
                            <option value="">-- Seleziona un cliente --</option>
                            <?php foreach ($clienti_referenti as $cliente_ref): ?>
                                <option value="<?php echo (int)$cliente_ref['id_cliente']; ?>"><?php echo htmlspecialchars($cliente_ref['nome'] . ' ' . $cliente_ref['cognome']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select class="form-select form-select-sm sorgente-select-quick" id="sorgente_PROMOZIONE_quick" style="display: none;" disabled>
                            <option value="">-- Seleziona una promozione --</option>
                            <?php foreach ($promozioni_disponibili as $promo_ref): ?>
                                <option value="<?php echo (int)$promo_ref['id_promozione']; ?>"><?php echo htmlspecialchars($promo_ref['DESCRIZIONE']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" class="form-control form-control-sm sorgente-select-quick" id="sorgente_ALTRO_quick" style="display: none;" placeholder="Specifica la fonte (max 50 caratteri)" maxlength="50" disabled>

                        <p class="sorgente-select-quick" id="sorgente_NONE_quick" style="display: block;">Seleziona prima una Provenienza.</p>
                    </div>
                </div>

                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-1"></i> Salva Cliente Rapido</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-12 mb-3">
        <form method="get" class="d-flex" action="clients.php">
            <input type="text" name="search" class="form-control me-2" placeholder="Cerca per nome, cognome o città..." value="<?php echo htmlspecialchars($search_term); ?>">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            <?php if (!empty($search_term)): ?>
                <a href="clients.php" class="btn btn-outline-danger ms-2" title="Pulisci ricerca"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <p class="text-muted small">Trovati <?php echo $totClienti; ?> clienti</p>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-5">
    <?php if (empty($clienti)): ?>
        <div class="col-12">
            <p>Nessun cliente trovato.</p>
        </div>
    <?php else: ?>
        <?php foreach ($clienti as $cliente): ?>
            <div class="col">
                <div class="card h-100 shadow-sm border-secondary">
                    <div class="card-body">
                        <h5 class="card-title text-primary mb-1">
                            <?php echo htmlspecialchars($cliente['nome'] . ' ' . $cliente['cognome']); ?>
                            <?php if (!empty($cliente['settimane_gravidanza_contatto'])): ?>
                                <span class="badge bg-danger ms-2" data-bs-toggle="tooltip" title="Gravidanza al momento dell'ultimo contatto: <?php echo $cliente['settimane_gravidanza_contatto']; ?> settimane">
                                    <i class="bi bi-heart-fill me-1"></i> <?php echo $cliente['settimane_gravidanza_contatto']; ?>s
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($cliente['exported_to_google_contacts'])): ?>
                                <span class="badge bg-info ms-2" data-bs-toggle="tooltip" title="Esportato su Google Contatti">
                                    <i class="bi bi-google"></i>
                                </span>
                            <?php endif; ?>
                        </h5>
                        <small class="text-muted d-block mb-3"><i class="bi bi-calendar-date me-1"></i>Creato il <?php echo date('d/m/Y', strtotime($cliente['data_creazione'])); ?></small>
                        <ul class="list-unstyled small">
                            <?php if ($cliente['telefono']): ?>
                                <li><i class="bi bi-phone me-1"></i> <?php echo htmlspecialchars($cliente['telefono']); ?></li>
                            <?php endif; ?>
                            <?php if ($cliente['email']): ?>
                                <li><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($cliente['email']); ?></li>
                            <?php endif; ?>
                            <?php if ($cliente['indirizzo'] || $cliente['citta']): ?>
                                <li><i class="bi bi-geo-alt me-1"></i> <?php echo htmlspecialchars(trim(($cliente['indirizzo'] ?? '') . ' ' . ($cliente['citta'] ?? ''))); ?></li>
                            <?php endif; ?>
                            <?php if ($cliente['provenienza_dettaglio']): ?>
                                <li><i class="bi bi-share me-1"></i> Origine: <?php echo htmlspecialchars($cliente['provenienza_dettaglio']); ?></li>
                            <?php endif; ?>
                            <?php if ($cliente['note']): ?>
                                <li><i class="bi bi-journal-text me-1"></i> Note: <?php echo htmlspecialchars(substr($cliente['note'], 0, 50)) . (strlen($cliente['note']) > 50 ? '...' : ''); ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="card-footer bg-white border-top-0 d-flex flex-wrap gap-2 justify-content-between text-center">
                        <a href="event_form.php?id_cliente=<?php echo $cliente['id_cliente']; ?>" class="btn btn-sm btn-outline-info flex-fill" data-bs-toggle="tooltip" title="Aggiungi Evento">
                            <i class="bi bi-calendar-plus"></i> Nuovo Evento
                        </a>
                        <a href="client_form.php?id=<?php echo $cliente['id_cliente']; ?>" class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="tooltip" title="Modifica Dettagli">
                            <i class="bi bi-pencil-square"></i> Modifica
                        </a>

                        <?php if (empty($cliente['exported_to_google_contacts'])): ?>
                            <form method="post" action="clients.php" class="flex-fill">
                                <input type="hidden" name="action" value="mark_exported">
                                <input type="hidden" name="id_cliente" value="<?php echo $cliente['id_cliente']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success w-100" data-bs-toggle="tooltip" title="Segna come esportato su Google Contatti">
                                    <i class="bi bi-check2-circle"></i> Segna come Importato
                                </button>
                            </form>
                        <?php endif; ?>

                        <button type="button" class="btn btn-sm btn-outline-danger flex-fill btn-delete-cliente" 
                                data-id="<?php echo $cliente['id_cliente']; ?>" 
                                data-nome="<?php echo htmlspecialchars($cliente['nome'] . ' ' . $cliente['cognome']); ?>"
                                data-bs-toggle="tooltip" title="Elimina Cliente">
                            <i class="bi bi-trash"></i> Elimina
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ---------------------------------------------------------------------
    // LOGICA Provenienza (Quick Add)
    // ---------------------------------------------------------------------
    const tipologiaSelect = document.getElementById('id_tipologia_provenienza_quick');
    const sorgenteContainer = document.getElementById('sorgente-container-quick');
    const sorgenteSelects = sorgenteContainer.querySelectorAll('.sorgente-select-quick');
    const hiddenAssociativoInput = document.getElementById('id_associativo_hidden');

    const tipoMap = {
        'CLIENTE': 'sorgente_CLIENTE_quick',
        'PROMOZIONE': 'sorgente_PROMOZIONE_quick',
        'ALTRO': 'sorgente_ALTRO_quick'
    };

    function updateHiddenInput(activeSelect) {
        if (!activeSelect) { hiddenAssociativoInput.value = ''; return; }
        if (activeSelect.disabled) { hiddenAssociativoInput.value = ''; return; }
        if (activeSelect.tagName === 'SELECT') {
            hiddenAssociativoInput.value = activeSelect.value;
        } else if (activeSelect.tagName === 'INPUT') {
            hiddenAssociativoInput.value = activeSelect.value.trim();
        } else {
            hiddenAssociativoInput.value = '';
        }
    }

    function onSorgenteChange(event) {
        updateHiddenInput(event.target);
    }

    function toggleSorgenteSelect(tipologiaId) {
        const activeOption = tipologiaSelect.querySelector(`option[value="${tipologiaId}"]`);
        const tipoNome = activeOption ? activeOption.dataset.tipo : '';

        sorgenteSelects.forEach(select => {
            select.style.display = 'none';
            select.disabled = true;
            select.removeEventListener('change', onSorgenteChange);
            select.removeEventListener('input', onSorgenteChange);
        });

        let activeSelect = null;
        const activeSelectId = tipoMap[tipoNome];

        if (activeSelectId) {
            activeSelect = document.getElementById(activeSelectId);
            // Pre-popola il campo associativo se l'errore ha mantenuto il valore
            if (activeSelect.id === 'sorgente_ALTRO_quick' && hiddenAssociativoInput.value) {
                activeSelect.value = hiddenAssociativoInput.value;
            } else if (activeSelect.tagName === 'SELECT' && hiddenAssociativoInput.value) {
                 // Se è una select, seleziona l'opzione che corrisponde al valore nascosto (se esiste)
                 const optionToSelect = activeSelect.querySelector(`option[value="${hiddenAssociativoInput.value}"]`);
                 if(optionToSelect) {
                    optionToSelect.selected = true;
                 }
            }
        } else {
            activeSelect = document.getElementById('sorgente_NONE_quick');
        }

        if (activeSelect) {
            activeSelect.style.display = 'block';
            if (activeSelect.id !== 'sorgente_NONE_quick') {
                activeSelect.disabled = false;
                if (activeSelect.tagName === 'SELECT') {
                    activeSelect.addEventListener('change', onSorgenteChange);
                } else if (activeSelect.tagName === 'INPUT') {
                    activeSelect.addEventListener('input', onSorgenteChange);
                }
            }
            updateHiddenInput(activeSelect); // Aggiorna per la visualizzazione iniziale
        }
    }

    if (tipologiaSelect) {
        tipologiaSelect.addEventListener('change', (e) => {
            hiddenAssociativoInput.value = '';
            sorgenteSelects.forEach(select => {
                if (select.tagName === 'SELECT') select.selectedIndex = 0;
                else if (select.tagName === 'INPUT') select.value = '';
            });
            toggleSorgenteSelect(e.target.value);
        });
        
        // Esegui all'avvio per gestire i valori precompilati in caso di errore POST
        toggleSorgenteSelect(tipologiaSelect.value);
    }

    // ---------------------------------------------------------------------
    // LOGICA Eliminazione Cliente (pulsante)
    // ---------------------------------------------------------------------
    const deleteButtons = document.querySelectorAll('.btn-delete-cliente');

    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const clienteId = this.dataset.id;
            const clienteNome = this.dataset.nome;

            if (confirm(`Sei sicuro di voler eliminare il cliente: ${clienteNome} (ID: ${clienteId})? \n\nATTENZIONE: Se sono presenti eventi collegati, l'eliminazione non sarà consentita.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'clients.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id_cliente';
                idInput.value = clienteId;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>