<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
setlocale(LC_TIME, 'it_IT.utf8', 'ita', 'Italian');
require_once __DIR__ . '/../includes/header.php';


function formattaDataItaliana($data, $formato = 'EEEE d MMMM YYYY') {
    $timestamp = is_numeric($data) ? $data : strtotime($data);
    $formatter = new IntlDateFormatter(
        'it_IT',
        IntlDateFormatter::FULL,
        IntlDateFormatter::NONE,
        'Europe/Rome',
        IntlDateFormatter::GREGORIAN,
        $formato
    );
    // Rende la prima lettera maiuscola (es. Lunedì)
    return ucfirst($formatter->format($timestamp));
}

$user = current_user();
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h2 class="mb-2"><i class="bi bi-speedometer2 text-primary me-2"></i>Benvenuto, <?php echo htmlspecialchars($user['user_name'] ?? ''); ?></h2>
            <span class="badge bg-primary fs-6">Ruolo: <?php echo htmlspecialchars($user['user_role'] ?? ''); ?></span>
        </div>
        <hr>
    </div>

    <?php if (is_admin()): ?>

    <?php
    // ===================================
    // SEZIONE: ATTENZIONE FATTURE 
    // ===================================
    $giorni_scadenza = 12;
    $sql_fatture = "
        SELECT 
            m.id_movimento, 
            m.importo, 
            m.data_movimento, 
            m.metodo_pagamento,
            DATEDIFF(DATE_ADD(m.data_movimento, INTERVAL {$giorni_scadenza} DAY), CURDATE()) AS giorni_rimanenti,
            e.id_evento,
            c.cognome AS cliente_cognome,
            c.nome AS cliente_nome
        FROM movimentazioni m
        LEFT JOIN eventi e ON m.id_evento = e.id_evento
        LEFT JOIN clienti c ON e.id_cliente = c.id_cliente
        WHERE m.fattura_caricata = 0 
          AND m.tipo = 'entrata'
          AND m.metodo_pagamento IN ('POS', 'BONIFICO')
        ORDER BY giorni_rimanenti ASC";
    $movimentazioni_non_fatturate = $pdo->query($sql_fatture)->fetchAll();
    $num_non_fatturate = count($movimentazioni_non_fatturate);
    ?>

    <?php if ($num_non_fatturate > 0): ?>
    <div class="alert alert-warning border-warning border-3 rounded-4 shadow-sm mb-5 p-4" id="alert-fatture">
        <h4 class="alert-heading text-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Attenzione Fatturazione!</h4>
        <p>Devi registrare le fatture per **<?php echo $num_non_fatturate; ?>** movimentazioni (metodo **POS** o **BONIFICO**). La scadenza massima è di **<?php echo $giorni_scadenza; ?> giorni** dalla data di movimento.</p>
        <hr>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered align-middle">
                <thead class="table-warning">
                    <tr>
                        <th>ID Mov.</th>
                        <th>Data Mov.</th>
                        <th>Metodo</th>
                        <th class="text-end">Importo</th>
                        <th>Evento/Cliente</th>
                        <th class="text-center">Scadenza (Giorni Rim.)</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody id="movimenti-fatturare">
                    <?php foreach($movimentazioni_non_fatturate as $mov): 
                        $giorni = (int)$mov['giorni_rimanenti'];
                        $classe_colore = '';
                        if ($giorni <= 3) {
                            $classe_colore = 'table-danger fw-bold';
                        } elseif ($giorni <= 7) {
                            $classe_colore = 'table-warning';
                        }
                    ?>
                    <tr id="mov-<?php echo $mov['id_movimento']; ?>" class="<?php echo $classe_colore; ?>">
                        <td><?php echo $mov['id_movimento']; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($mov['metodo_pagamento']); ?></span></td>
                        <td class="text-end">€ <?php echo number_format($mov['importo'], 2, ',', '.'); ?></td>
                        <td>
                            <?php if ($mov['id_evento']): ?>
                                <a href="event_form.php?id=<?php echo $mov['id_evento']; ?>" target="_blank">#<?php echo $mov['id_evento']; ?></a> (<?php echo htmlspecialchars($mov['cliente_cognome'].' '.$mov['cliente_nome']); ?>)
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($giorni < 0): ?>
                                <span class="badge bg-danger">SCADUTO!</span>
                            <?php else: ?>
                                <span class="badge bg-<?php echo $giorni <= 3 ? 'danger' : ($giorni <= 7 ? 'warning' : 'success'); ?>"><?php echo $giorni; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-success btn-fatturato" data-id="<?php echo $mov['id_movimento']; ?>">
                                <i class="bi bi-check-circle"></i> Segna come Fatturato
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <style>
    /* Stili per la nuova sezione */
    .table-danger a, .table-warning a { color: inherit; text-decoration: underline; }
    .table-danger a:hover, .table-warning a:hover { color: #842029; }
    </style>

    <?php
    // ===================================
    // SEZIONE: NUOVI EVENTI PASSATI (id_stato=1, data_evento < CURDATE()) - AGGIUNTA
    // ===================================
    $sql_nuovi_passati = "
        SELECT 
            e.id_evento,
            e.data_evento,
			e.orario_evento,
            e.note AS nome_evento,
            c.cognome AS cliente_cognome,
            c.nome AS cliente_nome,
            ae.nome_evento AS tipo_evento
        FROM eventi e
        JOIN clienti c ON e.id_cliente = c.id_cliente
        JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento
        WHERE e.id_stato = 1 
          AND e.data_evento < CURDATE()
        ORDER BY e.data_evento,e.orario_evento";
    $eventi_nuovi_passati = $pdo->query($sql_nuovi_passati)->fetchAll();
    $num_nuovi_passati = count($eventi_nuovi_passati);
    ?>

    <?php if ($num_nuovi_passati > 0): ?>
    <div class="alert alert-danger border-danger border-3 rounded-4 shadow-sm mb-5 p-4" id="alert-nuovi-passati">
        <h4 class="alert-heading text-danger"><i class="bi bi-calendar-x-fill me-2"></i>Eventi Nuovi in data passata</h4>
        <p>Ci sono **<?php echo $num_nuovi_passati; ?>** eventi in stato **NUOVO EVENTO** la cui data è **precedente ad oggi**. Potrebbe essere necessario portarli in stato **DA LAVORARE**.</p>
        <hr>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered align-middle">
                <thead class="table-danger">
                    <tr>
                        <th>ID Evento</th>
                        <th>Data Evento</th>
						<th>Orario Evento</th>
                        <th>Tipo Evento</th>
                        <th>Cliente</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody id="eventi-da-lavorare">
                    <?php foreach($eventi_nuovi_passati as $ev_passato): ?>
                    <tr id="event-<?php echo $ev_passato['id_evento']; ?>">
                        <td><?php echo $ev_passato['id_evento']; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($ev_passato['data_evento'])); ?></td>
                        <td><?php echo substr($ev_passato['orario_evento'], 0, 5); ?></td>
						<td><?php echo htmlspecialchars($ev_passato['tipo_evento']); ?></td>
                        <td>
                            <a href="client_form.php?id=<?php echo $ev_passato['id_cliente']; ?>" target="_blank"><?php echo htmlspecialchars($ev_passato['cliente_cognome'].' '.$ev_passato['cliente_nome']); ?></a>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-info btn-da-lavorare" data-id="<?php echo $ev_passato['id_evento']; ?>">
                                <i class="bi bi-pencil-square"></i> Segna DA LAVORARE (ID 7)
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
	
	<?php
