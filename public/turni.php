<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crud-helpers.php';
require_once __DIR__ . '/../includes/turni-helpers.php';
require_once __DIR__ . '/../includes/export-helpers.php';

avviaSessione();
richiediLogin();

$pdo = getConnessione();

/* ---------- Azioni POST (invariate nella logica, solo il "ritorno" cambia) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'segna_assente') {
    richiediRuoloDsga();

    $ritornoAzione = ritornoSicuro((string) ($_POST['ritorno'] ?? ''), 'vista=settimana');
    verificaCsrfOFallisci('turni.php?' . $ritornoAzione . '&msg=errore');

    $turnoId = (int) ($_POST['id'] ?? 0);

    if ($turnoId <= 0) {
        header('Location: turni.php?' . $ritornoAzione . '&msg=errore');
        exit;
    }

    // UPDATE condizionato sullo stato attuale: atomico, evita la finestra
    // tra un SELECT di controllo e la UPDATE (race condition).
    $stmt = $pdo->prepare("UPDATE turni SET stato = 'assente' WHERE id = :id AND stato = 'pianificato'");
    $stmt->execute(['id' => $turnoId]);

    header('Location: turni.php?' . $ritornoAzione . '&msg=' . ($stmt->rowCount() > 0 ? 'segnato_assente' : 'errore'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina_turno') {
    richiediRuoloDsga();

    $ritornoAzione = ritornoSicuro((string) ($_POST['ritorno'] ?? ''), 'vista=settimana');
    verificaCsrfOFallisci('turni.php?' . $ritornoAzione . '&msg=errore');

    $turnoId = (int) ($_POST['id'] ?? 0);

    if ($turnoId <= 0) {
        header('Location: turni.php?' . $ritornoAzione . '&msg=errore');
        exit;
    }

    $esito = eliminaSeSenzaDipendenze($pdo, 'turni', 'turni', 'sostituto_di_turno_id', $turnoId);

    header('Location: turni.php?' . $ritornoAzione . '&msg=' . ($esito === 'ha_dipendenze' ? 'ha_sostituto' : $esito));
    exit;
}

/* ---------- Vista selezionata: giorno | settimana | mese ---------- */

$vista = $_GET['vista'] ?? 'settimana';
if (!in_array($vista, ['giorno', 'settimana', 'mese'], true)) {
    $vista = 'settimana';
}

$mesiAbbr = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$mesiCompleti = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$giorniAbbr = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven'];
$giorniCompleti = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

$giorniColonne = [];   // usato da vista giorno/settimana (tabella dettagliata)
$giorniMese = [];      // usato da vista mese (tabella aggregata)

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

    $giorniColonne[] = [
        'data' => $dataRif->format('Y-m-d'),
        'etichetta' => $giorniAbbr[((int) $dataRif->format('N')) - 1] ?? $dataRif->format('D'),
        'data_breve' => $dataRif->format('d/m'),
    ];

    $inizioRange = $dataRif->format('Y-m-d');
    $fineRange = $dataRif->format('Y-m-d');
    $ritornoCorrente = 'vista=giorno&data=' . $dataRif->format('Y-m-d');
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
    $ritornoCorrente = 'vista=mese&mese=' . $primoGiornoMese->format('Y-m');
} else {
    $vista = 'settimana';
    $parametroSettimana = $_GET['settimana'] ?? null;
    try {
        $riferimento = $parametroSettimana ? new DateTime($parametroSettimana) : new DateTime();
    } catch (Exception $e) {
        $riferimento = new DateTime();
    }

    $giornoIso = (int) $riferimento->format('N');
    $lunedi = (clone $riferimento)->modify('-' . ($giornoIso - 1) . ' days');
    $venerdi = (clone $lunedi)->modify('+4 days');

    $navPrecedente = 'vista=settimana&settimana=' . (clone $lunedi)->modify('-7 days')->format('Y-m-d');
    $navSuccessiva = 'vista=settimana&settimana=' . (clone $lunedi)->modify('+7 days')->format('Y-m-d');
    $navOggi = 'vista=settimana';

    if ($lunedi->format('n') === $venerdi->format('n')) {
        $rangeTesto = $lunedi->format('j') . ' – ' . $venerdi->format('j') . ' ' . $mesiAbbr[(int) $venerdi->format('n')] . ' ' . $venerdi->format('Y');
    } else {
        $rangeTesto = $lunedi->format('j') . ' ' . $mesiAbbr[(int) $lunedi->format('n')] . ' – ' . $venerdi->format('j') . ' ' . $mesiAbbr[(int) $venerdi->format('n')] . ' ' . $venerdi->format('Y');
    }

    for ($i = 0; $i < 5; $i++) {
        $giorno = (clone $lunedi)->modify("+{$i} days");
        $giorniColonne[] = [
            'data' => $giorno->format('Y-m-d'),
            'etichetta' => $giorniAbbr[$i],
            'data_breve' => $giorno->format('d/m'),
        ];
    }

    $inizioRange = $lunedi->format('Y-m-d');
    $fineRange = $venerdi->format('Y-m-d');
    $ritornoCorrente = 'vista=settimana&settimana=' . $lunedi->format('Y-m-d');
}

