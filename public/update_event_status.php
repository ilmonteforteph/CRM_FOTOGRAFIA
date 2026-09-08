<?php
// Assumi che questi percorsi siano corretti per l'inclusione dei file di configurazione
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Errore generico.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_evento']) && isset($_POST['id_stato'])) {
    $id_evento = (int)$_POST['id_evento'];
    $id_stato = (int)$_POST['id_stato'];

    // In questo caso, forziamo e verifichiamo che si voglia aggiornare a ID 7 (DA LAVORARE)
    if ($id_stato !== 7) {
        $response['message'] = 'Tentativo di impostare uno stato non valido per questa azione.';
        echo json_encode($response);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE eventi SET id_stato = ? WHERE id_evento = ? AND id_stato = 1");
        $stmt->execute([$id_stato, $id_evento]);

        if ($stmt->rowCount() > 0) {
            $response['success'] = true;
            $response['message'] = "Stato evento #{$id_evento} aggiornato a 'DA LAVORARE' (ID 7) con successo.";
        } else {
            $response['message'] = 'Nessun evento trovato o evento non in stato NUOVO EVENTO (ID 1).';
        }

    } catch (PDOException $e) {
        // Gestione degli errori di database
        $response['message'] = 'Errore di database: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Dati non validi forniti.';
}

echo json_encode($response);
?>