<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crud-helpers.php';

avviaSessione();
richiediLogin();

$pdo = getConnessione();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'elimina') {
    richiediRuoloDsga();
    verificaCsrfOFallisci('bidelli.php?msg=errore');

    $bidelloId = (int) ($_POST['id'] ?? 0);

    if ($bidelloId <= 0) {
        header('Location: bidelli.php?msg=errore');
        exit;
    }

    $esito = eliminaSeSenzaDipendenze($pdo, 'bidelli', 'turni', 'bidello_id', $bidelloId);

    header('Location: bidelli.php?msg=' . ($esito === 'ha_dipendenze' ? 'ha_turni' : $esito));
    exit;
}

$messaggi = [
    'salvato'   => ['tipo' => 'ok', 'testo' => 'Bidello salvato correttamente.'],
    'eliminato' => ['tipo' => 'ok', 'testo' => 'Bidello eliminato correttamente.'],
    'ha_turni'  => ['tipo' => 'danger', 'testo' => 'Impossibile eliminare: il bidello ha turni associati. Disattivalo (imposta "non attivo") invece di eliminarlo, per non perdere lo storico.'],
    'errore'    => ['tipo' => 'danger', 'testo' => 'Richiesta non valida. Riprova.'],
];
$msg = $messaggi[$_GET['msg'] ?? ''] ?? null;

$bidelli = $pdo->query(
    'SELECT b.id, b.nome, b.cognome, b.telefono, b.email, b.attivo,
            b.ore_settimanali, b.ore_straordinario_max, p.nome AS plesso_nome
     FROM bidelli b
     LEFT JOIN plessi p ON p.id = b.plesso_principale_id
     ORDER BY b.cognome, b.nome'
)->fetchAll();

$paginaTitolo = 'Bidelli';
$paginaAttiva = 'bidelli';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
    <div class="form-error" style="<?= $msg['tipo'] === 'ok' ? 'background:var(--status-ok-bg);color:var(--status-ok);' : '' ?>">
        <?= htmlspecialchars($msg['testo']) ?>
    </div>
<?php endif; ?>

<?php if (isDsga()): ?>
    <div class="page-actions">
        <a class="btn btn--primary" href="bidello-form.php">
            <i class="fa-solid fa-plus"></i> Nuovo bidello
        </a>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel__header">
        <div class="panel__title">Elenco bidelli</div>
        <div class="panel__sub"><?= count($bidelli) ?> totali</div>
    </div>

    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Cognome</th>
                    <th>Nome</th>
                    <th>Telefono</th>
                    <th>Email</th>
                    <th>Plesso principale</th>
                    <th>Ore settimanali</th>
                    <th>Tetto straordinario</th>
                    <th>Stato</th>
                    <?php if (isDsga()): ?><th>Azioni</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bidelli as $bidello): ?>
                    <tr>
                        <td><?= htmlspecialchars($bidello['cognome']) ?></td>
                        <td><?= htmlspecialchars($bidello['nome']) ?></td>
                        <td><?= htmlspecialchars($bidello['telefono'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($bidello['email'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($bidello['plesso_nome'] ?? '—') ?></td>
                        <td><?= (int) $bidello['ore_settimanali'] ?>h</td>
                        <td><?= (int) $bidello['ore_straordinario_max'] ?>h</td>
                        <td>
                            <?php if ((int) $bidello['attivo'] === 1): ?>
                                <span class="badge badge--ok">Attivo</span>
                            <?php else: ?>
                                <span class="badge badge--neutral">Non attivo</span>
                            <?php endif; ?>
                        </td>
                        <?php if (isDsga()): ?>
                            <td>
                                <a class="btn btn--secondary" href="bidello-form.php?id=<?= (int) $bidello['id'] ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <form class="inline-form" method="post" action="bidelli.php" onsubmit="return confirm('Eliminare ' + <?= htmlspecialchars(json_encode($bidello['nome'] . ' ' . $bidello['cognome']), ENT_QUOTES) ?> + '? Operazione non reversibile.');">
                                    <input type="hidden" name="azione" value="elimina">
                                    <input type="hidden" name="id" value="<?= (int) $bidello['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">
                                    <button type="submit" class="btn btn--secondary" title="Elimina">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$bidelli): ?>
                    <tr>
                        <td colspan="<?= isDsga() ? 9 : 8 ?>">Nessun bidello presente.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
