<?php
/**
 * Helper per l'import CSV massivo (plessi, bidelli, ...).
 */

/**
 * Legge un CSV caricato e lo restituisce come lista di righe associative,
 * chiave = intestazione della prima riga. Toglie il BOM UTF-8 iniziale
 * dalla prima cella (se non lo si fa, l'intestazione della prima colonna
 * risulta sporca e non fa più match con nessuna chiave attesa).
 *
 * Stesso delimitatore ";" usato da esportaCSV(), per coerenza col
 * template scaricabile.
 *
 * @return array<int, array<string, string>>
 */
function leggiCsvAssociativo(string $percorsoFile): array
{
    $handle = fopen($percorsoFile, 'r');
    if ($handle === false) {
        return [];
    }

    $intestazioni = fgetcsv($handle, 0, ';');
    if ($intestazioni === false || $intestazioni === null) {
        fclose($handle);
        return [];
    }

    $intestazioni[0] = preg_replace('/^\xEF\xBB\xBF/', '', $intestazioni[0]);
    $intestazioni = array_map('trim', $intestazioni);

    $righe = [];
    while (($valori = fgetcsv($handle, 0, ';')) !== false) {
        if ($valori === null || $valori === [null]) {
            continue; // riga vuota
        }

        $riga = [];
        foreach ($intestazioni as $indice => $chiave) {
            $riga[$chiave] = trim((string) ($valori[$indice] ?? ''));
        }
        $righe[] = $riga;
    }

    fclose($handle);
    return $righe;
}

/**
 * Scrive $righe (già validate dal chiamante — qui non si valida più
 * nulla) chiamando $inserisciRiga per ciascuna, dentro un'unica
 * transazione: se una qualsiasi chiamata lancia un'eccezione, annulla
 * tutto, nessun inserimento parziale.
 */
function importaInTransazione(PDO $pdo, array $righe, callable $inserisciRiga): int
{
    $pdo->beginTransaction();
    try {
        foreach ($righe as $riga) {
            $inserisciRiga($riga);
        }
        $pdo->commit();
        return count($righe);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
