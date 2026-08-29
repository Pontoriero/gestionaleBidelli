-- Migrazione: traccia le assegnazioni che hanno richiesto autorizzazione
-- esplicita per superamento della soglia giornaliera (7h12m senza pausa
-- minima di 30 minuti tra mattina e pomeriggio nello stesso plesso).
-- Da eseguire su DB già esistenti (chi ricrea il DB da zero trova già
-- questa colonna in schema.sql).

ALTER TABLE turni
    ADD COLUMN straordinario_giornaliero_autorizzato TINYINT(1) NOT NULL DEFAULT 0 AFTER stato;
