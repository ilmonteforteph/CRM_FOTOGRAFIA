<?php
// public/export_contacts.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: clients.php?error=' . urlencode('Accesso negato. Solo amministratori.'));
    exit;
}

// Verifica parametri GET
if (!isset($_GET['action']) || $_GET['action'] !== 'export') {
    header('Location: clients.php?error=' . urlencode('Azione non valida.'));
    exit;
}

$data_start = trim($_GET['data_start'] ?? '');
$data_end   = trim($_GET['data_end']   ?? '');
// NUOVO: Recupera il termine di ricerca per nominativo
$search_export = trim($_GET['search_export'] ?? '');

// --- 1. Validazione dei filtri ---
// Se non ci sono né date né nominativo, reindirizza con errore.
if (empty($data_start) && empty($data_end) && empty($search_export)) {
    header('Location: clients.php?error=' . urlencode('Devi specificare un intervallo di date o un termine di ricerca per il nominativo.'));
    exit;
}

// Se c'è una sola data, reindirizza per coerenza. (La Data Fine è gestita da clients.php)
if ((!empty($data_start) && empty($data_end)) || (empty($data_start) && !empty($data_end))) {
    header('Location: clients.php?error=' . urlencode('Per filtrare per data, devi specificare sia la Data Inizio che la Data Fine.'));
    exit;
}

// Se Data Fine è presente, la usiamo.
if (!empty($data_end) && empty($data_start)) {
    // Questo caso è gestito dalla validazione qui sopra, ma per sicurezza.
    $data_end = date('Y-m-d');
}

try {
    // --- 2. Costruzione della Query SQL Dinamica ---
    $sql = "SELECT id_cliente, nome, cognome, telefono, email, citta, note
            FROM clienti
            WHERE exported_to_google_contacts = 0";
    
    $params = [];
    $conditions = [];

    // Condizione: Intervallo di date (se entrambe presenti)
    if (!empty($data_start) && !empty($data_end)) {
        // Usa la logica DATE_ADD per includere l'ultimo giorno completo.
        $conditions[] = "data_creazione >= ?";
        $params[] = $data_start;
        
        // Aggiungo un giorno a data_end per includere le ore 23:59:59 di quel giorno
        $conditions[] = "data_creazione < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $data_end;
    }
    
    // Condizione: Ricerca per nominativo/città
    if (!empty($search_export)) {
        $conditions[] = "(nome LIKE ? OR cognome LIKE ? OR citta LIKE ?)";
        $like_param = '%' . $search_export . '%';
        $params[] = $like_param;
        $params[] = $like_param;
        $params[] = $like_param;
    }

    // Unisce tutte le condizioni aggiuntive alla query WHERE
    if (!empty($conditions)) {
        $sql .= " AND " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY cognome, nome";


    // --- 3. Esecuzione della Query ---
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params); 
    $clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gestione del caso in cui non vengono trovati clienti con i filtri
    if (empty($clienti)) {
        // Reindirizza alla pagina principale con i dati di export per mantenere il pannello aperto e i filtri
        $redirect_params = [
            'msg' => "Nessun nuovo contatto da esportare con i filtri selezionati.",
            'export_step' => 'preview',
            'data_start' => $data_start,
            'data_end' => $data_end,
            'search_export' => $search_export
        ];
        header('Location: clients.php?' . http_build_query($redirect_params));
        exit;
    }

    $client_ids = array_column($clienti, 'id_cliente');

    // --- 4. Intestazioni CSV Google Contacts ---
    $header = [
        'First Name', 'Middle Name', 'Last Name',
        'Phonetic First Name', 'Phonetic Middle Name', 'Phonetic Last Name',
        'Name Prefix', 'Name Suffix', 'Nickname', 'File As',
        'Organization Name', 'Organization Title', 'Organization Department',
        'Birthday', 'Notes', 'Photo', 'Labels',
        'Phone 1 - Label', 'Phone 1 - Value'
    ];

    // --- 5. Imposta intestazioni HTTP per download ---
    $filename = 'contatti_google_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Scrive la riga intestazione
    fputcsv($output, $header);

    // --- 6. Scrive i contatti ---
    foreach ($clienti as $row) {
        $firstName  = trim($row['nome']);
        $lastName   = trim($row['cognome']);
        $notes      = 'ID Cliente: ' . $row['id_cliente'];
        if (!empty($row['note'])) {
            $notes .= ' - ' . str_replace(["\r", "\n"], ' ', $row['note']);
        }

        // Telefono formattato
        $telefono = trim($row['telefono']);
        // Usa substr per compatibilità PHP < 8.0
        if ($telefono && substr($telefono, 0, 1) !== '+') {
            $telefono = '+39 ' . $telefono; // Prefisso italiano se mancante
        }

        // Nome visualizzato
        $displayName = $firstName . ' ' . $lastName;

        $record = [
            $firstName,                      // First Name
            '',                              // Middle Name
            $lastName,                       // Last Name
            '', '', '',                      // Phonetic names
            '', '', '',                      // Prefix, Suffix, Nickname
            $displayName,                    // File As
            '', '', '',                      // Organization info
            '',                              // Birthday
            $notes,                          // Notes
            '',                              // Photo
            '* myContacts',                  // Labels
            'Mobile',                        // Phone 1 - Label
            $telefono                        // Phone 1 - Value
        ];

        fputcsv($output, $record);
    }

    fclose($output);
    
    // Non reindirizzare qui. Il download è già stato avviato.
    exit;

} catch (PDOException $e) {
    // Reindirizzamento in caso di errore DB nella NUOVA scheda
    header('Location: clients.php?error=' . urlencode("Errore SQL durante l'esportazione: " . $e->getMessage()));
    exit;
}
?>