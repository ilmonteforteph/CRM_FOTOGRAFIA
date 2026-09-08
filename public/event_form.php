<?php
// event_form.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendar_link_generator.php';
require_once __DIR__ . '/../includes/header.php';

require_login();

// --- LOGICA PRELIMINARE ---
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$err = null;
$preselected_id_cliente = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
$success_msg = $_GET['msg'] ?? null; // Aggiunto per messaggi di successo da redirect

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Caricamento dati anagrafici
$clients = $pdo->query("SELECT id_cliente, nome, cognome FROM clienti ORDER BY nome")->fetchAll();
$anag = $pdo->query("SELECT id_anagrafica_evento, nome_evento FROM anagrafica_eventi")->fetchAll();
$stati = $pdo->query("SELECT id_stato, nome_stato FROM stati_evento")->fetchAll();
$ops = $pdo->query("SELECT id_operatore, nome, cognome FROM operatori ORDER BY cognome, nome")->fetchAll();
$laboratori = $pdo->query("SELECT id_laboratorio, nome FROM laboratori ORDER BY nome")->fetchAll();
$anagrafica_servizi = $pdo->query("SELECT id_anagrafica_servizio, nome_servizio, prezzo_base, descrizione_standard FROM anagrafica_servizi WHERE is_attivo = 1 ORDER BY nome_servizio")->fetchAll();

// NUOVE ANAGRAFICHE ALBUM
$formati_album = $pdo->query("SELECT * FROM anagrafica_formati_album ORDER BY nome_formato")->fetchAll();
$tipologie_album = $pdo->query("SELECT * FROM anagrafica_tipologia_album ORDER BY nome_tipologia")->fetchAll();

// ? AGGIUNTO: CARICAMENTO DATI PER PROVENIENZA (Necessario per il Quick Add Form)
$tipologie_provenienza = [];
$clienti_referenti = [];
$promozioni_disponibili = [];

