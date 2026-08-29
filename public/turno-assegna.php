<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crud-helpers.php';
require_once __DIR__ . '/../includes/turni-helpers.php';

avviaSessione();
richiediRuoloDsga();

$pdo = getConnessione();

/**
 * Calcola i bidelli attivi assegnabili a ($plessoId, $data, $turnoGiorno)
 * rispettando: (a) vincolo UNIQUE bidello+data+turno_giorno, (b) vincolo
 * "un plesso al giorno" (se ha già l'altro turno_giorno quel giorno, deve
 * essere nello stesso plesso), (c) vincolo monte ore: la nuova assegnazione
 * (durata $durataTurno) non deve superare ordinario+straordinario residui.
 * Un bidello segnato assente occupa comunque lo slot (a), quindi risulta
 * escluso in automatico anche come candidato sostituto di se stesso.
 *
 * Ogni elemento del risultato include 'situazione' (da situazioneOreSettimana)
 * per mostrare le ore residue nel select.
 */
function bidelliDisponibili(PDO $pdo, int $plessoId, string $data, string $turnoGiorno, float $durataTurno, string $inizioSettimana, string $fineSettimana): array
{
    $bidelli = $pdo->query('SELECT id, nome, cognome, ore_settimanali, ore_straordinario_max FROM bidelli WHERE attivo = 1 ORDER BY cognome, nome')->fetchAll();

    $stmt = $pdo->prepare('SELECT bidello_id, turno_giorno, plesso_id FROM turni WHERE data = :data');
    $stmt->execute(['data' => $data]);

    $occupazione = [];
    foreach ($stmt->fetchAll() as $riga) {
        $occupazione[$riga['bidello_id']][$riga['turno_giorno']] = (int) $riga['plesso_id'];
    }

    $altroTurno = $turnoGiorno === 'mattina' ? 'pomeriggio' : 'mattina';

    $risultato = [];
    foreach ($bidelli as $b) {
        $occ = $occupazione[$b['id']] ?? [];
        if (isset($occ[$turnoGiorno])) {
            continue;
        }
        if (isset($occ[$altroTurno]) && $occ[$altroTurno] !== $plessoId) {
            continue;
        }

        $situazione = situazioneOreSettimana(
            $pdo,
            (int) $b['id'],
            (int) $b['ore_settimanali'],
            (int) $b['ore_straordinario_max'],
            $inizioSettimana,
            $fineSettimana
        );

        if (superaTettoStraordinario($situazione, $durataTurno)) {
            continue;
        }

        $risultato[] = [
            'id' => (int) $b['id'],
            'nome' => $b['nome'],
            'cognome' => $b['cognome'],
            'situazione' => $situazione,
        ];
    }

    return $risultato;
}

function formattaOre(float $ore): string
{
    return rtrim(rtrim(number_format($ore, 1, '.', ''), '0'), '.');
}

/* ---------- Validazione contesto ---------- */

$origine = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$sostitutoDiTurnoId = (int) ($origine['sostituto_di_turno_id'] ?? 0);
$modalitaSostituto = $sostitutoDiTurnoId > 0;
$turnoOriginale = null;
$plessoId = 0;
$data = '';
$turnoGiorno = '';
$plesso = null;
$contestoValido = false;

if ($modalitaSostituto) {
    $stmtOrig = $pdo->prepare(
        'SELECT t.id, t.plesso_id, t.data, t.turno_giorno, t.stato, b.nome, b.cognome
         FROM turni t JOIN bidelli b ON b.id = t.bidello_id
         WHERE t.id = :id'
    );
    $stmtOrig->execute(['id' => $sostitutoDiTurnoId]);
    $turnoOriginale = $stmtOrig->fetch();

    $contestoValido = $turnoOriginale
        && $turnoOriginale['stato'] === 'assente'
        && contaRecordCollegati($pdo, 'turni', 'sostituto_di_turno_id', $sostitutoDiTurnoId) === 0;

    if ($contestoValido) {
        $plessoId = (int) $turnoOriginale['plesso_id'];
        $data = $turnoOriginale['data'];
        $turnoGiorno = $turnoOriginale['turno_giorno'];

        $stmtPlesso = $pdo->prepare('SELECT id, nome, orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine FROM plessi WHERE id = :id');
        $stmtPlesso->execute(['id' => $plessoId]);
        $plesso = $stmtPlesso->fetch();
        $contestoValido = (bool) $plesso;
    }
} else {
    $plessoId = (int) ($origine['plesso_id'] ?? 0);
    $data = (string) ($origine['data'] ?? '');
    $turnoGiorno = (string) ($origine['turno_giorno'] ?? '');

    $dataValidaTmp = DateTime::createFromFormat('Y-m-d', $data);
    $contestoValido = $plessoId > 0
        && in_array($turnoGiorno, ['mattina', 'pomeriggio'], true)
        && $dataValidaTmp !== false
        && $dataValidaTmp->format('Y-m-d') === $data;

    if ($contestoValido) {
        $stmtPlesso = $pdo->prepare('SELECT id, nome, orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine FROM plessi WHERE id = :id AND attivo = 1');
        $stmtPlesso->execute(['id' => $plessoId]);
        $plesso = $stmtPlesso->fetch();
        $contestoValido = (bool) $plesso;
    }
}

