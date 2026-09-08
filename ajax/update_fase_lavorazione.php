<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

header('Content-Type: application/json');

if (!isset($_POST['id']) || !isset($_POST['fase'])) {
    echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
    exit;
}

$id = (int)$_POST['id'];
$fase = trim($_POST['fase']);

try {
    $stmt = $pdo->prepare("UPDATE eventi SET stato_lavorazione_fase = ? WHERE id_evento = ?");
    $stmt->execute([$fase, $id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
