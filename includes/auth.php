<?php
/**
 * Funzioni di autenticazione e gestione sessione.
 */

require_once __DIR__ . '/../config/database.php';

const MAX_TENTATIVI_FALLITI = 5;
const FINESTRA_BLOCCO_MINUTI = 10;

/**
 * Avvia la sessione PHP con impostazioni cookie sicure.
 * Va chiamata all'inizio di ogni pagina, prima di qualsiasi output.
 */
function avviaSessione(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ]);

    session_start();
}

/**
 * Restituisce true se l'IP ha superato il numero massimo di tentativi
 * falliti nella finestra temporale configurata.
 */
function troppiTentativiFalliti(string $ip): bool
{
    $pdo = getConnessione();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativi_login
         WHERE ip = :ip AND creato_il > (NOW() - INTERVAL :minuti MINUTE)'
    );
    $stmt->execute(['ip' => $ip, 'minuti' => FINESTRA_BLOCCO_MINUTI]);

    return (int) $stmt->fetchColumn() >= MAX_TENTATIVI_FALLITI;
}

/**
 * Registra un tentativo di login fallito per l'IP indicato.
 * Effettua occasionalmente una pulizia dei tentativi vecchi (>1 giorno)
 * per evitare crescita indefinita della tabella, senza bisogno di un cron.
 */
function registraTentativoFallito(string $ip, string $email): void
{
    $pdo = getConnessione();
    $stmt = $pdo->prepare('INSERT INTO tentativi_login (ip, email) VALUES (:ip, :email)');
    $stmt->execute(['ip' => $ip, 'email' => $email]);

    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM tentativi_login WHERE creato_il < (NOW() - INTERVAL 1 DAY)');
    }
}

/**
 * Verifica le credenziali e, se valide, avvia la sessione utente.
 * Restituisce ['successo' => bool, 'messaggio' => string].
 */
function effettuaLogin(string $email, string $password): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sconosciuto';

    if (troppiTentativiFalliti($ip)) {
        return [
            'successo'  => false,
            'messaggio' => 'Troppi tentativi falliti. Riprova tra qualche minuto.',
        ];
    }

    $pdo = getConnessione();
    $stmt = $pdo->prepare('SELECT * FROM utenti WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $utente = $stmt->fetch();

    if (!$utente || !password_verify($password, $utente['password_hash']) || (int) $utente['attivo'] !== 1) {
        registraTentativoFallito($ip, $email);

        return [
            'successo'  => false,
            'messaggio' => 'Email o password non validi.',
        ];
    }

    // Rigenera l'ID di sessione per prevenire session fixation
    session_regenerate_id(true);

    $_SESSION['utente_id'] = (int) $utente['id'];
    $_SESSION['nome']      = $utente['nome'];
    $_SESSION['cognome']   = $utente['cognome'];
    $_SESSION['ruolo']     = $utente['ruolo'];

    return ['successo' => true, 'messaggio' => ''];
}

/**
 * True se esiste una sessione utente valida.
 */
function isLoggato(): bool
{
    return isset($_SESSION['utente_id']);
}

/**
 * True se l'utente in sessione ha ruolo 'dsga'.
 */
function isDsga(): bool
{
    return isLoggato() && $_SESSION['ruolo'] === 'dsga';
}

/**
 * Distrugge la sessione corrente (logout).
 */
function effettuaLogout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametri = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $parametri['path'],
            $parametri['domain'],
            $parametri['secure'],
            $parametri['httponly']
        );
    }

    session_destroy();
}

/**
 * Da chiamare in cima alle pagine protette: reindirizza al login
 * se l'utente non è autenticato.
 */
function richiediLogin(): void
{
    if (!isLoggato()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Da chiamare in cima alle pagine riservate al DSGA: richiede login
 * e blocca con 403 chi non ha ruolo 'dsga'.
 */
function richiediRuoloDsga(): void
{
    richiediLogin();

    if (!isDsga()) {
        http_response_code(403);
        die('Accesso negato: questa pagina è riservata al DSGA.');
    }
}
