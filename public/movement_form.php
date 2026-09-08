<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<?php
// public/movement_form.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
$isAdmin = is_admin();
$current_op_id = current_user()['id_operatore'] ?? 0;




$ID_STATO_CONSEGNATO = 0;
$ID_CATEGORIA_SALDO_CLIENTE = null;

// --- CARICAMENTO ID STATI E CATEGORIE NECESSARI ---
try {
    $stmt_status = $pdo->prepare("SELECT id_stato FROM stati_evento WHERE nome_stato = ?");
    $stmt_status->execute(['CONSEGNATO']); // Assicurati di usare il valore corretto dalla tabella
    $result_status = $stmt_status->fetchColumn();
    if ($result_status) {
        $ID_STATO_CONSEGNATO = (int)$result_status;
    }

    // 2. Carica le categorie e cerca l'ID di "Saldo Cliente"
    $categorie = $pdo->query("SELECT id_categoria, nome_categoria FROM categorie_movimento ORDER BY nome_categoria")->fetchAll();
    foreach ($categorie as $cat) {
        // Usa strtolower per una corrispondenza più robusta
        if (strtolower(trim($cat['nome_categoria'])) === 'saldo cliente') {
            $ID_CATEGORIA_SALDO_CLIENTE = (int)$cat['id_categoria'];
            break;
        }
    }
} catch (PDOException $e) {
    error_log("Errore caricamento dati configurazione: " . $e->getMessage());
}
// --- FINE CARICAMENTO ID STATI E CATEGORIE NECESSARI ---


$movimento = [
    'id_movimento' => null,
    'id_evento' => isset($_GET['event_id']) ? (int)$_GET['event_id'] : null,
    'tipo' => 'entrata',
    'importo' => 0.00,
    'descrizione' => '',
    'data_movimento' => date('Y-m-d H:i:s'),
    'id_laboratorio' => null,
    'id_operatore' => $current_op_id,
    'id_categoria' => null,
    'id_promozione' => null, // NUOVO CAMPO
    'metodo_pagamento' => 'Contanti',
    'evento_segna_consegnato' => 0,
    'fattura_caricata' => 0
];

$is_edit = false;
$error = '';
$success = '';

// Variabili per mantenere lo stato dei checkbox nel form
$evento_consegnato = false;
$fattura_caricata = false;

// ❌ RIMOSSO: Il caricamento di tutti gli eventi è stato rimosso.
// Verranno cercati via AJAX.

$laboratori = $pdo->query("SELECT id_laboratorio, nome FROM laboratori ORDER BY nome")->fetchAll();
$operatori = $pdo->query("SELECT id_operatore, nome, cognome FROM operatori ORDER BY cognome, nome")->fetchAll();
$metodi_pagamento = $pdo->query("SELECT nome_metodo FROM metodo_pagamento ORDER BY id_metodo")->fetchAll(PDO::FETCH_COLUMN);
// NUOVO: Caricamento lista promozioni
$promozioni = $pdo->query("SELECT id_promozione, descrizione FROM promozioni ORDER BY descrizione")->fetchAll();


// 1. CARICAMENTO DATI ESISTENTI
if (isset($_GET['id'])) {
    $is_edit = true;
    $id_movimento = (int)$_GET['id'];
    // AGGIUNTO 'id_promozione' alla query
    $stmt = $pdo->prepare("SELECT id_movimento, id_evento, tipo, importo, descrizione, data_movimento, id_laboratorio, id_operatore, id_categoria, id_promozione, metodo_pagamento, evento_segna_consegnato, fattura_caricata FROM movimentazioni WHERE id_movimento = ?");
    $stmt->execute([$id_movimento]);
    $data = $stmt->fetch();

    if ($data) {
        $movimento = $data;
        if (!$isAdmin && $movimento['id_operatore'] != $current_op_id) {
            header('Location: movements.php?error=Non autorizzato a modificare questo movimento.');
            exit;
        }

        // Carica lo stato dei checkbox dal DB
        $evento_consegnato = (bool)$movimento['evento_segna_consegnato'];
        $fattura_caricata = (bool)$movimento['fattura_caricata'];

    } else {
        $error = "Movimento non trovato.";
        $is_edit = false;
    }
}