// ===========================================================
// NUOVA SEZIONE: ANALISI ECONOMICA PER TIPOLOGIA EVENTO
// ===========================================================
$sql_tipologie = "
    SELECT 
        ae.nome_evento AS tipologia,
        COUNT(DISTINCT e.id_evento) AS totale_eventi,
        SUM(CASE WHEN e.data_evento < CURDATE() THEN 1 ELSE 0 END) AS conclusi,
        SUM(CASE WHEN e.data_evento >= CURDATE() THEN 1 ELSE 0 END) AS da_fare,
        COALESCE(SUM(CASE WHEN m.tipo = 'entrata' THEN m.importo ELSE 0 END), 0) AS ricavi_totali,
        COALESCE(SUM(CASE WHEN m.tipo = 'uscita' THEN m.importo ELSE 0 END), 0) AS costi_totali,
        (COALESCE(SUM(CASE WHEN m.tipo = 'entrata' THEN m.importo ELSE 0 END), 0) - 
         COALESCE(SUM(CASE WHEN m.tipo = 'uscita' THEN m.importo ELSE 0 END), 0)) AS profitto_totale
    FROM anagrafica_eventi ae
    LEFT JOIN eventi e ON ae.id_anagrafica_evento = e.id_anagrafica_evento
    LEFT JOIN movimentazioni m ON e.id_evento = m.id_evento
    GROUP BY ae.id_anagrafica_evento, ae.nome_evento
    ORDER BY ricavi_totali DESC";

