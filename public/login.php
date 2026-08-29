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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi - Gestione Turni</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <div class="auth-page">
        <div class="auth-brand">
            <div class="auth-brand__mark"><i class="fa-solid fa-broom"></i></div>
            <div class="auth-brand__title">Gestione Turni</div>
            <div class="auth-brand__sub">Istituto Pertini</div>
        </div>

        <div class="auth-card">
            <h1 class="auth-card__title">Accedi</h1>
            <p class="auth-card__subtitle">Inserisci le tue credenziali per accedere</p>

            <?php if ($errore !== ''): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?= htmlspecialchars($errore) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generaCsrfToken()) ?>">

                <div class="auth-field">
                    <label for="email">Email</label>
                    <div class="auth-input-wrap">
                        <input class="auth-input" type="email" id="email" name="email" required autofocus placeholder="nome@istituto.it">
                    </div>
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="auth-input-wrap auth-input-wrap--password">
                        <input class="auth-input" type="password" id="password" name="password" required placeholder="••••••••">
                        <button type="button" class="auth-toggle-password" id="togglePassword" aria-label="Mostra password" aria-pressed="false">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    Accedi <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
        const toggle = document.getElementById('togglePassword');
        const campoPassword = document.getElementById('password');

        toggle.addEventListener('click', () => {
            const nascosta = campoPassword.type === 'password';
            campoPassword.type = nascosta ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(nascosta));
            toggle.setAttribute('aria-label', nascosta ? 'Nascondi password' : 'Mostra password');
            toggle.querySelector('i').className = nascosta ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    </script>
</body>
</html>
