-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 89.46.111.121
-- Creato il: Set 18, 2026 alle 08:13
-- Versione del server: 5.7.43-47-log
-- Versione PHP: 8.0.7

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `Sql1520398_5`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `anagrafica_eventi`
--

CREATE TABLE `anagrafica_eventi` (
  `id_anagrafica_evento` int(11) NOT NULL,
  `nome_evento` varchar(100) NOT NULL,
  `descrizione` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `anagrafica_formati_album`
--

CREATE TABLE `anagrafica_formati_album` (
  `id_formato` int(11) NOT NULL,
  `nome_formato` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `anagrafica_servizi`
--

CREATE TABLE `anagrafica_servizi` (
  `id_anagrafica_servizio` int(11) NOT NULL,
  `nome_servizio` varchar(100) NOT NULL,
  `descrizione_standard` text,
  `prezzo_base` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_attivo` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `anagrafica_tipologia_album`
--

CREATE TABLE `anagrafica_tipologia_album` (
  `id_tipologia` int(11) NOT NULL,
  `nome_tipologia` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `categorie_movimento`
--

CREATE TABLE `categorie_movimento` (
  `id_categoria` int(11) NOT NULL,
  `nome_categoria` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `clienti`
--

CREATE TABLE `clienti` (
  `id_cliente` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cognome` varchar(100) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `indirizzo` varchar(255) DEFAULT NULL,
  `citta` varchar(100) DEFAULT NULL,
  `note` text,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `exported_to_google_contacts` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `dettagli_gravidanza`
--

CREATE TABLE `dettagli_gravidanza` (
  `id_dettaglio_gravidanza` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `settimane_gravidanza` int(2) NOT NULL COMMENT 'Numero di settimane di gestazione'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `dettagli_location_evento`
--

CREATE TABLE `dettagli_location_evento` (
  `id_dettaglio_location` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `nome_location` enum('Chiesa','Casa','Locale','Anteprima','Altro') NOT NULL,
  `indirizzo` varchar(255) DEFAULT NULL,
  `orario_inizio` time DEFAULT NULL,
  `note_location` text,
  `data_specifica` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `eventi`
--

CREATE TABLE `eventi` (
  `id_evento` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_anagrafica_evento` int(11) NOT NULL,
  `id_operatore` int(11) DEFAULT NULL,
  `data_evento` date NOT NULL,
  `data_consegna` date DEFAULT NULL,
  `costo_totale` decimal(10,2) DEFAULT '0.00',
  `id_stato` int(11) DEFAULT '1',
  `note` text,
  `stato_lavorazione_fase` varchar(255) DEFAULT NULL,
  `descrizione_lavorazione` text,
  `id_laboratorio` int(11) DEFAULT NULL,
  `check_casa` tinyint(1) NOT NULL DEFAULT '0',
  `check_chiesa` tinyint(1) NOT NULL DEFAULT '0',
  `check_location` tinyint(1) NOT NULL DEFAULT '0',
  `check_album` tinyint(1) NOT NULL DEFAULT '0',
  `id_formato_album` int(11) DEFAULT NULL,
  `id_tipologia_album` int(11) DEFAULT NULL,
  `check_poster` tinyint(1) DEFAULT '0',
  `quantita_poster` int(11) DEFAULT NULL,
  `check_segnaposti` tinyint(1) DEFAULT '0',
  `quantita_segnaposti` int(11) DEFAULT NULL,
  `check_anteprima` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Flag 1 se Anteprima è spuntata',
  `data_stampa` date DEFAULT NULL,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `path_google_drive` varchar(512) DEFAULT NULL COMMENT 'Path o link della cartella su Google Drive per l evento',
  `archiviato` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Flag per indicare se i file sono stati archiviati su backup esterno',
  `hdd_backup` varchar(100) DEFAULT NULL COMMENT 'Nome/ID dell HDD esterno utilizzato per il backup',
  `orario_evento` time DEFAULT NULL COMMENT 'Orario di inizio dell''evento',
  `orario_consegna` time DEFAULT NULL COMMENT 'Orario di consegna concordato per l''album'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `gravidanze_cliente`
--

CREATE TABLE `gravidanze_cliente` (
  `id_gravidanza` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `settimane_gravidanza_contatto` int(2) DEFAULT NULL COMMENT 'Settimane di gestazione al momento del primo contatto/registrazione',
  `data_creazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note_gravidanza` text,
  `id_evento_associato` int(11) DEFAULT NULL,
  `data_presunta_parto` date DEFAULT NULL COMMENT 'Data calcolata automaticamente a 40 settimane (280 giorni) dalla data di concepimento stimata.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `laboratori`
--

CREATE TABLE `laboratori` (
  `id_laboratorio` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `indirizzo` varchar(255) DEFAULT NULL,
  `citta` varchar(100) DEFAULT NULL,
  `note` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `log_attivita`
--

CREATE TABLE `log_attivita` (
  `id_log` int(11) NOT NULL,
  `tabella` varchar(100) DEFAULT NULL,
  `id_record` int(11) DEFAULT NULL,
  `azione` varchar(50) DEFAULT NULL,
  `utente` varchar(100) DEFAULT NULL,
  `data_log` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `messaggi_template`
--

CREATE TABLE `messaggi_template` (
  `id_template` int(11) NOT NULL,
  `titolo` varchar(100) NOT NULL,
  `messaggio` text NOT NULL,
  `attivo` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `metodo_pagamento`
--

CREATE TABLE `metodo_pagamento` (
  `id_metodo` int(11) NOT NULL,
  `nome_metodo` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `movimentazioni`
--

CREATE TABLE `movimentazioni` (
  `id_movimento` int(11) NOT NULL,
  `id_evento` int(11) DEFAULT NULL,
  `tipo` enum('entrata','uscita') NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `descrizione` varchar(255) DEFAULT NULL,
  `data_movimento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_laboratorio` int(11) DEFAULT NULL,
  `id_operatore` int(11) DEFAULT NULL,
  `id_categoria` int(11) DEFAULT NULL,
  `id_promozione` int(11) DEFAULT NULL,
  `metodo_pagamento` varchar(50) DEFAULT NULL,
  `evento_segna_consegnato` tinyint(1) NOT NULL DEFAULT '0',
  `fattura_caricata` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Flag 1 se la fattura è stata caricata (rilevante per POS/Bonifico).'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `operatori`
--

CREATE TABLE `operatori` (
  `id_operatore` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cognome` varchar(100) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `ruolo_aziendale` varchar(100) DEFAULT NULL,
  `note` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `promozioni`
--

CREATE TABLE `promozioni` (
  `id_promozione` int(11) NOT NULL,
  `DESCRIZIONE` varchar(255) NOT NULL,
  `IMPORTO_SPESO` decimal(10,2) NOT NULL,
  `PORTALE` varchar(100) DEFAULT NULL,
  `data_promo` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `provenienza_cliente`
--

CREATE TABLE `provenienza_cliente` (
  `id_provenienza_cliente` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `id_tipologia_provenienza` int(11) NOT NULL,
  `id_associativo` int(11) DEFAULT NULL,
  `follow_up_eseguito` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=Non eseguito, 1=Eseguito su questo specifico lead'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `servizi_aggiuntivi_evento`
--

CREATE TABLE `servizi_aggiuntivi_evento` (
  `id_servizio_evento` int(11) NOT NULL,
  `id_evento` int(11) NOT NULL,
  `id_anagrafica_servizio` int(11) DEFAULT NULL,
  `quantita` int(11) NOT NULL DEFAULT '1',
  `descrizione_override` varchar(255) DEFAULT NULL,
  `prezzo_unitario` decimal(10,2) DEFAULT '0.00',
  `totale_parziale` decimal(10,2) GENERATED ALWAYS AS ((`quantita` * `prezzo_unitario`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `social`
--

CREATE TABLE `social` (
  `id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `icona_classe` varchar(100) DEFAULT NULL COMMENT 'Classe per l''icona (es. da Bootstrap Icons o Font Awesome)'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `stati_evento`
--

CREATE TABLE `stati_evento` (
  `id_stato` int(11) NOT NULL,
  `nome_stato` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `tipologia_provenienza`
--

CREATE TABLE `tipologia_provenienza` (
  `id_provenienza` int(11) NOT NULL,
  `provenienza` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `users`
--

CREATE TABLE `users` (
  `id_user` int(11) NOT NULL,
  `id_operatore` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `ruolo` enum('admin','operatore') NOT NULL DEFAULT 'operatore',
  `email` varchar(150) DEFAULT NULL,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `anagrafica_eventi`
--
ALTER TABLE `anagrafica_eventi`
  ADD PRIMARY KEY (`id_anagrafica_evento`);

--
-- Indici per le tabelle `anagrafica_formati_album`
--
ALTER TABLE `anagrafica_formati_album`
  ADD PRIMARY KEY (`id_formato`),
  ADD UNIQUE KEY `nome_formato` (`nome_formato`);

--
-- Indici per le tabelle `anagrafica_servizi`
--
ALTER TABLE `anagrafica_servizi`
  ADD PRIMARY KEY (`id_anagrafica_servizio`),
  ADD UNIQUE KEY `nome_servizio` (`nome_servizio`);

--
-- Indici per le tabelle `anagrafica_tipologia_album`
--
ALTER TABLE `anagrafica_tipologia_album`
  ADD PRIMARY KEY (`id_tipologia`),
  ADD UNIQUE KEY `nome_tipologia` (`nome_tipologia`);

--
-- Indici per le tabelle `categorie_movimento`
--
ALTER TABLE `categorie_movimento`
  ADD PRIMARY KEY (`id_categoria`);

--
-- Indici per le tabelle `clienti`
--
ALTER TABLE `clienti`
  ADD PRIMARY KEY (`id_cliente`),
  ADD KEY `idx_exported_gc` (`exported_to_google_contacts`);

--
-- Indici per le tabelle `dettagli_gravidanza`
--
ALTER TABLE `dettagli_gravidanza`
  ADD PRIMARY KEY (`id_dettaglio_gravidanza`),
  ADD UNIQUE KEY `uk_evento_gravidanza` (`id_evento`);

--
-- Indici per le tabelle `dettagli_location_evento`
--
ALTER TABLE `dettagli_location_evento`
  ADD PRIMARY KEY (`id_dettaglio_location`),
  ADD KEY `id_evento` (`id_evento`);

--
-- Indici per le tabelle `eventi`
--
ALTER TABLE `eventi`
  ADD PRIMARY KEY (`id_evento`),
  ADD KEY `id_cliente` (`id_cliente`),
  ADD KEY `id_anagrafica_evento` (`id_anagrafica_evento`),
  ADD KEY `id_operatore` (`id_operatore`),
  ADD KEY `id_stato` (`id_stato`),
  ADD KEY `eventi_ibfk_5` (`id_laboratorio`),
  ADD KEY `fk_eventi_formato_album` (`id_formato_album`),
  ADD KEY `fk_eventi_tipologia_album` (`id_tipologia_album`);

--
-- Indici per le tabelle `gravidanze_cliente`
--
ALTER TABLE `gravidanze_cliente`
  ADD PRIMARY KEY (`id_gravidanza`),
  ADD KEY `idx_cliente_gravidanza` (`id_cliente`);

--
-- Indici per le tabelle `laboratori`
--
ALTER TABLE `laboratori`
  ADD PRIMARY KEY (`id_laboratorio`);

--
-- Indici per le tabelle `log_attivita`
--
ALTER TABLE `log_attivita`
  ADD PRIMARY KEY (`id_log`);

--
-- Indici per le tabelle `messaggi_template`
--
ALTER TABLE `messaggi_template`
  ADD PRIMARY KEY (`id_template`);

--
-- Indici per le tabelle `metodo_pagamento`
--
ALTER TABLE `metodo_pagamento`
  ADD PRIMARY KEY (`id_metodo`),
  ADD UNIQUE KEY `nome_metodo` (`nome_metodo`);

--
-- Indici per le tabelle `movimentazioni`
--
ALTER TABLE `movimentazioni`
  ADD PRIMARY KEY (`id_movimento`),
  ADD KEY `id_evento` (`id_evento`),
  ADD KEY `id_laboratorio` (`id_laboratorio`),
  ADD KEY `id_operatore` (`id_operatore`),
  ADD KEY `id_categoria` (`id_categoria`),
  ADD KEY `fk_movimentazioni_promozioni` (`id_promozione`);

--
-- Indici per le tabelle `operatori`
--
ALTER TABLE `operatori`
  ADD PRIMARY KEY (`id_operatore`);

--
-- Indici per le tabelle `promozioni`
--
ALTER TABLE `promozioni`
  ADD PRIMARY KEY (`id_promozione`);

--
-- Indici per le tabelle `provenienza_cliente`
--
ALTER TABLE `provenienza_cliente`
  ADD PRIMARY KEY (`id_provenienza_cliente`),
  ADD UNIQUE KEY `uk_cliente_provenienza` (`id_cliente`),
  ADD KEY `id_tipologia_provenienza` (`id_tipologia_provenienza`);

--
-- Indici per le tabelle `servizi_aggiuntivi_evento`
--
ALTER TABLE `servizi_aggiuntivi_evento`
  ADD PRIMARY KEY (`id_servizio_evento`),
  ADD KEY `fk_sae_evento` (`id_evento`),
  ADD KEY `fk_sae_anagrafica` (`id_anagrafica_servizio`);

--
-- Indici per le tabelle `social`
--
ALTER TABLE `social`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indici per le tabelle `stati_evento`
--
ALTER TABLE `stati_evento`
  ADD PRIMARY KEY (`id_stato`);

--
-- Indici per le tabelle `tipologia_provenienza`
--
ALTER TABLE `tipologia_provenienza`
  ADD PRIMARY KEY (`id_provenienza`),
  ADD UNIQUE KEY `provenienza` (`provenienza`);

--
-- Indici per le tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `id_operatore` (`id_operatore`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `anagrafica_eventi`
--
ALTER TABLE `anagrafica_eventi`
  MODIFY `id_anagrafica_evento` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `anagrafica_formati_album`
--
ALTER TABLE `anagrafica_formati_album`
  MODIFY `id_formato` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `anagrafica_servizi`
--
ALTER TABLE `anagrafica_servizi`
  MODIFY `id_anagrafica_servizio` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `anagrafica_tipologia_album`
--
ALTER TABLE `anagrafica_tipologia_album`
  MODIFY `id_tipologia` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `categorie_movimento`
--
ALTER TABLE `categorie_movimento`
  MODIFY `id_categoria` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `clienti`
--
ALTER TABLE `clienti`
  MODIFY `id_cliente` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `dettagli_gravidanza`
--
ALTER TABLE `dettagli_gravidanza`
  MODIFY `id_dettaglio_gravidanza` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `dettagli_location_evento`
--
ALTER TABLE `dettagli_location_evento`
  MODIFY `id_dettaglio_location` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `eventi`
--
ALTER TABLE `eventi`
  MODIFY `id_evento` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `gravidanze_cliente`
--
ALTER TABLE `gravidanze_cliente`
  MODIFY `id_gravidanza` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `laboratori`
--
ALTER TABLE `laboratori`
  MODIFY `id_laboratorio` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `log_attivita`
--
ALTER TABLE `log_attivita`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `messaggi_template`
--
ALTER TABLE `messaggi_template`
  MODIFY `id_template` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `metodo_pagamento`
--
ALTER TABLE `metodo_pagamento`
  MODIFY `id_metodo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `movimentazioni`
--
ALTER TABLE `movimentazioni`
  MODIFY `id_movimento` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `operatori`
--
ALTER TABLE `operatori`
  MODIFY `id_operatore` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `promozioni`
--
ALTER TABLE `promozioni`
  MODIFY `id_promozione` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `provenienza_cliente`
--
ALTER TABLE `provenienza_cliente`
  MODIFY `id_provenienza_cliente` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `servizi_aggiuntivi_evento`
--
ALTER TABLE `servizi_aggiuntivi_evento`
  MODIFY `id_servizio_evento` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `social`
--
ALTER TABLE `social`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `stati_evento`
--
ALTER TABLE `stati_evento`
  MODIFY `id_stato` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `tipologia_provenienza`
--
ALTER TABLE `tipologia_provenienza`
  MODIFY `id_provenienza` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `users`
--
ALTER TABLE `users`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `dettagli_gravidanza`
--
ALTER TABLE `dettagli_gravidanza`
  ADD CONSTRAINT `fk_gravidanza_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventi` (`id_evento`) ON DELETE CASCADE;

--
-- Limiti per la tabella `dettagli_location_evento`
--
ALTER TABLE `dettagli_location_evento`
  ADD CONSTRAINT `dettagli_location_evento_ibfk_1` FOREIGN KEY (`id_evento`) REFERENCES `eventi` (`id_evento`) ON DELETE CASCADE;

--
-- Limiti per la tabella `eventi`
--
ALTER TABLE `eventi`
  ADD CONSTRAINT `eventi_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clienti` (`id_cliente`) ON DELETE CASCADE,
  ADD CONSTRAINT `eventi_ibfk_2` FOREIGN KEY (`id_anagrafica_evento`) REFERENCES `anagrafica_eventi` (`id_anagrafica_evento`),
  ADD CONSTRAINT `eventi_ibfk_3` FOREIGN KEY (`id_operatore`) REFERENCES `operatori` (`id_operatore`) ON DELETE SET NULL,
  ADD CONSTRAINT `eventi_ibfk_4` FOREIGN KEY (`id_stato`) REFERENCES `stati_evento` (`id_stato`),
  ADD CONSTRAINT `eventi_ibfk_5` FOREIGN KEY (`id_laboratorio`) REFERENCES `laboratori` (`id_laboratorio`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_eventi_formato_album` FOREIGN KEY (`id_formato_album`) REFERENCES `anagrafica_formati_album` (`id_formato`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_eventi_tipologia_album` FOREIGN KEY (`id_tipologia_album`) REFERENCES `anagrafica_tipologia_album` (`id_tipologia`) ON DELETE SET NULL;

--
-- Limiti per la tabella `gravidanze_cliente`
--
ALTER TABLE `gravidanze_cliente`
  ADD CONSTRAINT `fk_gravidanza_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clienti` (`id_cliente`) ON DELETE CASCADE;

--
-- Limiti per la tabella `movimentazioni`
--
ALTER TABLE `movimentazioni`
  ADD CONSTRAINT `fk_movimentazioni_promozioni` FOREIGN KEY (`id_promozione`) REFERENCES `promozioni` (`id_promozione`) ON DELETE SET NULL,
  ADD CONSTRAINT `movimentazioni_ibfk_1` FOREIGN KEY (`id_evento`) REFERENCES `eventi` (`id_evento`) ON DELETE SET NULL,
  ADD CONSTRAINT `movimentazioni_ibfk_2` FOREIGN KEY (`id_laboratorio`) REFERENCES `laboratori` (`id_laboratorio`) ON DELETE SET NULL,
  ADD CONSTRAINT `movimentazioni_ibfk_3` FOREIGN KEY (`id_operatore`) REFERENCES `operatori` (`id_operatore`) ON DELETE SET NULL,
  ADD CONSTRAINT `movimentazioni_ibfk_4` FOREIGN KEY (`id_categoria`) REFERENCES `categorie_movimento` (`id_categoria`) ON DELETE SET NULL;

--
-- Limiti per la tabella `provenienza_cliente`
--
ALTER TABLE `provenienza_cliente`
  ADD CONSTRAINT `provenienza_cliente_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clienti` (`id_cliente`) ON DELETE CASCADE,
  ADD CONSTRAINT `provenienza_cliente_ibfk_2` FOREIGN KEY (`id_tipologia_provenienza`) REFERENCES `tipologia_provenienza` (`id_provenienza`);

--
-- Limiti per la tabella `servizi_aggiuntivi_evento`
--
ALTER TABLE `servizi_aggiuntivi_evento`
  ADD CONSTRAINT `fk_sae_anagrafica` FOREIGN KEY (`id_anagrafica_servizio`) REFERENCES `anagrafica_servizi` (`id_anagrafica_servizio`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sae_evento` FOREIGN KEY (`id_evento`) REFERENCES `eventi` (`id_evento`) ON DELETE CASCADE;

--
-- Limiti per la tabella `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`id_operatore`) REFERENCES `operatori` (`id_operatore`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
