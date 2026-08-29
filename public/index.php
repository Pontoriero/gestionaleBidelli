<?php
require_once __DIR__ . '/../includes/auth.php';

avviaSessione();
richiediLogin();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Gestionale Bidelli</title>
</head>
<body>
    <h1>Dashboard</h1>
    <p>Benvenuto/a, <?= htmlspecialchars($_SESSION['nome'] . ' ' . $_SESSION['cognome']) ?> (<?= htmlspecialchars($_SESSION['ruolo']) ?>)</p>

    <p><a href="logout.php">Esci</a></p>
</body>
</html>
