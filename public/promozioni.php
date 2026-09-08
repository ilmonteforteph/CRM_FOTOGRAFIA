<?php
// public/promozioni.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// La gestione delle promozioni è di solito un'attività amministrativa o comunque riservata
// Assumiamo che solo gli operatori (o gli admin) possano accedere
require_login(); 

require_once __DIR__ . '/../includes/header.php';

// Variabili di ricerca dal form
$search_desc = trim($_GET['search_desc'] ?? '');
$search_portale = trim($_GET['search_portale'] ?? '');
$search_date = trim($_GET['search_date'] ?? '');
$error = null;
$success_msg = $_GET['msg'] ?? null;

// --- Eliminazione ---
// Permessa solo agli amministratori
if (isset($_GET['delete']) && is_admin()) {
    $id = (int)$_GET['delete'];
    try {
        // La tabella è stata rinominata in 'promozioni' (tutto minuscolo)
        $pdo->prepare("DELETE FROM promozioni WHERE id_promozione = ?")->execute([$id]); 
        header('Location: promozioni.php?msg=' . urlencode('Promozione eliminata con successo!'));
        exit;
    } catch (PDOException $e) {
        $error = "Errore durante l'eliminazione: " . htmlspecialchars($e->getMessage());
    }
}

// --- Costruzione Query ---
$sql = "SELECT * FROM promozioni";

$params = [];
$where = [];

// Filtro per Descrizione o Portale
if ($search_desc) {
    // Cerco nella DESCRIZIONE e/o nel PORTALE
    $where[] = "(DESCRIZIONE LIKE ? OR PORTALE LIKE ?)";
    $like = '%' . $search_desc . '%';
    $params = array_merge($params, [$like, $like]);
}

// Filtro per Portale (se specificato in un campo separato, altrimenti usa $search_desc)
if ($search_portale) {
    // Aggiungo la possibilità di cercare il portale specificatamente, se serve.
    // Se non serve, si può omettere questo blocco, dato che $search_desc lo copre.
    $where[] = "PORTALE LIKE ?";
    $params[] = '%' . $search_portale . '%';
}

// Filtro per Data
if ($search_date) {
    $where[] = "data_promo = ?";
    $params[] = $search_date;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
// Ordina per data più recente
$sql .= " ORDER BY data_promo DESC, id_promozione DESC"; 

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $promozioni = $stmt->fetchAll();
    $tot = count($promozioni);
} catch (PDOException $e) {
    $error = "Errore durante il caricamento delle promozioni: " . htmlspecialchars($e->getMessage());
    $promozioni = [];
    $tot = 0;
}

// Se la colonna PORTALE ha valori ripetuti, si potrebbe anche creare una query per popolare un <select>
// $portali = $pdo->query("SELECT DISTINCT PORTALE FROM promozioni WHERE PORTALE IS NOT NULL ORDER BY PORTALE")->fetchAll(PDO::FETCH_COLUMN);
// Adesso usiamo un campo di testo semplice nel filtro

?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h3 class="mb-2 mb-md-0">
        <i class="bi bi-tags-fill me-2 text-primary"></i>Promozioni
        <span class="badge bg-secondary"><?php echo $tot; ?></span>
    </h3>
    <a href="promozione_form.php" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> Nuova
    </a>
</div>

<?php if ($success_msg): ?>
    <div class="alert alert-success d-flex align-items-center">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card p-3 shadow-sm mb-4 bg-light border-0">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-4 col-lg-4">
            <label for="search_desc" class="form-label small text-muted">
                <i class="bi bi-search me-1"></i>Descrizione/Portale
            </label>
            <input type="text" class="form-control" id="search_desc" name="search_desc"
                   placeholder="Cerca in descrizione o portale..." value="<?php echo htmlspecialchars($search_desc); ?>">
        </div>

        <div class="col-md-3 col-lg-3">
            <label for="search_date" class="form-label small text-muted">
                <i class="bi bi-calendar-date me-1"></i>Data
            </label>
            <input type="date" class="form-control" id="search_date" name="search_date"
                   value="<?php echo htmlspecialchars($search_date); ?>">
        </div>
        
        <div class="col-md-5 col-lg-5 d-flex">
            <button type="submit" class="btn btn-primary me-2 flex-grow-1" title="Cerca">
                <i class="bi bi-search"></i> Filtra
            </button>
            <a href="promozioni.php" class="btn btn-outline-secondary flex-grow-1" title="Resetta">
                <i class="bi bi-arrow-repeat"></i> Resetta
            </a>
        </div>
    </form>
</div>

<?php if (empty($promozioni)): ?>
    <div class="alert alert-warning text-center">
        <i class="bi bi-info-circle-fill me-2"></i>Nessuna promozione trovata.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle shadow-sm rounded-3 overflow-hidden" style="border-collapse:separate; border-spacing:0;">
            <thead class="table-primary">
                <tr>
                    <th class="text-center">#</th>
                    <th><i class="bi bi-calendar3"></i> Data</th>
                    <th><i class="bi bi-card-text"></i> Descrizione</th>
                    <th class="text-end"><i class="bi bi-currency-euro"></i> Importo</th>
                    <th><i class="bi bi-globe"></i> Portale</th>
                    <th class="text-center"><i class="bi bi-tools"></i> Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($promozioni as $p): ?>
                    <tr>
                        <td class="text-center"><?php echo $p['id_promozione']; ?></td>
                        <td><?php echo $p['data_promo'] ? date('d/m/Y', strtotime($p['data_promo'])) : '-'; ?></td>
                        <td><?php echo htmlspecialchars($p['DESCRIZIONE'] ?? '-'); ?></td>
                        <td class="text-end"><?php echo number_format($p['IMPORTO_SPESO'] ?? 0, 2, ',', '.') . ' €'; ?></td> 
                        <td><?php echo htmlspecialchars($p['PORTALE'] ?? '-'); ?></td>
                        <td class="text-center">
                            <a href="promozione_form.php?id=<?php echo $p['id_promozione']; ?>"
                               class="btn btn-sm btn-outline-primary me-1" title="Vedi / Modifica">
                                <i class="bi bi-pencil-fill"></i>
                            </a>
                            <?php if (is_admin()): ?>
                                <a href="promozioni.php?delete=<?php echo $p['id_promozione']; ?>"
                                   onclick="return confirm('Confermi eliminazione promozione <?php echo $p['id_promozione']; ?>?')"
                                   class="btn btn-sm btn-outline-danger" title="Elimina">
                                   <i class="bi bi-trash-fill"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
.table th, .table td { vertical-align: middle; }
.btn-sm i { vertical-align: -1px; }
.badge { font-size: 0.85em; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>