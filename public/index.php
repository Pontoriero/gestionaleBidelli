<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/turni-helpers.php';

avviaSessione();
richiediLogin();

$pdo = getConnessione();

$plessiAttivi = (int) $pdo->query('SELECT COUNT(*) FROM plessi WHERE attivo = 1')->fetchColumn();
$bidelliAttivi = (int) $pdo->query('SELECT COUNT(*) FROM bidelli WHERE attivo = 1')->fetchColumn();

$turniScopertiOggi = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT p.id
        FROM plessi p
        CROSS JOIN (SELECT 'mattina' AS turno_giorno UNION ALL SELECT 'pomeriggio') tg
        LEFT JOIN turni t
            ON t.plesso_id = p.id
            AND t.data = CURDATE()
            AND t.turno_giorno = tg.turno_giorno
            AND t.stato IN ('pianificato', 'sostituito')
        WHERE p.attivo = 1
        GROUP BY p.id, tg.turno_giorno, p.min_bidelli_mattina, p.min_bidelli_pomeriggio
        HAVING COUNT(t.id) < CASE tg.turno_giorno
            WHEN 'mattina' THEN p.min_bidelli_mattina
            ELSE p.min_bidelli_pomeriggio
        END
    ) AS scoperti"
)->fetchColumn();

/* ---------- Vista selezionata: giorno | settimana | mese ---------- */

$vista = $_GET['vista'] ?? 'settimana';
if (!in_array($vista, ['giorno', 'settimana', 'mese'], true)) {
    $vista = 'settimana';
}

$mesiAbbr = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$mesiCompleti = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$giorniAbbr = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven'];
$giorniCompleti = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

$giorniColonne = [];
$giorniMese = [];

if ($vista === 'giorno') {
    $parametroData = $_GET['data'] ?? null;
    try {
        $dataRif = $parametroData ? new DateTime($parametroData) : new DateTime();
    } catch (Exception $e) {
        $dataRif = new DateTime();
    }

    $navPrecedente = 'vista=giorno&data=' . (clone $dataRif)->modify('-1 day')->format('Y-m-d');
    $navSuccessiva = 'vista=giorno&data=' . (clone $dataRif)->modify('+1 day')->format('Y-m-d');
    $navOggi = 'vista=giorno';
    $rangeTesto = $giorniCompleti[(int) $dataRif->format('w')] . ' ' . $dataRif->format('j') . ' ' . $mesiCompleti[(int) $dataRif->format('n')] . ' ' . $dataRif->format('Y');

    $giorniColonne[] = ['data' => $dataRif->format('Y-m-d'), 'etichetta' => $giorniAbbr[((int) $dataRif->format('N')) - 1] ?? $dataRif->format('D')];

    $inizioRange = $dataRif->format('Y-m-d');
    $fineRange = $dataRif->format('Y-m-d');
} elseif ($vista === 'mese') {
    $parametroMese = $_GET['mese'] ?? null;
    try {
        $meseRif = $parametroMese ? new DateTime($parametroMese . '-01') : new DateTime();
    } catch (Exception $e) {
        $meseRif = new DateTime();
    }

    $primoGiornoMese = (clone $meseRif)->modify('first day of this month');
    $ultimoGiornoMese = (clone $meseRif)->modify('last day of this month');

    $navPrecedente = 'vista=mese&mese=' . (clone $primoGiornoMese)->modify('-1 month')->format('Y-m');
    $navSuccessiva = 'vista=mese&mese=' . (clone $primoGiornoMese)->modify('+1 month')->format('Y-m');
    $navOggi = 'vista=mese';
    $rangeTesto = ucfirst($mesiCompleti[(int) $primoGiornoMese->format('n')]) . ' ' . $primoGiornoMese->format('Y');

    $cursore = clone $primoGiornoMese;
    while ($cursore <= $ultimoGiornoMese) {
        if ((int) $cursore->format('N') <= 5) {
            $giorniMese[] = ['data' => $cursore->format('Y-m-d'), 'etichetta' => $cursore->format('j')];
        }
        $cursore = $cursore->modify('+1 day');
    }

    $inizioRange = $primoGiornoMese->format('Y-m-d');
    $fineRange = $ultimoGiornoMese->format('Y-m-d');
} else {
    $vista = 'settimana';
    $parametroSettimana = $_GET['settimana'] ?? null;
    try {
        $riferimentoSettimana = $parametroSettimana ? new DateTime($parametroSettimana) : new DateTime();
    } catch (Exception $e) {
        $riferimentoSettimana = new DateTime();
    }

    $giornoIsoOre = (int) $riferimentoSettimana->format('N');
    $lunediOre = (clone $riferimentoSettimana)->modify('-' . ($giornoIsoOre - 1) . ' days');
    $venerdiOre = (clone $lunediOre)->modify('+4 days');

    $navPrecedente = 'vista=settimana&settimana=' . (clone $lunediOre)->modify('-7 days')->format('Y-m-d');
    $navSuccessiva = 'vista=settimana&settimana=' . (clone $lunediOre)->modify('+7 days')->format('Y-m-d');
    $navOggi = 'vista=settimana';

    if ($lunediOre->format('n') === $venerdiOre->format('n')) {
        $rangeTesto = $lunediOre->format('j') . ' – ' . $venerdiOre->format('j') . ' ' . $mesiAbbr[(int) $venerdiOre->format('n')] . ' ' . $venerdiOre->format('Y');
    } else {
        $rangeTesto = $lunediOre->format('j') . ' ' . $mesiAbbr[(int) $lunediOre->format('n')] . ' – ' . $venerdiOre->format('j') . ' ' . $mesiAbbr[(int) $venerdiOre->format('n')] . ' ' . $venerdiOre->format('Y');
    }

    for ($i = 0; $i < 5; $i++) {
        $giorno = (clone $lunediOre)->modify("+{$i} days");
        $giorniColonne[] = ['data' => $giorno->format('Y-m-d'), 'etichetta' => $giorniAbbr[$i]];
    }

    $inizioRange = $lunediOre->format('Y-m-d');
    $fineRange = $venerdiOre->format('Y-m-d');
}

