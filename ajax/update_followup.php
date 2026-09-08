<?php
// File: ajax/update_followup.php

// Vengono inclusi i file di configurazione, assumendo che siano in ../includes/
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Controlli di sicurezza
require_login();
if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accesso non autorizzato.']);
    exit;
}

header('Content-Type: application/json');

// Ricezione dei dati JSON dalla richiesta fetch
$input = json_decode(file_get_contents('php://input'), true);

$id_cliente = $input['id_cliente'] ?? null;
$id_promozione = $input['id_promozione'] ?? null;
$new_state = $input['follow_up_eseguito'] ?? null;
$id_tipologia_provenienza = 2; // Valore fisso per 'PROMOZIONE' (come nel file principale)

// Validazione dei dati
if (!is_numeric($id_cliente) || !is_numeric($id_promozione) || !is_numeric($new_state) || !($new_state == 0 || $new_state == 1)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dati non validi o incompleti.']);
    exit;
}

try {
    // Query per aggiornare la riga specifica in provenienza_cliente
    // La combinazione id_cliente, id_associativo e id_tipologia_provenienza rende l'aggiornamento preciso.
    $sql = "UPDATE provenienza_cliente 
            SET follow_up_eseguito = :new_state 
            WHERE id_cliente = :id_cliente 
            AND id_associativo = :id_promozione
            AND id_tipologia_provenienza = :id_type";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'new_state' => $new_state,
        'id_cliente' => $id_cliente,
        'id_promozione' => $id_promozione,
        'id_type' => $id_tipologia_provenienza
    ]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Stato follow-up aggiornato con successo.']);
    } else {
        // Nessun record aggiornato (forse ID non corretti)
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Nessun record trovato da aggiornare per la coppia Cliente/Promozione.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("DB Error in update_followup: " . $e->getMessage()); 
    echo json_encode(['success' => false, 'message' => 'Errore interno del server durante l\'aggiornamento.']);
}
?>