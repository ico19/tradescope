<?php
/**
 * TradeScope - livello database (PDO, SQLite o MySQL)
 */
declare(strict_types=1);

function ts_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = ts_config('db');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], (int)$cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
    } else {
        $path = $cfg['sqlite'];
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function ts_db_driver(): string {
    return (string)(ts_config('db')['driver'] ?? 'sqlite');
}

/** Crea lo schema (idempotente) */
function ts_db_migrate(): void {
    $pdo    = ts_db();
    $mysql  = ts_db_driver() === 'mysql';
    $pk     = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt    = $mysql ? 'LONGTEXT' : 'TEXT';
    $str    = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS ts_settings (
        skey $str NOT NULL PRIMARY KEY,
        svalue $txt NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ts_watchlist (
        id $pk,
        symbol $str NOT NULL,
        provider $str NOT NULL DEFAULT 'binance',
        tv_symbol $str NULL,
        label $str NULL,
        asset_class $str NOT NULL DEFAULT 'crypto',
        timeframe $str NOT NULL DEFAULT '4h',
        enabled INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at $str NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ts_analyses (
        id $pk,
        symbol $str NOT NULL,
        provider $str NOT NULL,
        timeframe $str NOT NULL,
        created_at $str NOT NULL,
        candle_time INTEGER NOT NULL DEFAULT 0,
        price_at DOUBLE NOT NULL DEFAULT 0,
        bias $str NULL,
        confidence DOUBLE NULL,
        horizon_bars INTEGER NULL,
        entry DOUBLE NULL,
        stop_loss DOUBLE NULL,
        target1 DOUBLE NULL,
        target2 DOUBLE NULL,
        risk_reward DOUBLE NULL,
        model $str NULL,
        tokens_in INTEGER NULL,
        tokens_out INTEGER NULL,
        source $str NOT NULL DEFAULT 'cron',
        payload $txt NOT NULL,
        snapshot $txt NULL,
        outcome $str NULL,
        outcome_at $str NULL,
        mfe DOUBLE NULL,
        mae DOUBLE NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ts_runs (
        id $pk,
        started_at $str NOT NULL,
        finished_at $str NULL,
        analyzed INTEGER NOT NULL DEFAULT 0,
        errors INTEGER NOT NULL DEFAULT 0,
        detail $txt NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ts_login_attempts (
        id $pk,
        ip $str NOT NULL,
        at $str NOT NULL,
        ok INTEGER NOT NULL DEFAULT 0
    )$suffix");

    // Indici (ignora errori se gia' presenti)
    foreach ([
        "CREATE INDEX idx_analyses_symbol ON ts_analyses (symbol, timeframe, id)",
        "CREATE INDEX idx_analyses_created ON ts_analyses (created_at)",
        "CREATE INDEX idx_attempts_ip ON ts_login_attempts (ip, at)",
    ] as $sql) {
        try { $pdo->exec($mysql ? preg_replace('/^CREATE INDEX /', 'CREATE INDEX ', $sql) : $sql); }
        catch (Throwable $e) { /* indice gia' esistente */ }
    }
}

function ts_setting_get(string $key, $default = null) {
    try {
        $st = ts_db()->prepare('SELECT svalue FROM ts_settings WHERE skey = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) return $default;
        $decoded = json_decode((string)$row['svalue'], true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row['svalue'];
    } catch (Throwable $e) {
        return $default;
    }
}

function ts_setting_set(string $key, $value): void {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo  = ts_db();
    if (ts_db_driver() === 'mysql') {
        $st = $pdo->prepare('INSERT INTO ts_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    } else {
        $st = $pdo->prepare('INSERT INTO ts_settings (skey, svalue) VALUES (?, ?) ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue');
    }
    $st->execute([$key, $json]);
}
