<?php
require_once __DIR__ . '/../includes/auth.php';

avviaSessione();
richiediRuoloDsga();

$pdo = getConnessione();

/**
 * Calcola i bidelli attivi assegnabili a ($plessoId, $data, $turnoGiorno)
 * rispettando: (a) vincolo UNIQUE bidello+data+turno_giorno, (b) vincolo
 * "un plesso al giorno" (se ha già l'altro turno_giorno quel giorno,
 * deve essere nello stesso plesso).
 */
function bidelliDisponibili(PDO $pdo, int $plessoId, string $data, string $turnoGiorno): array
{
    $bidelli = $pdo->query('SELECT id, nome, cognome FROM bidelli WHERE attivo = 1 ORDER BY cognome, nome')->fetchAll();

    $stmt = $pdo->prepare('SELECT bidello_id, turno_giorno, plesso_id FROM turni WHERE data = :data');
    $stmt->execute(['data' => $data]);

    $occupazione = [];
    foreach ($stmt->fetchAll() as $riga) {
        $occupazione[$riga['bidello_id']][$riga['turno_giorno']] = (int) $riga['plesso_id'];
    }

    $altroTurno = $turnoGiorno === 'mattina' ? 'pomeriggio' : 'mattina';

    return array_values(array_filter($bidelli, static function ($b) use ($occupazione, $turnoGiorno, $altroTurno, $plessoId) {
        $occ = $occupazione[$b['id']] ?? [];
        if (isset($occ[$turnoGiorno])) {
            return false;
        }
        if (isset($occ[$altroTurno]) && $occ[$altroTurno] !== $plessoId) {
            return false;
        }
        return true;
    }));
}

/* ---------- Validazione contesto (plesso_id, data, turno_giorno) ---------- */

$origine = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$plessoId = (int) ($origine['plesso_id'] ?? 0);
$data = (string) ($origine['data'] ?? '');
$turnoGiorno = (string) ($origine['turno_giorno'] ?? '');
$settimana = (string) ($origine['settimana'] ?? $data);

$dataValida = DateTime::createFromFormat('Y-m-d', $data);
$contestoValido = $plessoId > 0
    && in_array($turnoGiorno, ['mattina', 'pomeriggio'], true)
    && $dataValida !== false
    && $dataValida->format('Y-m-d') === $data;

if ($contestoValido) {
    $stmtPlesso = $pdo->prepare('SELECT id, nome, min_bidelli_mattina, min_bidelli_pomeriggio FROM plessi WHERE id = :id AND attivo = 1');
    $stmtPlesso->execute(['id' => $plessoId]);
    $plesso = $stmtPlesso->fetch();
    $contestoValido = (bool) $plesso;
}

if (!$contestoValido) {
    http_response_code(400);
    die('Contesto non valido per l\'assegnazione (plesso, data o turno mancante/errato).');
}

$errori = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $errori[] = 'Richiesta non valida. Riprova.';
    }

    $bidelloId = (int) ($_POST['bidello_id'] ?? 0);
    $disponibiliPost = bidelliDisponibili($pdo, $plessoId, $data, $turnoGiorno);
    $idDisponibili = array_column($disponibiliPost, 'id');

    if ($bidelloId <= 0 || !in_array($bidelloId, $idDisponibili, true)) {
        $errori[] = 'Il bidello selezionato non è (più) disponibile per questo turno. Riprova.';
    }

    if (!$errori) {
        $pdo->prepare(
            'INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato)
             VALUES (:plesso_id, :bidello_id, :data, :turno_giorno, \'pianificato\')'
        )->execute([
            'plesso_id' => $plessoId,
            'bidello_id' => $bidelloId,
            'data' => $data,
            'turno_giorno' => $turnoGiorno,
        ]);

        header('Location: turni.php?settimana=' . $settimana . '&msg=assegnato');
        exit;
    }
}

$disponibili = bidelliDisponibili($pdo, $plessoId, $data, $turnoGiorno);

$giorniItaliani = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
$mesiItaliani = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$dataFormattata = $giorniItaliani[(int) $dataValida->format('w')] . ' ' . $dataValida->format('j') . ' ' . $mesiItaliani[(int) $dataValida->format('n')] . ' ' . $dataValida->format('Y');

$paginaTitolo = 'Nuova assegnazione';
$paginaAttiva = 'turni';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Nuova assegnazione</div>
        <div class="panel__sub"><a href="turni.php?settimana=<?= htmlspecialchars($settimana) ?>">← torna alla griglia</a></div>
    </div>

    <div style="padding: var(--space-6); max-width: 480px;">
        <?php if ($errori): ?>
            <div class="form-error" style="margin-bottom: var(--space-6);">
                <ul>
                    <?php foreach ($errori as $errore): ?>
                        <li><?= htmlspecialchars($errore) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-field" style="margin-bottom: var(--space-5);">
            <label>Plesso</label>
            <div><?= htmlspecialchars($plesso['nome']) ?></div>
        </div>

        <div class="form-field" style="margin-bottom: var(--space-5);">
            <label>Giorno e turno</label>
            <div><?= htmlspecialchars($dataFormattata) ?> — <?= $turnoGiorno === 'mattina' ? 'Mattina' : 'Pomeriggio' ?></div>
        </div>

        <form method="post" action="turno-assegna.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
            <input type="hidden" name="plesso_id" value="<?= (int) $plessoId ?>">
            <input type="hidden" name="data" value="<?= htmlspecialchars($data) ?>">
            <input type="hidden" name="turno_giorno" value="<?= htmlspecialchars($turnoGiorno) ?>">
            <input type="hidden" name="settimana" value="<?= htmlspecialchars($settimana) ?>">

            <div class="form-field" style="margin-bottom: var(--space-5);">
                <label for="bidello_id">Bidello</label>
                <select class="form-input" id="bidello_id" name="bidello_id" required <?= !$disponibili ? 'disabled' : '' ?>>
                    <?php if (!$disponibili): ?>
                        <option value="">Nessun bidello disponibile per questo turno</option>
                    <?php else: ?>
                        <option value="">— seleziona —</option>
                        <?php foreach ($disponibili as $bidello): ?>
                            <option value="<?= (int) $bidello['id'] ?>">
                                <?= htmlspecialchars($bidello['cognome'] . ' ' . $bidello['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (!$disponibili): ?>
                    <div class="form-hint">Tutti i bidelli attivi sono già assegnati altrove in questo turno, o impegnati nell'altro turno dello stesso giorno in un plesso diverso.</div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary" <?= !$disponibili ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-check"></i> Assegna
                </button>
                <a class="btn btn--secondary" href="turni.php?settimana=<?= htmlspecialchars($settimana) ?>">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