/* ---------- Dati ---------- */

$plessi = $pdo->query(
    'SELECT id, nome, min_bidelli_mattina, min_bidelli_pomeriggio
     FROM plessi WHERE attivo = 1 ORDER BY nome'
)->fetchAll();

if ($vista === 'mese') {
    // Vista mese: sola lettura in ogni caso, badge aggregato — basta il
    // conteggio, non serve la lista bidelli (niente join, niente nomi).
    $stmtConteggi = $pdo->prepare(
        "SELECT plesso_id, data, turno_giorno, COUNT(*) AS assegnati
         FROM turni
         WHERE data BETWEEN :inizio AND :fine
           AND stato IN ('pianificato', 'sostituito')
         GROUP BY plesso_id, data, turno_giorno"
    );
    $stmtConteggi->execute(['inizio' => $inizioRange, 'fine' => $fineRange]);

    $conteggiMese = [];
    foreach ($stmtConteggi->fetchAll() as $r) {
        $conteggiMese[$r['plesso_id']][$r['data']][$r['turno_giorno']] = (int) $r['assegnati'];
    }
} else {
    $stmtTurni = $pdo->prepare(
        'SELECT t.id, t.plesso_id, t.data, t.turno_giorno, t.stato, t.sostituto_di_turno_id,
                t.bidello_id, b.nome, b.cognome
         FROM turni t
         JOIN bidelli b ON b.id = t.bidello_id
         WHERE t.data BETWEEN :inizio AND :fine
         ORDER BY t.plesso_id, t.data, t.turno_giorno, b.cognome'
    );
    $stmtTurni->execute(['inizio' => $inizioRange, 'fine' => $fineRange]);

    $celle = [];
    $idsConSostituto = [];
    foreach ($stmtTurni->fetchAll() as $riga) {
        $celle[$riga['plesso_id']][$riga['data']][$riga['turno_giorno']][] = $riga;
        if ($riga['sostituto_di_turno_id']) {
            $idsConSostituto[(int) $riga['sostituto_di_turno_id']] = true;
        }
    }

    function contaCopertura(array $righe): int
    {
        return count(array_filter($righe, static fn($r) => in_array($r['stato'], ['pianificato', 'sostituito'], true)));
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    $righeCsv = [];

    if ($vista === 'mese') {
        $intestazioniCsv = array_merge(['Plesso'], array_column($giorniMese, 'etichetta'));
        foreach ($plessi as $plesso) {
            $riga = [$plesso['nome']];
            foreach ($giorniMese as $giorno) {
                $stato = statoGiorno($plesso, $conteggiMese[$plesso['id']][$giorno['data']] ?? []);
                $riga[] = $stato['testo'];
            }
            $righeCsv[] = $riga;
        }
    } else {
        $intestazioniCsv = ['Plesso', 'Data', 'Turno', 'Bidelli assegnati', 'Copertura'];
        foreach ($plessi as $plesso) {
            foreach ($giorniColonne as $giorno) {
                foreach (['mattina' => 'Mattina', 'pomeriggio' => 'Pomeriggio'] as $turnoGiorno => $etichettaTurno) {
                    $righe = $celle[$plesso['id']][$giorno['data']][$turnoGiorno] ?? [];
                    $minimo = (int) $plesso["min_bidelli_{$turnoGiorno}"];
                    $badge = badgeTurno(contaCopertura($righe), $minimo);

                    $nomiBidelli = array_map(
                        static fn($r) => $r['cognome'] . ' ' . $r['nome'] . ' (' . ucfirst($r['stato']) . ')',
                        $righe
                    );

                    $righeCsv[] = [
                        $plesso['nome'],
                        $giorno['data'],
                        $etichettaTurno,
                        implode(', ', $nomiBidelli),
                        $badge['testo'],
                    ];
                }
            }
        }
    }

    esportaCSV('turni_' . $vista . '_' . $inizioRange . '.csv', $intestazioniCsv, $righeCsv);
}

$messaggi = [
    'assegnato'       => ['tipo' => 'ok', 'testo' => 'Assegnazione salvata correttamente.'],
    'segnato_assente' => ['tipo' => 'ok', 'testo' => 'Turno segnato come assente.'],
    'eliminato'       => ['tipo' => 'ok', 'testo' => 'Assegnazione eliminata correttamente.'],
    'ha_sostituto'    => ['tipo' => 'danger', 'testo' => 'Impossibile eliminare: esiste una sostituzione collegata. Elimina prima il turno sostituto.'],
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

<div class="view-tabs">
    <a class="btn <?= $vista === 'giorno' ? 'btn--primary' : 'btn--secondary' ?>" href="turni.php?vista=giorno">Giorno</a>
    <a class="btn <?= $vista === 'settimana' ? 'btn--primary' : 'btn--secondary' ?>" href="turni.php?vista=settimana">Settimana</a>
    <a class="btn <?= $vista === 'mese' ? 'btn--primary' : 'btn--secondary' ?>" href="turni.php?vista=mese">Mese</a>
</div>

<div class="week-nav">
    <div class="week-nav__range"><?= htmlspecialchars($rangeTesto) ?></div>
    <div class="week-nav__controls">
        <a class="btn btn--secondary" href="turni.php?<?= $navPrecedente ?>" title="Precedente">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <a class="btn btn--secondary" href="turni.php?<?= $navOggi ?>">Oggi</a>
        <a class="btn btn--secondary" href="turni.php?<?= $navSuccessiva ?>" title="Successivo">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
</div>

<div class="page-actions">
    <a class="btn btn--secondary" href="turni.php?<?= $ritornoCorrente ?>&export=csv">
        <i class="fa-solid fa-file-csv"></i> Esporta CSV
    </a>
    <button type="button" class="btn btn--secondary" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Stampa/PDF
    </button>
</div>

<?php if ($vista === 'mese'): ?>

    <div class="panel">
        <div class="panel__header">
            <div class="panel__title">Copertura mensile</div>
            <div class="panel__sub">Vista aggregata, sola lettura — dettaglio in Giorno o Settimana</div>
        </div>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Plesso</th>
                        <?php foreach ($giorniMese as $giorno): ?>
                            <th><?= $giorno['etichetta'] ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plessi as $plesso): ?>
                        <tr>
                            <td><?= htmlspecialchars($plesso['nome']) ?></td>
                            <?php foreach ($giorniMese as $giorno): ?>
                                <?php $stato = statoGiorno($plesso, $conteggiMese[$plesso['id']][$giorno['data']] ?? []); ?>
                                <td><span class="badge <?= $stato['classe'] ?>"><?= $stato['testo'] ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$plessi): ?>
                        <tr>
                            <td colspan="<?= count($giorniMese) + 1 ?>">Nessun plesso attivo.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="panel">
        <div style="overflow-x: auto;">
            <table class="table schedule-table">
                <thead>
                    <tr>
                        <th>Plesso</th>
                        <?php foreach ($giorniColonne as $giorno): ?>
                            <th><?= $giorno['etichetta'] ?><span class="day-date"><?= $giorno['data_breve'] ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plessi as $plesso): ?>
                        <tr>
                            <td><?= htmlspecialchars($plesso['nome']) ?></td>
                            <?php foreach ($giorniColonne as $giorno): ?>
                                <td>
                                    <?php foreach (['mattina' => 'Mattina', 'pomeriggio' => 'Pomeriggio'] as $turnoGiorno => $etichettaTurno): ?>
                                        <?php
                                        $righe = $celle[$plesso['id']][$giorno['data']][$turnoGiorno] ?? [];
                                        $minimo = (int) $plesso["min_bidelli_{$turnoGiorno}"];
                                        $coperti = contaCopertura($righe);
                                        $badge = badgeTurno($coperti, $minimo);
                                        ?>
                                        <div class="turno-block">
                                            <div class="turno-block__head">
                                                <span class="turno-block__label"><?= $etichettaTurno ?></span>
                                                <span style="display:flex; align-items:center; gap:6px;">
                                                    <span class="badge <?= $badge['classe'] ?>"><?= $badge['testo'] ?></span>
                                                    <?php if (isDsga()): ?>
                                                        <a class="turno-block__add"
                                                           href="turno-assegna.php?plesso_id=<?= (int) $plesso['id'] ?>&data=<?= $giorno['data'] ?>&turno_giorno=<?= $turnoGiorno ?>&ritorno=<?= urlencode($ritornoCorrente) ?>"
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
                                                            <?php if (isDsga()): ?>
                                                                <span class="chip__azioni">
                                                                    <?php if ($riga['stato'] === 'pianificato'): ?>
                                                                        <form class="inline-form" method="post" action="turni.php"
                                                                              onsubmit="return confirm('Segnare assente ' + <?= htmlspecialchars(json_encode($riga['nome'] . ' ' . $riga['cognome']), ENT_QUOTES) ?> + '?');">
                                                                            <input type="hidden" name="azione" value="segna_assente">
                                                                            <input type="hidden" name="id" value="<?= (int) $riga['id'] ?>">
                                                                            <input type="hidden" name="ritorno" value="<?= htmlspecialchars($ritornoCorrente) ?>">
                                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
                                                                            <button type="submit" title="Segna assente">
                                                                                <i class="fa-solid fa-user-slash"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php elseif ($riga['stato'] === 'assente' && !isset($idsConSostituto[$riga['id']])): ?>
                                                                        <a href="turno-assegna.php?sostituto_di_turno_id=<?= (int) $riga['id'] ?>&ritorno=<?= urlencode($ritornoCorrente) ?>"
                                                                           title="Assegna sostituto">
                                                                            <i class="fa-solid fa-arrows-rotate"></i>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <form class="inline-form" method="post" action="turni.php"
                                                                          onsubmit="return confirm('Eliminare l\'assegnazione di ' + <?= htmlspecialchars(json_encode($riga['nome'] . ' ' . $riga['cognome']), ENT_QUOTES) ?> + '?');">
                                                                        <input type="hidden" name="azione" value="elimina_turno">
                                                                        <input type="hidden" name="id" value="<?= (int) $riga['id'] ?>">
                                                                        <input type="hidden" name="ritorno" value="<?= htmlspecialchars($ritornoCorrente) ?>">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
                                                                        <button type="submit" title="Elimina assegnazione">
                                                                            <i class="fa-solid fa-xmark"></i>
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
                            <td colspan="<?= count($giorniColonne) + 1 ?>">Nessun plesso attivo.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
