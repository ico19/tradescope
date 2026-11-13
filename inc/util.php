<?php
/**
 * TradeScope - funzioni di utilita' generiche
 */
declare(strict_types=1);

function ts_json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ts_e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ts_log(string $channel, string $message, array $ctx = []): void {
    $dir = TS_STORAGE . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $line = sprintf(
        "[%s] %s: %s %s\n",
        gmdate('Y-m-d H:i:s'),
        strtoupper($channel),
        $message,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function ts_now(): string { return gmdate('Y-m-d H:i:s'); }

/** Numero di secondi di un timeframe */
function ts_tf_seconds(string $tf): int {
    $map = [
        '1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800,
        '1h' => 3600, '2h' => 7200, '4h' => 14400, '6h' => 21600,
        '12h' => 43200, '1d' => 86400, '1w' => 604800,
    ];
    return $map[$tf] ?? 3600;
}

function ts_tf_label(string $tf): string {
    $map = ['1m'=>'1 minuto','5m'=>'5 minuti','15m'=>'15 minuti','30m'=>'30 minuti','1h'=>'1 ora','2h'=>'2 ore','4h'=>'4 ore','6h'=>'6 ore','12h'=>'12 ore','1d'=>'1 giorno','1w'=>'1 settimana'];
    return $map[$tf] ?? $tf;
}

/** Timeframe superiore usato per il contesto multi-timeframe */
function ts_tf_higher(string $tf): string {
    $map = ['1m'=>'15m','5m'=>'1h','15m'=>'4h','30m'=>'4h','1h'=>'1d','2h'=>'1d','4h'=>'1d','6h'=>'1d','12h'=>'1w','1d'=>'1w','1w'=>'1w'];
    return $map[$tf] ?? '1d';
}

function ts_supported_timeframes(): array {
    return ['15m','30m','1h','2h','4h','6h','12h','1d','1w'];
}

/** Arrotondamento "intelligente" in base alla scala del prezzo */
function ts_round_price(float $p): float {
    $abs = abs($p);
    if ($abs >= 1000) return round($p, 2);
    if ($abs >= 100)  return round($p, 3);
    if ($abs >= 1)    return round($p, 4);
    if ($abs >= 0.01) return round($p, 6);
    return round($p, 8);
}

function ts_fmt_price(float $p): string {
    return rtrim(rtrim(number_format(ts_round_price($p), 8, '.', ''), '0'), '.');
}

function ts_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function ts_csrf_check(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function ts_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function ts_clamp(float $v, float $min, float $max): float {
    return max($min, min($max, $v));
}
