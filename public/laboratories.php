<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

// Logica di ELIMINAZIONE
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_laboratorio = (int)$_GET['id'];
    try {
        // La FK in movimentazioni è ON DELETE SET NULL, quindi è sicuro
        $stmt = $pdo->prepare("DELETE FROM laboratori WHERE id_laboratorio = ?");
        $stmt->execute([$id_laboratorio]);
        header('Location: laboratories.php?msg=Laboratorio eliminato con successo!');
        exit;
    } catch (PDOException $e) {
        $error = "Errore durante l'eliminazione: " . $e->getMessage();
    }
}

// Recupero dati
$laboratori = $pdo->query("SELECT * FROM laboratori ORDER BY nome")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h2>Gestione Laboratori</h2>
        <a href="laboratory_form.php" class="btn btn-success"><i class="fas fa-plus"></i> Nuovo Laboratorio</a>
    </div>
</div>

<hr>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Contatti</th>
                <th>Città</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($laboratori as $lab): ?>
                <tr>
                    <td><?php echo htmlspecialchars($lab['nome']); ?></td>
                    <td><?php echo htmlspecialchars($lab['telefono'] . ' / ' . $lab['email']); ?></td>
                    <td><?php echo htmlspecialchars($lab['citta']); ?></td>
                    <td>
                        <a href="laboratory_form.php?id=<?php echo $lab['id_laboratorio']; ?>" class="btn btn-sm btn-primary" title="Modifica"><i class="fas fa-edit"></i></a>
                        <a href="laboratories.php?action=delete&id=<?php echo $lab['id_laboratorio']; ?>" class="btn btn-sm btn-danger" title="Elimina" onclick="return confirm('Sei sicuro di voler eliminare questo laboratorio? I movimenti collegati verranno scollegati.')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>