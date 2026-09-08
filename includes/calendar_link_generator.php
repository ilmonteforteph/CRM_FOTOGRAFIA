<?php
// includes/calendar_link_generator.php

/**
 * Genera l'URL per aggiungere un evento a Google Calendar.
 * * @param string $title Il titolo dell'evento.
 * @param DateTime $start_datetime L'oggetto DateTime dell'inizio dell'evento (con fuso orario corretto).
 * @param int $duration_minutes La durata dell'evento in minuti.
 * @param string $description La descrizione dell'evento.
 * @param string|null $location La location dell'evento (opzionale).
 * @return string L'URL completo per Google Calendar.
 */
function generate_google_calendar_url(
    $title,
    DateTime $start_datetime,
    $duration_minutes,
    $description = '',
    $location = null
) {
    // Clona l'oggetto per non modificare l'originale
    $data_inizio = clone $start_datetime;
    
    // Calcola la data di fine
    $data_fine = clone $data_inizio;
    $data_fine->modify("+$duration_minutes minutes");

    // Imposta il fuso orario in UTC per il formato Google Calendar (Z sta per Zulu/UTC)
    // Assicurati che $start_datetime sia già nell'ora locale (es. Europe/Rome) prima di chiamare la funzione
    // La conversione a UTC è necessaria per il formato standard di Google Calendar (YYYYMMDDThhmmssZ)
    $data_inizio->setTimezone(new DateTimeZone('UTC'));
    $data_fine->setTimezone(new DateTimeZone('UTC'));

    // Formatta le date nel formato YYYYMMDDThhmmssZ
    $utc_start = $data_inizio->format('Ymd\THis\Z');
    $utc_end = $data_fine->format('Ymd\THis\Z');

    // Costruisci i parametri dell'URL
    $params = [
        'action'    => 'TEMPLATE',
        'text'      => $title,
        'dates'     => $utc_start . '/' . $utc_end,
        'details'   => $description,
        'sf'        => 'true' // Apre l'interfaccia di creazione
    ];
    
    if ($location) {
        $params['location'] = $location;
    }

    // Codifica i parametri e costruisci l'URL finale
    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}
?>