try {
    // 1. Carica le tipologie (CLIENTE, PROMOZIONE, ecc.)
    $tipologie_provenienza = $pdo->query("SELECT id_provenienza, provenienza FROM tipologia_provenienza ORDER BY provenienza")->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Carica i clienti per il referrer
    $clienti_referenti = $pdo->query("SELECT id_cliente, nome, cognome FROM clienti ORDER BY cognome, nome")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Carica le promozioni per il referrer
    $promozioni_disponibili = $pdo->query("SELECT id_promozione, DESCRIZIONE FROM promozioni ORDER BY data_promo DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Errore silenzioso, la mancanza di questi dati non impedisce il salvataggio dell'evento
}
// FINE AGGIUNTA CARICAMENTO DATI

// Definisci i tipi di eventi che richiedono la gestione location e check specifici (ID dalla tabella anagrafica_eventi)
// ID 4: Battesimo, ID 6: Comunione, ID 8: 18° Compleanno
$eventi_con_check_e_location = [4, 6, 8]; 

// Array per memorizzare i dettagli di location salvati, indicizzati per nome_location
$locations_map = []; 
$servizi_salvati = [];

// Calcola gli ID degli stati necessari
$stato_stampato_id = null;
$default_stato_id = null;
$stato_album_ritirare_id = null;
foreach ($stati as $s) {
    if (strtolower($s['nome_stato']) === 'stampato') {
        $stato_stampato_id = (int)$s['id_stato'];
    }
    if (strtolower($s['nome_stato']) === 'nuovo evento') {
        $default_stato_id = (int)$s['id_stato'];
    }
	// ? NUOVA LOGICA PER ALBUM DA RITIRARE
    if (strtolower($s['nome_stato']) === 'album da ritirare') {
        $stato_album_ritirare_id = (int)$s['id_stato'];
    }
}
$default_id_operatore = null;
foreach ($ops as $op) {
    if (strtolower($op['nome']) === 'tommaso') {
        $default_id_operatore = (int)$op['id_operatore'];
        break;
    }
}


$event = null;
if ($id) {
    // 1. Carica Evento principale (incluse le nuove colonne, incluso stato_lavorazione_fase, check_poster, quantita_poster, check_segnaposti, quantita_segnaposti)
    $stmt = $pdo->prepare("SELECT * FROM eventi WHERE id_evento = ? LIMIT 1");
    $stmt->execute([$id]);
    $event = $stmt->fetch();
    
    if (!$event) {
        echo '<div class="alert alert-danger">Evento non trovato</div>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
    
    $preselected_id_cliente = (int)$event['id_cliente'];
    
    // 2. Carica i dettagli dei servizi aggiuntivi
    $stmt_serv = $pdo->prepare("SELECT id_servizio_evento, id_evento, id_anagrafica_servizio, quantita, prezzo_unitario, descrizione_override FROM servizi_aggiuntivi_evento WHERE id_evento = ? ORDER BY id_servizio_evento");
    $stmt_serv->execute([$id]);
    foreach($stmt_serv->fetchAll() as $index => $servizio) {
         $servizi_salvati[$index] = $servizio;
    }

    // 3. Carica i dettagli delle location e indicizzali per nome_location
    // ! MODIFICA LOGICA LOCATION: L'Anteprima (ID 'Anteprima') viene caricata SEMPRE, le altre solo se l'evento è un Battesimo/Comunione/18°
    // Definiamo i nomi delle location che richiedono check e location specifica
    $location_names_to_load = ['Anteprima'];
    if (in_array($event['id_anagrafica_evento'], $eventi_con_check_e_location)) {
        $location_names_to_load = array_merge($location_names_to_load, ['Casa', 'Chiesa', 'Locale']);
    }

    // Costruisci la stringa dei placeholder per la query IN
    $placeholders = implode(',', array_fill(0, count($location_names_to_load), '?'));
    
    $stmt_loc = $pdo->prepare("SELECT * FROM dettagli_location_evento WHERE id_evento = ? AND nome_location IN ({$placeholders})");
    $stmt_loc->execute(array_merge([$id], $location_names_to_load));
    
    foreach($stmt_loc->fetchAll() as $loc) {
        // Assicurati che l'indice sia il nome per facilitare la corrispondenza con i check
        $locations_map[$loc['nome_location']] = $loc; 
    }
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $err = 'Richiesta non valida (CSRF).';
    } else {
        // Estrazione dati comuni
        $id_stato = !empty($_POST['id_stato']) ? (int)$_POST['id_stato'] : ($event['id_stato'] ?? ($default_stato_id ?? 1));
        $note = $_POST['descrizione_lavorazione'] ?? '';
        $stato_lavorazione_fase = $_POST['stato_lavorazione_fase'] ?? '';
        
        if (is_admin()) {
            $id_cliente = (int)$_POST['id_cliente'];
            $id_anag = (int)$_POST['id_anagrafica_evento'];
            $id_oper = (!empty($_POST['id_operatore']) ? (int)$_POST['id_operatore'] : null);
            $data_evento = !empty($_POST['data_evento']) ? $_POST['data_evento'] : null;
            // ??? Modifica 1/4: Estrazione nuovo campo orario_evento
            $orario_evento = !empty($_POST['orario_evento']) ? $_POST['orario_evento'] : null;
			$orario_consegna = !empty($_POST['orario_consegna']) ? $_POST['orario_consegna'] : null;
            $costo = (float)($_POST['costo_totale'] ?? 0);
            
            // Dati di stampa
            $id_laboratorio = ($id_stato == $stato_stampato_id && !empty($_POST['id_laboratorio'])) ? (int)$_POST['id_laboratorio'] : null;
            $data_stampa = ($id_stato == $stato_stampato_id && !empty($_POST['data_stampa'])) ? $_POST['data_stampa'] : null;
			
			$data_consegna = ($id_stato == $stato_album_ritirare_id && !empty($_POST['data_consegna'])) ? $_POST['data_consegna'] : null;
			

            // NUOVI CAMPI CHECK
            $check_casa = isset($_POST['check_casa']) ? 1 : 0;
            $check_chiesa = isset($_POST['check_chiesa']) ? 1 : 0;
            $check_location = isset($_POST['check_location']) ? 1 : 0;
            $check_album = isset($_POST['check_album']) ? 1 : 0;
            
            // Dati Album (solo se check_album è SI)
            $id_formato_album = ($check_album && !empty($_POST['id_formato_album'])) ? (int)$_POST['id_formato_album'] : null;
            $id_tipologia_album = ($check_album && !empty($_POST['id_tipologia_album'])) ? (int)$_POST['id_tipologia_album'] : null;
            
            // NUOVI CHECK POSTER E SEGNAPOSTI
            $check_poster = isset($_POST['check_poster']) ? 1 : 0;
            $quantita_poster = ($check_poster && !empty($_POST['quantita_poster'])) ? (int)$_POST['quantita_poster'] : null;
            
            $check_segnaposti = isset($_POST['check_segnaposti']) ? 1 : 0;
            $quantita_segnaposti = ($check_segnaposti && !empty($_POST['quantita_segnaposti'])) ? (int)$_POST['quantita_segnaposti'] : null;
            
            // ? NUOVO CAMPO ANTEPRIMA (Stato checkbox Anteprima)
            $check_anteprima = isset($_POST['check_anteprima']) ? 1 : 0;
			
			// ? AGGIUNTA — nuovi campi Archiviazione
			$path_google_drive = $_POST['path_google_drive'] ?? null;
			$archiviato = isset($_POST['archiviato']) ? 1 : 0;
			$hdd_backup = $archiviato ? ($_POST['hdd_backup'] ?? null) : null;
            
            
            $id_evento_inserito = $id;

            // Logica di Inserimento/Aggiornamento Evento
            // AGGIORNATA LA QUERY: inseriti tutti i nuovi campi (check_anteprima)
            // ??? Modifica 2/4: Aggiornamento della stringa dei campi per includere orario_evento
           $query_campi_admin = "
id_cliente=?, id_anagrafica_evento=?, id_operatore=?, data_evento=?, orario_evento=?, costo_totale=?, 
id_stato=?, note=?, stato_lavorazione_fase=?, 
path_google_drive=?, archiviato=?, hdd_backup=?, 
data_consegna=?, data_stampa=?, id_laboratorio=?, 
check_casa=?, check_chiesa=?, check_location=?, check_album=?, 
id_formato_album=?, id_tipologia_album=?, 
check_poster=?, quantita_poster=?, 
check_segnaposti=?, quantita_segnaposti=?, check_anteprima=?,orario_consegna=?";

            // ??? Modifica 3/4: Aggiornamento dell'array dei parametri per includere orario_evento
            $params_admin = [
    $id_cliente, $id_anag, $id_oper, $data_evento, $orario_evento, $costo,
    $id_stato, $note, $stato_lavorazione_fase,
    $path_google_drive, $archiviato, $hdd_backup,
    $data_consegna, $data_stampa, $id_laboratorio, 
    $check_casa, $check_chiesa, $check_location, $check_album, 
    $id_formato_album, $id_tipologia_album, 
    $check_poster, $quantita_poster, 
    $check_segnaposti, $quantita_segnaposti, $check_anteprima,$orario_consegna
];

            if ($id) {
                $stmt = $pdo->prepare("UPDATE eventi SET {$query_campi_admin} WHERE id_evento=?");
                $params_admin[] = $id;
                $stmt->execute($params_admin);
            } else {
                // ??? Modifica 4/4: Aggiornamento della query INSERT
                $query_insert_columns = "id_cliente,id_anagrafica_evento,id_operatore,data_evento,orario_evento,costo_totale,id_stato,note,stato_lavorazione_fase,id_laboratorio,data_stampa,check_casa,check_chiesa,check_location,check_album,id_formato_album,id_tipologia_album,check_poster,quantita_poster,check_segnaposti,quantita_segnaposti,check_anteprima,data_stampa,id_laboratorio,orario_conegna";
                $query_insert_placeholders = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?"; // 15 placeholders
                $stmt = $pdo->prepare("INSERT INTO eventi (
    id_cliente,id_anagrafica_evento,id_operatore,data_evento,orario_evento,costo_totale,
    id_stato,note,stato_lavorazione_fase,path_google_drive,archiviato,hdd_backup,
    data_consegna,data_stampa,id_laboratorio,
	check_casa, check_chiesa, check_location, check_album,
	id_formato_album, id_tipologia_album, 
	check_poster, quantita_poster,
	check_segnaposti, quantita_segnaposti, check_anteprima,orario_consegna
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$stmt->execute($params_admin);

                $id_evento_inserito = $pdo->lastInsertId();
                $id = $id_evento_inserito; 
            }
            
            // --- LOGICA AGGIORNATA PER LOCATION ---
            // Le location da considerare ora includono 'Anteprima' SEMPRE, e le altre solo per Battesimo/Comunione/18°
            if ($id) {
                // 1. Definisci le location da gestire in questo POST
                $location_types_to_process = [];
                // Location standard (se l'evento lo richiede)
                if (in_array($id_anag, $eventi_con_check_e_location)) {
                     $location_types_to_process = ['Casa', 'Chiesa', 'Locale'];
                }
                // Anteprima (sempre gestita se il check è attivo)
                if ($check_anteprima) {
                    $location_types_to_process[] = 'Anteprima';
                }
                
                // Location da ELIMINARE: Elimina solo quelle che STIAMO processando.
                // Se $location_types_to_process è vuoto, non eliminiamo nulla.
                if (!empty($location_types_to_process)) {
                    $placeholders_delete = implode(',', array_fill(0, count($location_types_to_process), '?'));
                    $pdo->prepare("DELETE FROM dettagli_location_evento WHERE id_evento = ? AND nome_location IN ({$placeholders_delete})")->execute(array_merge([$id], $location_types_to_process));
                }
                
                $location_details_post = $_POST['location'] ?? [];
                
                $location_stmt = $pdo->prepare("INSERT INTO dettagli_location_evento (id_evento, nome_location, indirizzo, orario_inizio, note_location, data_specifica) VALUES (?, ?, ?, ?, ?, ?)");
                
                foreach ($location_types_to_process as $loc_name) {
                    $details = $location_details_post[$loc_name] ?? [];
                    $indirizzo = !empty($details['indirizzo']) ? htmlspecialchars($details['indirizzo']) : null;
                    $orario_inizio = !empty($details['orario_inizio']) ? htmlspecialchars($details['orario_inizio']) : null;
                    $note_location = !empty($details['note_location']) ? htmlspecialchars($details['note_location']) : null;
                    
                    // Solo l'Anteprima ha una data specifica, altrimenti è NULL
                    $data_specifica = null;
                    if ($loc_name === 'Anteprima') {
                        $data_specifica = !empty($details['data_specifica']) ? $details['data_specifica'] : null;
                    }
                    
                    // Logica check specifica per Casa/Chiesa/Locale (già gestita dalla lista $location_types_to_process)
                    $is_checked_in_post = false;
                    if ($loc_name === 'Anteprima') {
                         $is_checked_in_post = $check_anteprima;
                    } else { // Casa, Chiesa, Locale
                        $check_key = 'check_' . strtolower($loc_name);
                        if ($loc_name === 'Locale') { $check_key = 'check_location'; }
                        $is_checked_in_post = (isset($_POST[$check_key]) && (int)$_POST[$check_key] === 1);
                    }

                    // Inserisci solo se era spuntato nel POST (Anteprima è già filtrata sopra, ma per sicurezza)
                    if ($is_checked_in_post) {
                    		$location_stmt->execute([
                            $id,
                            $loc_name,
                            $indirizzo,
                            $orario_inizio,
                            $note_location,
                            $data_specifica
                         ]);
                    }
                }
            }


            // --- LOGICA AGGIUNTA PER SERVIZI AGGIUNTIVI (invariata) ---
            if ($id) {
                 // 1. Eliminazione dei servizi esistenti per l'evento
                 $pdo->prepare("DELETE FROM servizi_aggiuntivi_evento WHERE id_evento = ?")->execute([$id]);
                 
                 // 2. Inserimento dei nuovi servizi
                 if (isset($_POST['servizio']) && is_array($_POST['servizio'])) {
                    $servizio_stmt = $pdo->prepare("INSERT INTO servizi_aggiuntivi_evento (id_evento, id_anagrafica_servizio, quantita, prezzo_unitario, descrizione_override) VALUES (?, ?, ?, ?, ?)");
                    
                    foreach ($_POST['servizio'] as $serv) {
                        $id_anag_s = !empty($serv['id_anagrafica_servizio']) ? (int)$serv['id_anagrafica_servizio'] : null;
                        $desc_override = !empty($serv['descrizione_override']) ? htmlspecialchars($serv['descrizione_override']) : null;
                        $quantita = !empty($serv['quantita']) ? (int)$serv['quantita'] : 1;
                        $prezzo_unitario = !empty($serv['prezzo_unitario']) ? (float)$serv['prezzo_unitario'] : 0.00;
                        
                        // Assicurati che almeno un campo significativo sia presente
                        if ($id_anag_s || $desc_override) {
                            $servizio_stmt->execute([
                                $id, 
                                $id_anag_s,
                                $quantita,
                                $prezzo_unitario,
                                $desc_override
                            ]);
                        }
                    }
                 }
            }
            
            // Reindirizzamento in caso di successo
            header('Location: event_form.php?id=' . $id);
            exit;
            
        } else {
            // Logica per operatore base (può solo aggiornare la descrizione di lavorazione/stato)
            $query_op = "id_stato=?, note=?, stato_lavorazione_fase=?";
            $params_op = [$id_stato, $note, $stato_lavorazione_fase, $id];
            
            $stmt = $pdo->prepare("UPDATE eventi SET {$query_op} WHERE id_evento=?");
            $stmt->execute($params_op);
            
            header('Location: event_form.php?id=' . $id);
            exit;
        }
    }
}

// Ricarica l'evento per la visualizzazione se era in modalità modifica
if ($id && !$event) {
    $stmt = $pdo->prepare("SELECT * FROM eventi WHERE id_evento = ? LIMIT 1");
    $stmt->execute([$id]);
    $event = $stmt->fetch();
}

// Inizializza variabili per i messaggi
if ($id) {
    $page_title = 'Modifica Evento #' . $id;
} else {
    $page_title = 'Nuovo Evento';
}

// Gestione preselezione cliente (es. da Quick Add)
$client_name_preselected = '';
if ($preselected_id_cliente) {
    foreach ($clients as $c) {
        if ($c['id_cliente'] == $preselected_id_cliente) {
            $client_name_preselected = htmlspecialchars($c['nome'].' '.$c['cognome']);
            break;
        }
    }
}

?>

<h2 class="mb-4"><i class="bi bi-calendar-event me-2"></i><?php echo $page_title; ?></h2>

<?php if ($err): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($err); ?></div>
<?php endif; ?>

<?php if ($success_msg): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
<?php endif; ?>

<form method="post" novalidate id="eventForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="id_cliente" id="id_cliente_hidden" value="<?php echo $preselected_id_cliente; ?>"> 
    
    <div class="row g-3">
        <div class="col-12">
            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-info-circle me-1"></i> Dati Principali</h5>
        </div>
        
        <div class="col-md-6">
            <label class="form-label"><i class="bi bi-person"></i> Cliente <a href="#" data-bs-toggle="modal" data-bs-target="#quickAddClientModal" class="badge bg-success ms-2"><i class="bi bi-plus-lg"></i> Aggiungi Veloce</a></label>
            <input list="clienti_list" class="form-control" id="cliente_input" name="cliente_input" placeholder="Digita o seleziona cliente..." value="<?php 
                $current_client_id = $preselected_id_cliente ?: ($event['id_cliente'] ?? 0);
                if ($current_client_id) {
                    foreach ($clients as $c) {
                        if ($c['id_cliente'] == $current_client_id) {
                            echo htmlspecialchars($c['nome'].' '.$c['cognome']);
                            break;
                        }
                    }
                }
            ?>" <?php echo is_admin() ? '' : 'disabled'; ?> required>
            <datalist id="clienti_list">
                <?php foreach ($clients as $c): ?>
                    <option data-id="<?php echo $c['id_cliente']; ?>" value="<?php echo htmlspecialchars($c['nome'].' '.$c['cognome']); ?>">
                <?php endforeach; ?>
            </datalist>
            </div>
        
        <div class="col-md-3">
            <label for="id_anagrafica_evento" class="form-label"><i class="bi bi-tag"></i> Tipo Evento *</label>
            <select name="id_anagrafica_evento" id="id_anagrafica_evento" class="form-select" <?php echo is_admin() ? '' : 'disabled'; ?> required>
                <option value="">-- Seleziona Evento --</option>
                <?php foreach ($anag as $a): ?>
                    <option value="<?php echo $a['id_anagrafica_evento']; ?>" 
                            data-eventi-con-check="<?php echo in_array($a['id_anagrafica_evento'], $eventi_con_check_e_location) ? 'true' : 'false'; ?>"
                            <?php echo ($event['id_anagrafica_evento'] ?? '') == $a['id_anagrafica_evento'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($a['nome_evento']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
       <div class="col-md-3">
    <label for="data_evento" class="form-label d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar"></i> Data Evento *</span>
        <a href="#" id="google-calendar-link" class="text-danger" title="Aggiungi a Google Calendar">
        <i class="bi bi-calendar-plus-fill fs-5"></i>
    </a>
    </label>
    <input type="date" name="data_evento" id="data_evento" class="form-control" value="<?php echo htmlspecialchars($event['data_evento'] ?? date('Y-m-d')); ?>" <?php echo is_admin() ? '' : 'disabled'; ?> required>
</div>
        
        <div class="col-md-3">
            <label for="orario_evento" class="form-label small text-muted"><i class="bi bi-clock"></i> Orario Evento</label>
            <input type="time" name="orario_evento" id="orario_evento" class="form-control" value="<?php echo htmlspecialchars($event['orario_evento'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
        </div>
        <div class="col-md-3">
            <label for="id_operatore" class="form-label small text-muted"><i class="bi bi-camera"></i> Operatore Assegnato</label>
            <select name="id_operatore" id="id_operatore" class="form-select" <?php echo is_admin() ? '' : 'disabled'; ?>>
                <option value="">-- Non Assegnato --</option>
                <?php foreach ($ops as $op): ?>
                    <option value="<?php echo $op['id_operatore']; ?>" 
                            <?php echo ($event['id_operatore'] ?? $default_id_operatore) == $op['id_operatore'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($op['nome'] . ' ' . $op['cognome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="costo_totale" class="form-label"><i class="bi bi-currency-euro"></i> Costo Base (€) *</label> 
            <input type="number" step="0.01" min="0" name="costo_totale" id="costo_totale" class="form-control text-end" value="<?php echo htmlspecialchars($event['costo_totale'] ?? '0.00'); ?>" <?php echo is_admin() ? '' : 'disabled'; ?> required>
        </div>
        <div class="col-md-3">
            <label for="costo_totale_2" class="form-label"><i class="bi bi-currency-euro"></i> Costo Totale Finale (€)</label>
            <input type="text" id="costo_totale_2" class="form-control text-end bg-info bg-opacity-10 fw-bold" value="0.00" readonly>
        </div>
        <div class="col-md-3">
            <label for="id_stato" class="form-label"><i class="bi bi-cone-striped"></i> Stato Evento *</label>
            <select name="id_stato" id="id_stato" class="form-select" <?php echo (is_admin() || (($event['id_operatore'] ?? 0) == ($_SESSION['id_operatore'] ?? 0))) ? '' : 'disabled'; ?> required>
                <?php foreach ($stati as $s): ?>
                    <option value="<?php echo $s['id_stato']; ?>" <?php echo ($event['id_stato'] ?? ($default_stato_id ?? 1)) == $s['id_stato'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['nome_stato']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
		
		<div class="col-12 mt-3 p-3 border rounded bg-light" id="consegna_section" style="display:none;">
    <h6 class="mb-3 text-primary"><i class="bi bi-gift-fill me-1"></i> Data Consegna (Stato: ALBUM DA RITIRARE)</h6>
    <div class="row g-3">
        <div class="col-md-12">
            <label for="data_consegna" class="form-label small text-muted d-flex justify-content-between align-items-center">
        <span><i class="bi bi-gift"></i> Data Consegna</span>
        <a href="#" id="google-calendar-link-consegna" class="text-danger" title="Aggiungi a Google Calendar">
        <i class="bi bi-calendar-plus-fill fs-5"></i>
    </a>
    </label>
    <input type="date" name="data_consegna" id="data_consegna" class="form-control" value="<?php echo htmlspecialchars($event['data_consegna'] ?? date('Y-m-d')); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
	<label for="orario_consegna" class="form-label small text-muted"><i class="bi bi-clock"></i> Orario Consegna</label>
            <input type="time" name="orario_consegna" id="orario_consegna" class="form-control" value="<?php echo htmlspecialchars($event['orario_consegna'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
</div>
    </div>
</div>
        
        <div class="col-12 mt-4 p-3 border rounded bg-light" id="check_section">
            <h6 class="mb-3 text-primary"><i class="bi bi-list-check me-1"></i> Check Evento e Prodotti</h6>
            
            <div class="row g-3 mb-3">
                 <div class="col-md-3">
                     <div class="form-check form-check-inline">
                        <input class="form-check-input check-location-toggle" type="checkbox" id="check_anteprima" name="check_anteprima" value="1" data-location-name="Anteprima" <?php echo ($event['check_anteprima'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_anteprima">Anteprima</label>
                    </div>
                </div>
                
                <div class="col-md-3 event-location-check">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-location-toggle" type="checkbox" id="check_casa" name="check_casa" value="1" data-location-name="Casa" <?php echo ($event['check_casa'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_casa">Foto a Casa</label>
                    </div>
                </div>
                <div class="col-md-3 event-location-check">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-location-toggle" type="checkbox" id="check_chiesa" name="check_chiesa" value="1" data-location-name="Chiesa" <?php echo ($event['check_chiesa'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_chiesa">Foto in Chiesa</label>
                    </div>
                </div>
                <div class="col-md-3 event-location-check">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-location-toggle" type="checkbox" id="check_location" name="check_location" value="1" data-location-name="Locale" <?php echo ($event['check_location'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_location">Foto Location/Locale</label>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 mb-3 border-top pt-3">
                <div class="col-md-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-details-toggle" type="checkbox" id="check_album" name="check_album" value="1" data-target="album_details" <?php echo ($event['check_album'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_album">Album</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-details-toggle" type="checkbox" id="check_poster" name="check_poster" value="1" data-target="poster_details" <?php echo ($event['check_poster'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_poster">Poster</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input check-details-toggle" type="checkbox" id="check_segnaposti" name="check_segnaposti" value="1" data-target="segnaposti_details" <?php echo ($event['check_segnaposti'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <label class="form-check-label" for="check_segnaposti">Segnaposti</label>
                    </div>
                </div>
            </div>
            
            <div id="album_details" class="row g-3 mt-3 p-3 border rounded border-secondary bg-white" style="display: <?php echo ($event['check_album'] ?? 0) ? 'flex' : 'none'; ?>;">
                <h6 class="mb-2 text-primary border-bottom pb-2">Dettagli Album</h6>
                <div class="col-md-6">
                    <label class="form-label small text-muted"><i class="bi bi-rulers"></i> Formato Album</label>
                    <select name="id_formato_album" class="form-select" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <option value="">-- Seleziona Formato --</option>
                        <?php foreach($formati_album as $f): ?>
                            <option value="<?php echo $f['id_formato']; ?>" <?php echo ($event['id_formato_album'] ?? '') == $f['id_formato'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['nome_formato']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted"><i class="bi bi-book"></i> Tipologia Album</label>
                    <select name="id_tipologia_album" class="form-select" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <option value="">-- Seleziona Tipologia --</option>
                        <?php foreach($tipologie_album as $t): ?>
                            <option value="<?php echo $t['id_tipologia']; ?>" <?php echo ($event['id_tipologia_album'] ?? '') == $t['id_tipologia'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['nome_tipologia']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row g-3 mt-3 p-3 border rounded border-secondary bg-white" id="poster_details" style="display: <?php echo ($event['check_poster'] ?? 0) ? 'flex' : 'none'; ?>;">
                <h6 class="mb-2 text-primary border-bottom pb-2">Dettagli Poster</h6>
                <div class="col-md-12">
                    <label class="form-label small text-muted"><i class="bi bi-hash"></i> Quantità Poster</label>
                    <input type="number" step="1" min="1" name="quantita_poster" class="form-control" placeholder="Quantità" value="<?php echo htmlspecialchars($event['quantita_poster'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
            </div>
            
            <div class="row g-3 mt-3 p-3 border rounded border-secondary bg-white" id="segnaposti_details" style="display: <?php echo ($event['check_segnaposti'] ?? 0) ? 'flex' : 'none'; ?>;">
                <h6 class="mb-2 text-primary border-bottom pb-2">Dettagli Segnaposti</h6>
                <div class="col-md-12">
                    <label class="form-label small text-muted"><i class="bi bi-hash"></i> Quantità Segnaposti</label>
                    <input type="number" step="1" min="1" name="quantita_segnaposti" class="form-control" placeholder="Quantità" value="<?php echo htmlspecialchars($event['quantita_segnaposti'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
            </div>
            
        </div>
        <div class="col-12 mt-4 p-3 border rounded bg-light" id="location_details_section" style="display: none;">
            <h6 class="mb-3 text-primary"><i class="bi bi-pin-map-fill me-1"></i> Dettagli Location (se applicabile)</h6>
            <div id="location_details_container">
            <?php 
            // ! MODIFICA: Aggiunto 'Anteprima' qui per il rendering condizionale
            $location_names = ['Anteprima', 'Casa', 'Chiesa', 'Locale']; 
            foreach ($location_names as $loc_name): 
                $check_key = 'check_' . strtolower($loc_name);
                if ($loc_name === 'Locale') { $check_key = 'check_location'; }
                $loc_data = $locations_map[$loc_name] ?? [];
                
                // Determinazione della visibilità iniziale: Anteprima usa $event['check_anteprima']
                $is_visible_init = ($loc_name === 'Anteprima' && ($event['check_anteprima'] ?? 0));
                // Le altre location usano il loro check specifico E devono essere di un tipo evento con check
                if ($loc_name !== 'Anteprima' && in_array($event['id_anagrafica_evento'] ?? 0, $eventi_con_check_e_location)) {
                     $is_visible_init = ($event[$check_key] ?? 0);
                }
            ?>
                <div class="location-detail-item" data-location-name="<?php echo $loc_name; ?>" style="display: <?php echo $is_visible_init ? 'block' : 'none'; ?>;">
                    <h6 class="mt-3 mb-2 border-bottom pb-1 text-secondary d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-geo-alt-fill me-1"></i> <?php echo $loc_name; ?></span>
                        <?php if ($loc_name === 'Anteprima'): ?>
                            <a href="#" id="google-calendar-link-anteprima" class="text-danger" title="Aggiungi Anteprima a Google Calendar">
                                <i class="bi bi-calendar-plus-fill fs-5"></i>
                            </a>
                        <?php endif; ?>
                    </h6>
                    <div class="row g-3 mb-3">
                        <?php if ($loc_name === 'Anteprima'): ?>
                             <div class="col-md-3">
                                <label class="form-label small text-muted">Data Anteprima</label>
                                <input type="date" name="location[<?php echo $loc_name; ?>][data_specifica]" id="data_anteprima" class="form-control" value="<?php echo htmlspecialchars($loc_data['data_specifica'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                             </div>
                        <?php endif; ?>
                        
                        <div class="col-md-<?php echo $loc_name === 'Anteprima' ? '3' : '4'; ?>">
                            <label class="form-label small text-muted">Indirizzo</label>
                            <input type="text" name="location[<?php echo $loc_name; ?>][indirizzo]" class="form-control" placeholder="Indirizzo o Riferimento" value="<?php echo htmlspecialchars($loc_data['indirizzo'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        </div>
                        <div class="col-md-<?php echo $loc_name === 'Anteprima' ? '3' : '4'; ?>">
                            <label class="form-label small text-muted">Orario Inizio</label>
                            <input type="time" name="location[<?php echo $loc_name; ?>][orario_inizio]" id="orario_anteprima" class="form-control" placeholder="Orario" value="<?php echo htmlspecialchars($loc_data['orario_inizio'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        </div>
                        <div class="col-md-<?php echo $loc_name === 'Anteprima' ? '3' : '4'; ?>">
                            <label class="form-label small text-muted">Note Location</label>
                            <input type="text" name="location[<?php echo $loc_name; ?>][note_location]" class="form-control" placeholder="Note (Es: Riferimento, piano...)" value="<?php echo htmlspecialchars($loc_data['note_location'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
		
        <div class="col-12 mt-4">
            <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-journal-text"></i> Dettagli Lavorazione</h6>
        </div>
		
		<div class="col-12 mt-3 p-3 border rounded bg-light">
        <h6 class="mb-3 text-primary"><i class="bi bi-cloud-arrow-up me-1"></i> Archiviazione e Backup</h6>
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <label for="path_google_drive" class="form-label small text-muted"><i class="bi bi-link-45deg"></i> Path Google Drive</label>
                <input type="url" name="path_google_drive" id="path_google_drive" class="form-control" placeholder="https://drive.google.com/..." value="<?php echo htmlspecialchars($event['path_google_drive'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="archiviato" name="archiviato" value="1" <?php echo ($event['archiviato'] ?? 0) ? 'checked' : ''; ?> <?php echo is_admin() ? '' : 'disabled'; ?>>
                    <label class="form-check-label" for="archiviato">Archiviato su HDD esterno</label>
                </div>
            </div>
            <div class="col-md-3" id="hdd_backup_container" style="display: <?php echo ($event['archiviato'] ?? 0) ? 'block' : 'none'; ?>;">
                <label for="hdd_backup" class="form-label small text-muted"><i class="bi bi-hdd"></i> Nome HDD / Path Archivio</label>
                <input type="text" name="hdd_backup" id="hdd_backup" class="form-control" placeholder="Nome HDD o percorso archivio" value="<?php echo htmlspecialchars($event['hdd_backup'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
            </div>
        </div>
    </div>
	
<div class="col-12 mt-4">
    <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-journal-text"></i> Dettagli Lavorazione</h6>
</div>
        
        <div class="col-12 mt-3 p-3 border rounded bg-light" id="stampa_section">
            <h6 class="mb-3 text-primary"><i class="bi bi-printer-fill me-1"></i> Dettagli Stampa
    
 (Stato: Stampato)</h6>
            <div class="row g-3">
                 <div class="col-md-6">
                    <label for="id_laboratorio" class="form-label small text-muted"><i class="bi bi-building"></i> Laboratorio</label>
                    <select name="id_laboratorio" id="id_laboratorio" class="form-select" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <option value="">-- Seleziona Laboratorio --</option>
                        <?php foreach($laboratori as $lab): ?>
                            <option value="<?php echo $lab['id_laboratorio']; ?>" <?php echo ($event['id_laboratorio'] ?? '') == $lab['id_laboratorio'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lab['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="data_stampa" class="form-label small text-muted"><i class="bi bi-calendar-check"></i> Data Stampa</label>
                    <input type="date" name="data_stampa" id="data_stampa" class="form-control" value="<?php echo htmlspecialchars($event['data_stampa'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
            </div>
        </div>
        
        <div class="col-12 mt-3 p-3 border rounded bg-light">
            <h6 class="mb-3 text-primary"><i class="bi bi-card-text me-1"></i> Stato Lavorazione e Note</h6>
            <div class="row g-3">
                <div class="col-12">
                     <label for="stato_lavorazione_fase" class="form-label small text-muted">Fase di Lavorazione Corrente</label>
                     <input type="text" name="stato_lavorazione_fase" id="stato_lavorazione_fase" class="form-control" placeholder="Esempio: In attesa di selezione foto cliente" value="<?php echo htmlspecialchars($event['stato_lavorazione_fase'] ?? ''); ?>" <?php echo (is_admin() || (($event['id_operatore'] ?? 0) == ($_SESSION['id_operatore'] ?? 0))) ? '' : 'disabled'; ?>>
                </div>
                <div class="col-12">
                    <label for="descrizione_lavorazione" class="form-label small text-muted">Note e Dettagli Evento</label>
                    <textarea name="descrizione_lavorazione" id="descrizione_lavorazione" rows="3" class="form-control" placeholder="Aggiungi note importanti relative all'evento (contatti, richieste speciali, dettagli lavorazione...)" <?php echo (is_admin() || (($event['id_operatore'] ?? 0) == ($_SESSION['id_operatore'] ?? 0))) ? '' : 'disabled'; ?>><?php echo htmlspecialchars($event['note'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <div class="col-12 mt-4">
             <h6 class="border-bottom pb-2 mb-3 d-flex justify-content-between">
                <span><i class="bi bi-basket-fill"></i> Servizi Aggiuntivi/Prodotti Extra</span>
                <?php if (is_admin()): ?>
                    <button type="button" class="btn btn-sm btn-outline-success" id="add_servizio_btn"><i class="bi bi-plus-circle me-1"></i> Aggiungi Servizio</button>
                <?php endif; ?>
            </h6>
        </div>
        
        <div class="col-12" id="servizi_container">
            <?php 
            // Inizializza l'array per garantire almeno un campo vuoto se è un nuovo evento
            if (!$id && empty($servizi_salvati)) {
                $servizi_salvati[0] = ['id_servizio_evento' => 0, 'id_anagrafica_servizio' => null, 'quantita' => 1, 'prezzo_unitario' => 0.00, 'descrizione_override' => null];
            }
            
            foreach($servizi_salvati as $index => $serv): 
            ?>
            <div class="row g-3 mb-3 p-3 border rounded bg-white shadow-sm servizio-item">
                <div class="col-md-5">
                    <label class="form-label small text-muted">Servizio Standard</label>
                    <select name="servizio[<?php echo $index; ?>][id_anagrafica_servizio]" class="form-select servizio-anagrafica" <?php echo is_admin() ? '' : 'disabled'; ?>>
                        <option value="">-- Seleziona Servizio Standard --</option>
                        <?php foreach($anagrafica_servizi as $anag_s): ?>
                            <option value="<?php echo $anag_s['id_anagrafica_servizio']; ?>" data-prezzo="<?php echo $anag_s['prezzo_base']; ?>" <?php echo ($serv['id_anagrafica_servizio'] ?? '') == $anag_s['id_anagrafica_servizio'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($anag_s['nome_servizio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Descrizione Manuale/Override</label>
                    <input type="text" name="servizio[<?php echo $index; ?>][descrizione_override]" class="form-control" placeholder="Descrizione manuale/override" value="<?php echo htmlspecialchars($serv['descrizione_override'] ?? ''); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-1">
                     <label class="form-label small text-muted">Qtà</label>
                    <input type="number" step="1" min="1" name="servizio[<?php echo $index; ?>][quantita]" class="form-control text-end servizio-quantita" placeholder="Qtà" value="<?php echo htmlspecialchars($serv['quantita'] ?? 1); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Prezzo Unitario (€)</label>
                    <input type="number" step="0.01" min="0.00" name="servizio[<?php echo $index; ?>][prezzo_unitario]" class="form-control text-end servizio-prezzounitario" placeholder="0.00" value="<?php echo htmlspecialchars($serv['prezzo_unitario'] ?? '0.00'); ?>" <?php echo is_admin() ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-1 d-flex flex-column align-items-center justify-content-end">
                    <label class="form-label small text-muted">Totale</label>
                    <span class="badge bg-secondary total-parziale mb-2">€ 0.00</span>
                    <?php if (is_admin()): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-servizio-btn"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="col-12 mt-4 text-end">
            <a href="events.php" class="btn btn-secondary me-2"><i class="bi bi-x-circle me-1"></i> Annulla</a>
            <button type="submit" class="btn btn-primary" <?php echo is_admin() || (($event['id_operatore'] ?? 0) == ($_SESSION['id_operatore'] ?? 0)) ? '' : 'disabled'; ?>>
                <i class="bi bi-save me-1"></i> <?php echo $id ? 'Salva Modifiche' : 'Crea Evento'; ?>
            </button>
        </div>
    </div>
</form>

<div class="modal fade" id="quickAddClientModal" tabindex="-1" aria-labelledby="quickAddClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="quickAddClientModalLabel"><i class="bi bi-person-plus-fill me-1"></i> Aggiungi Cliente Veloce</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="clients.php" id="quickAddClientForm" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="quick_add"> 
                    <input type="hidden" name="redirect_to" value="event_form.php">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="nome_quick" class="form-label small">Nome *</label>
                            <input type="text" class="form-control form-control-sm" id="nome_quick" name="nome" required>
                        </div>
                        <div class="col-md-6">
                            <label for="cognome_quick" class="form-label small">Cognome *</label>
                            <input type="text" class="form-control form-control-sm" id="cognome_quick" name="cognome" required>
                        </div>
                        <div class="col-md-6">
                            <label for="telefono_quick" class="form-label small">Telefono</label>
                            <input type="tel" class="form-control form-control-sm" id="telefono_quick" name="telefono">
                        </div>
                        <div class="col-md-6">
                            <label for="settimane_gravidanza_quick" class="form-label small">Settimane Gravidanza (se applicabile)</label>
                            <input type="number" step="1" min="1" max="42" class="form-control form-control-sm" id="settimane_gravidanza_quick" name="settimane_gravidanza">
                        </div>
                        
                        <div class="col-12"><hr class="my-2"></div>
                        
                        <div class="col-md-6">
                            <label for="id_tipologia_provenienza_quick_modal" class="form-label small">Provenienza</label>
                            <select class="form-select form-select-sm" id="id_tipologia_provenienza_quick_modal" name="id_tipologia_provenienza">
                                <option value="">-- Nessuna --</option>
                                <?php foreach ($tipologie_provenienza as $tipo): ?>
                                    <?php $provenienza_nome = htmlspecialchars(strtoupper($tipo['provenienza'])); ?>
                                    <option value="<?php echo (int)$tipo['id_provenienza']; ?>" data-tipo="<?php echo $provenienza_nome; ?>">
                                        <?php echo $provenienza_nome; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="id_associativo_select_modal" class="form-label small">Fonte specifica</label>
                            <div id="sorgente-container-quick-modal">
                                <input type="hidden" id="id_associativo_hidden_modal" name="id_associativo" value="">
                                
                                <select class="form-select form-select-sm sorgente-select-quick-modal" id="sorgente_CLIENTE_quick_modal" style="display: none;" disabled>
                                    <option value="">-- Seleziona un cliente --</option>
                                    <?php foreach ($clienti_referenti as $cliente_ref): ?>
                                        <option value="<?php echo (int)$cliente_ref['id_cliente']; ?>">
                                            <?php echo htmlspecialchars($cliente_ref['nome'] . ' ' . $cliente_ref['cognome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <select class="form-select form-select-sm sorgente-select-quick-modal" id="sorgente_PROMOZIONE_quick_modal" style="display: none;" disabled>
                                    <option value="">-- Seleziona una promozione --</option>
                                    <?php foreach ($promozioni_disponibili as $promo_ref): ?>
                                        <option value="<?php echo (int)$promo_ref['id_promozione']; ?>">
                                            <?php echo htmlspecialchars($promo_ref['DESCRIZIONE']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                </div>
                        </div>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-floppy me-1"></i> Salva Cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const isAdmin = <?php echo json_encode(is_admin()); ?>;
    const isEventOperator = <?php echo json_encode((($event['id_operatore'] ?? 0) == ($_SESSION['id_operatore'] ?? 0))); ?>;
    const statoSelect = document.getElementById('id_stato');
    const stampaSection = document.getElementById('stampa_section');
    const statoStampatoId = <?php echo json_encode($stato_stampato_id); ?>;
    const checkDetailsToggles = document.querySelectorAll('.check-details-toggle');
    const checkLocationToggles = document.querySelectorAll('.check-location-toggle');
    const locationDetailsContainer = document.getElementById('location_details_container');
    const locationDetailsSection = document.getElementById('location_details_section');

    // -----------------------------------------------------------------
    // 1. Logica Selezione Cliente (Datalist)
    // -----------------------------------------------------------------
    const clienteInput = document.getElementById('cliente_input');
    const clientiList = document.getElementById('clienti_list');
    const idClienteHidden = document.getElementById('id_cliente_hidden');
	
	
	const checkSection = document.getElementById('check_section'); 
    const anagraficaSelect = document.getElementById('id_anagrafica_evento'); 
	
	const eventiConCheck = ["4", "6", "8"]; // ID Battesimo, Comunione, 18° Compleanno
	
	// ...

	// ? NUOVE VARIABILI JS
	const consegnaSection = document.getElementById('consegna_section');
	const statoAlbumRitirareId = <?php echo json_encode($stato_album_ritirare_id); ?>; 
	
    // ? NUOVE VARIABILI PER IL CALCOLO TOTALE
	const costoTotaleBaseInput = document.getElementById('costo_totale'); 
    const costoTotaleFinaleInput = document.getElementById('costo_totale_2');
    const serviziContainer = document.getElementById('servizi_container');
    
    // ? NUOVA FUNZIONE: Calcola il totale di tutti i servizi aggiuntivi + costo base
    function updateGrandTotal() {
        let servicesTotal = 0;
        serviziContainer.querySelectorAll('.servizio-item').forEach(item => {
            const qty = parseFloat(item.querySelector('.servizio-quantita').value) || 0;
            const price = parseFloat(item.querySelector('.servizio-prezzounitario').value) || 0.00;
            servicesTotal += qty * price;
        });

        const baseCost = parseFloat(costoTotaleBaseInput.value) || 0.00;
        const grandTotal = (baseCost + servicesTotal).toFixed(2);

        costoTotaleFinaleInput.value = grandTotal;
    }
    
    // Aggiungi listener per il costo base (se cambia, aggiorna il totale finale)
    if (costoTotaleBaseInput) {
        costoTotaleBaseInput.addEventListener('input', updateGrandTotal);
    }
	
	
	// -----------------------------------------------------------------
// 2. Gestione Visibilità Sezioni Condizionali (Stampa, Consegna)
// -----------------------------------------------------------------
function toggleConditionalSections() {
    const currentStatoId = statoSelect.value;
    
    // LOGICA STAMPA
    if (stampaSection && statoStampatoId) {
        if (currentStatoId == statoStampatoId) {
            stampaSection.style.display = 'block';
        } else {
            stampaSection.style.display = 'none';
        }
    }
    
    // ? NUOVA LOGICA CONSEGNA
    if (consegnaSection && statoAlbumRitirareId) {
        if (currentStatoId == statoAlbumRitirareId) {
            consegnaSection.style.display = 'block';
        } else {
            consegnaSection.style.display = 'none';
        }
    }
}
statoSelect.addEventListener('change', toggleConditionalSections);
toggleConditionalSections();

// -----------------------------------------------------------------
// 3. Gestione Visibilità Dettagli Prodotto (Album, Poster, Segnaposti)
// ... (il resto del codice continua da qui)
	
    
    // Funzione per aggiornare l'input nascosto con l'id del cliente
    function updateHiddenClientId(clientName) {
        let found = false;
        // Cerca l'opzione corrispondente nel datalist
        for (const option of clientiList.options) {
            if (option.value === clientName) {
                // Recupera l'id dal data-id dell'opzione
                const clientId = option.getAttribute('data-id'); 
                idClienteHidden.value = clientId;
                found = true;
                break;
            }
        }
        if (!found) {
            // Se il nome non corrisponde a nessun cliente esistente, resetta l'id
            idClienteHidden.value = '';
        }
    }

    // Ascolta il cambio (quando l'utente seleziona dal datalist o digita e perde il focus)
    clienteInput.addEventListener('input', (e) => updateHiddenClientId(e.target.value));

    // Se un cliente è pre-selezionato (es. al caricamento della pagina o da redirect quick-add), aggiorna l'input nascosto
    if (clienteInput.value) {
         updateHiddenClientId(clienteInput.value); 
    }
    // -----------------------------------------------------------------
    
    // -----------------------------------------------------------------
    // 2. Gestione Visibilità Dettagli Stampa
    // -----------------------------------------------------------------
    function toggleStampaSection() {
        if (statoSelect.value == statoStampatoId) {
            stampaSection.style.display = 'block';
        } else {
            stampaSection.style.display = 'none';
        }
    }
    
    // La gestione della stampa è ora inclusa in toggleConditionalSections(), ma lascio la funzione per chiarezza.
    // statoSelect.addEventListener('change', toggleStampaSection);
    // toggleStampaSection();
    
    // -----------------------------------------------------------------
    // 3. Gestione Visibilità Dettagli Prodotto (Album, Poster, Segnaposti)
    // -----------------------------------------------------------------
    function toggleDetails(checkbox) {
        const targetId = checkbox.dataset.target;
        const targetElement = document.getElementById(targetId);
        if (targetElement) {
            targetElement.style.display = checkbox.checked ? 'flex' : 'none';
        }
    }

    checkDetailsToggles.forEach(checkbox => {
        checkbox.addEventListener('change', () => toggleDetails(checkbox));
        toggleDetails(checkbox); // Esegui all'avvio per lo stato iniziale
    });

    // -----------------------------------------------------------------
    // 4. Gestione Visibilità Dettagli Location (Anteprima, Casa, Chiesa, Locale)
    // -----------------------------------------------------------------
    function toggleLocationDetail(checkbox) {
        const locationName = checkbox.dataset.locationName;
        const targetElement = locationDetailsContainer.querySelector(`.location-detail-item[data-location-name="${locationName}"]`);
        
        if (targetElement) {
            targetElement.style.display = checkbox.checked ? 'block' : 'none';
        }
        
        // Controlla se almeno un check location (inclusa Anteprima) è attivo per mostrare/nascondere la sezione principale
        const anyLocationChecked = Array.from(checkLocationToggles).some(cb => cb.checked);
        locationDetailsSection.style.display = anyLocationChecked ? 'block' : 'none';
        
        // Se la Anteprima è DISABILITATA (cioè l'evento non è Battesimo/Comunione/18°), la sua sezione NON deve essere nascosta dalla logica del tipo evento.
    }

    checkLocationToggles.forEach(checkbox => {
        checkbox.addEventListener('change', () => toggleLocationDetail(checkbox));
        // Esegui all'avvio per lo stato iniziale di Anteprima
        if (checkbox.dataset.locationName === 'Anteprima') {
            toggleLocationDetail(checkbox);
        }
    });
    
    // -----------------------------------------------------------------
    // 5. Gestione Visibilità Sezioni Check/Location in base al Tipo Evento
    // -----------------------------------------------------------------
    
    function toggleCheckSection() {
        const selectedOption = anagraficaSelect.options[anagraficaSelect.selectedIndex];
        const selectedId = selectedOption ? selectedOption.value : null;
        const isEventWithCheck = eventiConCheck.includes(selectedId);
        
        // 1. Mostra/nascondi la sezione Check
        checkSection.style.display = 'block'; // Mostra sempre la sezione check (Anteprima c'è sempre)
        
        // 2. Mostra/nascondi i check di Casa/Chiesa/Locale
        document.querySelectorAll('.event-location-check').forEach(el => {
            el.style.display = isEventWithCheck ? 'block' : 'none';
        });

        // 3. Logica per la location details section
        let anyLocationChecked = document.getElementById('check_anteprima').checked;
        
        document.querySelectorAll('.check-location-toggle').forEach(checkbox => {
            const locationName = checkbox.dataset.locationName;
            const targetElement = locationDetailsContainer.querySelector(`.location-detail-item[data-location-name="${locationName}"]`);
            
            if (locationName === 'Anteprima') {
                toggleLocationDetail(checkbox); // Anteprima è sempre gestita
                anyLocationChecked = anyLocationChecked || checkbox.checked;
            } else { // Casa, Chiesa, Locale
                if (isEventWithCheck) {
                    toggleLocationDetail(checkbox);
                    anyLocationChecked = anyLocationChecked || checkbox.checked;
                } else {
                    // Nascondi il pannello dei dettagli se l'evento non lo richiede
                    if(targetElement) targetElement.style.display = 'none';
                    checkbox.checked = false; // Reset dei check
                }
            }
        });
        
        // Aggiorna la visibilità della sezione location principale
        locationDetailsSection.style.display = anyLocationChecked ? 'block' : 'none';
    }
    
    checkDetailsToggles.forEach(checkbox => {
        checkbox.addEventListener('change', () => toggleDetails(checkbox));
        toggleDetails(checkbox);
    });

    anagraficaSelect.addEventListener('change', toggleCheckSection);
    toggleCheckSection(); 

    // -----------------------------------------------------------------
    // 6. Logica Aggiunta/Calcolo Servizi Aggiuntivi
    // -----------------------------------------------------------------
    let servizioIndex = <?php echo count($servizi_salvati); ?>; 
    
    const anagraficaServiziData = [ 
        <?php foreach($anagrafica_servizi as $anag_s): ?>
        { id: <?php echo $anag_s['id_anagrafica_servizio']; ?>, nome: '<?php echo htmlspecialchars($anag_s['nome_servizio']); ?>', prezzo: parseFloat('<?php echo $anag_s['prezzo_base']; ?>') },
        <?php endforeach; ?>
    ];

    function calculateTotal(item) {
        const qty = parseFloat(item.querySelector('.servizio-quantita').value) || 0;
        const priceInput = item.querySelector('.servizio-prezzounitario');
        const price = parseFloat(priceInput.value) || 0.00;
        const total = (qty * price).toFixed(2);
        item.querySelector('.total-parziale').textContent = `€ ${total}`;
        
        // ? AGGIUNTA: Aggiorna il costo totale finale
        updateGrandTotal(); 
    }

    function addCalculationListeners(item) {
        item.querySelectorAll('.servizio-quantita, .servizio-prezzounitario').forEach(input => {
            input.addEventListener('input', () => calculateTotal(item));
        });
        
        const anagraficaSelect = item.querySelector('.servizio-anagrafica');
        if (anagraficaSelect) {
            anagraficaSelect.addEventListener('change', () => {
                const selectedOption = anagraficaSelect.options[anagraficaSelect.selectedIndex];
                const priceInput = item.querySelector('.servizio-prezzounitario');
                
                const selectedAnagData = anagraficaServiziData.find(d => d.id == anagraficaSelect.value);
                
                if (selectedAnagData) {
                    priceInput.value = selectedAnagData.prezzo.toFixed(2);
                } else if (anagraficaSelect.value === '') {
                     // Non resettare il valore se l'utente ha già inserito un override manuale
                } else {
                     // Fallback, non dovrebbe succedere
                    priceInput.value = '0.00'; 
                }
                
                calculateTotal(item);
            });
        }
        
        // Aggiungi listener per la rimozione
        const removeBtn = item.querySelector('.remove-servizio-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                item.remove();
                // ? AGGIUNTA: Aggiorna il costo totale finale dopo la rimozione
                updateGrandTotal();
            });
        }
        
        // Calcola il totale iniziale
        calculateTotal(item);
    }
    
    function createServizioField(data = {}) {
        const index = servizioIndex++;
        const item = document.createElement('div');
        item.className = 'row g-3 mb-3 p-3 border rounded bg-white shadow-sm servizio-item';
        
        const isSelected = (id) => (data.id_anagrafica_servizio ?? '') == id ? 'selected' : '';
        const anagraficaOptions = anagraficaServiziData.map(anag_s => 
            `<option value="${anag_s.id}" data-prezzo="${anag_s.prezzo.toFixed(2)}" ${isSelected(anag_s.id)}>${anag_s.nome}</option>`
        ).join('');

        item.innerHTML = `
            <div class="col-md-5">
                 <label class="form-label small text-muted">Servizio Standard</label>
                <select name="servizio[${index}][id_anagrafica_servizio]" class="form-select servizio-anagrafica" ${isAdmin ? '' : 'disabled'}>
                    <option value="">-- Seleziona Servizio Standard --</option>
                    ${anagraficaOptions}
                </select>
            </div>
            <div class="col-md-3">
                 <label class="form-label small text-muted">Descrizione Manuale/Override</label>
                <input type="text" name="servizio[${index}][descrizione_override]" class="form-control" placeholder="Descrizione manuale/override" value="${data.descrizione_override ?? ''}" ${isAdmin ? '' : 'disabled'}>
            </div>
            <div class="col-md-1">
                 <label class="form-label small text-muted">Qtà</label>
                <input type="number" step="1" min="1" name="servizio[${index}][quantita]" class="form-control text-end servizio-quantita" placeholder="Qtà" value="${data.quantita ?? 1}" ${isAdmin ? '' : 'disabled'}>
            </div>
            <div class="col-md-2">
                 <label class="form-label small text-muted">Prezzo Unitario (€)</label>
                <input type="number" step="0.01" min="0.00" name="servizio[${index}][prezzo_unitario]" class="form-control text-end servizio-prezzounitario" placeholder="0.00" value="${data.prezzo_unitario ?? '0.00'}" ${isAdmin ? '' : 'disabled'}>
            </div>
            <div class="col-md-1 d-flex flex-column align-items-center justify-content-end">
                <label class="form-label small text-muted">Totale</label>
                <span class="badge bg-secondary total-parziale mb-2">€ 0.00</span>
                ${isAdmin ? '<button type="button" class="btn btn-sm btn-outline-danger remove-servizio-btn"><i class="bi bi-trash"></i></button>' : ''}
            </div>
        `;
        
        serviziContainer.appendChild(item);
        addCalculationListeners(item);
    }
    
    // Inizializza i listener per i servizi già presenti
    serviziContainer.querySelectorAll('.servizio-item').forEach(item => addCalculationListeners(item));

    // Aggiungi listener al pulsante "Aggiungi Servizio"
    const addServizioBtn = document.getElementById('add_servizio_btn');
    if (addServizioBtn) {
        addServizioBtn.addEventListener('click', () => createServizioField({}));
    }
    
    // ? AGGIUNTA: Chiamata iniziale per calcolare il totale all'apertura della pagina
    updateGrandTotal();
    
    // -----------------------------------------------------------------
    // 7. ? NUOVA LOGICA QUICK ADD CLIENTE (Gestione Provenienza nel Modal)
    // -----------------------------------------------------------------
    (function() {
        const tipologiaSelect = document.getElementById('id_tipologia_provenienza_quick_modal');
        const sorgenteContainer = document.getElementById('sorgente-container-quick-modal');
        const sorgenteSelects = sorgenteContainer.querySelectorAll('.sorgente-select-quick-modal');
        const hiddenAssociativoInput = document.getElementById('id_associativo_hidden_modal');
    
        // Funzione per mostrare/nascondere il selettore di sorgente
        function toggleSorgenteSelect(tipologiaId) {
            const activeOption = tipologiaSelect.querySelector(`option[value="${tipologiaId}"]`);
            const tipoNome = activeOption ? activeOption.dataset.tipo : '';
    
            // 1. Nascondi e disabilita tutti i select sorgente
            sorgenteSelects.forEach(select => {
                select.style.display = 'none';
                select.disabled = true;
                select.removeEventListener('change', onSorgenteChange);
                select.value = ''; // Reset select value
            });
    
            // 2. Mostra e abilita il select corretto
            let activeSelect = null;
            if (tipoNome === 'CLIENTE') {
                activeSelect = document.getElementById('sorgente_CLIENTE_quick_modal');
            } else if (tipoNome === 'PROMOZIONE') {
                activeSelect = document.getElementById('sorgente_PROMOZIONE_quick_modal');
            } 
            
            if (activeSelect) {
                activeSelect.style.display = 'block';
                activeSelect.disabled = false;
                // 3. Aggiungi il listener per il cambio
                activeSelect.addEventListener('change', onSorgenteChange);
                // 4. Aggiorna l'input nascosto con il valore del select ATTIVO
                updateHiddenInput(activeSelect);
            } else {
                 hiddenAssociativoInput.value = '';
            }
        }
    
        // Funzione chiamata quando un select "sorgente" cambia (aggiorna l'hidden input)
        function onSorgenteChange(event) {
            updateHiddenInput(event.target);
        }
        
        // Funzione helper per aggiornare l'input nascosto
        function updateHiddenInput(activeSelect) {
            if (activeSelect && !activeSelect.disabled && activeSelect.value) {
                hiddenAssociativoInput.value = activeSelect.value;
            } else {
                hiddenAssociativoInput.value = '';
            }
        }
    
        // Gestisci il cambio sul select principale (Tipologia)
        tipologiaSelect.addEventListener('change', (e) => {
            // Quando cambio la tipologia, resetto il valore associativo nell'hidden input e nei select
            hiddenAssociativoInput.value = ''; 
            sorgenteSelects.forEach(select => { 
                 select.selectedIndex = 0;
            });
            
            toggleSorgenteSelect(e.target.value);
        });
    
        // Esegui al caricamento della pagina per lo stato iniziale
        toggleSorgenteSelect(tipologiaSelect.value); 
    })();

    // -----------------------------------------------------------------
    // 8. Logica di Validazione Finale (invariata)
    // -----------------------------------------------------------------
    const eventForm = document.getElementById('eventForm');
    
    eventForm.addEventListener('submit', function(event) {
        const selectedStatusId = statoSelect.value;
        const isStamped = (selectedStatusId == statoStampatoId); 

        if (isStamped && isAdmin) {
            const serviziAggiuntiviCount = document.getElementById("servizi_container").querySelectorAll('.servizio-item').length;
            
            if (serviziAggiuntiviCount > 0) {
                const confirmationMessage = 
                    `Hai ${serviziAggiuntiviCount} prodotti/servizi aggiuntivi elencati. ` + 
                    `Hai verificato che siano stati inclusi nell'ordine di stampa per questo evento?`;
                    
                if (!confirm(confirmationMessage)) {
                    event.preventDefault(); 
                    alert("Aggiornamento annullato. Verifica l'ordine di stampa dei servizi aggiuntivi.");
                    return false;
                }
            }
        }
        
        // Se non è admin, e l'operatore è l'assegnato, sblocca i campi per il POST
        if (!isAdmin && isEventOperator) {
            document.querySelector('[name="id_stato"]').disabled = false;
            document.querySelector('[name="descrizione_lavorazione"]').disabled = false;
            document.querySelector('[name="stato_lavorazione_fase"]').disabled = false; 
        }

    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const archiviatoCheckbox = document.getElementById('archiviato');
    const hddBackupContainer = document.getElementById('hdd_backup_container');
    if (archiviatoCheckbox) {
        archiviatoCheckbox.addEventListener('change', () => {
            hddBackupContainer.style.display = archiviatoCheckbox.checked ? 'block' : 'none';
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // VARIABILE: Controlla se è un nuovo evento (ID=0)
    const isNewEvent = <?php echo ($id === 0) ? 'true' : 'false'; ?>;

    // Se non è un nuovo evento, interrompi la funzione di pre-riempimento
    if (!isNewEvent) {
        return;
    }

    const clienteSelect = document.getElementById('cliente_input');
    const anagraficaSelect = document.getElementById('id_anagrafica_evento');
        const dettagliTextarea = document.getElementById('descrizione_lavorazione');
    
    // Funzione per generare il prefisso
    function generatePrefix() {
        const clienteText = clienteSelect.value || '';
        const anagraficaText = anagraficaSelect.options[anagraficaSelect.selectedIndex]?.text || '';

        let prefix = '';
        if (anagraficaText && anagraficaText !== 'Tipo Evento') {
            prefix += ` ${anagraficaText} - #NOME`;
        }
        if (clienteText && clienteText !== 'Seleziona Cliente') {
            if (prefix) prefix += ' ';
            prefix += ` - Rif. ${clienteText}`;
        }
        
        return prefix;
    }
    
    // Funzione per aggiornare i campi Note e Dettagli Evento
    function updateFields() {
		
        const prefix = generatePrefix();
        
        // 2. Aggiorna il campo Dettagli Evento (descrizione_lavorazione)
        if (dettagliTextarea.value.trim() === '' || dettagliTextarea.value.trim().startsWith('') || dettagliTextarea.value.trim().startsWith('')) {
             // Mantieni solo il prefisso.
             dettagliTextarea.value = prefix;
        }
    }

    // Esegui l'aggiornamento iniziale
    if (clienteSelect && anagraficaSelect) {
        updateFields();

        // Aggiungi listener per aggiornare al cambio di selezione
        clienteSelect.addEventListener('change', updateFields);
        anagraficaSelect.addEventListener('change', updateFields);
    }
});


document.addEventListener('DOMContentLoaded', function() {
    const calendarLink = document.getElementById('google-calendar-link');
    const calendarLinkAnteprima = document.getElementById('google-calendar-link-anteprima');
    const calendarLinkConsegna = document.getElementById('google-calendar-link-consegna');
    
    // Listener per l'Evento Principale
    if (calendarLink) {
        calendarLink.addEventListener('click', function(e) {
            e.preventDefault(); 
            const dataEvento = document.getElementById('data_evento').value;
            const orarioEvento = document.getElementById('orario_evento').value;
            createGoogleCalendarEvent(dataEvento, orarioEvento, "Evento Fotografia", "Da Definire");
        });
    }

    // Listener per l'Anteprima
    if (calendarLinkAnteprima) {
        calendarLinkAnteprima.addEventListener('click', function(e) {
            e.preventDefault(); 
            // I campi Anteprima sono specifici
            const dataAnteprima = document.getElementById('data_anteprima').value;
            const orarioAnteprima = document.getElementById('orario_anteprima').value;
            
            if (dataAnteprima) {
                 createGoogleCalendarEvent(dataAnteprima, orarioAnteprima, "Anteprima Evento", "Anteprima");
            } else {
                 alert("Inserisci prima una Data Anteprima.");
            }
        });
    }

    // Listener per la Consegna
    if (calendarLinkConsegna) {
        calendarLinkConsegna.addEventListener('click', function(e) {
            e.preventDefault(); 
            const dataConsegna = document.getElementById('data_consegna').value;
            const orarioConsegna = document.getElementById('orario_consegna').value || "10:00"; // Default se vuoto
            createGoogleCalendarEvent(dataConsegna, orarioConsegna, "Consegna Album", "Consegna");
        });
    }

    /**
     * Crea e apre l'URL di Google Calendar per l'evento.
     * @param {string} date - Data dell'evento (YYYY-MM-DD).
     * @param {string} time - Ora dell'evento (HH:MM).
     * @param {string} baseTitle - Titolo di base dell'evento.
     * @param {string} eventIdentifier - Identificatore (es. 'Anteprima', 'Consegna').
     */
    function createGoogleCalendarEvent(date, time, baseTitle, eventIdentifier) {
        // 1. Recupera i valori CORRENTI dai campi del form
        const dettagliEvento = document.getElementById('descrizione_lavorazione').value.replace('#NOME', '');
        const clientName = document.getElementById('cliente_input').value;
        const eventId = '<?php echo htmlspecialchars($id ?? 'Nuovo'); ?>';
        const eventType = '<?php echo htmlspecialchars($event['nome_evento'] ?? 'Da Definire'); ?>';

        // 2. Costruisci i dati per l'evento
        const fullDateTime = `${date}T${time.substring(0, 5)}:00`;
        const durationMinutes = 60; 

        let title = baseTitle;
        if (clientName) {
            title += `: ${clientName}`;
        }

        const description = `Cliente: ${clientName}.\nTipo: ${eventType}.\nNote: ${dettagliEvento}.\nRiferimento ID: ${eventId}.`;
        
        // 3. Formatta l'URL di Google Calendar
        const baseUrl = 'https://www.google.com/calendar/render?action=TEMPLATE';

        // Parametri URL (codifica per sicurezza)
        const url = `${baseUrl}&text=${encodeURIComponent(title)}` +
                    `&details=${encodeURIComponent(description)}` +
                    `&dates=${getGoogleCalendarDates(fullDateTime, durationMinutes)}`; 

        // 4. Apri l'URL in una nuova scheda
        window.open(url, '_blank');
    }
});

/**
 * Funzione per calcolare le date nel formato richiesto da Google Calendar (YYYYMMDDTHHMMSS/YYYYMMDDTHHMMSSZ)
 * @param {string} startDateTime - Data e ora di inizio nel formato YYYY-MM-DDT-HH:MM:SS
 * @param {number} durationMinutes - Durata dell'evento in minuti
 * @returns {string} La stringa delle date formattata
 */
function getGoogleCalendarDates(startDateTime, durationMinutes) {
    const startDate = new Date(startDateTime);
    
    // Calcola l'ora di fine
    const endDate = new Date(startDate.getTime() + durationMinutes * 60000);

    // Funzione di utilità per formattare la data
    const formatDateTime = (date) => {
        // Formato base: YYYYMMDDTHHMMSS
        const pad = (num) => (num < 10 ? '0' : '') + num;
        
        const year = date.getFullYear();
        const month = pad(date.getMonth() + 1);
        const day = pad(date.getDate());
        const hour = pad(date.getHours());
        const minute = pad(date.getMinutes());
        const second = pad(date.getSeconds());
        
        return `${year}${month}${day}T${hour}${minute}${second}`;
    };

    return `${formatDateTime(startDate)}/${formatDateTime(endDate)}`;
}

</script>