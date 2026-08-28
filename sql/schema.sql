-- Schema database Gestionale Bidelli
-- Istituto Pertini - Reggio Emilia
-- Charset utf8mb4 per corretta gestione caratteri accentati italiani

-- Utenti (DSGA + consultazione)
CREATE TABLE utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    ruolo ENUM('dsga','consultazione') NOT NULL DEFAULT 'consultazione',
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plessi (sedi scolastiche)
CREATE TABLE plessi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    indirizzo VARCHAR(255),
    note TEXT,
    min_bidelli_mattina INT NOT NULL DEFAULT 1,
    min_bidelli_pomeriggio INT NOT NULL DEFAULT 1,
    orario_mattina_inizio TIME,
    orario_mattina_fine TIME,
    orario_pomeriggio_inizio TIME,
    orario_pomeriggio_fine TIME,
    attivo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bidelli (personale ATA)
CREATE TABLE bidelli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    telefono VARCHAR(30),
    email VARCHAR(150),
    plesso_principale_id INT NULL,
    note TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (plesso_principale_id) REFERENCES plessi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Turni: un record = un bidello assegnato a plesso/data/mattina-o-pomeriggio
CREATE TABLE turni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plesso_id INT NOT NULL,
    bidello_id INT NOT NULL,
    data DATE NOT NULL,
    turno_giorno ENUM('mattina','pomeriggio') NOT NULL,
    stato ENUM('pianificato','assente','sostituito') NOT NULL DEFAULT 'pianificato',
    sostituto_di_turno_id INT NULL,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plesso_id) REFERENCES plessi(id) ON DELETE CASCADE,
    FOREIGN KEY (bidello_id) REFERENCES bidelli(id) ON DELETE CASCADE,
    FOREIGN KEY (sostituto_di_turno_id) REFERENCES turni(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_bidello_data_turno (bidello_id, data, turno_giorno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE turni ADD INDEX idx_copertura (plesso_id, data, turno_giorno, stato);
