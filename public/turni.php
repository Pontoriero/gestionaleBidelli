<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crud-helpers.php';

avviaSessione();
richiediLogin();

$pdo = getConnessione();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'segna_assente') {
    richiediRuoloDsga();

    $settimanaRedirect = (string) ($_POST['settimana'] ?? '');
    verificaCsrfOFallisci('turni.php?settimana=' . $settimanaRedirect . '&msg=errore');

    $turnoId = (int) ($_POST['id'] ?? 0);

    if ($turnoId <= 0) {
        header('Location: turni.php?settimana=' . $settimanaRedirect . '&msg=errore');
        exit;
    }

    // UPDATE condizionato sullo stato attuale: atomico, evita la finestra
    // tra un SELECT di controllo e la UPDATE (race condition).
    $stmt = $pdo->prepare("UPDATE turni SET stato = 'assente' WHERE id = :id AND stato = 'pianificato'");
    $stmt->execute(['id' => $turnoId]);

    header('Location: turni.php?settimana=' . $settimanaRedirect . '&msg=' . ($stmt->rowCount() > 0 ? 'segnato_assente' : 'errore'));
    exit;
}

/* ---------- Calcolo settimana selezionata (lunedì-venerdì) ---------- */

$parametroSettimana = $_GET['settimana'] ?? null;
try {
    $riferimento = $parametroSettimana ? new DateTime($parametroSettimana) : new DateTime();
} catch (Exception $e) {
    $riferimento = new DateTime();
}

$giornoIso = (int) $riferimento->format('N'); // 1 (lun) .. 7 (dom)
$lunedi = (clone $riferimento)->modify('-' . ($giornoIso - 1) . ' days');
$venerdi = (clone $lunedi)->modify('+4 days');

$settimanaPrecedente = (clone $lunedi)->modify('-7 days')->format('Y-m-d');
$settimanaSuccessiva = (clone $lunedi)->modify('+7 days')->format('Y-m-d');

$mesiAbbr = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$giorniAbbr = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven'];

if ($lunedi->format('n') === $venerdi->format('n')) {
    $rangeTesto = $lunedi->format('j') . ' – ' . $venerdi->format('j') . ' ' . $mesiAbbr[(int) $venerdi->format('n')] . ' ' . $venerdi->format('Y');
} else {
    $rangeTesto = $lunedi->format('j') . ' ' . $mesiAbbr[(int) $lunedi->format('n')] . ' – ' . $venerdi->format('j') . ' ' . $mesiAbbr[(int) $venerdi->format('n')] . ' ' . $venerdi->format('Y');
}

$giorniSettimana = [];
for ($i = 0; $i < 5; $i++) {
    $giorno = (clone $lunedi)->modify("+{$i} days");
    $giorniSettimana[] = [
        'data' => $giorno->format('Y-m-d'),
        'etichetta' => $giorniAbbr[$i],
        'data_breve' => $giorno->format('d/m'),
    ];
}

/* ---------- Dati griglia ---------- */

$plessi = $pdo->query(
    'SELECT id, nome, min_bidelli_mattina, min_bidelli_pomeriggio
     FROM plessi WHERE attivo = 1 ORDER BY nome'
)->fetchAll();

$stmtTurni = $pdo->prepare(
    'SELECT t.id, t.plesso_id, t.data, t.turno_giorno, t.stato, t.sostituto_di_turno_id,
            t.bidello_id, b.nome, b.cognome
     FROM turni t
     JOIN bidelli b ON b.id = t.bidello_id
     WHERE t.data BETWEEN :inizio AND :fine
     ORDER BY t.plesso_id, t.data, t.turno_giorno, b.cognome'
);
$stmtTurni->execute([
    'inizio' => $lunedi->format('Y-m-d'),
    'fine' => $venerdi->format('Y-m-d'),
]);

$celle = [];
foreach ($stmtTurni->fetchAll() as $riga) {
    $celle[$riga['plesso_id']][$riga['data']][$riga['turno_giorno']][] = $riga;
}

function contaCopertura(array $righe): int
{
    return count(array_filter($righe, static fn($r) => in_array($r['stato'], ['pianificato', 'sostituito'], true)));
}

$messaggi = [
    'assegnato'       => ['tipo' => 'ok', 'testo' => 'Assegnazione salvata correttamente.'],
    'segnato_assente' => ['tipo' => 'ok', 'testo' => 'Turno segnato come assente.'],
    'errore'          => ['tipo' => 'danger', 'testo' => 'Richiesta non valida o turno non più nello stato atteso. Riprova.'],
];
$msg = $messaggi[$_GET['msg'] ?? ''] ?? null;

