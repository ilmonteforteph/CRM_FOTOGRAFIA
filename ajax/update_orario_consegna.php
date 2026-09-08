<?php
// ajax/update_orario_consegna.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['orario'])) {
    echo json_encode(['success' => false, 'error' => 'Richiesta non valida.']);
    exit;
}

$id = (int)$_POST['id'];
$orario = trim($_POST['orario']);

// L'orario potrebbe essere vuoto se l'utente lo cancella
if (empty($orario)) {
    $orario_value = null;
} else {
    // Sanificazione/Validazione orario (es. assicurati che sia H:i:s o H:i)
    $orario_value = $orario . ':00'; // Aggiungi secondi per formato TIME standard
}

try {
    $sql = "UPDATE eventi SET orario_consegna= ? WHERE id_evento = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orario_value, $id]);

    if ($stmt->rowCount() > 0 || $orario_value === null) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nessun evento aggiornato.']);
    }

} catch (PDOException $e) {
    // Logga l'errore per debugging
    // error_log("Errore DB update orario: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore nel salvataggio.']);
}
?>