$settimana = (string) ($origine['settimana'] ?? $data);

if (!$contestoValido) {
    http_response_code(400);
    die($modalitaSostituto
        ? 'Turno originale non trovato, non più in stato "assente", o già dotato di un sostituto.'
        : 'Contesto non valido per l\'assegnazione (plesso, data o turno mancante/errato).');
}

$dataValida = DateTime::createFromFormat('Y-m-d', $data);

/* ---------- Durata del turno e validità orari plesso ---------- */

$durataTurno = durataTurnoOre($plesso, $turnoGiorno);

if ($durataTurno === null) {
    http_response_code(400);
    die('Orari non configurati: il plesso "' . htmlspecialchars($plesso['nome']) . '" non ha gli orari di ' . ($turnoGiorno === 'mattina' ? 'mattina' : 'pomeriggio') . ' impostati, quindi non è possibile calcolare le ore del turno. Imposta gli orari del plesso prima di procedere.');
}

/* ---------- Settimana di riferimento per il monte ore (lun-ven della $data) ---------- */

$isoGiorno = (int) $dataValida->format('N');
$lunediSettimana = (clone $dataValida)->modify('-' . ($isoGiorno - 1) . ' days');
$venerdiSettimana = (clone $lunediSettimana)->modify('+4 days');
$inizioSettimanaOre = $lunediSettimana->format('Y-m-d');
$fineSettimanaOre = $venerdiSettimana->format('Y-m-d');

$errori = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $errori[] = 'Richiesta non valida. Riprova.';
    }

    $bidelloId = (int) ($_POST['bidello_id'] ?? 0);
    $disponibiliPost = bidelliDisponibili($pdo, $plessoId, $data, $turnoGiorno, $durataTurno, $inizioSettimanaOre, $fineSettimanaOre);
    $idDisponibili = array_column($disponibiliPost, 'id');

    if ($bidelloId <= 0 || !in_array($bidelloId, $idDisponibili, true)) {
        $errori[] = 'Il bidello selezionato non è (più) disponibile per questo turno (occupato altrove, vincolo plesso/giorno, o supererebbe anche il tetto straordinari). Riprova.';
    }

    if (!$errori) {
        if ($modalitaSostituto) {
            $pdo->prepare(
                "INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato, sostituto_di_turno_id)
                 VALUES (:plesso_id, :bidello_id, :data, :turno_giorno, 'sostituito', :sostituto_di_turno_id)"
            )->execute([
                'plesso_id' => $plessoId,
                'bidello_id' => $bidelloId,
                'data' => $data,
                'turno_giorno' => $turnoGiorno,
                'sostituto_di_turno_id' => $sostitutoDiTurnoId,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO turni (plesso_id, bidello_id, data, turno_giorno, stato)
                 VALUES (:plesso_id, :bidello_id, :data, :turno_giorno, 'pianificato')"
            )->execute([
                'plesso_id' => $plessoId,
                'bidello_id' => $bidelloId,
                'data' => $data,
                'turno_giorno' => $turnoGiorno,
            ]);
        }

        header('Location: turni.php?settimana=' . $settimana . '&msg=assegnato');
        exit;
    }
}

$disponibili = bidelliDisponibili($pdo, $plessoId, $data, $turnoGiorno, $durataTurno, $inizioSettimanaOre, $fineSettimanaOre);

$giorniItaliani = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
$mesiItaliani = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$dataFormattata = $giorniItaliani[(int) $dataValida->format('w')] . ' ' . $dataValida->format('j') . ' ' . $mesiItaliani[(int) $dataValida->format('n')] . ' ' . $dataValida->format('Y');