$paginaTitolo = 'Turni';
$paginaAttiva = 'turni';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
    <div class="form-error" style="<?= $msg['tipo'] === 'ok' ? 'background:var(--status-ok-bg);color:var(--status-ok);' : '' ?>">
        <?= htmlspecialchars($msg['testo']) ?>
    </div>
<?php endif; ?>

<div class="week-nav">
    <div class="week-nav__range"><?= htmlspecialchars($rangeTesto) ?></div>
    <div class="week-nav__controls">
        <a class="btn btn--secondary" href="turni.php?settimana=<?= $settimanaPrecedente ?>" title="Settimana precedente">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <a class="btn btn--secondary" href="turni.php">Oggi</a>
        <a class="btn btn--secondary" href="turni.php?settimana=<?= $settimanaSuccessiva ?>" title="Settimana successiva">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
</div>

<div class="panel">
    <div style="overflow-x: auto;">
        <table class="table schedule-table">
            <thead>
                <tr>
                    <th>Plesso</th>
                    <?php foreach ($giorniSettimana as $giorno): ?>
                        <th><?= $giorno['etichetta'] ?><span class="day-date"><?= $giorno['data_breve'] ?></span></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plessi as $plesso): ?>
                    <tr>
                        <td><?= htmlspecialchars($plesso['nome']) ?></td>
                        <?php foreach ($giorniSettimana as $giorno): ?>
                            <td>
                                <?php foreach (['mattina' => 'Mattina', 'pomeriggio' => 'Pomeriggio'] as $turnoGiorno => $etichettaTurno): ?>
                                    <?php
                                    $righe = $celle[$plesso['id']][$giorno['data']][$turnoGiorno] ?? [];
                                    $minimo = (int) $plesso["min_bidelli_{$turnoGiorno}"];
                                    $coperti = contaCopertura($righe);
                                    $ok = $coperti >= $minimo;
                                    ?>
                                    <div class="turno-block">
                                        <div class="turno-block__head">
                                            <span class="turno-block__label"><?= $etichettaTurno ?></span>
                                            <span style="display:flex; align-items:center; gap:6px;">
                                                <span class="badge <?= $ok ? 'badge--ok' : 'badge--danger' ?>">
                                                    <?= $ok ? 'Coperto' : 'Sotto soglia' ?> <?= $coperti ?>/<?= $minimo ?>
                                                </span>
                                                <?php if (isDsga()): ?>
                                                    <a class="turno-block__add"
                                                       href="turno-assegna.php?plesso_id=<?= (int) $plesso['id'] ?>&data=<?= $giorno['data'] ?>&turno_giorno=<?= $turnoGiorno ?>&settimana=<?= $lunedi->format('Y-m-d') ?>"
                                                       title="Aggiungi assegnazione">
                                                        <i class="fa-solid fa-plus"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php if ($righe): ?>
                                            <div class="turno-chips">
                                                <?php foreach ($righe as $riga): ?>
                                                    <?php
                                                    $classeChip = 'chip';
                                                    if ($riga['stato'] === 'sostituito') {
                                                        $classeChip .= ' chip--sostituito';
                                                    } elseif ($riga['stato'] === 'assente') {
                                                        $classeChip .= ' chip--assente';
                                                    }
                                                    ?>
                                                    <span class="<?= $classeChip ?>" title="<?= htmlspecialchars($riga['nome'] . ' ' . $riga['cognome'] . ' — ' . ucfirst($riga['stato'])) ?>">
                                                        <?= htmlspecialchars($riga['cognome'] . ' ' . mb_substr($riga['nome'], 0, 1) . '.') ?>
                                                        <?php if (isDsga() && $riga['stato'] === 'pianificato'): ?>
                                                            <span class="chip__azioni">
                                                                <form class="inline-form" method="post" action="turni.php"
                                                                      onsubmit="return confirm('Segnare assente ' + <?= htmlspecialchars(json_encode($riga['nome'] . ' ' . $riga['cognome']), ENT_QUOTES) ?> + '?');">
                                                                    <input type="hidden" name="azione" value="segna_assente">
                                                                    <input type="hidden" name="id" value="<?= (int) $riga['id'] ?>">
                                                                    <input type="hidden" name="settimana" value="<?= $lunedi->format('Y-m-d') ?>">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
                                                                    <button type="submit" title="Segna assente">
                                                                        <i class="fa-solid fa-user-slash"></i>
                                                                    </button>
                                                                </form>
                                                            </span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="form-hint">Nessuno assegnato</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$plessi): ?>
                    <tr>
                        <td colspan="6">Nessun plesso attivo.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