// 🚨 LOGICA NECESSARIA: Se si è in modifica e l'evento è impostato, si deve caricare il suo dettaglio
// per pre-popolare il campo Select2.
$current_event_data = null;
if ($movimento['id_evento']) {
    $stmt_event = $pdo->prepare("
        SELECT e.id_evento, e.data_evento, e.costo_totale, c.nome AS cliente_nome, c.cognome AS cliente_cognome, a.nome_evento AS evento_descrizione
        FROM eventi e
        LEFT JOIN clienti c ON e.id_cliente = c.id_cliente
        LEFT JOIN anagrafica_eventi a ON e.id_anagrafica_evento = a.id_anagrafica_evento
        WHERE e.id_evento = ?
    ");
    $stmt_event->execute([$movimento['id_evento']]);
    $current_event_data = $stmt_event->fetch();
}


// 2. GESTIONE SUBMIT DEL FORM (Invariata)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2.1 Recupero dati del movimento
    $movimento['id_evento'] = !empty($_POST['id_evento']) ? (int)$_POST['id_evento'] : null;
    $movimento['tipo'] = trim($_POST['tipo']);
    // Normalizzazione dell'input: sostituisce la virgola con il punto prima della conversione
    $movimento['importo'] = (float)str_replace(',', '.', trim($_POST['importo']));
    $movimento['descrizione'] = trim($_POST['descrizione']);
    $movimento['data_movimento'] = trim($_POST['data_movimento']);
    $movimento['id_laboratorio'] = !empty($_POST['id_laboratorio']) ? (int)$_POST['id_laboratorio'] : null;
    $movimento['id_operatore'] = !empty($_POST['id_operatore']) && $isAdmin ? (int)$_POST['id_operatore'] : $current_op_id;
    $movimento['id_categoria'] = !empty($_POST['id_categoria']) ? (int)$_POST['id_categoria'] : null;
    $movimento['id_promozione'] = !empty($_POST['id_promozione']) ? (int)$_POST['id_promozione'] : null; // NUOVO: Recupero id_promozione
    $movimento['metodo_pagamento'] = trim($_POST['metodo_pagamento']);
    $movimento['id_movimento'] = (int)($_POST['id_movimento'] ?? null);


    // 2.2 Recupero e salvataggio dei NUOVI CAMPI
    $movimento['evento_segna_consegnato'] = isset($_POST['evento_segna_consegnato']) ? 1 : 0;
    $evento_consegnato = (bool)$movimento['evento_segna_consegnato'];

    // Recupero il nuovo campo 'fattura_caricata'
    $movimento['fattura_caricata'] = isset($_POST['fattura_caricata']) ? 1 : 0;
    $fattura_caricata = (bool)$movimento['fattura_caricata'];


    // --- NUOVI CONTROLLI DI VALIDAZIONE PRIMA DEL SALVATAGGIO ---
    if ($movimento['evento_segna_consegnato'] === 1 && $error === '') {
        if (!$movimento['id_evento']) {
            $error = "Devi collegare un Evento per segnarlo come Consegnato.";
        } else if ($ID_STATO_CONSEGNATO === 0) {
            $error = "Errore di configurazione: Lo stato 'Consegnato' non è stato trovato nella tabella 'stati_evento'. Contatta l'amministratore.";
        } else if ($ID_CATEGORIA_SALDO_CLIENTE === null) {
            $error = "Errore di configurazione: La categoria 'Saldo Cliente' non è stata trovata nella tabella 'categorie_movimento'. Contatta l'amministratore.";
        }
    }
    // --- FINE CONTROLLI ---


    if (!in_array($movimento['tipo'], ['entrata', 'uscita']) || $movimento['importo'] <= 0) {
        $error = "Tipo e Importo (maggiore di zero) sono obbligatori.";
    } else if ($error === '') { // Procedi solo se non ci sono errori di validazione

        // Avvia la transazione per garantire che sia il movimento sia l'evento vengano aggiornati insieme
        $pdo->beginTransaction();

        try {
            // Preparazione dei parametri di esecuzione (AGGIUNTO id_promozione)
            $params = [
                $movimento['id_evento'],
                $movimento['tipo'],
                $movimento['importo'],
                $movimento['descrizione'],
                $movimento['data_movimento'],
                $movimento['id_laboratorio'],
                $movimento['id_operatore'],
                $movimento['id_categoria'],
                $movimento['id_promozione'], // Parametro NUOVO
                $movimento['metodo_pagamento'],
                $movimento['evento_segna_consegnato'],
                $movimento['fattura_caricata']
            ];

            if ($is_edit && $movimento['id_movimento']) {
                // AGGIORNAMENTO (AGGIUNTO id_promozione)
                $sql = "UPDATE movimentazioni SET id_evento=?, tipo=?, importo=?, descrizione=?, data_movimento=?, id_laboratorio=?, id_operatore=?, id_categoria=?, id_promozione=?, metodo_pagamento=?, evento_segna_consegnato=?, fattura_caricata=? WHERE id_movimento=?";
                $params[] = $movimento['id_movimento'];
                $success = "Movimento aggiornato con successo!";
            } else {
                // INSERIMENTO (AGGIUNTO id_promozione)
                $sql = "INSERT INTO movimentazioni (id_evento, tipo, importo, descrizione, data_movimento, id_laboratorio, id_operatore, id_categoria, id_promozione, metodo_pagamento, evento_segna_consegnato, fattura_caricata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $success = "Movimento creato con successo!";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if (!$is_edit) {
                $movimento['id_movimento'] = $pdo->lastInsertId();
                $is_edit = true;
            }

            // 2.3 LOGICA PER CONSEGNA EVENTO (invariata)
            if ($movimento['evento_segna_consegnato'] === 1) {

                // Verifica se è un Saldo Cliente (Entrata con Categoria Saldo Cliente)
                $is_saldo_cliente = ($movimento['tipo'] === 'entrata' && $movimento['id_categoria'] === $ID_CATEGORIA_SALDO_CLIENTE);

                if ($is_saldo_cliente) {
                    // Aggiorna lo stato dell'evento a "Consegnato"
                    $stmt_update_event = $pdo->prepare("UPDATE eventi SET id_stato = ? WHERE id_evento = ?");
                    $stmt_update_event->execute([$ID_STATO_CONSEGNATO, $movimento['id_evento']]);

                    $success .= " Stato evento aggiornato a 'CONSEGNATO'.";
                } else {
                    $error .= " Attenzione: L'evento può essere segnato come 'Consegnato' solo se il movimento è un'Entrata con categoria 'Saldo Cliente'. Stato evento non aggiornato.";
                }
            }

            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Errore durante il salvataggio: " . htmlspecialchars($e->getMessage());
        }
    }

    if (!$error) {
        header("Location: movement_form.php?id=" . $movimento['id_movimento'] . "&msg=" . urlencode($success));
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <h2><i class="bi bi-cash-stack"></i> <?php echo $is_edit ? 'Modifica Movimento #' . $movimento['id_movimento'] : 'Nuovo Movimento'; ?></h2>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="movement_form.php<?php echo $is_edit ? '?id=' . $movimento['id_movimento'] : ''; ?>">
    <input type="hidden" name="id_movimento" value="<?php echo htmlspecialchars($movimento['id_movimento'] ?? ''); ?>">

    <div class="row mb-3">
        <div class="col-md-3">
            <label for="tipo" class="form-label">Tipo *</label>
            <select class="form-select" id="tipo" name="tipo" required>
                <option value="entrata" <?php echo ($movimento['tipo'] == 'entrata') ? 'selected' : ''; ?>>Entrata</option>
                <option value="uscita" <?php echo ($movimento['tipo'] == 'uscita') ? 'selected' : ''; ?>>Uscita</option>
            </select>
        </div>
        <div class="col-md-3">
            <label for="importo" class="form-label">Importo (€) *</label>
            <input type="number" step="0.01" class="form-control" id="importo" name="importo" value="<?php echo number_format($movimento['importo'], 2, '.', ''); ?>" required min="0.01">
        </div>
        <div class="col-md-6">
            <label for="data_movimento" class="form-label">Data e Ora *</label>
            <input type="datetime-local" class="form-control" id="data_movimento" name="data_movimento" value="<?php echo date('Y-m-d\TH:i:s', strtotime($movimento['data_movimento'])); ?>" required>
        </div>
    </div>

    <div class="mb-3">
        <label for="descrizione" class="form-label">Descrizione</label>
        <input type="text" class="form-control" id="descrizione" name="descrizione" value="<?php echo htmlspecialchars($movimento['descrizione'] ?? ''); ?>" maxlength="255">
    </div>

    <div class="row mb-3">
        <div class="col-md-6 d-flex align-items-end">
            <div class="flex-grow-1">
                <label for="id_evento" class="form-label">Evento Collegato (Cerca Cliente o ID Evento)</label>
                <select class="form-select select2-evento" id="id_evento" name="id_evento">
                    <option value="">Nessun Evento</option>
                    <?php if ($current_event_data):
                        // Pre-popola l'opzione solo in modifica se un evento è selezionato
                        $event_label = '#' . $current_event_data['id_evento'] . ' - ' . htmlspecialchars($current_event_data['cliente_cognome'] . ' ' . $current_event_data['cliente_nome']) . ' | ' . htmlspecialchars($current_event_data['evento_descrizione']) . ' | Data: ' . date('d/m/Y', strtotime($current_event_data['data_evento'])) . ' | €' . number_format($current_event_data['costo_totale'], 2, ',', '.');
                    ?>
                        <option value="<?php echo $current_event_data['id_evento']; ?>" selected="selected">
                            <?php echo $event_label; ?>
                        </option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="ms-2">
                <button type="button" class="btn btn-outline-primary" id="goto_evento" <?php echo empty($movimento['id_evento']) ? 'disabled' : ''; ?>>
                    <i class="bi bi-box-arrow-up-right"></i>
                </button>
            </div>
        </div>

        <div class="col-md-3">
            <label for="id_categoria" class="form-label">Categoria *</label>
            <select class="form-select" id="id_categoria" name="id_categoria" required>
                <option value="">Seleziona Categoria</option>
                <?php foreach($categorie as $cat): ?>
                    <option value="<?php echo $cat['id_categoria']; ?>"
                            data-nome="<?php echo strtolower(htmlspecialchars($cat['nome_categoria'])); ?>"
                            <?php echo ($movimento['id_categoria'] == $cat['id_categoria']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['nome_categoria']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label for="id_promozione" class="form-label">Promozione Collegata</label>
            <select class="form-select" id="id_promozione" name="id_promozione">
                <option value="">Nessuna Promozione</option>
                <?php foreach($promozioni as $p): ?>
                    <option value="<?php echo $p['id_promozione']; ?>" <?php echo ($movimento['id_promozione'] == $p['id_promozione']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['descrizione']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="mb-3 form-check" id="checkbox-consegnato-container">
        <input type="checkbox" class="form-check-input" id="evento_consegnato" name="evento_segna_consegnato"
                value="1"
                <?php echo $evento_consegnato ? 'checked' : ''; ?>
                <?php echo ($movimento['id_evento'] && $movimento['id_categoria'] == $ID_CATEGORIA_SALDO_CLIENTE) ? '' : 'disabled'; ?>>
        <label class="form-check-label" for="evento_consegnato">
            <i class="bi bi-check-circle-fill text-success"></i> Segna Evento come **Consegnato** (Richiede Categoria "Saldo Cliente")
        </label>
    </div>
    <hr>

    <div class="row mb-3">
        <div class="col-md-4">
            <label for="metodo_pagamento" class="form-label">Metodo di Pagamento</label>
            <select class="form-select" id="metodo_pagamento" name="metodo_pagamento">
                <?php foreach($metodi_pagamento as $mp): ?>
                    <option value="<?php echo $mp; ?>" <?php echo ($movimento['metodo_pagamento'] == $mp) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mp); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 d-flex align-items-center" id="fattura_container" style="display: none !important">
            <div class="form-check pt-4">
                <input class="form-check-input" type="checkbox" value="1" id="fattura_caricata" name="fattura_caricata"
                        <?php echo $fattura_caricata ? 'checked' : ''; ?>>
                <label class="form-check-label" for="fattura_caricata">
                    <i class="bi bi-file-earmark-text me-1"></i> Fattura (Fiscale) Caricata
                </label>
            </div>
        </div>

        <div class="col-md-4">
            <label for="id_laboratorio" class="form-label">Laboratorio di Riferimento</label>
            <select class="form-select" id="id_laboratorio" name="id_laboratorio">
                <option value="">Nessun Laboratorio</option>
                <?php foreach($laboratori as $l): ?>
                    <option value="<?php echo $l['id_laboratorio']; ?>" <?php echo ($movimento['id_laboratorio'] == $l['id_laboratorio']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($l['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($isAdmin): ?>
            <div class="col-md-4">
                <label for="id_operatore" class="form-label">Operatore</label>
                <select class="form-select" id="id_operatore" name="id_operatore">
                    <option value="">Nessuno</option>
                    <?php foreach($operatori as $op): ?>
                        <option value="<?php echo $op['id_operatore']; ?>" <?php echo ($movimento['id_operatore'] == $op['id_operatore']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($op['cognome'].' '.$op['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="id_operatore" value="<?php echo $current_op_id; ?>">
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Salva Movimento</button>
    <a href="movements.php" class="btn btn-secondary">Torna alla Lista</a>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectEvento = document.getElementById('id_evento');
    const btnGoto = document.getElementById('goto_evento');
    const selectCategoria = document.getElementById('id_categoria');
    const checkboxConsegnato = document.getElementById('evento_consegnato');
    const containerConsegnato = document.getElementById('checkbox-consegnato-container');
    const selectMetodoPagamento = document.getElementById('metodo_pagamento');
    const fatturaContainer = document.getElementById('fattura_container');

    // 🚨 NUOVA LOGICA: Inizializzazione di Select2 per Eventi
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined') {
        $('.select2-evento').select2({
            placeholder: 'Cerca per ID evento, nome o cognome del cliente...',
            allowClear: true,
            ajax: {
                // *** PERCORSO CORRETTO ***
                url: './ajax/fetch_events.php', 
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term, // Termine di ricerca
                        page: params.page
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return {
                        // Select2 richiede 'results' o 'items' a seconda della configurazione.
                        // Stiamo usando 'items' nel backend PHP e mappandolo qui:
                        results: data.items, 
                        pagination: {
                            more: (params.page * 30) < data.total_count 
                        }
                    };
                },
                cache: true
            },
            minimumInputLength: 2, // Inizia a cercare dopo 2 caratteri
            templateResult: formatEventResult,
            templateSelection: formatEventSelection
        });

        // Funzione per formattare l'opzione nell'elenco a discesa
        function formatEventResult(event) {
            if (event.loading) return event.text;
            return event.text; 
        }

        // Funzione per formattare l'opzione selezionata
        function formatEventSelection(event) {
             if (event.id) {
                return event.text || event.id; 
            }
            return event.text;
        }

        // Sostituisce il vecchio listener 'change' del select nativo
        $('.select2-evento').on('change', function() {
            const isEventSelected = this.value !== '';
            btnGoto.disabled = !isEventSelected;
            toggleConsegnatoCheckbox();
        });

    } else {
        console.error("Libreria Select2 non trovata. Il campo evento non è autoassistito.");
        // Manteniamo il listener del select nativo in caso di fallimento Select2
        selectEvento.addEventListener('change', function() {
            btnGoto.disabled = !this.value;
            toggleConsegnatoCheckbox();
        });
    }
    // 🚨 FINE LOGICA Select2


    // Funzione per abilitare/disabilitare il checkbox Consegnato
    function toggleConsegnatoCheckbox() {
        const isEventSelected = selectEvento.value !== '';

        const selectedOption = selectCategoria.options[selectCategoria.selectedIndex];
        const categoriaNome = selectedOption ? selectedOption.getAttribute('data-nome') : '';
        const isSaldoCliente = categoriaNome === 'saldo cliente';

        const shouldBeEnabled = isEventSelected && isSaldoCliente;

        checkboxConsegnato.disabled = !shouldBeEnabled;

        if (shouldBeEnabled) {
              containerConsegnato.classList.remove('text-muted');
              containerConsegnato.classList.add('text-dark');
        } else {
              containerConsegnato.classList.add('text-muted');
              containerConsegnato.classList.remove('text-dark');
              if (!<?php echo json_encode($is_edit); ?>) {
                  checkboxConsegnato.checked = false;
              }
        }
    }

    // Funzione per mostrare/nascondi checkbox Fattura
    function toggleFatturaCheckbox() {
        // Normalizza il valore per il controllo
        const selectedValue = selectMetodoPagamento.value.toUpperCase();

        // Verifica se il metodo di pagamento è POS o BONIFICO
        if (selectedValue === 'POS' || selectedValue === 'BONIFICO') {
            fatturaContainer.style.display = 'flex'; // Mostra il container
        } else {
            fatturaContainer.style.display = 'none'; // Nasconde il container
            // Se nascosto, forziamo la deselezione della checkbox per evitare salvataggi non voluti
            document.getElementById('fattura_caricata').checked = false;
        }
    }

    // Listener per il pulsante "Vai all'Evento" (Il listener 'change' Select2 è ora prioritario)
    btnGoto.addEventListener('click', function() {
        const eventoId = selectEvento.value;
        if(eventoId) {
            window.open('event_form.php?id=' + eventoId, '_blank');
        }
    });

    // Listener per la Categoria (Invariato)
    selectCategoria.addEventListener('change', toggleConsegnatoCheckbox);

    // Listener per il Metodo di Pagamento (Invariato)
    selectMetodoPagamento.addEventListener('change', toggleFatturaCheckbox);

    // Inizializza all'avvio
    toggleConsegnatoCheckbox();
    toggleFatturaCheckbox();

    // --- LOGICA: Svuota l'input importo al focus (click) ---
    const inputImporto = document.getElementById('importo');

    inputImporto.addEventListener('focus', function() {
        if (this.value === '0.00' || this.value === '0') {
            this.value = '';
        }
        this.select();
    });

    inputImporto.addEventListener('blur', function() {
        if (this.value.trim() === '') {
             this.value = '0.00';
        }
    });
    // --- FINE LOGICA SCRIPT ---

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>