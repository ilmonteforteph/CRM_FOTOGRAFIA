<?php

// Configurazione: modifica con i tuoi dati
define('DB_HOST', '89.46.111.121');
define('DB_NAME', 'Sql1520398_5');
define('DB_USER', 'Sql1520398');
define('DB_PASS', '20g460b77d');



// Inizia sessione
if (session_status() === PHP_SESSION_NONE) session_start();

// Error reporting (disabilitare in produzione)
ini_set('display_errors', 1);
error_reporting(E_ALL);