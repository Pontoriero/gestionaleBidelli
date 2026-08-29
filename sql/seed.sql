-- Dati di esempio per test in locale
-- Gestionale Bidelli - Istituto Pertini, Reggio Emilia

-- Utente DSGA di test
-- Email: dsga@pertini.test  |  Password in chiaro: Test1234!
INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, attivo) VALUES
('Maria', 'Rossi', 'dsga@pertini.test', '$2y$10$bIhgyh2Z3snbnsg/.5Yuyu375JVrbp/gt7XuvVaopJcx9p.Q43LQS', 'dsga', 1);

-- Plessi
INSERT INTO plessi (id, nome, indirizzo, note, min_bidelli_mattina, min_bidelli_pomeriggio,
                     orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine, attivo) VALUES
(1, 'Plesso Centrale', 'Via Roma 12, Reggio Emilia', 'Sede principale, segreteria presente', 3, 2,
    '07:30:00', '13:30:00', '13:30:00', '18:30:00', 1),
(2, 'Plesso Nord', 'Via Turchi 45, Reggio Emilia', NULL, 2, 1,
    '07:45:00', '13:15:00', '13:15:00', '17:45:00', 1),
(3, 'Plesso Sud', 'Via Adua 8, Reggio Emilia', 'Palestra condivisa con associazioni esterne', 2, 2,
    '08:00:00', '14:00:00', '14:00:00', '19:00:00', 1);

-- Bidelli
INSERT INTO bidelli (id, nome, cognome, telefono, email, plesso_principale_id, note, attivo) VALUES
(1, 'Luca', 'Bianchi', '3331234567', 'luca.bianchi@pertini.test', 1, NULL, 1),
(2, 'Anna', 'Ferrari', '3339876543', 'anna.ferrari@pertini.test', 1, NULL, 1),
(3, 'Giuseppe', 'Colombo', '3345551122', 'giuseppe.colombo@pertini.test', 2, NULL, 1),
(4, 'Paola', 'Ricci', '3357778899', 'paola.ricci@pertini.test', 2, NULL, 1),
(5, 'Marco', 'Greco', '3362223344', 'marco.greco@pertini.test', 3, NULL, 1),
(6, 'Sara', 'Conti', '3374445566', 'sara.conti@pertini.test', 3, 'Part-time, disponibile solo pomeriggio', 1);

-- Turni di esempio per la settimana 31/08/2026 - 04/09/2026
-- Plesso Centrale (min 3 mattina / 2 pomeriggio) - Luca e Anna coprono
INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato) VALUES
(1, 1, '2026-08-31', 'mattina', 'pianificato'),
(1, 2, '2026-08-31', 'mattina', 'pianificato'),
(1, 1, '2026-08-31', 'pomeriggio', 'pianificato'),
(1, 1, '2026-09-01', 'mattina', 'pianificato'),
(1, 2, '2026-09-01', 'mattina', 'pianificato'),
(1, 2, '2026-09-01', 'pomeriggio', 'pianificato');

-- Plesso Nord (min 2 mattina / 1 pomeriggio) - Giuseppe e Paola
INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato) VALUES
(2, 3, '2026-08-31', 'mattina', 'pianificato'),
(2, 4, '2026-08-31', 'mattina', 'pianificato'),
(2, 3, '2026-08-31', 'pomeriggio', 'pianificato'),
(2, 4, '2026-09-01', 'mattina', 'pianificato');
-- Nota: 2026-09-01 pomeriggio Plesso Nord volutamente scoperto -> sotto soglia, per testare l'avviso

-- Plesso Sud (min 2 mattina / 2 pomeriggio) - Marco e Sara, con un esempio di assenza + sostituzione
INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato) VALUES
(3, 5, '2026-08-31', 'mattina', 'pianificato');

-- Turno originale di Sara, segnato assente (INSERT a se' per catturare l'ID corretto con LAST_INSERT_ID)
INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato) VALUES
(3, 6, '2026-08-31', 'pomeriggio', 'assente');

-- Sostituzione: Anna (normalmente al Plesso Centrale) copre l'assenza di Sara al Plesso Sud
INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato, sostituto_di_turno_id) VALUES
(3, 2, '2026-08-31', 'pomeriggio', 'sostituito', LAST_INSERT_ID());
