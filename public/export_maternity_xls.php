<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$sql = "
    SELECT c.nome, c.cognome, c.telefono, gc.data_creazione, gc.settimane_gravidanza_contatto,
           e.data_evento,
           DATE_ADD(e.data_evento, INTERVAL (39 - COALESCE(gc.settimane_gravidanza_contatto, 0)) WEEK) AS data_parto_presunta,
           DATEDIFF(CURDATE(), DATE_ADD(e.data_evento, INTERVAL (39 - COALESCE(gc.settimane_gravidanza_contatto, 0)) WEEK)) AS giorni_dal_parto
    FROM eventi e
    JOIN clienti c ON e.id_cliente = c.id_cliente
    LEFT JOIN gravidanze_cliente gc ON c.id_cliente = gc.id_cliente
    WHERE e.id_anagrafica_evento = 1
    ORDER BY (gc.settimane_gravidanza_contatto IS NULL) DESC, giorni_dal_parto ASC";

$clienti = $pdo->query($sql)->fetchAll();

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Report_Maternity_" . date('Y-m-d') . ".xls");

echo "<table border='1'>";
echo "<tr style='background-color: #0d6efd; color: #ffffff;'>
        <th>Cliente</th><th>Data Inserimento</th><th>Settimane</th><th>Età Bebè (Stima)</th><th>Stato Suggerito</th><th>Telefono</th>
      </tr>";

foreach ($clienti as $r) {
    $mancante = is_null($r['settimane_gravidanza_contatto']);
    $giorni = $r['giorni_dal_parto'];
    
    if ($mancante) { 
        $eta = "N.D."; 
        $status = "Dati Mancanti"; 
    } elseif ($giorni < 0) { 
        $eta = "Non ancora nato"; 
        $status = "In attesa parto"; 
    } else { 
        $eta = floor($giorni/30) . " mesi e " . ($giorni % 30) . " gg";
        if ($giorni <= 30) { $status = "Proporre Newborn"; }
        elseif ($giorni <= 180) { $status = "Proporre Sitting"; }
        else { $status = "Proporre 1° Compleanno"; }
    }

    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['nome'] . ' ' . $r['cognome']) . "</td>";
    echo "<td>" . ($r['data_creazione'] ? date('d/m/Y', strtotime($r['data_creazione'])) : 'N/A') . "</td>";
    echo "<td>" . ($mancante ? "MANCANTE" : $r['settimane_gravidanza_contatto']) . "</td>";
    echo "<td>" . $eta . "</td>";
    echo "<td>" . $status . "</td>";
    echo "<td>" . htmlspecialchars($r['telefono'] ?? 'N/A') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>