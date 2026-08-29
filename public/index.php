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

/* ---------- Settimana selezionata per il pannello ore (indipendente da turni.php) ---------- */

$parametroSettimana = $_GET['settimana'] ?? null;
try {
    $riferimentoSettimana = $parametroSettimana ? new DateTime($parametroSettimana) : new DateTime();
} catch (Exception $e) {
    $riferimentoSettimana = new DateTime();
}

$giornoIsoOre = (int) $riferimentoSettimana->format('N');
$lunediOre = (clone $riferimentoSettimana)->modify('-' . ($giornoIsoOre - 1) . ' days');
$venerdiOre = (clone $lunediOre)->modify('+4 days');

$settimanaOrePrecedente = (clone $lunediOre)->modify('-7 days')->format('Y-m-d');
$settimanaOreSuccessiva = (clone $lunediOre)->modify('+7 days')->format('Y-m-d');

$mesiAbbrOre = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
if ($lunediOre->format('n') === $venerdiOre->format('n')) {
    $rangeOreTesto = $lunediOre->format('j') . ' – ' . $venerdiOre->format('j') . ' ' . $mesiAbbrOre[(int) $venerdiOre->format('n')] . ' ' . $venerdiOre->format('Y');
} else {
    $rangeOreTesto = $lunediOre->format('j') . ' ' . $mesiAbbrOre[(int) $lunediOre->format('n')] . ' – ' . $venerdiOre->format('j') . ' ' . $mesiAbbrOre[(int) $venerdiOre->format('n')] . ' ' . $venerdiOre->format('Y');
}

$bidelliOre = $pdo->query(
    'SELECT id, nome, cognome, ore_settimanali, ore_straordinario_max
     FROM bidelli WHERE attivo = 1 ORDER BY cognome, nome'
)->fetchAll();

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

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Copertura settimanale</div>
        <div class="panel__sub">Placeholder statico</div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>Plesso</th>
                <th>Lun</th>
                <th>Mar</th>
                <th>Mer</th>
                <th>Gio</th>
                <th>Ven</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Plesso Centrale</td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
            </tr>
            <tr>
                <td>Plesso Nord</td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--danger">Sotto soglia</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
            </tr>
            <tr>
                <td>Plesso Sud</td>
                <td><span class="badge badge--warn">Sostituito</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
                <td><span class="badge badge--ok">Coperto</span></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="note-banner">
    <strong>Nota:</strong> la tabella "Copertura settimanale" sopra è dati hardcoded temporanei, non letti dal database. La colleghiamo ai turni reali nel prossimo step.
</div>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Ore bidelli — settimana corrente</div>
        <div class="week-nav__controls">
            <a class="btn btn--secondary" href="index.php?settimana=<?= $settimanaOrePrecedente ?>" title="Settimana precedente">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <a class="btn btn--secondary" href="index.php"><?= htmlspecialchars($rangeOreTesto) ?></a>
            <a class="btn btn--secondary" href="index.php?settimana=<?= $settimanaOreSuccessiva ?>" title="Settimana successiva">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
        </div>
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
                        $lunediOre->format('Y-m-d'),
                        $venerdiOre->format('Y-m-d')
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