$analisi_tipologie = $pdo->query($sql_tipologie)->fetchAll();
?>
    
    <?php
    // =========================================================
    // SEZIONE: EVENTI IMMINENTI (ENTRO 7 GIORNI) - NUOVA SEZIONE
    // =========================================================
    $giorni_imminenti = 7;
    $today = date('Y-m-d');
    $data_limite = date('Y-m-d', strtotime("+$giorni_imminenti days"));

    // Query per eventi.data_evento, eventi.data_consegna e dettagli_location_evento.data_specifica
    $sql_imminenti = "
        SELECT
            e.id_evento,
            c.cognome AS cliente_cognome,
            c.nome AS cliente_nome,
            ae.nome_evento AS tipo_evento,
            'Evento Principale' AS tipo_impegno,
            e.data_evento AS data_impegno,
			e.orario_evento as orario_evento,
            e.note
        FROM eventi e
        JOIN clienti c ON e.id_cliente = c.id_cliente
        JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento
        WHERE e.id_stato NOT IN (5, 6, 8) -- Esclude: Annullato, Archiviato, Completato
          AND e.data_evento >= CURDATE() AND e.data_evento <= DATE_ADD(CURDATE(), INTERVAL {$giorni_imminenti} DAY)

        UNION ALL

        SELECT
            e.id_evento,
            c.cognome AS cliente_cognome,
            c.nome AS cliente_nome,
            ae.nome_evento AS tipo_evento,
            'Consegna/Ritiro' AS tipo_impegno,
            e.data_consegna AS data_impegno,
			e.orario_evento as orario_evento,
            e.note
        FROM eventi e
        JOIN clienti c ON e.id_cliente = c.id_cliente
        JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento
        WHERE e.id_stato NOT IN (5, 6, 8)
          AND e.data_consegna IS NOT NULL 
          AND e.data_consegna >= CURDATE() AND e.data_consegna <= DATE_ADD(CURDATE(), INTERVAL {$giorni_imminenti} DAY)
          AND e.data_consegna != e.data_evento -- Evita duplicati se data evento = data consegna

        UNION ALL

        SELECT
            e.id_evento,
            c.cognome AS cliente_cognome,
            c.nome AS cliente_nome,
            ae.nome_evento AS tipo_evento,
            CONCAT('Dettaglio: ', dle.nome_location) AS tipo_impegno,
            dle.data_specifica AS data_impegno,
			e.orario_evento as orario_evento,
            e.note
        FROM eventi e
        JOIN clienti c ON e.id_cliente = c.id_cliente
        JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento
        JOIN dettagli_location_evento dle ON e.id_evento = dle.id_evento
        WHERE e.id_stato NOT IN (5, 6, 8)
          AND dle.data_specifica >= CURDATE() AND dle.data_specifica <= DATE_ADD(CURDATE(), INTERVAL {$giorni_imminenti} DAY)

        ORDER BY data_impegno ASC, orario_evento";

    $impegni_imminenti = $pdo->query($sql_imminenti)->fetchAll();
    $num_impegni = count($impegni_imminenti);

    // Raggruppa per data
    $impegni_raggruppati = [];
    foreach ($impegni_imminenti as $impegno) {
        $data = $impegno['data_impegno'];
        if (!isset($impegni_raggruppati[$data])) {
            $impegni_raggruppati[$data] = [];
        }
        $impegni_raggruppati[$data][] = $impegno;
    }
    // Ordina le date in modo crescente
    ksort($impegni_raggruppati);
    ?>

    <div class="mb-5">
        <h3 class="text-secondary mb-3"><i class="bi bi-calendar-week text-info me-2"></i>📅 Impegni Imminenti (Prossimi <?php echo $giorni_imminenti; ?> giorni)</h3>
        <?php if ($num_impegni > 0): ?>
            <?php foreach ($impegni_raggruppati as $data => $impegni_del_giorno): 
                $oggi_str = date('Y-m-d');
                $domani_str = date('Y-m-d', strtotime('+1 day'));
                
                if ($data == $oggi_str) {
                    $giorno_label = 'Oggi, ' . date('d/m/Y', strtotime($data));
                    $data_colore = 'primary';
                } elseif ($data == $domani_str) {
                    $giorno_label = 'Domani, ' . date('d/m/Y', strtotime($data));
                    $data_colore = 'success';
                } else {
                    //$giorno_label = date('l d/m/Y', strtotime($data)); // Mostra il giorno della settimana
					$giorno_label = formattaDataItaliana($data, 'EEEE d/MM/YYYY');
                    $data_colore = 'secondary';
                }
            ?>
            <div class="card shadow-sm rounded-4 mb-3 border-<?php echo $data_colore; ?> border-2">
                <div class="card-header bg-<?php echo $data_colore; ?> text-white fw-bold">
                    <?php echo $giorno_label; ?> <span class="badge bg-light text-<?php echo $data_colore; ?> ms-2"><?php echo count($impegni_del_giorno); ?> Impegno/i</span>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach($impegni_del_giorno as $impegno): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-dot me-2 text-<?php echo $data_colore; ?>"></i>
                                **<?php echo htmlspecialchars($impegno['tipo_impegno']); ?>** <span class="badge bg-light text-dark">
                                    <?php echo htmlspecialchars($impegno['tipo_evento']); ?>
                                </span>
                                &mdash; Cliente: <?php echo htmlspecialchars($impegno['cliente_cognome'].' '.$impegno['cliente_nome'].' --> '.substr($impegno['orario_evento'], 0, 5)); ?>
                            </div>
                            <a href="event_form.php?id=<?php echo $impegno['id_evento']; ?>" class="btn btn-sm btn-outline-<?php echo $data_colore; ?>">
                                <i class="bi bi-eye"></i> Vedi Evento #<?php echo $impegno['id_evento']; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info rounded-4 shadow-sm"><i class="bi bi-check-circle-fill me-2"></i>Nessun impegno imminente (prossimi <?php echo $giorni_imminenti; ?> giorni).</div>
        <?php endif; ?>
    </div>
    <hr>
    
    <?php
    // ==========================
    // SEZIONE: STATISTICHE BASE
    // ==========================
    $totClienti = $pdo->query("SELECT COUNT(*) FROM clienti")->fetchColumn();
    $totEventi = $pdo->query("SELECT COUNT(*) FROM eventi")->fetchColumn();
    $totMov = $pdo->query("SELECT COUNT(*) FROM movimentazioni")->fetchColumn();
    $totPromozioni = $pdo->query("SELECT COUNT(*) FROM promozioni")->fetchColumn();
    ?>
    <div class="row g-4 mb-5">
        <?php
        $cards = [
            ['Clienti Totali', $totClienti, 'bi-person-badge-fill', 'primary', 'clients.php'],
            ['Eventi Registrati', $totEventi, 'bi-calendar-event-fill', 'success', 'events.php'],
            ['Movimentazioni Totali', $totMov, 'bi-box-seam-fill', 'info', 'movements.php'],
            ['Promozioni Totali', $totPromozioni, 'bi-megaphone-fill', 'warning', 'promozioni.php'],
        ];
        foreach ($cards as [$label, $val, $icon, $color, $link]): ?>
        <div class="col-md-3">
            <div class="card p-3 shadow-sm border-<?php echo $color; ?> border-3 rounded-4 statistic-card">
                <div class="d-flex align-items-center">
                    <i class="bi <?php echo $icon; ?> text-<?php echo $color; ?> display-5 me-3"></i>
                    <div>
                        <h6 class="text-muted mb-1"><?php echo $label; ?></h6>
                        <h3 class="fw-bold text-<?php echo $color; ?>"><?php echo $val; ?></h3>
                    </div>
                </div>
                <a href="<?php echo $link; ?>" class="btn btn-sm btn-outline-<?php echo $color; ?> mt-3 stretched-link">Apri</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    // ==========================
    // SEZIONE: REDDITIVITÀ EVENTI
    // ==========================
    $sql = "
        SELECT e.id_evento, e.note as nome_evento,
               COALESCE(SUM(CASE WHEN m.tipo = 'entrata' THEN m.importo ELSE 0 END), 0) AS ricavi,
               COALESCE(SUM(CASE WHEN m.tipo = 'uscita' THEN m.importo ELSE 0 END), 0) AS costi,
               (COALESCE(SUM(CASE WHEN m.tipo = 'entrata' THEN m.importo ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN m.tipo = 'uscita' THEN m.importo ELSE 0 END), 0)) AS profitto
        FROM eventi e
        LEFT JOIN movimentazioni m ON e.id_evento = m.id_evento
        GROUP BY e.id_evento
        ORDER BY profitto DESC
        LIMIT 10";
    $topEventi = $pdo->query($sql)->fetchAll();

    $totali = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN tipo='entrata' THEN importo ELSE 0 END), 0) AS tot_ricavi,
            COALESCE(SUM(CASE WHEN tipo='uscita' THEN importo ELSE 0 END), 0) AS tot_costi
        FROM movimentazioni
    ")->fetch();

    $totRicavi = $totali['tot_ricavi'] ?? 0;
    $totCosti = $totali['tot_costi'] ?? 0;
    $totProfitto = $totRicavi - $totCosti;
    ?>

    <h4 class="text-muted mb-3"><i class="bi bi-cash-coin text-secondary me-2"></i>Redditività Eventi</h4>
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-success border-3 shadow-sm rounded-4 p-3 text-center">
                <i class="bi bi-arrow-down-circle-fill text-success display-5 mb-2"></i>
                <h6 class="text-muted">Totale Ricavi</h6>
                <h3 class="fw-bold text-success">€ <?php echo number_format($totRicavi, 2, ',', '.'); ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-danger border-3 shadow-sm rounded-4 p-3 text-center">
                <i class="bi bi-arrow-up-circle-fill text-danger display-5 mb-2"></i>
                <h6 class="text-muted">Totale Costi</h6>
                <h3 class="fw-bold text-danger">€ <?php echo number_format($totCosti, 2, ',', '.'); ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-primary border-3 shadow-sm rounded-4 p-3 text-center">
                <i class="bi bi-graph-up-arrow text-primary display-5 mb-2"></i>
                <h6 class="text-muted">Profitto Netto</h6>
                <h3 class="fw-bold text-primary">€ <?php echo number_format($totProfitto, 2, ',', '.'); ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5" style="display:none">
        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 p-4 h-100">
                <h6 class="text-secondary mb-3"><i class="bi bi-trophy-fill text-warning me-2"></i>Top 10 Eventi più Redditizi</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-warning">
                            <tr><th>Evento</th><th class="text-end">Ricavi</th><th class="text-end">Costi</th><th class="text-end">Profitto</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($topEventi as $e): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($e['nome_evento']); ?></td>
                                <td class="text-end text-success">€ <?php echo number_format($e['ricavi'], 2, ',', '.'); ?></td>
                                <td class="text-end text-danger">€ <?php echo number_format($e['costi'], 2, ',', '.'); ?></td>
                                <td class="text-end fw-bold text-<?php echo $e['profitto'] >= 0 ? 'primary' : 'danger'; ?>">€ <?php echo number_format($e['profitto'], 2, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 p-4 h-100">
                <h6 class="text-secondary mb-3"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Profitto per Evento</h6>
                <canvas id="profitChart" height="220"></canvas>
            </div>
        </div>
    </div>

    <?php
    // ==========================
    // SEZIONE: TREND MENSILE
    // ==========================
    $trend = $pdo->query("
        SELECT DATE_FORMAT(data_movimento, '%Y-%m') AS mese,
               SUM(CASE WHEN tipo='entrata' THEN importo ELSE 0 END) AS ricavi,
               SUM(CASE WHEN tipo='uscita' THEN importo ELSE 0 END) AS costi
        FROM movimentazioni
        WHERE data_movimento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY mese
        ORDER BY mese ASC
    ")->fetchAll();
    ?>

    <div class="card shadow-sm rounded-4 p-4 mb-5">
        <h5 class="text-muted mb-4"><i class="bi bi-graph-up text-success me-2"></i>Andamento Mensile Ricavi e Costi</h5>
        <canvas id="trendChart" height="120"></canvas>
    </div>

    <?php
    // ==========================
    // SEZIONE: PROFITTO PER OPERATORE
    // ==========================
    $byOp = $pdo->query("
        SELECT o.nome as nome_operatore, 
               SUM(CASE WHEN m.tipo='entrata' THEN m.importo ELSE 0 END) -
               SUM(CASE WHEN m.tipo='uscita' THEN m.importo ELSE 0 END) AS profitto
        FROM eventi e
        LEFT JOIN movimentazioni m ON e.id_evento = m.id_evento
        LEFT JOIN operatori o ON e.id_operatore = o.id_operatore
        GROUP BY o.id_operatore
        ORDER BY profitto DESC
    ")->fetchAll();
    ?>
	
	<div class="row mb-5">
    <div class="col-12">
        <h3 class="text-secondary mb-3"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Resoconto per Tipologia Evento</h3>
    </div>
    
    <div class="col-lg-8">
        <div class="card shadow-sm rounded-4 border-0">
            <div class="table-responsive p-3">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tipologia</th>
                            <th class="text-center">Eventi (Fatti/Tot)</th>
                            <th class="text-end">Ricavi</th>
                            <th class="text-end">Costi</th>
                            <th class="text-end">Profitto</th>
                            <th class="text-end">Media Profitto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($analisi_tipologie as $t): 
                            $margine_medio = $t['totale_eventi'] > 0 ? $t['profitto_totale'] / $t['totale_eventi'] : 0;
                        ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($t['tipologia']); ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $t['conclusi']; ?></span> / <b><?php echo $t['totale_eventi']; ?></b>
                                <br><small class="text-primary"><?php echo $t['da_fare']; ?> da fare</small>
                            </td>
                            <td class="text-end text-success">€ <?php echo number_format($t['ricavi_totali'], 2, ',', '.'); ?></td>
                            <td class="text-end text-danger">€ <?php echo number_format($t['costi_totali'], 2, ',', '.'); ?></td>
                            <td class="text-end fw-bold">€ <?php echo number_format($t['profitto_totale'], 2, ',', '.'); ?></td>
                            <td class="text-end"><span class="badge bg-light text-dark">€ <?php echo number_format($margine_medio, 2, ',', '.'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card shadow-sm rounded-4 border-0 p-3 h-100">
            <h6 class="text-center text-muted">Distribuzione Ricavi per Tipologia</h6>
            <canvas id="tipologiaPieChart"></canvas>
        </div>
    </div>
</div>

    <div class="card shadow-sm rounded-4 p-4 mb-4">
        <h5 class="text-muted mb-4"><i class="bi bi-people-fill text-info me-2"></i>Profitto Medio per Operatore</h5>
        <canvas id="opChart" height="120"></canvas>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Inizializzazione Grafici
    const profitChart = new Chart(document.getElementById('profitChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($topEventi, 'nome_evento')); ?>,
            datasets: [{
                label: 'Profitto (€)',
                data: <?php echo json_encode(array_map('floatval', array_column($topEventi, 'profitto'))); ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.6)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    const trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($trend, 'mese')); ?>,
            datasets: [
                { label: 'Ricavi', data: <?php echo json_encode(array_column($trend, 'ricavi')); ?>, borderColor: 'rgba(25, 135, 84, 1)', fill: false, tension: 0.3 },
                { label: 'Costi', data: <?php echo json_encode(array_column($trend, 'costi')); ?>, borderColor: 'rgba(220, 53, 69, 1)', fill: false, tension: 0.3 }
            ]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });

    const opChart = new Chart(document.getElementById('opChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($byOp, 'nome_operatore')); ?>,
            datasets: [{ label: 'Profitto (€)', data: <?php echo json_encode(array_map('floatval', array_column($byOp, 'profitto'))); ?>, backgroundColor: 'rgba(0, 123, 255, 0.7)', borderRadius: 6 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
    
    // ===================================
    // LOGICA AJAX PER L'AGGIORNAMENTO FATTURA 
    // ===================================
    document.querySelectorAll('.btn-fatturato').forEach(button => {
        button.addEventListener('click', function() {
            const movId = this.dataset.id;
            const row = document.getElementById(`mov-${movId}`);

            if (confirm(`Confermi di voler segnare il Movimento #${movId} come 'Fatturato'?`)) {
                // Esegue la chiamata AJAX
                fetch('update_invoice_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id_movimento=${movId}&fattura_caricata=1`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Rimuove la riga dalla tabella
                        row.remove();
                        // Aggiorna il conteggio nell'intestazione
                        let alertElement = document.getElementById('alert-fatture');
                        if (alertElement) {
                            let pTag = alertElement.querySelector('p:first-of-type');
                            let currentCountMatch = pTag.textContent.match(/\d+/);
                            let currentCount = currentCountMatch ? parseInt(currentCountMatch[0]) : 1;
                            currentCount--;

                            pTag.textContent = `Devi registrare le fatture per **${currentCount}** movimentazioni (metodo **POS** o **BONIFICO**). La scadenza massima è di 12 giorni dalla data di movimento.`;
                            
                            // Se non ci sono più movimenti, nasconde l'intera sezione di avviso
                            if (currentCount <= 0) {
                                alertElement.remove();
                            }
                        }
                    } else {
                        alert(`Errore durante l'aggiornamento: ${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert("Si è verificato un errore di connessione.");
                });
            }
        });
    });
    // ===================================
    // FINE LOGICA AJAX FATTURA
    // ===================================

    // ===================================
    // LOGICA AJAX PER L'AGGIORNAMENTO STATO EVENTO (NUOVA)
    // ===================================
    document.querySelectorAll('.btn-da-lavorare').forEach(button => {
        button.addEventListener('click', function() {
            const eventId = this.dataset.id;
            const row = document.getElementById(`event-${eventId}`);

            if (confirm(`Confermi di voler cambiare lo stato dell'Evento #${eventId} in 'DA LAVORARE' (ID 7)?`)) {
                // Esegue la chiamata AJAX per aggiornare lo stato
                fetch('update_event_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    // Imposta id_stato a 7 (DA LAVORARE)
                    body: `id_evento=${eventId}&id_stato=7` 
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Rimuove la riga dalla tabella
                        row.remove();
                        
                        let alertElement = document.getElementById('alert-nuovi-passati');
                        if (alertElement) {
                            // Aggiorna il conteggio nell'intestazione
                            let pTag = alertElement.querySelector('p:first-of-type');
                            let currentCountMatch = pTag.textContent.match(/\d+/);
                            let currentCount = currentCountMatch ? parseInt(currentCountMatch[0]) : 1;
                            currentCount--;

                            pTag.textContent = `Ci sono **${currentCount}** eventi in stato NUOVO EVENTO (ID 1) la cui data è precedente ad oggi. Potrebbe essere necessario portarli in stato DA LAVORARE (ID 7).`;
                            
                            // Se non ci sono più movimenti, nasconde l'intera sezione di avviso
                            if (currentCount <= 0) {
                                alertElement.remove();
                            }
                        }
                    } else {
                        alert(`Errore durante l'aggiornamento: ${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert("Si è verificato un errore di connessione.");
                });
            }
        });
    });
    // ===================================
    // FINE LOGICA AJAX STATO EVENTO
    // ===================================
	
	const ctxTipologia = document.getElementById('tipologiaPieChart').getContext('2d');
new Chart(ctxTipologia, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($analisi_tipologie, 'tipologia')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($analisi_tipologie, 'ricavi_totali')); ?>,
            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6610f2', '#fd7e14', '#adb5bd'],
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
	
    </script>

    <?php else: ?>
    <?php
    $id_op = (int)($user['id_operatore'] ?? 0);
    $stmt = $pdo->prepare("SELECT e.*, c.nome AS cliente_nome, c.cognome AS cliente_cognome, ae.nome_evento as tipo_evento, se.nome_stato as stato_nome FROM eventi e LEFT JOIN clienti c ON e.id_cliente = c.id_cliente JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento JOIN stati_evento se ON e.id_stato = se.id_stato WHERE e.id_operatore = ? ORDER BY e.data_evento DESC LIMIT 10");
    $stmt->execute([$id_op]);
    $myEvents = $stmt->fetchAll();
    ?>
    <h4><i class="bi bi-calendar-week text-primary me-1"></i>I tuoi eventi recenti</h4>
    <div class="card shadow-sm rounded-4 mt-3">
        <div class="card-body">
            <?php if(empty($myEvents)): ?>
                <div class="alert alert-warning"><i class="bi bi-info-circle-fill me-2"></i>Nessun evento assegnato.</div>
            <?php else: ?>
                <table class="table table-hover align-middle">
                    <thead class="table-primary"><tr><th>Data</th><th>Tipo Evento</th><th>Cliente</th><th>Stato</th><th>Azioni</th></tr></thead>
                    <tbody>
                        <?php foreach($myEvents as $ev): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($ev['data_evento'])); ?></td>
                                <td><?php echo htmlspecialchars($ev['tipo_evento']); ?></td>
                                <td><?php echo htmlspecialchars($ev['cliente_nome'].' '.$ev['cliente_cognome']); ?></td>
                                <td><?php echo htmlspecialchars($ev['stato_nome']); ?></td>
                                <td><a href="event_form.php?id=<?php echo $ev['id_evento']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Vedi</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.statistic-card { transition: transform 0.2s ease-in-out; }
.statistic-card:hover { transform: translateY(-4px); }
canvas { width: 100% !important; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>