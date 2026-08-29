<?php
require_once __DIR__ . '/../includes/auth.php';

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
