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
    'cognome' => '',
    'telefono' => '',
    'email' => '',
    'plesso_principale_id' => '',
    'ore_settimanali' => 36,
    'ore_straordinario_max' => 0,
    'note' => '',
    'attivo' => 1,
];

if ($modifica && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM bidelli WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $riga = $stmt->fetch();

    if (!$riga) {
        http_response_code(404);
        die('Bidello non trovato.');
    }

    $valori = $riga;
}

$errori = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $errori[] = 'Richiesta non valida. Riprova.';
    }

    $valori['nome'] = trim($_POST['nome'] ?? '');
    $valori['cognome'] = trim($_POST['cognome'] ?? '');
    $valori['telefono'] = trim($_POST['telefono'] ?? '');
    $valori['email'] = trim($_POST['email'] ?? '');
    $valori['plesso_principale_id'] = trim($_POST['plesso_principale_id'] ?? '');
    $valori['ore_settimanali'] = trim($_POST['ore_settimanali'] ?? '');
    $valori['ore_straordinario_max'] = trim($_POST['ore_straordinario_max'] ?? '');
    $valori['note'] = trim($_POST['note'] ?? '');
    $valori['attivo'] = isset($_POST['attivo']) ? 1 : 0;

    if ($valori['nome'] === '') {
        $errori[] = 'Il nome è obbligatorio.';
    }

    if ($valori['cognome'] === '') {
        $errori[] = 'Il cognome è obbligatorio.';
    }

    if ($valori['email'] !== '' && !filter_var($valori['email'], FILTER_VALIDATE_EMAIL)) {
        $errori[] = 'L\'email non è in un formato valido.';
    }

    if ($valori['telefono'] !== '' && !preg_match('/^[0-9+\-\s]+$/', $valori['telefono'])) {
        $errori[] = 'Il telefono può contenere solo numeri, spazi, "+" e "-".';
    }

    if ($valori['plesso_principale_id'] !== '' && !ctype_digit((string) $valori['plesso_principale_id'])) {
        $errori[] = 'Plesso principale non valido.';
    }

    if (!ctype_digit((string) $valori['ore_settimanali']) || (int) $valori['ore_settimanali'] <= 0) {
        $errori[] = 'Il monte ore settimanali è obbligatorio e deve essere un numero maggiore di 0.';
    }

    if (!ctype_digit((string) $valori['ore_straordinario_max'])) {
        $errori[] = 'Il tetto straordinari è obbligatorio e deve essere un numero maggiore o uguale a 0.';
    }

    if (!$errori) {
        $parametri = [
            'nome' => $valori['nome'],
            'cognome' => $valori['cognome'],
            'telefono' => $valori['telefono'] !== '' ? $valori['telefono'] : null,
            'email' => $valori['email'] !== '' ? $valori['email'] : null,
            'plesso_principale_id' => $valori['plesso_principale_id'] !== '' ? (int) $valori['plesso_principale_id'] : null,
            'ore_settimanali' => (int) $valori['ore_settimanali'],
            'ore_straordinario_max' => (int) $valori['ore_straordinario_max'],
            'note' => $valori['note'] !== '' ? $valori['note'] : null,
            'attivo' => $valori['attivo'],
        ];

        if ($modifica) {
            $parametri['id'] = $id;
            $pdo->prepare(
                'UPDATE bidelli SET
                    nome = :nome,
                    cognome = :cognome,
                    telefono = :telefono,
                    email = :email,
                    plesso_principale_id = :plesso_principale_id,
                    ore_settimanali = :ore_settimanali,
                    ore_straordinario_max = :ore_straordinario_max,
                    note = :note,
                    attivo = :attivo
                 WHERE id = :id'
            )->execute($parametri);
        } else {
            $pdo->prepare(
                'INSERT INTO bidelli
                    (nome, cognome, telefono, email, plesso_principale_id, ore_settimanali, ore_straordinario_max, note, attivo)
                 VALUES
                    (:nome, :cognome, :telefono, :email, :plesso_principale_id, :ore_settimanali, :ore_straordinario_max, :note, :attivo)'
            )->execute($parametri);
        }

        header('Location: bidelli.php?msg=salvato');
        exit;
    }
}

$plessi = $pdo->query(
    'SELECT id, nome, attivo FROM plessi
     WHERE attivo = 1 OR id = ' . (int) ($valori['plesso_principale_id'] ?: 0) . '
     ORDER BY nome'
)->fetchAll();

$paginaTitolo = $modifica ? 'Modifica bidello' : 'Nuovo bidello';
$paginaAttiva = 'bidelli';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title"><?= $modifica ? 'Modifica bidello' : 'Nuovo bidello' ?></div>
        <div class="panel__sub"><a href="bidelli.php">← torna all'elenco</a></div>
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

        <form method="post" action="bidello-form.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
            <?php if ($modifica): ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
            <?php endif; ?>

            <div class="form-grid" style="margin-bottom: var(--space-5);">
                <div class="form-field">
                    <label for="cognome">Cognome</label>
                    <input class="form-input" type="text" id="cognome" name="cognome" required
                           value="<?= htmlspecialchars($valori['cognome']) ?>">
                </div>

                <div class="form-field">
                    <label for="nome">Nome</label>
                    <input class="form-input" type="text" id="nome" name="nome" required
                           value="<?= htmlspecialchars($valori['nome']) ?>">
                </div>

                <div class="form-field">
                    <label for="telefono">Telefono</label>
                    <input class="form-input" type="text" id="telefono" name="telefono"
                           value="<?= htmlspecialchars($valori['telefono'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label for="email">Email</label>
                    <input class="form-input" type="email" id="email" name="email"
                           value="<?= htmlspecialchars($valori['email'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label for="ore_settimanali">Ore settimanali contrattuali</label>
                    <input class="form-input" type="number" min="1" step="1" id="ore_settimanali" name="ore_settimanali" required
                           value="<?= htmlspecialchars((string) $valori['ore_settimanali']) ?>">
                </div>

                <div class="form-field">
                    <label for="ore_straordinario_max">Tetto straordinari settimanali</label>
                    <input class="form-input" type="number" min="0" step="1" id="ore_straordinario_max" name="ore_straordinario_max" required
                           value="<?= htmlspecialchars((string) $valori['ore_straordinario_max']) ?>">
                    <div class="form-hint">0 = nessuno straordinario consentito per questo bidello.</div>
                </div>

                <div class="form-field form-field--full">
                    <label for="plesso_principale_id">Plesso principale</label>
                    <select class="form-input" id="plesso_principale_id" name="plesso_principale_id">
                        <option value="">— nessuno —</option>
                        <?php foreach ($plessi as $plesso): ?>
                            <option value="<?= (int) $plesso['id'] ?>"
                                <?= (string) $valori['plesso_principale_id'] === (string) $plesso['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($plesso['nome']) ?><?= (int) $plesso['attivo'] === 0 ? ' (non attivo)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field form-field--full">
                    <label for="note">Note</label>
                    <textarea class="form-textarea" id="note" name="note"><?= htmlspecialchars($valori['note'] ?? '') ?></textarea>
                </div>

                <div class="form-field form-field--full">
                    <label class="form-checkbox">
                        <input type="checkbox" name="attivo" value="1" <?= ((int) $valori['attivo']) === 1 ? 'checked' : '' ?>>
                        Bidello attivo
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-check"></i> Salva
                </button>
                <a class="btn btn--secondary" href="bidelli.php">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
