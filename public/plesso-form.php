<?php
require_once __DIR__ . '/../includes/auth.php';

avviaSessione();
richiediRuoloDsga();

$pdo = getConnessione();

$id = null;
if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
} elseif (isset($_POST['id']) && $_POST['id'] !== '') {
    $id = (int) $_POST['id'];
}
$modifica = $id !== null && $id > 0;

$valori = [
    'nome' => '',
    'indirizzo' => '',
    'note' => '',
    'min_bidelli_mattina' => 1,
    'min_bidelli_pomeriggio' => 1,
    'orario_mattina_inizio' => '',
    'orario_mattina_fine' => '',
    'orario_pomeriggio_inizio' => '',
    'orario_pomeriggio_fine' => '',
    'attivo' => 1,
];

if ($modifica && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM plessi WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $riga = $stmt->fetch();

    if (!$riga) {
        http_response_code(404);
        die('Plesso non trovato.');
    }

    $valori = $riga;
}

$errori = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $errori[] = 'Richiesta non valida. Riprova.';
    }

    $valori['nome'] = trim($_POST['nome'] ?? '');
    $valori['indirizzo'] = trim($_POST['indirizzo'] ?? '');
    $valori['note'] = trim($_POST['note'] ?? '');
    $valori['min_bidelli_mattina'] = trim($_POST['min_bidelli_mattina'] ?? '');
    $valori['min_bidelli_pomeriggio'] = trim($_POST['min_bidelli_pomeriggio'] ?? '');
    $valori['orario_mattina_inizio'] = trim($_POST['orario_mattina_inizio'] ?? '');
    $valori['orario_mattina_fine'] = trim($_POST['orario_mattina_fine'] ?? '');
    $valori['orario_pomeriggio_inizio'] = trim($_POST['orario_pomeriggio_inizio'] ?? '');
    $valori['orario_pomeriggio_fine'] = trim($_POST['orario_pomeriggio_fine'] ?? '');
    $valori['attivo'] = isset($_POST['attivo']) ? 1 : 0;

    if ($valori['nome'] === '') {
        $errori[] = 'Il nome del plesso è obbligatorio.';
    }

    foreach (['min_bidelli_mattina' => 'mattina', 'min_bidelli_pomeriggio' => 'pomeriggio'] as $campo => $etichetta) {
        if (!ctype_digit((string) $valori[$campo])) {
            $errori[] = "Il minimo bidelli $etichetta deve essere un numero intero maggiore o uguale a 0.";
        }
    }

    $formatoOrario = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $campiOrario = [
        'orario_mattina_inizio' => 'inizio mattina',
        'orario_mattina_fine' => 'fine mattina',
        'orario_pomeriggio_inizio' => 'inizio pomeriggio',
        'orario_pomeriggio_fine' => 'fine pomeriggio',
    ];
    foreach ($campiOrario as $campo => $etichetta) {
        if ($valori[$campo] !== '' && !preg_match($formatoOrario, $valori[$campo])) {
            $errori[] = "Orario \"$etichetta\" non valido, usa il formato HH:MM.";
        }
    }

    if (!$errori) {
        $parametri = [
            'nome' => $valori['nome'],
            'indirizzo' => $valori['indirizzo'] !== '' ? $valori['indirizzo'] : null,
            'note' => $valori['note'] !== '' ? $valori['note'] : null,
            'min_bidelli_mattina' => (int) $valori['min_bidelli_mattina'],
            'min_bidelli_pomeriggio' => (int) $valori['min_bidelli_pomeriggio'],
            'orario_mattina_inizio' => $valori['orario_mattina_inizio'] !== '' ? $valori['orario_mattina_inizio'] : null,
            'orario_mattina_fine' => $valori['orario_mattina_fine'] !== '' ? $valori['orario_mattina_fine'] : null,
            'orario_pomeriggio_inizio' => $valori['orario_pomeriggio_inizio'] !== '' ? $valori['orario_pomeriggio_inizio'] : null,
            'orario_pomeriggio_fine' => $valori['orario_pomeriggio_fine'] !== '' ? $valori['orario_pomeriggio_fine'] : null,
            'attivo' => $valori['attivo'],
        ];

        if ($modifica) {
            $parametri['id'] = $id;
            $pdo->prepare(
                'UPDATE plessi SET
                    nome = :nome,
                    indirizzo = :indirizzo,
                    note = :note,
                    min_bidelli_mattina = :min_bidelli_mattina,
                    min_bidelli_pomeriggio = :min_bidelli_pomeriggio,
                    orario_mattina_inizio = :orario_mattina_inizio,
                    orario_mattina_fine = :orario_mattina_fine,
                    orario_pomeriggio_inizio = :orario_pomeriggio_inizio,
                    orario_pomeriggio_fine = :orario_pomeriggio_fine,
                    attivo = :attivo
                 WHERE id = :id'
            )->execute($parametri);
        } else {
            $pdo->prepare(
                'INSERT INTO plessi
                    (nome, indirizzo, note, min_bidelli_mattina, min_bidelli_pomeriggio,
                     orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine, attivo)
                 VALUES
                    (:nome, :indirizzo, :note, :min_bidelli_mattina, :min_bidelli_pomeriggio,
                     :orario_mattina_inizio, :orario_mattina_fine, :orario_pomeriggio_inizio, :orario_pomeriggio_fine, :attivo)'
            )->execute($parametri);
        }

        header('Location: plessi.php?msg=salvato');
        exit;
    }
}

