<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/header.php';

$sql = "
    SELECT 
        c.id_cliente, c.nome, c.cognome, c.telefono,
        e.data_evento,
        gc.settimane_gravidanza_contatto,
        gc.data_creazione as data_inserimento,
        DATE_ADD(e.data_evento, INTERVAL (39 - COALESCE(gc.settimane_gravidanza_contatto, 0)) WEEK) AS data_parto_presunta,
        DATEDIFF(CURDATE(), DATE_ADD(e.data_evento, INTERVAL (39 - COALESCE(gc.settimane_gravidanza_contatto, 0)) WEEK)) AS giorni_dal_parto
    FROM eventi e
    JOIN clienti c ON e.id_cliente = c.id_cliente
    JOIN anagrafica_eventi ae ON e.id_anagrafica_evento = ae.id_anagrafica_evento
    LEFT JOIN gravidanze_cliente gc ON c.id_cliente = gc.id_cliente
    WHERE ae.nome_evento LIKE '%Maternity%'
    ORDER BY giorni_dal_parto DESC, (gc.settimane_gravidanza_contatto IS NULL) DESC";

$clienti = $pdo->query($sql)->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-baby text-primary"></i> CRM Follow-up Maternity</h2>
        <a href="export_maternity_xls.php" class="btn btn-success shadow-sm">
            <i class="bi bi-file-earmark-excel"></i> Esporta Excel Formattato
        </a>
    </div>

    <div class="card shadow-sm rounded-4 border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Cliente</th>
                        <th>Data Inserimento</th>
                        <th>Settimane al Contatto</th>
                        <th>Età Bebè (Stima)</th>
                        <th>Stato Suggerito</th>
                        <th>Telefono</th>
                        <th>WhatsApp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($clienti as $r): 
                        $mancante = is_null($r['settimane_gravidanza_contatto']);
                        $giorni = $r['giorni_dal_parto'];
                        
                        if ($mancante) { 
                            $eta = "N.D."; 
                            $status = "Dati Mancanti"; 
                            $bg = "warning"; 
                        } elseif ($giorni < 0) { 
                            $eta = "Non ancora nato"; 
                            $status = "In attesa parto"; 
                            $bg = "secondary"; 
                        } else { 
                            $eta = floor($giorni/30) . " mesi (" . ($giorni % 30) . " gg)";
                            if ($giorni <= 30) { $status = "Proporre Newborn"; $bg = "danger"; }
                            elseif ($giorni <= 180) { $status = "Proporre Sitting"; $bg = "warning"; }
                            else { $status = "Proporre 1° Compleanno"; $bg = "info"; }
                        }
                    ?>
                    <tr class="<?php echo $mancante ? 'table-warning' : ''; ?>">
                        <td class="fw-bold"><?php echo htmlspecialchars($r['nome'] . ' ' . $r['cognome']); ?></td>
                        <td><?php echo $r['data_inserimento'] ? date('d/m/Y', strtotime($r['data_inserimento'])) : 'N/A'; ?></td>
                        <td><?php echo $mancante ? 'MANCANTE' : $r['settimane_gravidanza_contatto']; ?></td>
                        <td><?php echo $eta; ?></td>
                        <td><span class="badge bg-<?php echo $bg; ?>"><?php echo $status; ?></span></td>
                        <td><?php echo htmlspecialchars($r['telefono'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($r['telefono']): ?>
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $r['telefono']); ?>" target="_blank" class="btn btn-sm btn-success">Contatta</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>