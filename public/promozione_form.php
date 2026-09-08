<?php
// public/promozione_form.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login(); 

// Assicurati che solo gli amministratori possano creare/modificare promozioni,
// o adatta questa condizione se tutti gli operatori possono farlo.
if (!is_admin()) {
    // Potresti reindirizzare o mostrare un messaggio di errore di autorizzazione
    // Per ora, reindirizziamo alla lista con un messaggio di errore
    header('Location: promozioni.php?error=' . urlencode('Autorizzazione negata.'));
    exit;
}

$id_promozione = (int)($_GET['id'] ?? 0);
$is_edit = $id_promozione > 0;
$promo = [
    'DESCRIZIONE' => '',
    'IMPORTO_SPESO' => '',
    'PORTALE' => '',
    'data_promo' => date('Y-m-d') // Imposta la data di default a oggi per il nuovo inserimento
];

$error = null;
$success_msg = null;

// --- 1. CARICAMENTO DATI (In caso di modifica) ---
if ($is_edit) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM promozioni WHERE id_promozione = ?");
        $stmt->execute([$id_promozione]);
        $fetched_promo = $stmt->fetch();

        if (!$fetched_promo) {
            header('Location: promozioni.php?msg=' . urlencode('Promozione non trovata.'));
            exit;
        }
        $promo = $fetched_promo;
    } catch (PDOException $e) {
        $error = "Errore durante il caricamento dei dati: " . htmlspecialchars($e->getMessage());
    }
}

// --- 2. GESTIONE SUBMIT FORM (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitizzazione e validazione dei dati
    $descrizione = trim($_POST['descrizione'] ?? '');
    $importo_speso = floatval(str_replace(',', '.', trim($_POST['importo_speso'] ?? 0)));
    $portale = trim($_POST['portale'] ?? '');
    $data_promo = trim($_POST['data_promo'] ?? date('Y-m-d'));

    if (empty($descrizione) || $importo_speso <= 0 || empty($portale) || empty($data_promo)) {
        $error = "Tutti i campi obbligatori (Descrizione, Importo, Portale, Data) devono essere compilati correttamente.";
    } elseif (!strtotime($data_promo)) {
        $error = "Formato data non valido.";
    } else {
        try {
            if ($is_edit) {
                // Query di AGGIORNAMENTO
                $sql = "UPDATE promozioni SET DESCRIZIONE = ?, IMPORTO_SPESO = ?, PORTALE = ?, data_promo = ? WHERE id_promozione = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$descrizione, $importo_speso, $portale, $data_promo, $id_promozione]);
                $msg = 'Promozione aggiornata con successo!';
            } else {
                // Query di INSERIMENTO
                $sql = "INSERT INTO promozioni (DESCRIZIONE, IMPORTO_SPESO, PORTALE, data_promo) VALUES (?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$descrizione, $importo_speso, $portale, $data_promo]);
                $id_promozione = $pdo->lastInsertId(); // Ottieni il nuovo ID
                $msg = 'Nuova promozione creata con successo!';
            }

            // Reindirizza alla pagina di lista con messaggio di successo
            header('Location: promozioni.php?msg=' . urlencode($msg));
            exit;
        } catch (PDOException $e) {
            $error = "Errore di salvataggio: " . htmlspecialchars($e->getMessage());
        }
    }
    
    // In caso di errore, ricarica i valori postati nel form per evitare la perdita di dati
    $promo = [
        'DESCRIZIONE' => $descrizione,
        'IMPORTO_SPESO' => $importo_speso,
        'PORTALE' => $portale,
        'data_promo' => $data_promo
    ];
}

require_once __DIR__ . '/../includes/header.php';

// Determina il titolo della pagina
$page_title = $is_edit ? "Modifica Promozione #{$id_promozione}" : "Nuova Promozione";
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">
        <i class="bi bi-tags-fill me-2 text-primary"></i><?php echo htmlspecialchars($page_title); ?>
    </h3>
    <a href="promozioni.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left-circle me-1"></i>Torna all'elenco
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card p-4 shadow-sm">
    <form method="post">
        <div class="row g-3">
            
            <div class="col-12">
                <label for="descrizione" class="form-label">
                    Descrizione <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="descrizione" name="descrizione"
                       value="<?php echo htmlspecialchars($promo['DESCRIZIONE']); ?>" required>
            </div>

            <div class="col-md-6">
                <label for="importo_speso" class="form-label">
                    Importo Speso (€) <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-currency-euro"></i></span>
                    <input type="number" class="form-control" id="importo_speso" name="importo_speso"
                           step="0.01" min="0.01"
                           value="<?php echo htmlspecialchars(number_format($promo['IMPORTO_SPESO'], 2, '.', '')); ?>" required>
                </div>
            </div>

            <div class="col-md-6">
                <label for="data_promo" class="form-label">
                    Data Promozione <span class="text-danger">*</span>
                </label>
                <input type="date" class="form-control" id="data_promo" name="data_promo"
                       value="<?php echo htmlspecialchars($promo['data_promo'] ? date('Y-m-d', strtotime($promo['data_promo'])) : date('Y-m-d')); ?>" required>
            </div>

            <div class="col-12">
                <label for="portale" class="form-label">
                    Portale (Es. Google, Facebook, ecc.) <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="portale" name="portale"
                       value="<?php echo htmlspecialchars($promo['PORTALE']); ?>" required>
            </div>

            <div class="col-12 mt-4 text-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save me-1"></i> <?php echo $is_edit ? 'Salva Modifiche' : 'Crea Promozione'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<style>
/* Stili opzionali per migliorare l'aspetto */
.form-label { font-weight: 500; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>