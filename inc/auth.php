<?php
/**
 * TradeScope - autenticazione a utente singolo
 */
declare(strict_types=1);

function ts_auth_check(): bool {
    if (PHP_SAPI === 'cli') return true;
    if (empty($_SESSION['ts_user'])) return false;
    $ttl = (int)(ts_config('auth')['session_ttl'] ?? 43200);
    if (!empty($_SESSION['ts_login_at']) && (time() - (int)$_SESSION['ts_login_at']) > $ttl) {
        ts_auth_logout();
        return false;
    }
    if (($_SESSION['ts_ua'] ?? '') !== substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)) {
        return false;
    }
    return true;
}

function ts_auth_require(): void {
    if (!ts_auth_check()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php')) {
            ts_json_out(['ok' => false, 'error' => 'auth_required'], 401);
        }
        header('Location: login.php');
        exit;
    }
}

function ts_auth_attempts(string $ip): int {
    $st = ts_db()->prepare('SELECT COUNT(*) c FROM ts_login_attempts WHERE ip = ? AND ok = 0 AND at > ?');
    $st->execute([$ip, gmdate('Y-m-d H:i:s', time() - 900)]);
    return (int)($st->fetch()['c'] ?? 0);
}

function ts_auth_login(string $user, string $pass): bool {
    $ip = ts_client_ip();
    if (ts_auth_attempts($ip) >= 8) {
        ts_log('auth', 'login bloccato per troppi tentativi', ['ip' => $ip]);
        return false;
    }
    $auth = ts_config('auth');
    $ok = hash_equals((string)$auth['username'], $user)
        && !empty($auth['password_hash'])
        && password_verify($pass, (string)$auth['password_hash']);

    $st = ts_db()->prepare('INSERT INTO ts_login_attempts (ip, at, ok) VALUES (?, ?, ?)');
    $st->execute([$ip, ts_now(), $ok ? 1 : 0]);

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['ts_user']     = $user;
        $_SESSION['ts_login_at'] = time();
        $_SESSION['ts_ua']       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
    }
    return $ok;
}

function ts_auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
