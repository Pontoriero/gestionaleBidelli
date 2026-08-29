-- Migrazione: monte ore settimanali ordinarie + tetto straordinari per bidello.
-- Da eseguire su DB già esistenti (chi ricrea il DB da zero trova già
-- queste colonne in schema.sql).

ALTER TABLE bidelli
    ADD COLUMN ore_settimanali INT NOT NULL DEFAULT 36 AFTER plesso_principale_id,
    ADD COLUMN ore_straordinario_max INT NOT NULL DEFAULT 0 AFTER ore_settimanali;
