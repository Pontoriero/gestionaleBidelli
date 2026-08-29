<?php
/**
 * Connessione PDO al database MySQL.
 * Credenziali lette da file .env (mai committato), nessuna dipendenza esterna.
 */

/**
 * Carica variabili da un file .env nell'ambiente (getenv/putenv).
 * Formato atteso: CHIAVE=valore, righe vuote e commenti (#) ignorati.
 */
function carica_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $riga) {
        $riga = trim($riga);
        if ($riga === '' || str_starts_with($riga, '#')) {
            continue;
        }
        [$chiave, $valore] = array_pad(explode('=', $riga, 2), 2, '');
        putenv(trim($chiave) . '=' . trim($valore));
    }
}

/**
 * Restituisce una connessione PDO condivisa (singleton per la request corrente).
 */
function getConnessione(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    carica_env(__DIR__ . '/../.env');

    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_name = getenv('DB_NAME') ?: 'gestionale_bidelli';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";

    $opzioni_pdo = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $opzioni_pdo);
    } catch (PDOException $e) {
        error_log('Errore connessione DB: ' . $e->getMessage());
        die('Errore di connessione al database. Contattare l\'amministratore.');
    }

    return $pdo;
}
