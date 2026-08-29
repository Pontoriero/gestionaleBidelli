<?php
/**
 * Helper per l'esportazione CSV delle tabelle dati.
 */

/**
 * Invia $righe come CSV scaricabile e termina lo script — nessun altro
 * output deve seguire la chiamata.
 *
 * Delimitatore ";" (non ","): con la localizzazione italiana di Excel la
 * virgola è il separatore decimale, quindi un CSV con virgola si apre
 * incollato in un'unica colonna. Il punto e virgola apre correttamente.
 * BOM UTF-8 iniziale per gli accenti italiani (Excel senza BOM li
 * interpreta con la codifica sbagliata).
 *
 * @param string $nomeFile es. "plessi.csv" — costruito lato server, mai da input utente
 * @param string[] $intestazioni es. ['Nome', 'Indirizzo', ...]
 * @param array<int, array<int, string>> $righe ogni riga nello stesso ordine delle intestazioni
 */
function esportaCSV(string $nomeFile, array $intestazioni, array $righe): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, $intestazioni, ';');
    foreach ($righe as $riga) {
        fputcsv($output, $riga, ';');
    }
    fclose($output);
    exit;
}
