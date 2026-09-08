<?php
// Dati dell'appuntamento (Esempio)
$titolo = "Appuntamento Fotografico Maternity";
$descrizione = "Cliente Rossi, Pacchetto Base. Contatto: 3331234567";
$data_inizio = new DateTime('2025-11-20 14:00:00', new DateTimeZone('Europe/Rome'));
$durata_minuti = 90;

// Calcola la data di fine
$data_fine = clone $data_inizio;
$data_fine->modify("+$durata_minuti minutes");

// Imposta il fuso orario in UTC per il formato Google Calendar (Z sta per Zulu/UTC)
$data_inizio->setTimezone(new DateTimeZone('UTC'));
$data_fine->setTimezone(new DateTimeZone('UTC'));

// Formatta le date nel formato YYYYMMDDThhmmssZ
$utc_start = $data_inizio->format('Ymd\THis\Z');
$utc_end = $data_fine->format('Ymd\THis\Z');

// Costruisci i parametri dell'URL
$params = [
    'action'    => 'TEMPLATE',
    'text'      => $titolo,
    'dates'     => $utc_start . '/' . $utc_end,
    'details'   => $descrizione,
    // Puoi aggiungere anche 'location' => 'Via delle Rose 10',
    'sf'        => 'true' // Apre l'interfaccia di creazione
];

// Codifica i parametri e costruisci l'URL finale
$calendar_url = 'https://calendar.google.com/calendar/render?' . http_build_query($params);
?>

<a href="<?php echo htmlspecialchars($calendar_url); ?>" target="_blank" class="btn btn-danger">
    <i class="bi bi-calendar-plus-fill me-2"></i> Aggiungi a Google Calendar
</a>