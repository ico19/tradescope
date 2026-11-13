<?php
/**
 * TradeScope - bootstrap dell'applicazione
 */
declare(strict_types=1);

if (!defined('TS_ROOT'))    define('TS_ROOT', dirname(__DIR__));
if (!defined('TS_STORAGE')) define('TS_STORAGE', TS_ROOT . '/storage');
if (!defined('TS_VERSION')) define('TS_VERSION', '2.1.0');

mb_internal_encoding('UTF-8');

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/indicators.php';
require_once __DIR__ . '/indicators2.php';
require_once __DIR__ . '/derivatives.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/analysis2.php';
require_once __DIR__ . '/pine.php';
require_once __DIR__ . '/auth.php';

function ts_config(?string $section = null) {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Configurazione mancante: esegui install.php dal browser.\n");
                exit(1);
            }
            header('Location: install.php');
            exit;
        }
        $cfg = require $file;
    }
    if ($section === null) return $cfg;
    return $cfg[$section] ?? [];
}

/** Valore di configurazione con override dalle impostazioni salvate a DB */
function ts_opt(string $section, string $key, $default = null) {
    $over = ts_setting_get('cfg_' . $section . '_' . $key, null);
    if ($over !== null && $over !== '') return $over;
    $sec = ts_config($section);
    return $sec[$key] ?? $default;
}

function ts_boot(bool $withSession = true): void {
    $ui = ts_config('ui');
    date_default_timezone_set($ui['timezone'] ?? 'Europe/Rome');

    if (!is_dir(TS_STORAGE)) { @mkdir(TS_STORAGE, 0750, true); }
    if (!is_dir(TS_STORAGE . '/cache')) { @mkdir(TS_STORAGE . '/cache', 0750, true); }

    ts_db_migrate();

    if ($withSession && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        $auth = ts_config('auth');
        session_name($auth['session_name'] ?? 'tradescope_sid');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** Intestazioni di sicurezza per le pagine HTML */
function ts_security_headers(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
