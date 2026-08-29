<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crud-helpers.php';
require_once __DIR__ . '/../includes/export-helpers.php';

avviaSessione();
richiediLogin();

$pdo = getConnessione();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina') {
    richiediRuoloDsga();
    verificaCsrfOFallisci('plessi.php?msg=errore');

    $plessoId = (int) ($_POST['id'] ?? 0);

    if ($plessoId <= 0) {
        header('Location: plessi.php?msg=errore');
        exit;
    }

    $esito = eliminaSeSenzaDipendenze($pdo, 'plessi', 'turni', 'plesso_id', $plessoId);

    header('Location: plessi.php?msg=' . ($esito === 'ha_dipendenze' ? 'ha_turni' : $esito));
    exit;
}

$messaggi = [
    'salvato'   => ['tipo' => 'ok', 'testo' => 'Plesso salvato correttamente.'],
    'eliminato' => ['tipo' => 'ok', 'testo' => 'Plesso eliminato correttamente.'],
    'ha_turni'  => ['tipo' => 'danger', 'testo' => 'Impossibile eliminare: il plesso ha turni associati. Disattivalo (imposta "non attivo") invece di eliminarlo, per non perdere lo storico.'],
    'errore'    => ['tipo' => 'danger', 'testo' => 'Richiesta non valida. Riprova.'],
];
$msg = $messaggi[$_GET['msg'] ?? ''] ?? null;

if (($_GET['msg'] ?? '') === 'importato') {
    $conteggioImportati = (int) ($_GET['conteggio'] ?? 0);
    $msg = ['tipo' => 'ok', 'testo' => "Importazione completata: {$conteggioImportati} plessi creati."];
}

$plessi = $pdo->query(
    'SELECT id, nome, indirizzo, min_bidelli_mattina, min_bidelli_pomeriggio,
            orario_mattina_inizio, orario_mattina_fine, orario_pomeriggio_inizio, orario_pomeriggio_fine, attivo
     FROM plessi
     ORDER BY nome'
)->fetchAll();

if (($_GET['export'] ?? '') === 'csv' && ($_GET['template'] ?? '') === '1') {
    // Template import: intestazioni = nomi campo grezzi (nome, min_bidelli_mattina, ...),
    // non le etichette leggibili dell'export sotto — devono corrispondere
    // esattamente a cosa legge plessi-importa.php.
    esportaCSV(
        'plessi_template.csv',
        ['nome', 'indirizzo', 'note', 'min_bidelli_mattina', 'min_bidelli_pomeriggio',
         'orario_mattina_inizio', 'orario_mattina_fine', 'orario_pomeriggio_inizio', 'orario_pomeriggio_fine', 'attivo'],
        []
    );
}

if (($_GET['export'] ?? '') === 'csv') {
    $righeCsv = [];
    foreach ($plessi as $plesso) {
        $righeCsv[] = [
            $plesso['nome'],
            $plesso['indirizzo'] ?? '',
            (int) $plesso['min_bidelli_mattina'],
            (int) $plesso['min_bidelli_pomeriggio'],
            $plesso['orario_mattina_inizio'] && $plesso['orario_mattina_fine']
                ? substr($plesso['orario_mattina_inizio'], 0, 5) . '-' . substr($plesso['orario_mattina_fine'], 0, 5)
                : '',
            $plesso['orario_pomeriggio_inizio'] && $plesso['orario_pomeriggio_fine']
                ? substr($plesso['orario_pomeriggio_inizio'], 0, 5) . '-' . substr($plesso['orario_pomeriggio_fine'], 0, 5)
                : '',
            (int) $plesso['attivo'] === 1 ? 'Attivo' : 'Non attivo',
        ];
    }
    esportaCSV(
        'plessi.csv',
        ['Nome', 'Indirizzo', 'Min. mattina', 'Min. pomeriggio', 'Orario mattina', 'Orario pomeriggio', 'Stato'],
        $righeCsv
    );
}

$paginaTitolo = 'Plessi';
$paginaAttiva = 'plessi';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
    <div class="form-error" style="<?= $msg['tipo'] === 'ok' ? 'background:var(--status-ok-bg);color:var(--status-ok);' : '' ?>">
        <?= htmlspecialchars($msg['testo']) ?>
    </div>
<?php endif; ?>

<div class="page-actions">
    <a class="btn btn--secondary" href="plessi.php?export=csv">
        <i class="fa-solid fa-file-csv"></i> Esporta CSV
    </a>
    <button type="button" class="btn btn--secondary" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Stampa/PDF
    </button>
    <?php if (isDsga()): ?>
        <a class="btn btn--secondary" href="plessi.php?export=csv&template=1">
            <i class="fa-solid fa-download"></i> Scarica template
        </a>
        <a class="btn btn--secondary" href="plessi-importa.php">
            <i class="fa-solid fa-upload"></i> Importa CSV
        </a>
        <a class="btn btn--primary" href="plesso-form.php">
            <i class="fa-solid fa-plus"></i> Nuovo plesso
        </a>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Elenco plessi</div>
        <div class="panel__sub"><?= count($plessi) ?> totali</div>
    </div>

    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Indirizzo</th>
                    <th>Min. mattina</th>
                    <th>Min. pomeriggio</th>
                    <th>Orario mattina</th>
                    <th>Orario pomeriggio</th>
                    <th>Stato</th>
                    <?php if (isDsga()): ?><th>Azioni</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plessi as $plesso): ?>
                    <tr>
                        <td><?= htmlspecialchars($plesso['nome']) ?></td>
                        <td><?= htmlspecialchars($plesso['indirizzo'] ?? '—') ?></td>
                        <td><?= (int) $plesso['min_bidelli_mattina'] ?></td>
                        <td><?= (int) $plesso['min_bidelli_pomeriggio'] ?></td>
                        <td>
                            <?= $plesso['orario_mattina_inizio'] && $plesso['orario_mattina_fine']
                                ? htmlspecialchars(substr($plesso['orario_mattina_inizio'], 0, 5) . '–' . substr($plesso['orario_mattina_fine'], 0, 5))
                                : '—' ?>
                        </td>
                        <td>
                            <?= $plesso['orario_pomeriggio_inizio'] && $plesso['orario_pomeriggio_fine']
                                ? htmlspecialchars(substr($plesso['orario_pomeriggio_inizio'], 0, 5) . '–' . substr($plesso['orario_pomeriggio_fine'], 0, 5))
                                : '—' ?>
                        </td>
                        <td>
                            <?php if ((int) $plesso['attivo'] === 1): ?>
                                <span class="badge badge--ok">Attivo</span>
                            <?php else: ?>
                                <span class="badge badge--neutral">Non attivo</span>
                            <?php endif; ?>
                        </td>
                        <?php if (isDsga()): ?>
                            <td>
                                <a class="btn btn--secondary" href="plesso-form.php?id=<?= (int) $plesso['id'] ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <form class="inline-form" method="post" action="plessi.php" onsubmit="return confirm('Eliminare il plesso ' + <?= htmlspecialchars(json_encode($plesso['nome']), ENT_QUOTES) ?> + '? Operazione non reversibile.');">
                                    <input type="hidden" name="azione" value="elimina">
                                    <input type="hidden" name="id" value="<?= (int) $plesso['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
                                    <button type="submit" class="btn btn--secondary" title="Elimina">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$plessi): ?>
                    <tr>
                        <td colspan="<?= isDsga() ? 8 : 7 ?>">Nessun plesso presente.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
