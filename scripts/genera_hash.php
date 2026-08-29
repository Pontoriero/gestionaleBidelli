<?php
/**
 * Script da riga di comando per generare un hash password_hash().
 * Uso: php scripts/genera_hash.php "PasswordInChiaro"
 * Solo per uso locale/sviluppo (creazione utenti di seed) — non esporre via web.
 */

if ($argc !== 2) {
    fwrite(STDERR, "Uso: php scripts/genera_hash.php \"PasswordInChiaro\"\n");
    exit(1);
}

echo password_hash($argv[1], PASSWORD_DEFAULT) . PHP_EOL;
