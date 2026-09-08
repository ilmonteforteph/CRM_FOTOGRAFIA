<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$lab = [
    'id_laboratorio' => null,
    'nome' => '',
    'telefono' => '',
    'email' => '',
    'indirizzo' => '',
    'citta' => '',
    'note' => ''
];
$is_edit = false;
$error = '';
$success = '';

// 1. CARICAMENTO DATI ESISTENTI
if (isset($_GET['id'])) {
    $is_edit = true;
    $id_laboratorio = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM laboratori WHERE id_laboratorio = ?");
    $stmt->execute([$id_laboratorio]);
    $data = $stmt->fetch();

    if ($data) {
        $lab = $data;
    } else {
        $error = "Laboratorio non trovato.";
        $is_edit = false;
    }
}

// 2. GESTIONE SUBMIT DEL FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lab['nome'] = trim($_POST['nome']);
    $lab['telefono'] = trim($_POST['telefono']);
    $lab['email'] = trim($_POST['email']);
    $lab['indirizzo'] = trim($_POST['indirizzo']);
    $lab['citta'] = trim($_POST['citta']);
    $lab['note'] = trim($_POST['note']);
    $lab['id_laboratorio'] = (int)$_POST['id_laboratorio'] ?? null;

    if (empty($lab['nome'])) {
        $error = "Il campo Nome è obbligatorio.";
    } else {
        if ($is_edit && $lab['id_laboratorio']) {
            // Modifica
            $stmt = $pdo->prepare("UPDATE laboratori SET nome=?, telefono=?, email=?, indirizzo=?, citta=?, note=? WHERE id_laboratorio=?");
            $stmt->execute(array_values($lab) + [$lab['id_laboratorio']]);
            $success = "Laboratorio aggiornato con successo!";
        } else {
            // Creazione
            $stmt = $pdo->prepare("INSERT INTO laboratori (nome, telefono, email, indirizzo, citta, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(array_values($lab));
            $lab['id_laboratorio'] = $pdo->lastInsertId();
            $is_edit = true;
            $success = "Laboratorio creato con successo!";
        }
        
        // Reindirizza
        if (!$error) {
            header("Location: laboratory_form.php?id=" . $lab['id_laboratorio'] . "&msg=" . urlencode($success));
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <h2><?php echo $is_edit ? 'Modifica Laboratorio' : 'Nuovo Laboratorio'; ?></h2>
    </div>
</div>

<hr>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="laboratory_form.php<?php echo $is_edit ? '?id=' . $lab['id_laboratorio'] : ''; ?>">
    <input type="hidden" name="id_laboratorio" value="<?php echo htmlspecialchars($lab['id_laboratorio'] ?? ''); ?>">

    <div class="mb-3">
        <label for="nome" class="form-label">Nome *</label>
        <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($lab['nome'] ?? ''); ?>" required>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salva Laboratorio</button>
    <a href="laboratories.php" class="btn btn-secondary">Torna alla Lista</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>