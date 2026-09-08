<?php
// File: public/promozioni_stats.php

// Vengono mantenuti gli include originali
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/header.php';

// Accesso solo per gli amministratori (Admin)
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

// ===================================
// LOGICA DB: STATISTICHE DI CONVERSIONE PROMOZIONI (FOCUS MATERNITY)
// ===================================

$id_tipologia_promozione = 2; // Assumo che '2' sia l'ID per le promozioni
$id_evento_maternity = 1; // Assumo che '1' sia l'ID per l'evento Maternity

$promo_stats = [];
$client_details = [];

if ($id_tipologia_promozione) {
    // PASSO 2: Esegui la query per calcolare Leads, Conversioni Maternity, Incassi, Investimenti e ROI
    
    $sql = "
        SELECT
            p.id_promozione, 
            p.descrizione as nome_promozione,
            COUNT(pc.id_cliente) AS totale_clienti_acquisiti,
            COALESCE(SUM(CASE WHEN E.numero_eventi > 0 THEN 1 ELSE 0 END), 0) AS clienti_maternity_convertiti,
            IFNULL(SUM(E.incasso_eventi), 0) AS incasso_totale_maternity,
						IFNULL(SUM(E2.incasso_potenziale), 0) AS incasso_potenziale,
            -- NUOVO: Calcolo dell'investimento totale (Dalla subquery S)
            IFNULL(S.spesa_totale, 0) AS investimento_totale, 

            -- NUOVO: Calcolo del Ritorno sull'Investimento (ROI)
            (IFNULL(SUM(E.incasso_eventi), 0) - IFNULL(S.spesa_totale, 0)) AS guadagno_netto,
            
            (COALESCE(SUM(CASE WHEN E.numero_eventi > 0 THEN 1 ELSE 0 END), 0) / NULLIF(COUNT(pc.id_cliente), 0)) * 100 AS percentuale_conversione
        FROM
            promozioni p
        INNER JOIN 
            provenienza_cliente pc ON p.id_promozione = pc.id_associativo
        LEFT JOIN 
            (
        SELECT
            e.id_cliente,
            COUNT(1) AS numero_eventi,
            SUM(m.importo) AS incasso_eventi
        FROM
            eventi e
        INNER JOIN 
            movimentazioni m ON e.id_evento = m.id_evento -- Collega Evento e Movimentazione
        WHERE
            e.id_anagrafica_evento = :id_maternity_event
            AND m.tipo = 'entrata'
        GROUP BY
            e.id_cliente
    ) AS E ON pc.id_cliente = E.id_cliente
    LEFT JOIN 
            (
                -- Subquery E: Calcolo Incassi (Eventi Maternity associati al cliente)
                SELECT
                    SUM(e.costo_totale) AS incasso_potenziale
                FROM
                    eventi e
                WHERE
                    e.id_anagrafica_evento = :id_maternity_event
                GROUP BY
                    e.id_cliente
            ) AS E2 ON pc.id_cliente = E.id_cliente
        LEFT JOIN
            (
                -- Subquery S: Calcolo Spese (Movimentazioni di Uscita COLLEGATE ALLA PROMOZIONE)
                SELECT
                    m.id_promozione, -- <<< CORRETTO: Usa la colonna id_promozione da movimentazioni
                    SUM(m.importo) AS spesa_totale
                FROM
                    movimentazioni m
                WHERE
                    m.tipo = 'uscita' 
                    AND m.id_promozione IS NOT NULL -- Filtra solo quelle connesse a una promozione
                GROUP BY
                    m.id_promozione
            ) AS S ON p.id_promozione = S.id_promozione -- <<< CORRETTO: Collega su id_promozione
        WHERE
            pc.id_tipologia_provenienza = :id_promo_type
        GROUP BY
            p.id_promozione, p.descrizione, S.spesa_totale
        ORDER BY
            percentuale_conversione DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id_maternity_event' => $id_evento_maternity,
        'id_promo_type' => $id_tipologia_promozione
    ]);
    $promo_stats = $stmt->fetchAll();


    // PASSO 3: Ottieni i dettagli dei clienti per il follow-up
    $sql_clients = "
        SELECT
            p.id_promozione,
            c.id_cliente,
            c.nome,
            c.cognome,
            c.telefono,
            c.email,
            pc.follow_up_eseguito, 
            EXISTS (
                SELECT 1
                FROM eventi e
                WHERE e.id_cliente = c.id_cliente
                AND e.id_anagrafica_evento = :id_maternity_event
                LIMIT 1
            ) AS is_converted
        FROM
            promozioni p
        INNER JOIN
            provenienza_cliente pc ON p.id_promozione = pc.id_associativo
        INNER JOIN
            clienti c ON pc.id_cliente = c.id_cliente
        WHERE
            pc.id_tipologia_provenienza = :id_promo_type
        ORDER BY
            p.id_promozione, c.data_creazione, c.cognome, c.nome
    ";

    $stmt_clients = $pdo->prepare($sql_clients);
    $stmt_clients->execute([
        'id_maternity_event' => $id_evento_maternity,
        'id_promo_type' => $id_tipologia_promozione
    ]);
    
    // Riorganizza i risultati per id_promozione per facilità di accesso nell'HTML
    while ($row = $stmt_clients->fetch(PDO::FETCH_ASSOC)) {
        $id_promo = $row['id_promozione'];
        if (!isset($client_details[$id_promo])) {
            $client_details[$id_promo] = [];
        }
        $client_details[$id_promo][] = $row;
    }
}

