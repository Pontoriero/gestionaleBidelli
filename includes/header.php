<?php
/**
 * Header condiviso: apre il documento HTML e disegna la sidebar.
 * Richiede che la pagina chiamante abbia già eseguito avviaSessione()
 * e richiediLogin() (o richiediRuoloDsga()), e impostato:
 *   $paginaTitolo  string  titolo mostrato in <title> e in topbar
 *   $paginaAttiva  string  chiave voce menu corrente: dashboard|plessi|bidelli|turni|utenti
 */

$paginaTitolo = $paginaTitolo ?? 'Gestione Turni';
$paginaAttiva = $paginaAttiva ?? '';

$giorniItaliani = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
$mesiItaliani = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$oggi = new DateTime();
$dataOggiFormattata = $giorniItaliani[(int) $oggi->format('w')] . ' ' . $oggi->format('j') . ' ' . $mesiItaliani[(int) $oggi->format('n')] . ' ' . $oggi->format('Y');

$iniziali = strtoupper(mb_substr($_SESSION['nome'] ?? '?', 0, 1) . mb_substr($_SESSION['cognome'] ?? '', 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($paginaTitolo) ?> - Gestione Turni</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__brand-mark">
                <i class="fa-solid fa-broom" style="color:#1a1f2e;"></i>
            </div>
            <div class="sidebar__brand-text">
                <div class="sidebar__brand-title">Gestione Turni</div>
                <div class="sidebar__brand-sub">Istituto Pertini</div>
            </div>
        </div>

        <nav class="sidebar__nav">
            <div class="nav-section">
                <div class="nav-section__label">Gestione</div>
                <a class="nav-item <?= $paginaAttiva === 'dashboard' ? 'is-active' : '' ?>" href="index.php">
                    <i class="fa-solid fa-table-cells"></i> Dashboard
                </a>
                <a class="nav-item <?= $paginaAttiva === 'plessi' ? 'is-active' : '' ?>" href="plessi.php">
                    <i class="fa-solid fa-school"></i> Plessi
                </a>
                <a class="nav-item <?= $paginaAttiva === 'bidelli' ? 'is-active' : '' ?>" href="bidelli.php">
                    <i class="fa-solid fa-users"></i> Bidelli
                </a>
                <a class="nav-item <?= $paginaAttiva === 'turni' ? 'is-active' : '' ?>" href="turni.php">
                    <i class="fa-solid fa-calendar-days"></i> Turni
                </a>
            </div>

            <?php if (isDsga()): ?>
            <div class="nav-section">
                <div class="nav-section__label">Amministrazione</div>
                <a class="nav-item <?= $paginaAttiva === 'utenti' ? 'is-active' : '' ?>" href="utenti.php">
                    <i class="fa-solid fa-user-gear"></i> Utenti
                </a>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar__user">
            <div class="sidebar__avatar"><?= htmlspecialchars($iniziali) ?></div>
            <div class="sidebar__user-meta">
                <div class="sidebar__user-name"><?= htmlspecialchars(($_SESSION['nome'] ?? '') . ' ' . ($_SESSION['cognome'] ?? '')) ?></div>
                <div class="sidebar__user-role"><?= htmlspecialchars(strtoupper($_SESSION['ruolo'] ?? '')) ?></div>
            </div>
            <a class="sidebar__logout" href="logout.php" title="Esci">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar__title"><?= htmlspecialchars($paginaTitolo) ?></div>
            <div class="topbar__meta">
                <strong><?= htmlspecialchars(($_SESSION['nome'] ?? '') . ' ' . ($_SESSION['cognome'] ?? '')) ?></strong>
                · <?= htmlspecialchars($dataOggiFormattata) ?>
            </div>
        </header>

        <div class="content">
