<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Assicura che l'utente sia loggato
require_login();
$isAdmin = is_admin();

// Reindirizza se l'utente non è un amministratore
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

// Logica di ELIMINAZIONE (solo per admin)
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_operatore = (int)$_GET['id'];
    try {
        // L'eliminazione in cascata è impostata sulle foreign keys in users e eventi
        $stmt = $pdo->prepare("DELETE FROM operatori WHERE id_operatore = ?");
        $stmt->execute([$id_operatore]);
        header('Location: operators.php?msg=' . urlencode('Operatore eliminato con successo!'));
        exit;
    } catch (PDOException $e) {
        // Aggiungo un messaggio di errore più descrittivo per l'utente
        $error = "Errore durante l'eliminazione. Assicurati che non ci siano riferimenti vincolanti non gestiti: " . htmlspecialchars($e->getMessage());
    }
}

// Recupero dati - Join con la tabella users per vedere se l'operatore ha un account
$stmt = $pdo->query("SELECT o.*, u.username, u.ruolo, u.id_user FROM operatori o LEFT JOIN users u ON o.id_operatore = u.id_operatore ORDER BY o.cognome, o.nome");
$operatori = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h3 class="mb-2 mb-md-0">
        <i class="bi bi-people-fill me-2 text-primary"></i>Gestione Operatori
    </h3>
    <a href="operator_form.php" class="btn btn-primary shadow-sm">
        <i class="bi bi-person-plus-fill me-1"></i> Nuovo Operatore
    </a>
</div>

<hr>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success d-flex align-items-center" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped table-hover align-middle rounded-3 overflow-hidden" style="border-collapse:separate; border-spacing:0;">
        <thead class="table-primary shadow-sm">
            <tr>
                <th><i class="bi bi-person-badge-fill me-1"></i> Nome Completo</th>
                <th><i class="bi bi-briefcase-fill me-1"></i> Ruolo Aziendale</th>
                <th class="text-center"><i class="bi bi-key-fill me-1"></i> Account App</th>
                <th class="text-center"><i class="bi bi-shield-fill me-1"></i> Ruolo App</th>
                <th class="text-center"><i class="bi bi-tools me-1"></i> Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($operatori)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="bi bi-info-circle me-1"></i>Nessun operatore registrato.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($operatori as $op): 
                    $account_active = $op['username'] !== null;
                    $badge_class = $account_active ? 'bg-success' : 'bg-warning text-dark';
                    $badge_text = $account_active ? 'Attivo' : 'Manca';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($op['cognome'] . ' ' . $op['nome']); ?></td>
                        <td><?php echo htmlspecialchars($op['ruolo_aziendale'] ?? '-'); ?></td>
                        <td class="text-center">
                            <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                            <?php if ($account_active): ?>
                                <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($op['username']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php 
                                if ($op['ruolo']) {
                                    $role_class = $op['ruolo'] == 'admin' ? 'bg-danger' : 'bg-info text-dark';
                                    echo '<span class="badge ' . $role_class . '">' . htmlspecialchars(ucfirst($op['ruolo'])) . '</span>';
                                } else {
                                    echo '-';
                                }
                            ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="operator_form.php?id=<?php echo $op['id_operatore']; ?>" class="btn btn-sm btn-outline-primary" title="Modifica">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <a href="operators.php?action=delete&id=<?php echo $op['id_operatore']; ?>" 
                                class="btn btn-sm btn-outline-danger ms-1" 
                                title="Elimina" 
                                onclick="return confirm('Sei sicuro di voler eliminare l\'operatore <?php echo addslashes($op['nome'] . ' ' . $op['cognome']); ?>? Verranno rimossi anche l\'eventuale account e gli eventi a lui assegnati diventeranno orfani (se non diversamente gestito dal DB).')">
                                <i class="bi bi-trash-fill"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
/* Stile per centrare verticalmente il contenuto delle celle e allineare le icone */
.table th, .table td { 
    vertical-align: middle; 
}
.btn-sm i { 
    vertical-align: -1px; 
}
/* Rende i badge più leggibili e con un po' di padding */
.badge {
    padding: 0.4em 0.7em;
    font-size: 0.85em;
    font-weight: 600;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>