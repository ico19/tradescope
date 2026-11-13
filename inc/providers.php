<?php
/**
 * TradeScope - fornitori di dati di mercato
 *
 * Ogni provider restituisce candele nel formato:
 *   [ ['t'=>int(unix sec), 'o'=>float, 'h'=>float, 'l'=>float, 'c'=>float, 'v'=>float], ... ]
 * ordinate dalla piu' vecchia alla piu' recente.
 */
declare(strict_types=1);

function ts_providers(): array {
    return [
        'binance' => 'Binance (crypto spot, real-time)',
        'bybit'   => 'Bybit (crypto spot, real-time)',
        'yahoo'   => 'Yahoo Finance (azioni, indici, forex, materie prime)',
    ];
}

/**
 * Scarica le candele con fallback automatico tra provider crypto.
 * @return array{ok:bool, candles:array, provider:string, error:string}
 */
function ts_fetch_candles(string $symbol, string $timeframe, int $limit = 300, string $provider = 'binance'): array {
    $ttl   = (int)ts_opt('engine', 'http_cache_ttl', 45);
    $order = $provider === 'yahoo' ? ['yahoo'] : ($provider === 'bybit' ? ['bybit', 'binance'] : ['binance', 'bybit']);
    $lastErr = '';

    foreach ($order as $p) {
        $fn = 'ts_provider_' . $p;
        if (!function_exists($fn)) continue;
        $res = $fn($symbol, $timeframe, $limit, $ttl);
        if ($res['ok'] && count($res['candles']) >= 30) {
            $res['provider'] = $p;
            return $res;
        }
        $lastErr = $res['error'] ?: 'dati insufficienti da ' . $p;
    }
    return ['ok' => false, 'candles' => [], 'provider' => $provider, 'error' => $lastErr ?: 'nessun dato disponibile'];
}

/* ------------------------------------------------------------------ Binance */

function ts_binance_interval(string $tf): ?string {
    $map = ['1m'=>'1m','5m'=>'5m','15m'=>'15m','30m'=>'30m','1h'=>'1h','2h'=>'2h','4h'=>'4h','6h'=>'6h','12h'=>'12h','1d'=>'1d','1w'=>'1w'];
    return $map[$tf] ?? null;
}

function ts_provider_binance(string $symbol, string $tf, int $limit, int $ttl): array {
    $iv = ts_binance_interval($tf);
    if ($iv === null) return ['ok' => false, 'candles' => [], 'error' => 'timeframe non supportato da Binance'];
    $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol));
    $url = 'https://api.binance.com/api/v3/klines?symbol=' . rawurlencode($sym)
         . '&interval=' . $iv . '&limit=' . max(50, min(1000, $limit));
    $res = ts_http_cached($url, $ttl);
    if (!$res['ok']) return ['ok' => false, 'candles' => [], 'error' => 'Binance: ' . ($res['error'] ?: 'errore rete')];
    $data = json_decode($res['body'], true);
    if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        $msg = is_array($data) && isset($data['msg']) ? $data['msg'] : 'risposta non valida';
        return ['ok' => false, 'candles' => [], 'error' => 'Binance: ' . $msg];
    }
    $out = [];
    foreach ($data as $k) {
        $out[] = [
            't' => (int)floor(((float)$k[0]) / 1000),
            'o' => (float)$k[1], 'h' => (float)$k[2], 'l' => (float)$k[3],
            'c' => (float)$k[4], 'v' => (float)$k[5],
        ];
    }
    return ['ok' => true, 'candles' => $out, 'error' => ''];
}

/* -------------------------------------------------------------------- Bybit */

function ts_bybit_interval(string $tf): ?string {
    $map = ['1m'=>'1','5m'=>'5','15m'=>'15','30m'=>'30','1h'=>'60','2h'=>'120','4h'=>'240','6h'=>'360','12h'=>'720','1d'=>'D','1w'=>'W'];
    return $map[$tf] ?? null;
}

function ts_provider_bybit(string $symbol, string $tf, int $limit, int $ttl): array {
    $iv = ts_bybit_interval($tf);
    if ($iv === null) return ['ok' => false, 'candles' => [], 'error' => 'timeframe non supportato da Bybit'];
    $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol));
    $url = 'https://api.bybit.com/v5/market/kline?category=spot&symbol=' . rawurlencode($sym)
         . '&interval=' . $iv . '&limit=' . max(50, min(1000, $limit));
    $res = ts_http_cached($url, $ttl);
    if (!$res['ok']) return ['ok' => false, 'candles' => [], 'error' => 'Bybit: ' . ($res['error'] ?: 'errore rete')];
    $data = json_decode($res['body'], true);
    $list = $data['result']['list'] ?? null;
    if (!is_array($list) || !$list) {
        return ['ok' => false, 'candles' => [], 'error' => 'Bybit: ' . ($data['retMsg'] ?? 'risposta non valida')];
    }
    $out = [];
    foreach ($list as $k) {
        $out[] = [
            't' => (int)floor(((float)$k[0]) / 1000),
            'o' => (float)$k[1], 'h' => (float)$k[2], 'l' => (float)$k[3],
            'c' => (float)$k[4], 'v' => (float)$k[5],
        ];
    }
    usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
    return ['ok' => true, 'candles' => $out, 'error' => ''];
}