/* ---------- Copertura ---------- */

$plessi = $pdo->query(
    'SELECT id, nome, min_bidelli_mattina, min_bidelli_pomeriggio
     FROM plessi WHERE attivo = 1 ORDER BY nome'
)->fetchAll();

$stmtConteggi = $pdo->prepare(
    "SELECT plesso_id, data, turno_giorno, COUNT(*) AS assegnati
     FROM turni
     WHERE data BETWEEN :inizio AND :fine
       AND stato IN ('pianificato', 'sostituito')
     GROUP BY plesso_id, data, turno_giorno"
);
$stmtConteggi->execute(['inizio' => $inizioRange, 'fine' => $fineRange]);

$conteggi = [];
foreach ($stmtConteggi->fetchAll() as $r) {
    $conteggi[$r['plesso_id']][$r['data']][$r['turno_giorno']] = (int) $r['assegnati'];
}

/* ---------- Ore bidelli — solo vista settimana (monte ore è un concetto settimanale) ---------- */

if ($vista === 'settimana') {
    $bidelliOre = $pdo->query(
        'SELECT id, nome, cognome, ore_settimanali, ore_straordinario_max
         FROM bidelli WHERE attivo = 1 ORDER BY cognome, nome'
    )->fetchAll();
}

function formattaOreIndex(float $ore): string
{
    return rtrim(rtrim(number_format($ore, 1, '.', ''), '0'), '.');
}

$paginaTitolo = 'Dashboard';
$paginaAttiva = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-card__top">
            <div class="stat-card__icon"><i class="fa-solid fa-school"></i></div>
        </div>
        <div class="stat-card__value"><?= $plessiAttivi ?></div>
        <div class="stat-card__label">Plessi attivi</div>
    </div>

    <div class="stat-card">
        <div class="stat-card__top">
            <div class="stat-card__icon"><i class="fa-solid fa-users"></i></div>
        </div>
        <div class="stat-card__value"><?= $bidelliAttivi ?></div>
        <div class="stat-card__label">Bidelli attivi</div>
    </div>

    <div class="stat-card <?= $turniScopertiOggi > 0 ? 'stat-card--alert' : '' ?>">
        <div class="stat-card__top">
            <div class="stat-card__icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
        <div class="stat-card__value"><?= $turniScopertiOggi ?></div>
        <div class="stat-card__label">Turni scoperti oggi</div>
    </div>
</div>