$paginaTitolo = $modalitaSostituto ? 'Assegna sostituto' : 'Nuova assegnazione';
$paginaAttiva = 'turni';
require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title"><?= $modalitaSostituto ? 'Assegna sostituto' : 'Nuova assegnazione' ?></div>
        <div class="panel__sub"><a href="turni.php?settimana=<?= htmlspecialchars($settimana) ?>">← torna alla griglia</a></div>
    </div>

    <div style="padding: var(--space-6); max-width: 480px;">
        <?php if ($modalitaSostituto): ?>
            <div class="note-banner" style="margin-bottom: var(--space-6);">
                Sostituzione di <strong><?= htmlspecialchars($turnoOriginale['nome'] . ' ' . $turnoOriginale['cognome']) ?></strong> (assente). Plesso, giorno e turno sono bloccati: uguali al turno originale.
            </div>
        <?php endif; ?>

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
            <div><?= htmlspecialchars($dataFormattata) ?> — <?= $turnoGiorno === 'mattina' ? 'Mattina' : 'Pomeriggio' ?> (<?= formattaOre($durataTurno) ?>h)</div>
        </div>

        <form method="post" action="turno-assegna.php" id="form-assegnazione">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
            <input type="hidden" name="plesso_id" value="<?= (int) $plessoId ?>">
            <input type="hidden" name="data" value="<?= htmlspecialchars($data) ?>">
            <input type="hidden" name="turno_giorno" value="<?= htmlspecialchars($turnoGiorno) ?>">
            <input type="hidden" name="settimana" value="<?= htmlspecialchars($settimana) ?>">
            <?php if ($modalitaSostituto): ?>
                <input type="hidden" name="sostituto_di_turno_id" value="<?= (int) $sostitutoDiTurnoId ?>">
            <?php endif; ?>

            <div class="form-field" style="margin-bottom: var(--space-5);">
                <label for="bidello_id"><?= $modalitaSostituto ? 'Sostituto' : 'Bidello' ?></label>
                <select class="form-input" id="bidello_id" name="bidello_id" required <?= !$disponibili ? 'disabled' : '' ?>>
                    <?php if (!$disponibili): ?>
                        <option value="">Nessun bidello disponibile per questo turno</option>
                    <?php else: ?>
                        <option value="">— seleziona —</option>
                        <?php foreach ($disponibili as $bidello): ?>
                            <option value="<?= (int) $bidello['id'] ?>"
                                    data-nome-completo="<?= htmlspecialchars($bidello['cognome'] . ' ' . $bidello['nome']) ?>"
                                    data-residuo-ordinario="<?= $bidello['situazione']['ore_residue_ordinarie'] ?>">
                                <?= htmlspecialchars($bidello['cognome'] . ' ' . $bidello['nome']) ?>
                                (residue: <?= formattaOre($bidello['situazione']['ore_residue_ordinarie']) ?>h ord.<?php if ($bidello['situazione']['ore_residue_straordinario'] > 0): ?>, <?= formattaOre($bidello['situazione']['ore_residue_straordinario']) ?>h straord.<?php endif; ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (!$disponibili): ?>
                    <div class="form-hint">Tutti i bidelli attivi sono già assegnati altrove in questo turno, impegnati nell'altro turno dello stesso giorno in un plesso diverso, o senza margine ore (ordinario + straordinario) sufficiente.</div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary" <?= !$disponibili ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-check"></i> <?= $modalitaSostituto ? 'Assegna sostituto' : 'Assegna' ?>
                </button>
                <a class="btn btn--secondary" href="turni.php?settimana=<?= htmlspecialchars($settimana) ?>">Annulla</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const durataTurno = <?= json_encode($durataTurno) ?>;
    const form = document.getElementById('form-assegnazione');
    const select = document.getElementById('bidello_id');

    if (!form || !select) {
        return;
    }

    form.addEventListener('submit', function (e) {
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            return;
        }

        const residuoOrdinario = parseFloat(opt.dataset.residuoOrdinario);
        if (durataTurno > residuoOrdinario) {
            const nome = opt.dataset.nomeCompleto;
            const messaggio = 'Questa assegnazione (' + durataTurno + 'h) porta ' + nome + ' in straordinario questa settimana (residuo ordinario: ' + residuoOrdinario + 'h). Confermi?';
            if (!confirm(messaggio)) {
                e.preventDefault();
            }
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
