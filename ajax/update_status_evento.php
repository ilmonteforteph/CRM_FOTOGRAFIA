<?php
// ajax/update_status_evento.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Accesso non autorizzato.']);
    exit;
}

$id_evento = (int)($_POST['id'] ?? 0);
$new_status_id = (int)($_POST['new_status_id'] ?? 0);

if ($id_evento <= 0 || $new_status_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID evento o ID stato non validi.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE eventi SET id_stato = ? WHERE id_evento = ?");
    $success = $stmt->execute([$new_status_id, $id_evento]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nessuna riga modificata o stato già impostato.']);
    }

} catch (PDOException $e) {
    error_log("Errore DB in update_status_evento.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore nel database.']);
}
?>