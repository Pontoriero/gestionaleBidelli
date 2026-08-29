<?php
require_once __DIR__ . '/../includes/auth.php';

avviaSessione();

if (isLoggato()) {
    header('Location: index.php');
    exit;
}

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificaCsrfToken($_POST['csrf_token'] ?? '')) {
        $errore = 'Richiesta non valida. Riprova.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $risultato = effettuaLogin($email, $password);

        if ($risultato['successo']) {
            header('Location: index.php');
            exit;
        }

        $errore = $risultato['messaggio'];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Accedi - Gestionale Bidelli</title>
</head>
<body>
    <h1>Accedi</h1>

    <?php if ($errore !== ''): ?>
        <p style="color:red;"><?= htmlspecialchars($errore) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">

        <label>
            Email
            <input type="email" name="email" required autofocus>
        </label>

        <label>
            Password
            <input type="password" name="password" required>
        </label>

        <button type="submit">Accedi</button>
    </form>
</body>
</html>
