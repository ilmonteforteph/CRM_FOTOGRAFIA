<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$cliente = [
    'id_cliente' => null,
    'nome' => '',
    'cognome' => '',
    'telefono' => '',
    'email' => '',
    'indirizzo' => '',
    'citta' => '',
    'note' => ''
];
$gravidanza = [ 
    'id_gravidanza' => null,
    'settimane_gravidanza_contatto' => null,
    'note_gravidanza' => ''
];
$is_edit = false;
$error = '';
$success = '';

// Variabile per gli eventi del cliente
$eventi_cliente = []; 


// --- CARICAMENTO DATI DI SUPPORTO (Provenienza) ---
$tipologie_provenienza = [];
$clienti_referenti = [];
$promozioni_disponibili = [];
$social_disponibili = []; // <<< NUOVO ARRAY
$provenienza_corrente = [
    'id_tipologia_provenienza' => '',
    'id_associativo' => ''
];

try {
    // 1. Carica le tipologie (CLIENTE, PROMOZIONE, ecc.)
    $tipologie_provenienza = $pdo->query("SELECT id_provenienza, provenienza FROM tipologia_provenienza ORDER BY provenienza")->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Carica i clienti per il referrer
    $clienti_referenti = $pdo->query("SELECT id_cliente, nome, cognome FROM clienti ORDER BY cognome, nome")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Carica le promozioni per il referrer
    $promozioni_disponibili = $pdo->query("SELECT id_promozione, DESCRIZIONE FROM promozioni ORDER BY data_promo DESC")->fetchAll(PDO::FETCH_ASSOC);

    // 4. Carica i social per la provenienza (NUOVA QUERY)
    $social_disponibili = $pdo->query("SELECT id, nome, icona_classe FROM social ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC); // <<< NUOVA QUERY

} catch (PDOException $e) {
    $error = "Attenzione: Errore durante il caricamento dei dati di provenienza. " . htmlspecialchars($e->getMessage());
}
// --- FINE CARICAMENTO DATI DI SUPPORTO ---


// 1. CARICAMENTO DATI ESISTENTI (Modifica)
if (isset($_GET['id'])) {
    $is_edit = true;
    $id_cliente = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM clienti WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $data = $stmt->fetch();

    if ($data) {
        $cliente = $data;

        // --- CARICAMENTO PROVENIENZA ESISTENTE ---
        try {
            $stmt_prov = $pdo->prepare("SELECT id_tipologia_provenienza, id_associativo FROM provenienza_cliente WHERE id_cliente = ?");
            $stmt_prov->execute([$id_cliente]);
            $provenienza_db = $stmt_prov->fetch(PDO::FETCH_ASSOC);
            if ($provenienza_db) {
                $provenienza_corrente = $provenienza_db;
            }
        } catch (PDOException $e) {
            $error = "Errore durante il caricamento della provenienza: " . htmlspecialchars($e->getMessage());
        }
        
        // --- CARICAMENTO ULTIMA GRAVIDANZA NON ASSOCIATA ---
        try {
            // Seleziona l'ultimo record di gravidanza non associato a un evento
            $sql_grav = "
                SELECT 
                    gc1.id_gravidanza, 
                    gc1.settimane_gravidanza_contatto, 
                    gc1.note_gravidanza
                FROM 
                    gravidanze_cliente gc1
                INNER JOIN (
                    SELECT 
                        id_cliente, 
                        MAX(data_creazione) as max_data_creazione
                    FROM 
                        gravidanze_cliente
                    WHERE 
                        id_evento_associato IS NULL 
                        AND id_cliente = :id_cliente 
                    GROUP BY 
                        id_cliente
                ) gc2 ON gc1.id_cliente = gc2.id_cliente AND gc1.data_creazione = gc2.max_data_creazione
                WHERE 
                    gc1.id_cliente = :id_cliente AND gc1.id_evento_associato IS NULL
                ";
            
            $stmt_grav = $pdo->prepare($sql_grav);
            $stmt_grav->execute([':id_cliente' => $id_cliente]);
            $gravidanza_db = $stmt_grav->fetch(PDO::FETCH_ASSOC);
            
            if ($gravidanza_db) {
                // Sovrascrive i valori di default con quelli trovati nel DB
                $gravidanza = [
                    'id_gravidanza' => $gravidanza_db['id_gravidanza'],
                    'settimane_gravidanza_contatto' => $gravidanza_db['settimane_gravidanza_contatto'],
                    'note_gravidanza' => $gravidanza_db['note_gravidanza']
                ];
            }
        } catch (PDOException $e) {
            $error .= " Errore durante il caricamento della gravidanza: " . htmlspecialchars($e->getMessage());
        }
        // --- FINE CARICAMENTO GRAVIDANZA ---
        
        // --- INIZIO CARICAMENTO EVENTI DEL CLIENTE ---
        try {
            $sql_eventi = "
                SELECT 
                    e.id_evento, 
                    e.data_evento, 
                    e.note as titolo, 
                    te.nome_evento AS tipologia_evento
                FROM 
                    eventi e
                JOIN 
                    anagrafica_eventi te ON e.id_anagrafica_evento = te.id_anagrafica_evento
                WHERE 
                    e.id_cliente = ?
                ORDER BY 
                    e.data_evento DESC
                ";
            $stmt_eventi = $pdo->prepare($sql_eventi);
            $stmt_eventi->execute([$id_cliente]);
            $eventi_cliente = $stmt_eventi->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error .= " Errore durante il caricamento degli eventi: " . htmlspecialchars($e->getMessage());
        }
        // --- FINE CARICAMENTO EVENTI DEL CLIENTE ---


    } else {
        $error = "Cliente non trovato.";
        $is_edit = false; 
    }
}

// 2. GESTIONE SUBMIT DEL FORM (Creazione/Modifica)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dati cliente
    $cliente['nome'] = trim($_POST['nome']);
    $cliente['cognome'] = trim($_POST['cognome']);
    $telefono_raw = trim($_POST['telefono']);
    $cliente['telefono'] = str_replace(' ', '', $telefono_raw);
    $cliente['email'] = trim($_POST['email']);
    $cliente['indirizzo'] = trim($_POST['indirizzo']);
    $cliente['citta'] = trim($_POST['citta']);
    $cliente['note'] = trim($_POST['note']);
    $cliente['id_cliente'] = (int)$_POST['id_cliente'] ?? null;

    // Dati gravidanza
    $id_gravidanza_corrente = !empty($_POST['id_gravidanza']) ? (int)$_POST['id_gravidanza'] : null;

    $settimane_gravidanza = !empty($_POST['settimane_gravidanza_contatto']) ? (int)$_POST['settimane_gravidanza_contatto'] : null;
    $note_gravidanza = trim($_POST['note_gravidanza'] ?? '');
    
    // Aggiorna $gravidanza per ripopolare il form in caso di errore
    $gravidanza['id_gravidanza'] = $id_gravidanza_corrente;
    $gravidanza['settimane_gravidanza_contatto'] = $settimane_gravidanza;
    $gravidanza['note_gravidanza'] = $note_gravidanza;

    // Dati provenienza
    $id_tipologia_provenienza = !empty($_POST['id_tipologia_provenienza']) ? (int)$_POST['id_tipologia_provenienza'] : null;
    $id_associativo = !empty($_POST['id_associativo']) ? (int)$_POST['id_associativo'] : null;
    
    // Aggiorna $provenienza_corrente per ripopolare il form in caso di errore
    $provenienza_corrente = ['id_tipologia_provenienza' => $id_tipologia_provenienza, 'id_associativo' => $id_associativo];

    if (empty($cliente['nome'])) {
        $error = "Il campo Nome è obbligatorio.";
    } elseif ($id_tipologia_provenienza && !$id_associativo) {
        $error = "Se è selezionata una provenienza, è obbligatorio specificare anche la fonte.";
    } elseif (!$id_tipologia_provenienza && $id_associativo) {
        $error = "Errore di logica: impossibile salvare una fonte senza una tipologia di provenienza.";
    } elseif ($settimane_gravidanza && ($settimane_gravidanza < 1 || $settimane_gravidanza > 42)) {
         $error = "Le settimane di gravidanza devono essere comprese tra 1 e 42.";
    } else {
        
        // --- Avvia transazione ---
        $pdo->beginTransaction();
        
        try {
            $current_client_id = $cliente['id_cliente'];
            $azione_principale = ''; 

            if ($is_edit && $current_client_id) {
                // Modifica
                $stmt = $pdo->prepare("UPDATE clienti SET nome=?, cognome=?, telefono=?, email=?, indirizzo=?, citta=?, note=? WHERE id_cliente=?");
                $stmt->execute([
                    $cliente['nome'], $cliente['cognome'], $cliente['telefono'], $cliente['email'], $cliente['indirizzo'], $cliente['citta'], $cliente['note'], $current_client_id
                ]);
                $azione_principale = "aggiornato";
            } else {
                // Creazione
                $stmt = $pdo->prepare("INSERT INTO clienti (nome, cognome, telefono, email, indirizzo, citta, note, data_creazione) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $cliente['nome'], $cliente['cognome'], $cliente['telefono'], $cliente['email'], $cliente['indirizzo'], $cliente['citta'], $cliente['note']
                ]);
                $current_client_id = $pdo->lastInsertId(); 
                $cliente['id_cliente'] = $current_client_id;
                $is_edit = true; 
                $azione_principale = "creato";
            }

            // --- LOGICA SALVATAGGIO/AGGIORNAMENTO PROVENIENZA ---
            $stmt_check_prov = $pdo->prepare("SELECT id_provenienza_cliente FROM provenienza_cliente WHERE id_cliente = ?");
            $stmt_check_prov->execute([$current_client_id]);
            $provenienza_esistente = $stmt_check_prov->fetch();

            if ($id_tipologia_provenienza && $id_associativo) {
                if ($provenienza_esistente) {
                    $sql_prov = "UPDATE provenienza_cliente SET id_tipologia_provenienza = ?, id_associativo = ? WHERE id_cliente = ?";
                    $pdo->prepare($sql_prov)->execute([$id_tipologia_provenienza, $id_associativo, $current_client_id]);
                } else {
                    $sql_prov = "INSERT INTO provenienza_cliente (id_cliente, id_tipologia_provenienza, id_associativo) VALUES (?, ?, ?)";
                    $pdo->prepare($sql_prov)->execute([$current_client_id, $id_tipologia_provenienza, $id_associativo]);
                }
            } elseif ($provenienza_esistente) {
                $sql_prov = "DELETE FROM provenienza_cliente WHERE id_cliente = ?";
                $pdo->prepare($sql_prov)->execute([$current_client_id]);
            }
            
            // --- LOGICA SALVATAGGIO GRAVIDANZA (Nuova Logica di Insert con DPP) ---
            
            if ($settimane_gravidanza) {
                // Calcolo DPP: Sottrai i giorni passati (Settimane * 7) e aggiungi 280 (40 settimane totali)
                $giorni_da_aggiungere = 280 - ($settimane_gravidanza * 7);
                $data_creazione = date('Y-m-d H:i:s');
                
                // Calcola la Data Presunta del Parto
                $dpp_date = new DateTime($data_creazione);
                $dpp_date->modify("+$giorni_da_aggiungere days");
                $dpp_formattata = $dpp_date->format('Y-m-d');
                
                // Settimane fornite: INSERISCI SEMPRE UN NUOVO RECORD con la DPP calcolata
                $sql_grav = "INSERT INTO gravidanze_cliente (id_cliente, settimane_gravidanza_contatto, note_gravidanza, data_creazione, id_evento_associato, data_presunta_parto) VALUES (?, ?, ?, ?, NULL, ?)";
                $pdo->prepare($sql_grav)->execute([$current_client_id, $settimane_gravidanza, $note_gravidanza, $data_creazione, $dpp_formattata]);
                
            } elseif ($id_gravidanza_corrente) {
                // Settimane NON fornite E avevamo un ID da eliminare: Elimina il record caricato.
                $sql_grav = "DELETE FROM gravidanze_cliente WHERE id_gravidanza = ?"; 
                $pdo->prepare($sql_grav)->execute([$id_gravidanza_corrente]);

                // Resetta lo stato di visualizzazione
                $gravidanza['settimane_gravidanza_contatto'] = null;
                $gravidanza['note_gravidanza'] = '';
                $gravidanza['id_gravidanza'] = null;
            } 
            // --- FINE LOGICA GRAVIDANZA ---

            // --- Commit della transazione ---
            $pdo->commit();
            $success = "Cliente $azione_principale con successo!";
            
            // Ricarica i dati per l'aggiornamento (necessario ricaricare per gli eventi se è un INSERT)
            if ($azione_principale === "creato") {
                 header("Location: client_form.php?id=$current_client_id&msg=" . urlencode($success));
                 exit;
            }
            // Ricarica i dati di provenienza per il form
            $provenienza_corrente = ['id_tipologia_provenienza' => $id_tipologia_provenienza, 'id_associativo' => $id_associativo];

        } catch (PDOException $e) {
            // --- Rollback in caso di errore ---
            $pdo->rollBack();
            $error = "Errore grave durante il salvataggio: " . htmlspecialchars($e->getMessage());
            $success = '';
        }
    }
}

