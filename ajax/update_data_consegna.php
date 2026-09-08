<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

header('Content-Type: application/json');

if (!isset($_POST['id'], $_POST['data'])) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$id = (int) $_POST['id'];
$data = trim($_POST['data']);

// Se la data è vuota, la imposta a NULL
if ($data === '') {
    $sql = "UPDATE eventi SET data_consegna = NULL WHERE id_evento = ?";
    $params = [$id];
} else {
    // Controllo formato data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        echo json_encode(['success' => false, 'error' => 'Formato data non valido']);
        exit;
    }
    $sql = "UPDATE eventi SET data_consegna = ? WHERE id_evento = ?";
    $params = [$data, $id];
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