$promo_names = array_column($promo_stats, 'nome_promozione');
$conversion_rates = array_column($promo_stats, 'percentuale_conversione');
?>

<style>
    /* Stile per l'icona del Collapse */
    .toggle-icon {
        transition: transform 0.3s ease-in-out;
    }
    .card-header button:not(.collapsed) .toggle-icon {
        transform: rotate(180deg);
    }
    /* Stile per il checkbox di follow-up */
    .follow-up-checkbox {
        cursor: pointer;
        font-size: 1.25rem;
    }
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-2"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Statistiche Promozioni Maternity</h2>
            <p class="text-muted">Analisi della conversione e incasso: **Eventi Maternity (ID <?php echo $id_evento_maternity; ?>)** per promozione.</p>
            <hr>
        </div>
    </div>

    <?php if (empty($promo_stats)): ?>
        <div class="alert alert-info shadow-sm rounded-4">
            <i class="bi bi-info-circle-fill me-2"></i>Nessuna statistica di conversione Maternity trovata, o la tipologia 'PROMOZIONE' non è configurata correttamente.
        </div>
    <?php else: ?>
    <div class="card shadow-sm rounded-4 p-4 mb-5">
        <h5 class="text-muted mb-4"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Tasso di Conversione Maternity</h5>
        <canvas id="conversionChart" height="150"></canvas>

        <div class="table-responsive mt-5">
            <table class="table table-hover align-middle table-striped table-bordered">
                <thead class="table-primary">
                    <tr>
                        <th>Promozione</th>
                        <th class="text-end">Clienti Acquisiti</th>
                        <th class="text-end">Convertiti Maternity</th>
                        <th class="text-end">Incasso Totale</th>
                        <th class="text-end">Investimento</th> 
                        <th class="text-end">ROI (Guadagno)</th> 
                        <th class="text-end">Incasso Potenziale</th> 
                        <th class="text-end">Tasso di Conversione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totale_incasso_globale = 0;
                    $totale_investimento_globale = 0;
                    $totale_guadagno_globale = 0;
                    
                    foreach($promo_stats as $stat): 
                        $incasso = (float)$stat['incasso_totale_maternity'];
                        $investimento = (float)$stat['investimento_totale'];
                        $guadagno_netto = (float)$stat['guadagno_netto'];
                        $incasso_potenziale = (float)$stat['incasso_potenziale'];

                        $totale_incasso_globale += $incasso;
                        $totale_investimento_globale += $investimento;
                        $totale_guadagno_globale += $guadagno_netto;

                        // Determina la classe di colore per il ROI
                        $roi_class = $guadagno_netto > 0 ? 'text-success' : ($guadagno_netto < 0 ? 'text-danger' : 'text-muted');
                    ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars($stat['nome_promozione']); ?></td>
                        <td class="text-end"><?php echo $stat['totale_clienti_acquisiti']; ?></td>
                        <td class="text-end"><?php echo $stat['clienti_maternity_convertiti']; ?></td>
                        <td class="text-end fw-bold text-success">
                            € <?php echo number_format($incasso, 2, ',', '.'); ?>
                        </td>
                        <td class="text-end text-danger"> 
                            € <?php echo number_format($investimento, 2, ',', '.'); ?>
                        </td>
                        <td class="text-end fw-bold <?php echo $roi_class; ?>"> 
                            € <?php echo number_format($guadagno_netto, 2, ',', '.'); ?>
                        </td>
                        <td class="text-end fw-bold <?php echo $roi_class; ?>"> 
                            € <?php echo number_format($incasso_potenziale, 2, ',', '.'); ?>
                        </td>
                        <td class="text-end fw-bold text-<?php echo $stat['percentuale_conversione'] > 0 ? 'primary' : 'muted'; ?>">
                            <?php echo number_format($stat['percentuale_conversione'], 2, ',', '.'); ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light border-top border-3">
                    <tr>
                        <th colspan="3" class="text-start">Totali (Maternity):</th>
                        <th class="text-end text-success">€ <?php echo number_format($totale_incasso_globale, 2, ',', '.'); ?> (Incasso)</th>
                        <th class="text-end text-danger">€ <?php echo number_format($totale_investimento_globale, 2, ',', '.'); ?> (Invest.)</th>
                        <th class="text-end fw-bold <?php echo $totale_guadagno_globale > 0 ? 'text-success' : ($totale_guadagno_globale < 0 ? 'text-danger' : 'text-muted'); ?>">
                            € <?php echo number_format($totale_guadagno_globale, 2, ',', '.'); ?> (Netto)
                        </th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-5">
        <h3 class="mb-4"><i class="bi bi-person-lines-fill text-secondary me-2"></i>Dettaglio Clienti e Follow-up</h3>
        <?php foreach ($promo_stats as $stat): 
            $id_promozione = $stat['id_promozione'];
            $clients = isset($client_details[$id_promozione]) ? $client_details[$id_promozione] : [];
            $collapse_id = 'clients-' . $id_promozione;
        ?>
        <div class="card mb-3 shadow-sm rounded-4">
            <div class="card-header bg-light p-0 border-0">
                <button class="btn btn-link w-100 text-start text-decoration-none py-3 px-4 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapse_id; ?>" aria-expanded="false" aria-controls="<?php echo $collapse_id; ?>">
                    <i class="bi bi-chevron-down toggle-icon me-2"></i>
                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($stat['nome_promozione']); ?></span> 
                    <span class="badge bg-secondary ms-2"><?php echo count($clients); ?> Leads</span>
                </button>
            </div>
            <div id="<?php echo $collapse_id; ?>" class="collapse" aria-labelledby="headingOne">
                <div class="card-body p-0">
                    <?php if (empty($clients)): ?>
                        <div class="alert alert-warning m-3">Nessun dettaglio cliente trovato per questa promozione.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover m-0">
                                <thead>
                                    <tr class="table-secondary">
                                        <th>Cliente</th>
                                        <th>Contatto</th>
                                        <th class="text-center">Status Maternity</th>
                                        <th class="text-center">Follow-up</th> 
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $client): 
                                        $id_cliente = $client['id_cliente'];
                                        $is_converted = (int)$client['is_converted'];
                                        $follow_up_eseguito = (int)$client['follow_up_eseguito']; 
                                        
                                        $status_text = $is_converted ? 'Prenotato' : 'Non Prenotato';
                                        $status_icon = $is_converted ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                                        
                                        $follow_up_icon = $follow_up_eseguito ? 'bi-check-square-fill text-success' : 'bi-square text-muted';
                                    ?>
                                    <tr class="<?php echo $is_converted ? 'table-light' : 'table-warning'; ?>">
                                        <td>
                                            <a href="client_form.php?id=<?php echo $id_cliente; ?>" class="fw-semibold text-decoration-none">
                                                <?php echo htmlspecialchars($client['nome'] . ' ' . $client['cognome']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($client['telefono'])): ?>
                                                <small class="d-block"><i class="bi bi-telephone-fill me-1"></i> <?php echo htmlspecialchars($client['telefono']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($client['email'])): ?>
                                                <small class="d-block"><i class="bi bi-envelope-fill me-1"></i> <a href="mailto:<?php echo htmlspecialchars($client['email']); ?>"><?php echo htmlspecialchars($client['email']); ?></a></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $is_converted ? 'bg-success' : 'bg-danger'; ?> py-2 px-3">
                                                <i class="bi <?php echo $status_icon; ?> me-1"></i> <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        
                                        <td class="text-center">
                                            <i 
                                                class="bi <?php echo $follow_up_icon; ?> follow-up-checkbox" 
                                                data-id-cliente="<?php echo $id_cliente; ?>"
                                                data-id-promozione="<?php echo $id_promozione; ?>" 
                                                data-current-state="<?php echo $follow_up_eseguito; ?>"
                                                title="Contrassegna follow-up"
                                            ></i>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?php if (!empty($client['telefono'])): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-success me-1 btn-whatsapp"
                                                data-id="<?php echo $id_cliente; ?>"
                                                data-nome="<?php echo htmlspecialchars($client['nome']); ?>"
                                                data-cognome="<?php echo htmlspecialchars($client['cognome']); ?>"
                                                data-telefono="<?php echo htmlspecialchars($client['telefono']); ?>"
                                                data-evento="Maternity (Promo <?php echo htmlspecialchars($stat['nome_promozione']); ?>)"
                                                data-dataevento="" 
                                                data-dataconsegna="" 
                                                data-orarioconsegna="" 
                                                data-orarioevento=""
                                                title="Invia WhatsApp con Template">
                                                <i class="bi bi-whatsapp"></i>
                                            </button>
                                            <?php endif; ?>
                                            <a href="event_form.php?id_cliente=<?php echo $id_cliente; ?>" class="btn btn-sm btn-primary" title="Crea Evento">
                                                <i class="bi bi-calendar-plus"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
        <?php endif; ?>
