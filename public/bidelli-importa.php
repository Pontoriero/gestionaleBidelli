<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/import-helpers.php';

avviaSessione();
richiediRuoloDsga();

$pdo = getConnessione();

$erroriValidazione = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $erroriValidazione[] = 'Richiesta non valida. Riprova.';
    } elseif (!isset($_FILES['file_csv']) || $_FILES['file_csv']['error'] !== UPLOAD_ERR_OK) {
        $erroriValidazione[] = 'Nessun file caricato, o errore durante il caricamento.';
    } else {
        $righeCsv = leggiCsvAssociativo($_FILES['file_csv']['tmp_name']);

        if (!$righeCsv) {
            $erroriValidazione[] = 'Il file è vuoto o non è un CSV leggibile.';
        } else {
            // Mappa nome plesso (case/spazi esatti) -> id, per risolvere
            // plesso_principale scritto in chiaro nel CSV.
            $plessiPerNome = [];
            foreach ($pdo->query('SELECT id, nome FROM plessi')->fetchAll() as $p) {
                $plessiPerNome[$p['nome']] = (int) $p['id'];
            }

            /* ---------- Passata 1: valida OGNI riga, non scrive nulla ---------- */

            $righeValide = [];
            $numeroRiga = 1; // riga 1 = intestazione

            foreach ($righeCsv as $riga) {
                $numeroRiga++;
                $erroriRiga = [];

                $nome = trim($riga['nome'] ?? '');
                if ($nome === '') {
                    $erroriRiga[] = 'nome mancante';
                }

                $cognome = trim($riga['cognome'] ?? '');
                if ($cognome === '') {
                    $erroriRiga[] = 'cognome mancante';
                }

                $telefono = trim($riga['telefono'] ?? '');
                if ($telefono !== '' && !preg_match('/^[0-9+\-\s]+$/', $telefono)) {
                    $erroriRiga[] = 'telefono può contenere solo numeri, spazi, "+" e "-"';
                }

                $email = trim($riga['email'] ?? '');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $erroriRiga[] = 'email non è in un formato valido';
                }

                $nomePlesso = trim($riga['plesso_principale'] ?? '');
                $plessoPrincipaleId = null;
                if ($nomePlesso !== '') {
                    if (!isset($plessiPerNome[$nomePlesso])) {
                        $erroriRiga[] = "plesso \"{$nomePlesso}\" non trovato";
                    } else {
                        $plessoPrincipaleId = $plessiPerNome[$nomePlesso];
                    }
                }

                $oreSettimanali = trim($riga['ore_settimanali'] ?? '');
                if (!ctype_digit($oreSettimanali) || (int) $oreSettimanali <= 0) {
                    $erroriRiga[] = 'ore_settimanali deve essere un numero maggiore di 0';
                }

                $oreStraordinarioMax = trim($riga['ore_straordinario_max'] ?? '');
                if (!ctype_digit($oreStraordinarioMax)) {
                    $erroriRiga[] = 'ore_straordinario_max deve essere un numero maggiore o uguale a 0';
                }

                $attivoGrezzo = trim($riga['attivo'] ?? '');
                if ($attivoGrezzo === '') {
                    $attivoGrezzo = '1';
                }
                if (!in_array($attivoGrezzo, ['0', '1'], true)) {
                    $erroriRiga[] = 'attivo deve essere 0 o 1';
                }

                if ($erroriRiga) {
                    $erroriValidazione[] = "Riga {$numeroRiga}: " . implode('; ', $erroriRiga);
                    continue;
                }

                $righeValide[] = [
                    'nome' => $nome,
                    'cognome' => $cognome,
                    'telefono' => $telefono !== '' ? $telefono : null,
                    'email' => $email !== '' ? $email : null,
                    'plesso_principale_id' => $plessoPrincipaleId,
                    'note' => trim($riga['note'] ?? '') ?: null,
                    'ore_settimanali' => (int) $oreSettimanali,
                    'ore_straordinario_max' => (int) $oreStraordinarioMax,
                    'attivo' => (int) $attivoGrezzo,
                ];
            }

            /* ---------- Passata 2: solo se ZERO errori, importa tutto ---------- */

            if (!$erroriValidazione) {
                $importati = importaInTransazione($pdo, $righeValide, function (array $r) use ($pdo) {
                    $pdo->prepare(
                        'INSERT INTO bidelli
                            (nome, cognome, telefono, email, plesso_principale_id, note, ore_settimanali, ore_straordinario_max, attivo)
                         VALUES
                            (:nome, :cognome, :telefono, :email, :plesso_principale_id, :note, :ore_settimanali, :ore_straordinario_max, :attivo)'
                    )->execute($r);
                });

                header('Location: bidelli.php?msg=importato&conteggio=' . $importati);
                exit;
            }
        }
    }
}

$paginaTitolo = 'Importa bidelli';
$paginaAttiva = 'bidelli';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Importa bidelli da CSV</div>
        <div class="panel__sub"><a href="bidelli.php">← torna all'elenco</a></div>
    </div>

    <div style="padding: var(--space-6); max-width: 640px;">
        <?php if ($erroriValidazione): ?>
            <div class="form-error" style="margin-bottom: var(--space-6);">
                Import annullato, nessun bidello è stato creato. Correggi il file e ricarica:
                <ul>
                    <?php foreach ($erroriValidazione as $errore): ?>
                        <li><?= htmlspecialchars($errore) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="form-hint" style="margin-bottom: var(--space-5);">
            Scarica il <a href="bidelli.php?export=csv&template=1">template CSV</a>, compilalo e ricaricalo qui.
            Il campo "plesso_principale" va scritto con il nome esatto del plesso (es. "Plesso Centrale"), non un ID.
            Vengono creati solo nuovi bidelli — non aggiorna record esistenti. Se anche una sola riga ha errori, non viene importato nulla.
        </p>

        <form method="post" action="bidelli-importa.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">

            <div class="form-field" style="margin-bottom: var(--space-5);">
                <label for="file_csv">File CSV</label>
                <input class="form-input" type="file" id="file_csv" name="file_csv" accept=".csv" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-upload"></i> Importa
                </button>
                <a class="btn btn--secondary" href="bidelli.php">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
