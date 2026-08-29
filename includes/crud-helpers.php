<?php
/**
 * Helper condivisi per le pagine CRUD (plessi, bidelli, ...).
 */

/**
 * Verifica il token CSRF di un POST; se non valido, redirige e termina.
 * Da usare per azioni immediate (es. elimina) — per i form che devono
 * ri-mostrare errori inline (creazione/modifica) si continua a usare
 * verificaCsrfToken() direttamente.
 */
function verificaCsrfOFallisci(string $redirectSeNonValido): void
{
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $redirectSeNonValido);
        exit;
    }
}

/**
 * Conta i record in $tabella con $colonnaFk = $id.
 * $tabella e $colonnaFk devono essere letterali fissi nel codice
 * chiamante, MAI valori provenienti da input utente (interpolati
 * direttamente nella query).
 */
function contaRecordCollegati(PDO $pdo, string $tabella, string $colonnaFk, int $id): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabella} WHERE {$colonnaFk} = :id");
    $stmt->execute(['id' => $id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Elimina il record $id da $tabellaPrincipale solo se non esistono righe
 * collegate in $tabellaDipendente.$colonnaFk. Ritorna 'ha_dipendenze',
 * 'eliminato' o 'errore' (0 righe toccate, es. id inesistente).
 */
function eliminaSeSenzaDipendenze(PDO $pdo, string $tabellaPrincipale, string $tabellaDipendente, string $colonnaFk, int $id): string
{
    if (contaRecordCollegati($pdo, $tabellaDipendente, $colonnaFk, $id) > 0) {
        return 'ha_dipendenze';
    }

    $stmt = $pdo->prepare("DELETE FROM {$tabellaPrincipale} WHERE id = :id");
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0 ? 'eliminato' : 'errore';
}