</div>

<div class="modal fade" id="modalWhatsApp" tabindex="-1" aria-labelledby="modalWhatsAppLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="modalWhatsAppLabel"><i class="bi bi-whatsapp me-2"></i>Invia Messaggio WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Scegli Template:</label>
                    <select id="select-template" class="form-select">
                        <option value="">-- Seleziona un template --</option>
                        <?php
                        // Assumo che $pdo sia disponibile e che la tabella 'messaggi_template' esista
                        try {
                            $templates = $pdo->query("SELECT * FROM messaggi_template WHERE attivo = 1 ORDER BY titolo")->fetchAll();
                            foreach ($templates as $t) {
                                echo '<option value="'.htmlspecialchars($t['messaggio']).'">'.htmlspecialchars($t['titolo']).'</option>';
                            }
                        } catch (PDOException $e) {
                            // In caso di errore DB, la select sarà vuota (gestione silenziosa)
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Anteprima messaggio:</label>
                    <textarea id="msg-preview" class="form-control" rows="5"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <a id="btn-send-wa" href="#" target="_blank" class="btn btn-success disabled">
                    <i class="bi bi-send"></i> Apri WhatsApp Web
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('modalWhatsApp');
    const msgPreview = document.getElementById('msg-preview');
    const selectTemplate = document.getElementById('select-template');
    const btnSend = document.getElementById('btn-send-wa');
    const followUpCheckboxes = document.querySelectorAll('.follow-up-checkbox');

    let currentData = {};

    // Funzione per aggiornare il link WhatsApp
    const updateWhatsAppLink = (text) => {
        const encoded = encodeURIComponent(text);
        const phone = (currentData.telefono || '').replace(/\D/g, '');
        if (phone) {
             btnSend.href = `https://wa.me/${phone}?text=${encoded}`;
             btnSend.classList.remove('disabled');
        } else {
             btnSend.href = "#";
             btnSend.classList.add('disabled');
        }
    };
    
    // Funzione helper per formattare una data da YYYY-MM-DD
    const formatDate = (dateString) => {
        if (!dateString) return '';
        try {
            return new Date(dateString + 'T00:00:00').toLocaleDateString('it-IT');
        } catch {
            return dateString;
        }
    };


    // Quando clicchi il bottone WhatsApp accanto a un cliente
    document.querySelectorAll('.btn-whatsapp').forEach(btn => {
        btn.addEventListener('click', () => {
            currentData = {
                nome: btn.dataset.nome,
                cognome: btn.dataset.cognome,
                telefono: btn.dataset.telefono,
                evento: btn.dataset.evento,
                dataevento: formatDate(btn.dataset.dataevento),
                dataconsegna: formatDate(btn.dataset.dataconsegna),
                orarioevento: btn.dataset.orarioevento || '', 
                orarioconsegna: btn.dataset.orarioconsegna || ''
            };

            msgPreview.value = '';
            selectTemplate.value = '';
            updateWhatsAppLink(''); 

            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    });

    // Quando scegli un template
    selectTemplate.addEventListener('change', () => {
        let text = selectTemplate.value || '';
        // sostituzioni dinamiche
        text = text.replace(/#NomeCliente/g, currentData.nome || '')
                    .replace(/#CognomeCliente/g, currentData.cognome || '')
                    .replace(/#TipoEvento/g, currentData.evento || '')
                    .replace(/#DataEvento/g, currentData.dataevento || '')
                    .replace(/#OrarioEvento/g, currentData.orarioevento || '')
                    .replace(/#DataConsegna/g, currentData.dataconsegna || '')
                    .replace(/#OrarioConsegna/g, currentData.orarioconsegna || ''); 

        msgPreview.value = text;
        updateWhatsAppLink(text);
    });

    // Aggiorna il link ogni volta che il contenuto dell'area di testo cambia
    msgPreview.addEventListener('input', () => {
        updateWhatsAppLink(msgPreview.value);
    });

    // ===================================
    // LOGICA FOLLOW-UP (CHIAMATA AJAX ATTIVA)
    // ===================================

    followUpCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('click', function() {
            const idCliente = this.dataset.idCliente;
            const idPromozione = this.dataset.idPromozione; 
            const currentState = parseInt(this.dataset.currentState);
            const newState = currentState === 1 ? 0 : 1; 
            
            // Variabili per il rollback in caso di errore
            const iconElement = this;
            const previousClass = iconElement.className;
            const previousState = currentState;

            // 1. Aggiorna l'attributo e l'icona (feedback immediato)
            iconElement.dataset.currentState = newState;
            if (newState === 1) {
                iconElement.classList.remove('bi-square', 'text-muted');
                iconElement.classList.add('bi-check-square-fill', 'text-success');
            } else {
                iconElement.classList.remove('bi-check-square-fill', 'text-success');
                iconElement.classList.add('bi-square', 'text-muted');
            }
            
            // 2. CHIAMATA AJAX AL BACKEND PER SALVARE
            fetch('../ajax/update_followup.php', { // <-- PERCORSO CORRETTO
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    id_cliente: idCliente, 
                    id_promozione: idPromozione, 
                    follow_up_eseguito: newState 
                })
            })
            .then(response => {
                // Gestisce errori HTTP (es. 404, 500)
                if (!response.ok) {
                    throw new Error('Errore HTTP: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                // Gestisce errori logici (success: false nel JSON)
                if (!data.success) {
                    console.error('Errore dal server:', data.message);
                    alert('Errore nell\'aggiornamento del follow-up: ' + data.message);
                    // Rollback
                    iconElement.dataset.currentState = previousState;
                    iconElement.className = previousClass; 
                }
            })
            .catch(error => {
                console.error('Errore di rete o server:', error);
                alert('Errore di comunicazione: impossibile salvare lo stato.');
                // Rollback
                iconElement.dataset.currentState = previousState;
                iconElement.className = previousClass; 
            });
        });
    });
    
    // ===================================
    // FINE LOGICA FOLLOW-UP
    // ===================================
});
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const promoNames = <?php echo json_encode($promo_names); ?>;
        // Mappa i tassi di conversione (che sono stringhe o null) a float
        const conversionRates = <?php echo json_encode(array_map(function($rate) {
            return is_numeric($rate) ? (float)$rate : 0;
        }, $conversion_rates)); ?>;

        if (promoNames.length > 0) {
            const conversionChart = new Chart(document.getElementById('conversionChart'), {
                type: 'bar', // Istogramma per confronto immediato
                data: {
                    labels: promoNames,
                    datasets: [{
                        label: 'Tasso di Conversione Maternity (%)',
                        data: conversionRates,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Tasso (%)'
                            }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y.toFixed(2) + '%';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>