<div class="view-tabs">
    <a class="btn <?= $vista === 'giorno' ? 'btn--primary' : 'btn--secondary' ?>" href="index.php?vista=giorno">Giorno</a>
    <a class="btn <?= $vista === 'settimana' ? 'btn--primary' : 'btn--secondary' ?>" href="index.php?vista=settimana">Settimana</a>
    <a class="btn <?= $vista === 'mese' ? 'btn--primary' : 'btn--secondary' ?>" href="index.php?vista=mese">Mese</a>
</div>

<div class="week-nav">
    <div class="week-nav__range"><?= htmlspecialchars($rangeTesto) ?></div>
    <div class="week-nav__controls">
        <a class="btn btn--secondary" href="index.php?<?= $navPrecedente ?>" title="Precedente">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <a class="btn btn--secondary" href="index.php?<?= $navOggi ?>">Oggi</a>
        <a class="btn btn--secondary" href="index.php?<?= $navSuccessiva ?>" title="Successivo">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
</div>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title"><?= $vista === 'mese' ? 'Copertura mensile' : 'Copertura' ?></div>
        <?php if ($vista === 'mese'): ?>
            <div class="panel__sub">Vista aggregata, sola lettura — dettaglio in Turni</div>
        <?php endif; ?>
    </div>

    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Plesso</th>
                    <?php if ($vista === 'mese'): ?>
                        <?php foreach ($giorniMese as $giorno): ?>
                            <th><?= $giorno['etichetta'] ?></th>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($giorniColonne as $giorno): ?>
                            <th><?= $giorno['etichetta'] ?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plessi as $plesso): ?>
                    <tr>
                        <td><?= htmlspecialchars($plesso['nome']) ?></td>
                        <?php if ($vista === 'mese'): ?>
                            <?php foreach ($giorniMese as $giorno): ?>
                                <?php $stato = statoGiorno($plesso, $conteggi[$plesso['id']][$giorno['data']] ?? []); ?>
                                <td><span class="badge <?= $stato['classe'] ?>"><?= $stato['testo'] ?></span></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($giorniColonne as $giorno): ?>
                                <?php
                                $conteggiGiorno = $conteggi[$plesso['id']][$giorno['data']] ?? [];
                                $badgeMattina = badgeTurno($conteggiGiorno['mattina'] ?? 0, (int) $plesso['min_bidelli_mattina']);
                                $badgePomeriggio = badgeTurno($conteggiGiorno['pomeriggio'] ?? 0, (int) $plesso['min_bidelli_pomeriggio']);
                                ?>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                                        <span class="badge <?= $badgeMattina['classe'] ?>"><?= $badgeMattina['testo'] ?></span>
                                        <span class="badge <?= $badgePomeriggio['classe'] ?>"><?= $badgePomeriggio['testo'] ?></span>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$plessi): ?>
                    <tr>
                        <td colspan="<?= ($vista === 'mese' ? count($giorniMese) : count($giorniColonne)) + 1 ?>">Nessun plesso attivo.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($vista === 'settimana'): ?>
    <div class="panel">
        <div class="panel__header">
            <div class="panel__title">Ore bidelli — settimana selezionata</div>
            <div class="panel__sub"><?= count($bidelliOre) ?> bidelli attivi</div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Bidello</th>
                        <th>Ordinarie assegnate</th>
                        <th>Ordinarie residue</th>
                        <th>Straordinario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bidelliOre as $bidello): ?>
                        <?php
                        $situazione = situazioneOreSettimana(
                            $pdo,
                            (int) $bidello['id'],
                            (int) $bidello['ore_settimanali'],
                            (int) $bidello['ore_straordinario_max'],
                            $inizioRange,
                            $fineRange
                        );
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($bidello['cognome'] . ' ' . $bidello['nome']) ?>
                                <?php if (!$situazione['completo']): ?>
                                    <span class="form-hint" title="Alcuni turni assegnati sono su plessi con orari non configurati, non conteggiati">≈</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formattaOreIndex($situazione['ore_ordinarie_assegnate']) ?>h</td>
                            <td><?= formattaOreIndex($situazione['ore_residue_ordinarie']) ?>h</td>
                            <td>
                                <?php if ($situazione['ore_straordinario_assegnate'] > 0): ?>
                                    <span class="badge badge--warn"><?= formattaOreIndex($situazione['ore_straordinario_assegnate']) ?>h</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$bidelliOre): ?>
                        <tr>
                            <td colspan="4">Nessun bidello attivo.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
