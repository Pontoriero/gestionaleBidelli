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
            /* ---------- Passata 1: valida OGNI riga, non scrive nulla ---------- */

            $righeValide = [];
            $numeroRiga = 1; // riga 1 = intestazione
            $formatoOrario = '/^([01]\d|2[0-3]):[0-5]\d$/';

            foreach ($righeCsv as $riga) {
                $numeroRiga++;
                $erroriRiga = [];

                $nome = trim($riga['nome'] ?? '');
                if ($nome === '') {
                    $erroriRiga[] = 'nome mancante';
                }

                $minMattina = trim($riga['min_bidelli_mattina'] ?? '');
                if (!ctype_digit($minMattina)) {
                    $erroriRiga[] = 'min_bidelli_mattina non è un numero valido';
                }

                $minPomeriggio = trim($riga['min_bidelli_pomeriggio'] ?? '');
                if (!ctype_digit($minPomeriggio)) {
                    $erroriRiga[] = 'min_bidelli_pomeriggio non è un numero valido';
                }

                foreach (['orario_mattina_inizio', 'orario_mattina_fine', 'orario_pomeriggio_inizio', 'orario_pomeriggio_fine'] as $campoOrario) {
                    $valore = trim($riga[$campoOrario] ?? '');
                    if ($valore !== '' && !preg_match($formatoOrario, $valore)) {
                        $erroriRiga[] = "{$campoOrario} non è un orario valido (HH:MM)";
                    }
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
                    'indirizzo' => trim($riga['indirizzo'] ?? '') ?: null,
                    'note' => trim($riga['note'] ?? '') ?: null,
                    'min_bidelli_mattina' => (int) $minMattina,
                    'min_bidelli_pomeriggio' => (int) $minPomeriggio,
                    'orario_mattina_inizio' => trim($riga['orario_mattina_inizio'] ?? '') ?: null,
                    'orario_mattina_fine' => trim($riga['orario_mattina_fine'] ?? '') ?: null,
                    'orario_pomeriggio_inizio' => trim($riga['orario_pomeriggio_inizio'] ?? '') ?: null,
                    'orario_pomeriggio_fine' => trim($riga['orario_pomeriggio_fine'] ?? '') ?: null,
                    'attivo' => (int) $attivoGrezzo,
                ];
            }

            /* ---------- Passata 2: solo se ZERO errori, importa tutto ---------- */

            if (!$erroriValidazione) {
                $importati = importaInTransazione($pdo, $righeValide, function (array $r) use ($pdo) {
                    $pdo->prepare(
                        'INSERT INTO plessi
                            (nome, indirizzo, note, min_bidelli_mattina, min_bidelli_pomeriggio,
                             orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine, attivo)
                         VALUES
                            (:nome, :indirizzo, :note, :min_bidelli_mattina, :min_bidelli_pomeriggio,
                             :orario_mattina_inizio, :orario_mattina_fine, :orario_pomeriggio_inizio, :orario_pomeriggio_fine, :attivo)'
                    )->execute($r);
                });

                header('Location: plessi.php?msg=importato&conteggio=' . $importati);
                exit;
            }
        }
    }
}

$paginaTitolo = 'Importa plessi';
$paginaAttiva = 'plessi';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Importa plessi da CSV</div>
        <div class="panel__sub"><a href="plessi.php">← torna all'elenco</a></div>
    </div>

    <div style="padding: var(--space-6); max-width: 640px;">
        <?php if ($erroriValidazione): ?>
            <div class="form-error" style="margin-bottom: var(--space-6);">
                Import annullato, nessun plesso è stato creato. Correggi il file e ricarica:
                <ul>
                    <?php foreach ($erroriValidazione as $errore): ?>
                        <li><?= htmlspecialchars($errore) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="form-hint" style="margin-bottom: var(--space-5);">
            Scarica il <a href="plessi.php?export=csv&template=1">template CSV</a>, compilalo e ricaricalo qui.
            Vengono creati solo nuovi plessi — non aggiorna record esistenti. Se anche una sola riga ha errori, non viene importato nulla.
        </p>

        <form method="post" action="plessi-importa.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">

            <div class="form-field" style="margin-bottom: var(--space-5);">
                <label for="file_csv">File CSV</label>
                <input class="form-input" type="file" id="file_csv" name="file_csv" accept=".csv" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-upload"></i> Importa
                </button>
                <a class="btn btn--secondary" href="plessi.php">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
