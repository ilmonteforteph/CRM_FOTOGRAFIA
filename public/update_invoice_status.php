<?php
// public/update_invoice_status.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Accesso non autorizzato o richiesta non valida.'];

// 1. Verifica login e ruolo Amministratore
if (!is_logged_in() || !is_admin()) {
    echo json_encode($response);
    exit;
}

// 2. Verifica che i dati POST siano presenti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_movimento']) && isset($_POST['fattura_caricata'])) {
    
    $id_movimento = (int)$_POST['id_movimento'];
    $fattura_caricata = (int)$_POST['fattura_caricata'] > 0 ? 1 : 0;

    if ($id_movimento > 0) {
        try {
            // 3. Esegue l'aggiornamento nel database
            $stmt = $pdo->prepare("UPDATE movimentazioni SET fattura_caricata = ? WHERE id_movimento = ?");
            $stmt->execute([$fattura_caricata, $id_movimento]);

            if ($stmt->rowCount()) {
                $response['success'] = true;
                $response['message'] = "Movimento #{$id_movimento} aggiornato con successo.";
            } else {
                $response['message'] = "Movimento non trovato o già aggiornato.";
            }

        } catch (PDOException $e) {
            $response['message'] = "Errore DB: " . $e->getMessage();
            error_log("DB Error in update_invoice_status.php: " . $e->getMessage());
        }
    } else {
        $response['message'] = "ID Movimento non valido.";
    }
}

echo json_encode($response);
?>