// Se c'è un messaggio GET (dopo redirect da insert)
if (isset($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
}


require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <h2><i class="bi bi-person-lines-fill me-2"></i><?php echo $is_edit ? 'Modifica Cliente' : 'Nuovo Cliente'; ?></h2>
    </div>
</div>

<hr>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="client_form.php<?php echo $is_edit ? '?id=' . $cliente['id_cliente'] : ''; ?>">
    <input type="hidden" name="id_cliente" value="<?php echo htmlspecialchars($cliente['id_cliente'] ?? ''); ?>">

    <h4 class="mb-3 text-primary"><i class="bi bi-person-vcard me-2"></i>Dati Anagrafici</h4>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="nome" class="form-label">Nome *</label>
            <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($cliente['nome'] ?? ''); ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="cognome" class="form-label">Cognome</label>
            <input type="text" class="form-control" id="cognome" name="cognome" value="<?php echo htmlspecialchars($cliente['cognome'] ?? ''); ?>">
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="telefono" class="form-label">Telefono</label>
            <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8 mb-3">
            <label for="indirizzo" class="form-label">Indirizzo</label>
            <input type="text" class="form-control" id="indirizzo" name="indirizzo" value="<?php echo htmlspecialchars($cliente['indirizzo'] ?? ''); ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label for="citta" class="form-label">Città</label>
            <input type="text" class="form-control" id="citta" name="citta" value="<?php echo htmlspecialchars($cliente['citta'] ?? ''); ?>">
        </div>
    </div>

    <div class="mb-3">
        <label for="note" class="form-label">Note Cliente</label>
        <textarea class="form-control" id="note" name="note" rows="3"><?php echo htmlspecialchars($cliente['note'] ?? ''); ?></textarea>
    </div>

    <hr class="my-4">
    <h4 class="mb-3 text-info"><i class="bi bi-suit-heart-fill me-2"></i>Informazioni Gravidanza</h4>

    <div class="row">
        <div class="col-md-3 mb-3">
            <label for="settimane_gravidanza_contatto" class="form-label">Settimane Gravidanza (Contatto)</label>
            <input type="number" class="form-control" id="settimane_gravidanza_contatto" name="settimane_gravidanza_contatto" min="1" max="42" 
                    placeholder="1-42 (Opz.)"
                    value="<?php echo htmlspecialchars($gravidanza['settimane_gravidanza_contatto'] ?? ''); ?>">
            <small class="form-text text-muted">Inserire qui le settimane registra un **nuovo stato** e calcola la DPP.</small>
        </div>
        <div class="col-md-9 mb-3">
            <label for="note_gravidanza" class="form-label">Note Gravidanza</label>
            <input type="text" class="form-control" id="note_gravidanza" name="note_gravidanza" 
                    value="<?php echo htmlspecialchars($gravidanza['note_gravidanza'] ?? ''); ?>">
        </div>
        <input type="hidden" name="id_gravidanza" value="<?php echo htmlspecialchars($gravidanza['id_gravidanza'] ?? ''); ?>">
    </div>
    <hr class="my-4">
    <h4 class="mb-3 text-success"><i class="bi bi-link-45deg me-2"></i>Acquisizione Cliente</h4>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="id_tipologia_provenienza" class="form-label">Provenienza</label>
            <select class="form-select" id="id_tipologia_provenienza" name="id_tipologia_provenienza">
                <option value="">-- Nessuna --</option>
                <?php foreach ($tipologie_provenienza as $tipo): ?>
                    <?php
                    $provenienza_nome = htmlspecialchars(strtoupper($tipo['provenienza']));
                    $selected = ($tipo['id_provenienza'] == $provenienza_corrente['id_tipologia_provenienza']) ? 'selected' : '';
                    ?>
                    <option value="<?php echo (int)$tipo['id_provenienza']; ?>" 
                            data-tipo="<?php echo $provenienza_nome; // Es: data-tipo="CLIENTE" ?>" 
                            <?php echo $selected; ?>>
                        <?php echo $provenienza_nome; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6 mb-3">
            <label for="id_associativo_select" class="form-label">Fonte specifica</label>
            
            <div id="sorgente-container">

                <select class="form-select sorgente-select" id="sorgente_CLIENTE" style="display: none;" disabled>
                    <option value="">-- Seleziona un cliente --</option>
                    <?php foreach ($clienti_referenti as $cliente_ref): ?>
                        <?php
                        // Evita che un cliente referenzi se stesso
                        if ($is_edit && $cliente_ref['id_cliente'] == $cliente['id_cliente']) continue; 
                        
                        $nome_cliente = htmlspecialchars($cliente_ref['cognome'] . ' ' . $cliente_ref['nome']);
                        // Pre-seleziona se l'ID associativo corrisponde
                        $selected = ($provenienza_corrente['id_associativo'] == $cliente_ref['id_cliente']) ? 'selected' : '';
                        ?>
                        <option value="<?php echo (int)$cliente_ref['id_cliente']; ?>" <?php echo $selected; ?>>
                            <?php echo $nome_cliente; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select class="form-select sorgente-select" id="sorgente_PROMOZIONE" style="display: none;" disabled>
                    <option value="">-- Seleziona una promozione --</option>
                    <?php foreach ($promozioni_disponibili as $promo): ?>
                        <?php
                        $nome_promo = htmlspecialchars($promo['DESCRIZIONE']);
                        // Pre-seleziona se l'ID associativo corrisponde
                        $selected = ($provenienza_corrente['id_associativo'] == $promo['id_promozione']) ? 'selected' : '';
                        ?>
                        <option value="<?php echo (int)$promo['id_promozione']; ?>" <?php echo $selected; ?>>
                            <?php echo $nome_promo; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select class="form-select sorgente-select" id="sorgente_SOCIAL" style="display: none;" disabled>
                    <option value="">-- Seleziona un social network --</option>
                    <?php foreach ($social_disponibili as $social): ?>
                        <?php
                        $nome_social = htmlspecialchars($social['nome']);
                        $icona = !empty($social['icona_classe']) ? '<i class="' . htmlspecialchars($social['icona_classe']) . ' me-1"></i>' : '';
                        // Pre-seleziona se l'ID associativo corrisponde
                        $selected = ($provenienza_corrente['id_associativo'] == $social['id']) ? 'selected' : '';
                        ?>
                        <option value="<?php echo (int)$social['id']; ?>" <?php echo $selected; ?>>
                            <?php echo $icona . $nome_social; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                </div>
            
            <input type="hidden" id="id_associativo" name="id_associativo" 
                    value="<?php echo htmlspecialchars($provenienza_corrente['id_associativo']); ?>">
        </div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill me-1"></i> Salva Cliente</button>
        <a href="clients.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i> Torna alla Lista</a>
    </div>
</form>

<?php if ($is_edit): // Mostra la sezione Eventi solo se si è in modalità modifica ?>

    <hr class="my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 text-dark"><i class="bi bi-calendar-event-fill me-2"></i>Eventi del Cliente</h4>
        <a href="event_form.php?id_cliente=<?php echo $cliente['id_cliente']; ?>" class="btn btn-success">
            <i class="bi bi-plus-circle-fill me-1"></i> Aggiungi Evento
        </a>
    </div>

    <?php if (empty($eventi_cliente)): ?>
        <div class="alert alert-info text-center">
            Nessun evento registrato per questo cliente.
        </div>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($eventi_cliente as $evento): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center shadow-sm mb-2 rounded-3">
                    <div>
                        <span class="badge bg-secondary me-2"><?php echo htmlspecialchars($evento['tipologia_evento']); ?></span>
                        <h6 class="mb-1 d-inline-block">**<?php echo htmlspecialchars($evento['titolo']); ?>**</h6>
                        <p class="mb-0 small text-muted">
                            <i class="bi bi-calendar-check me-1"></i>Data: <?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?>
                        </p>
                    </div>
                    <a href="event_form.php?id=<?php echo $evento['id_evento']; ?>" class="btn btn-outline-primary btn-sm" title="Modifica Evento">
                        <i class="bi bi-pencil-square"></i> Modifica
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipologiaSelect = document.getElementById('id_tipologia_provenienza');
    const sorgenteContainer = document.getElementById('sorgente-container');
    const sorgenteSelects = sorgenteContainer.querySelectorAll('.sorgente-select');
    const hiddenAssociativoInput = document.getElementById('id_associativo');

    function toggleSorgenteSelect(selectedTipoValue) {
        let activeSelect = null;

        // 1. Nascondi e disabilita tutti i select "sorgente"
        sorgenteSelects.forEach(select => {
            select.style.display = 'none';
            select.disabled = true;
        });
        
        if (selectedTipoValue) {
            // 2. Trova l'option selezionata per leggere il data-tipo (es. "CLIENTE", "PROMOZIONE", "SOCIAL")
            const selectedOption = tipologiaSelect.querySelector(`option[value="${selectedTipoValue}"]`);
            if (selectedOption) {
                const tipo = selectedOption.dataset.tipo; // Es. "CLIENTE" o "PROMOZIONE" o "SOCIAL"
                
                // 3. Mostra e abilita il select corrispondente
                activeSelect = document.getElementById(`sorgente_${tipo}`);
                if (activeSelect) {
                    activeSelect.style.display = 'block';
                    activeSelect.disabled = false;
                }
            }
        }

        // 4. Aggiorna l'input nascosto con il valore del select ATTIVO (se c'è una selezione preesistente)
        // Questo è necessario solo al caricamento della pagina se siamo in edit
        updateHiddenInput(activeSelect, true); 

        // 5. Rimuovi e ri-aggiungi il listener per il cambio
        sorgenteSelects.forEach(select => {
            select.removeEventListener('change', onSorgenteChange);
            select.addEventListener('change', onSorgenteChange);
        });
    }

    // Funzione chiamata quando un QUALSIASI select "sorgente" cambia
    function onSorgenteChange(event) {
        updateHiddenInput(event.target);
    }
    
    // Funzione helper per aggiornare l'input nascosto
    function updateHiddenInput(activeSelect, isInitialLoad = false) {
        if (activeSelect && !activeSelect.disabled) {
            // Se è il caricamento iniziale, usa il valore pre-caricato dal PHP
            if (isInitialLoad && hiddenAssociativoInput.value !== '') {
                // Tenta di settare il select visualizzato con il valore pre-caricato
                activeSelect.value = hiddenAssociativoInput.value;
            } else {
                 // Altrimenti (se è un cambio utente), aggiorna l'input nascosto dal select visibile
                 hiddenAssociativoInput.value = activeSelect.value;
            }
        } else {
            // Se nessun select è attivo (provenienza è "-- Nessuna --")
            hiddenAssociativoInput.value = '';
        }
    }

    // Gestisci il cambio sul select principale (Tipologia)
    tipologiaSelect.addEventListener('change', (e) => {
        // Quando cambio la tipologia, resetto il valore associativo PRIMA di chiamare toggleSorgenteSelect
        hiddenAssociativoInput.value = ''; 
        // Pulisco la selezione dei sub-select (rimetto "Seleziona...")
        sorgenteSelects.forEach(select => { select.selectedIndex = 0; });
        
        // Eseguo la visualizzazione del select corretto
        toggleSorgenteSelect(e.target.value);
    });

    // Esegui al caricamento della pagina per gestire lo stato (in caso di modifica o errore POST)
    toggleSorgenteSelect(tipologiaSelect.value);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>