/* ---------------------------------------------------------- Yahoo Finance */

function ts_yahoo_params(string $tf): ?array {
    $map = [
        '15m' => ['15m', '60d'], '30m' => ['30m', '60d'], '1h' => ['1h', '730d'],
        '1d'  => ['1d', '5y'],   '1w'  => ['1wk', '10y'],
    ];
    // I timeframe non nativi vengono ricampionati a partire da quelli disponibili
    $fallback = ['2h' => ['1h', '730d'], '4h' => ['1h', '730d'], '6h' => ['1h', '730d'], '12h' => ['1h', '730d']];
    return $map[$tf] ?? $fallback[$tf] ?? null;
}

function ts_provider_yahoo(string $symbol, string $tf, int $limit, int $ttl): array {
    $p = ts_yahoo_params($tf);
    if ($p === null) return ['ok' => false, 'candles' => [], 'error' => 'timeframe non supportato da Yahoo'];
    [$interval, $range] = $p;
    $sym = trim($symbol);
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym)
         . '?interval=' . $interval . '&range=' . $range . '&includePrePost=false';
    $res = ts_http_cached($url, max($ttl, 60));
    if (!$res['ok']) return ['ok' => false, 'candles' => [], 'error' => 'Yahoo: ' . ($res['error'] ?: 'errore rete')];
    $data = json_decode($res['body'], true);
    $r = $data['chart']['result'][0] ?? null;
    if (!$r || empty($r['timestamp'])) {
        $err = $data['chart']['error']['description'] ?? 'simbolo non trovato';
        return ['ok' => false, 'candles' => [], 'error' => 'Yahoo: ' . $err];
    }
    $q = $r['indicators']['quote'][0] ?? [];
    $out = [];
    foreach ($r['timestamp'] as $i => $t) {
        if (!isset($q['close'][$i]) || $q['close'][$i] === null) continue;
        $out[] = [
            't' => (int)$t,
            'o' => (float)($q['open'][$i]  ?? $q['close'][$i]),
            'h' => (float)($q['high'][$i]  ?? $q['close'][$i]),
            'l' => (float)($q['low'][$i]   ?? $q['close'][$i]),
            'c' => (float)$q['close'][$i],
            'v' => (float)($q['volume'][$i] ?? 0),
        ];
    }
    if (in_array($tf, ['2h', '4h', '6h', '12h'], true)) {
        $out = ts_resample($out, ts_tf_seconds($tf));
    }
    if (count($out) > $limit) $out = array_slice($out, -$limit);
    return ['ok' => true, 'candles' => $out, 'error' => ''];
}

/** Ricampionamento di candele in bucket di N secondi */
function ts_resample(array $candles, int $seconds): array {
    $buckets = [];
    foreach ($candles as $c) {
        $b = (int)(floor($c['t'] / $seconds) * $seconds);
        if (!isset($buckets[$b])) {
            $buckets[$b] = ['t' => $b, 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'v' => $c['v']];
        } else {
            $buckets[$b]['h'] = max($buckets[$b]['h'], $c['h']);
            $buckets[$b]['l'] = min($buckets[$b]['l'], $c['l']);
            $buckets[$b]['c'] = $c['c'];
            $buckets[$b]['v'] += $c['v'];
        }
    }
    ksort($buckets);
    return array_values($buckets);
}

/** Statistiche 24h (solo crypto) */
function ts_ticker_24h(string $symbol, string $provider = 'binance'): ?array {
    if ($provider === 'yahoo') return null;
    $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol));
    $res = ts_http_cached('https://api.binance.com/api/v3/ticker/24hr?symbol=' . rawurlencode($sym), 30);
    if (!$res['ok']) return null;
    $d = json_decode($res['body'], true);
    if (!is_array($d) || !isset($d['lastPrice'])) return null;
    return [
        'price'      => (float)$d['lastPrice'],
        'change_pct' => (float)($d['priceChangePercent'] ?? 0),
        'high'       => (float)($d['highPrice'] ?? 0),
        'low'        => (float)($d['lowPrice'] ?? 0),
        'volume'     => (float)($d['quoteVolume'] ?? 0),
    ];
}

/** Simbolo nel formato TradingView per il widget */
function ts_tv_symbol(array $item): string {
    if (!empty($item['tv_symbol'])) return (string)$item['tv_symbol'];
    $sym = strtoupper((string)$item['symbol']);
    return match ($item['provider'] ?? 'binance') {
        'binance' => 'BINANCE:' . $sym,
        'bybit'   => 'BYBIT:' . $sym,
        default   => $sym,
    };
}