function orarioValore(?string $v): string
{
    return $v ? substr($v, 0, 5) : '';
}

$paginaTitolo = $modifica ? 'Modifica plesso' : 'Nuovo plesso';
$paginaAttiva = 'plessi';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title"><?= $modifica ? 'Modifica plesso' : 'Nuovo plesso' ?></div>
        <div class="panel__sub"><a href="plessi.php">← torna all'elenco</a></div>
    </div>

    <div style="padding: var(--space-6);">
        <?php if ($errori): ?>
            <div class="form-error" style="margin-bottom: var(--space-6);">
                Correggi i seguenti errori:
                <ul>
                    <?php foreach ($errori as $errore): ?>
                        <li><?= htmlspecialchars($errore) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="plesso-form.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
            <?php if ($modifica): ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
            <?php endif; ?>

            <div class="form-grid" style="margin-bottom: var(--space-5);">
                <div class="form-field form-field--full">
                    <label for="nome">Nome</label>
                    <input class="form-input" type="text" id="nome" name="nome" required
                           value="<?= htmlspecialchars($valori['nome']) ?>">
                </div>

                <div class="form-field form-field--full">
                    <label for="indirizzo">Indirizzo</label>
                    <input class="form-input" type="text" id="indirizzo" name="indirizzo"
                           value="<?= htmlspecialchars($valori['indirizzo'] ?? '') ?>">
                </div>

                <div class="form-field form-field--full">
                    <label for="note">Note</label>
                    <textarea class="form-textarea" id="note" name="note"><?= htmlspecialchars($valori['note'] ?? '') ?></textarea>
                </div>

                <div class="form-field">
                    <label for="min_bidelli_mattina">Min. bidelli mattina</label>
                    <input class="form-input" type="number" min="0" step="1" id="min_bidelli_mattina" name="min_bidelli_mattina"
                           value="<?= htmlspecialchars((string) $valori['min_bidelli_mattina']) ?>">
                </div>

                <div class="form-field">
                    <label for="min_bidelli_pomeriggio">Min. bidelli pomeriggio</label>
                    <input class="form-input" type="number" min="0" step="1" id="min_bidelli_pomeriggio" name="min_bidelli_pomeriggio"
                           value="<?= htmlspecialchars((string) $valori['min_bidelli_pomeriggio']) ?>">
                </div>

                <div class="form-field">
                    <label for="orario_mattina_inizio">Inizio mattina</label>
                    <input class="form-input" type="time" id="orario_mattina_inizio" name="orario_mattina_inizio"
                           value="<?= htmlspecialchars(orarioValore($valori['orario_mattina_inizio'])) ?>">
                </div>

                <div class="form-field">
                    <label for="orario_mattina_fine">Fine mattina</label>
                    <input class="form-input" type="time" id="orario_mattina_fine" name="orario_mattina_fine"
                           value="<?= htmlspecialchars(orarioValore($valori['orario_mattina_fine'])) ?>">
                </div>

                <div class="form-field">
                    <label for="orario_pomeriggio_inizio">Inizio pomeriggio</label>
                    <input class="form-input" type="time" id="orario_pomeriggio_inizio" name="orario_pomeriggio_inizio"
                           value="<?= htmlspecialchars(orarioValore($valori['orario_pomeriggio_inizio'])) ?>">
                </div>

                <div class="form-field">
                    <label for="orario_pomeriggio_fine">Fine pomeriggio</label>
                    <input class="form-input" type="time" id="orario_pomeriggio_fine" name="orario_pomeriggio_fine"
                           value="<?= htmlspecialchars(orarioValore($valori['orario_pomeriggio_fine'])) ?>">
                </div>

                <div class="form-field form-field--full">
                    <label class="form-checkbox">
                        <input type="checkbox" name="attivo" value="1" <?= ((int) $valori['attivo']) === 1 ? 'checked' : '' ?>>
                        Plesso attivo
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-check"></i> Salva
                </button>
                <a class="btn btn--secondary" href="plessi.php">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
