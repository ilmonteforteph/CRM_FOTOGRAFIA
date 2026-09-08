<?php
// public/ajax/fetch_events.php

// Inclusione delle configurazioni di base e del database
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
// L'autenticazione è cruciale per la sicurezza
require_once __DIR__ . '/../../includes/auth.php';

// Assicurati che l'utente sia loggato (anche se la richiesta è AJAX)
require_login(); 

// Imposta l'header per la risposta JSON
header('Content-Type: application/json');

// --- 1. Recupero del termine di ricerca ---
$search_term = $_GET['q'] ?? '';
$search_term = trim($search_term);

// Se il termine di ricerca è troppo breve o vuoto, restituisci un array vuoto
if (strlen($search_term) < 2) {
    echo json_encode(['items' => []]);
    exit;
}

// Prepara il termine di ricerca per la clausola LIKE (aggiungendo i caratteri jolly)
$search_param = '%' . $search_term . '%';

// --- 2. Query al Database ---

// Query per cercare eventi per ID, nome/cognome cliente, o nome evento.
$sql = "
    SELECT 
        e.id_evento, 
        e.data_evento, 
        e.costo_totale, 
        c.nome AS cliente_nome, 
        c.cognome AS cliente_cognome, 
        a.nome_evento AS evento_descrizione
    FROM 
        eventi e
    LEFT JOIN 
        clienti c ON e.id_cliente = c.id_cliente
    LEFT JOIN 
        anagrafica_eventi a ON e.id_anagrafica_evento = a.id_anagrafica_evento
    WHERE 
        e.id_evento LIKE ? OR 
        c.nome LIKE ? OR 
        c.cognome LIKE ? OR
        a.nome_evento LIKE ?
    ORDER BY 
        e.data_evento DESC
    LIMIT 30 -- Limita i risultati per performance
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $search_param, 
        $search_param, 
        $search_param, 
        $search_param
    ]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. Formattazione per Select2 ---
    $events_formatted = [];
    foreach ($results as $event) {
        // Crea la stringa di visualizzazione completa (text)
        $event_text = sprintf(
            "#%d - %s %s | %s | Data: %s | €%s",
            $event['id_evento'],
            htmlspecialchars($event['cliente_cognome']),
            htmlspecialchars($event['cliente_nome']),
            htmlspecialchars($event['evento_descrizione']),
            date('d/m/Y', strtotime($event['data_evento'])),
            number_format($event['costo_totale'], 2, ',', '.')
        );

        $events_formatted[] = [
            'id' => (int)$event['id_evento'],
            'text' => $event_text
        ];
    }

    // --- 4. Risposta JSON finale ---
    // Select2 si aspetta la chiave 'items' (o 'results' a seconda della versione e configurazione, qui usiamo 'items')
    echo json_encode([
        'items' => $events_formatted,
        // Puoi omettere total_count se non stai gestendo la paginazione
        'total_count' => count($events_formatted) 
    ]);

} catch (PDOException $e) {
    // Gestione dell'errore (solo per debug, in produzione potresti non voler esporre il messaggio)
    error_log("AJAX Error during event search: " . $e->getMessage());
    echo json_encode([
        'items' => [],
        'error' => 'Database error.'
    ]);
}
?>