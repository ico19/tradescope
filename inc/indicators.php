<?php
/**
 * TradeScope - libreria di analisi tecnica
 * Tutti gli indicatori lavorano su array di float indicizzati 0..n-1 (dal piu' vecchio al piu' recente).
 */
declare(strict_types=1);

function ts_col(array $candles, string $k): array {
    return array_map(static fn($c) => (float)$c[$k], $candles);
}

function ts_last(array $a, int $back = 0) {
    $n = count($a);
    $i = $n - 1 - $back;
    return $i >= 0 ? $a[$i] : null;
}

function ts_sma(array $v, int $p): array {
    $out = []; $sum = 0.0;
    foreach ($v as $i => $x) {
        $sum += $x;
        if ($i >= $p) $sum -= $v[$i - $p];
        $out[$i] = $i >= $p - 1 ? $sum / $p : null;
    }
    return $out;
}

function ts_ema(array $v, int $p): array {
    $out = []; $k = 2 / ($p + 1); $ema = null; $sum = 0.0;
    foreach ($v as $i => $x) {
        if ($i < $p - 1) { $sum += $x; $out[$i] = null; continue; }
        if ($i === $p - 1) { $sum += $x; $ema = $sum / $p; $out[$i] = $ema; continue; }
        $ema = ($x - $ema) * $k + $ema;
        $out[$i] = $ema;
    }
    return $out;
}

/** Media mobile di Wilder (usata da RSI/ATR/ADX) */
function ts_wilder(array $v, int $p): array {
    $out = []; $acc = null; $sum = 0.0;
    foreach ($v as $i => $x) {
        if ($i < $p - 1) { $sum += $x; $out[$i] = null; continue; }
        if ($i === $p - 1) { $sum += $x; $acc = $sum / $p; $out[$i] = $acc; continue; }
        $acc = ($acc * ($p - 1) + $x) / $p;
        $out[$i] = $acc;
    }
    return $out;
}

function ts_rsi(array $close, int $p = 14): array {
    $gain = [0.0]; $loss = [0.0];
    for ($i = 1; $i < count($close); $i++) {
        $d = $close[$i] - $close[$i - 1];
        $gain[$i] = $d > 0 ? $d : 0.0;
        $loss[$i] = $d < 0 ? -$d : 0.0;
    }
    $ag = ts_wilder($gain, $p); $al = ts_wilder($loss, $p);
    $out = [];
    foreach ($close as $i => $_) {
        if ($ag[$i] === null || $al[$i] === null) { $out[$i] = null; continue; }
        $out[$i] = $al[$i] == 0.0 ? 100.0 : 100 - (100 / (1 + $ag[$i] / $al[$i]));
    }
    return $out;
}

function ts_macd(array $close, int $fast = 12, int $slow = 26, int $signal = 9): array {
    $ef = ts_ema($close, $fast); $es = ts_ema($close, $slow);
    $line = [];
    foreach ($close as $i => $_) {
        $line[$i] = ($ef[$i] === null || $es[$i] === null) ? null : $ef[$i] - $es[$i];
    }
    $clean = array_values(array_filter($line, static fn($x) => $x !== null));
    $sig   = ts_ema($clean, $signal);
    $offset = count($line) - count($clean);
    $sigFull = []; $hist = [];
    foreach ($line as $i => $_) {
        $j = $i - $offset;
        $sigFull[$i] = ($j >= 0 && isset($sig[$j])) ? $sig[$j] : null;
        $hist[$i] = ($line[$i] === null || $sigFull[$i] === null) ? null : $line[$i] - $sigFull[$i];
    }
    return ['macd' => $line, 'signal' => $sigFull, 'hist' => $hist];
}

function ts_true_range(array $candles): array {
    $tr = [0.0];
    for ($i = 1; $i < count($candles); $i++) {
        $h = $candles[$i]['h']; $l = $candles[$i]['l']; $pc = $candles[$i - 1]['c'];
        $tr[$i] = max($h - $l, abs($h - $pc), abs($l - $pc));
    }
    return $tr;
}

function ts_atr(array $candles, int $p = 14): array {
    return ts_wilder(ts_true_range($candles), $p);
}

function ts_bbands(array $close, int $p = 20, float $mult = 2.0): array {
    $mid = ts_sma($close, $p);
    $up = []; $lo = []; $bw = [];
    foreach ($close as $i => $_) {
        if ($mid[$i] === null) { $up[$i] = $lo[$i] = $bw[$i] = null; continue; }
        $slice = array_slice($close, $i - $p + 1, $p);
        $mean = $mid[$i]; $var = 0.0;
        foreach ($slice as $x) { $var += ($x - $mean) ** 2; }
        $sd = sqrt($var / $p);
        $up[$i] = $mean + $mult * $sd;
        $lo[$i] = $mean - $mult * $sd;
        $bw[$i] = $mean != 0.0 ? (($up[$i] - $lo[$i]) / $mean) * 100 : null;
    }
    return ['upper' => $up, 'middle' => $mid, 'lower' => $lo, 'bandwidth' => $bw];
}

function ts_stoch(array $candles, int $kP = 14, int $dP = 3): array {
    $k = [];
    foreach ($candles as $i => $c) {
        if ($i < $kP - 1) { $k[$i] = null; continue; }
        $slice = array_slice($candles, $i - $kP + 1, $kP);
        $hh = max(array_map(static fn($x) => $x['h'], $slice));
        $ll = min(array_map(static fn($x) => $x['l'], $slice));
        $k[$i] = ($hh - $ll) == 0.0 ? 50.0 : (($c['c'] - $ll) / ($hh - $ll)) * 100;
    }
    $clean = array_values(array_filter($k, static fn($x) => $x !== null));
    $d = ts_sma($clean, $dP);
    $off = count($k) - count($clean);
    $dFull = [];
    foreach ($k as $i => $_) {
        $j = $i - $off;
        $dFull[$i] = ($j >= 0 && isset($d[$j])) ? $d[$j] : null;
    }
    return ['k' => $k, 'd' => $dFull];
}

function ts_adx(array $candles, int $p = 14): array {
    $n = count($candles);
    $plus = [0.0]; $minus = [0.0];
    for ($i = 1; $i < $n; $i++) {
        $upMove   = $candles[$i]['h'] - $candles[$i - 1]['h'];
        $downMove = $candles[$i - 1]['l'] - $candles[$i]['l'];
        $plus[$i]  = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
        $minus[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
    }
    $atr = ts_wilder(ts_true_range($candles), $p);
    $pdi = ts_wilder($plus, $p); $mdi = ts_wilder($minus, $p);
    $dx = [];
    for ($i = 0; $i < $n; $i++) {
        if ($atr[$i] === null || !$atr[$i] || $pdi[$i] === null || $mdi[$i] === null) { $dx[$i] = null; continue; }
        $pd = 100 * $pdi[$i] / $atr[$i];
        $md = 100 * $mdi[$i] / $atr[$i];
        $dx[$i] = ($pd + $md) == 0.0 ? 0.0 : 100 * abs($pd - $md) / ($pd + $md);
    }
    $clean = array_values(array_filter($dx, static fn($x) => $x !== null));
    $adxC  = ts_wilder($clean, $p);
    $off   = count($dx) - count($clean);
    $adx = []; $pdiP = []; $mdiP = [];
    for ($i = 0; $i < $n; $i++) {
        $j = $i - $off;
        $adx[$i]  = ($j >= 0 && isset($adxC[$j])) ? $adxC[$j] : null;
        $pdiP[$i] = ($atr[$i] ?? 0) ? 100 * $pdi[$i] / $atr[$i] : null;
        $mdiP[$i] = ($atr[$i] ?? 0) ? 100 * $mdi[$i] / $atr[$i] : null;
    }
    return ['adx' => $adx, 'plus_di' => $pdiP, 'minus_di' => $mdiP];
}

/** OBV - On Balance Volume */
function ts_obv(array $candles): array {
    $out = [0.0];
    for ($i = 1; $i < count($candles); $i++) {
        $prev = $out[$i - 1];
        if ($candles[$i]['c'] > $candles[$i - 1]['c'])      $out[$i] = $prev + $candles[$i]['v'];
        elseif ($candles[$i]['c'] < $candles[$i - 1]['c'])  $out[$i] = $prev - $candles[$i]['v'];
        else                                                 $out[$i] = $prev;
    }
    return $out;
}

/** VWAP ancorato alle ultime N candele */
function ts_vwap(array $candles, int $n = 50): ?float {
    $slice = array_slice($candles, -$n);
    $pv = 0.0; $vol = 0.0;
    foreach ($slice as $c) {
        $tp = ($c['h'] + $c['l'] + $c['c']) / 3;
        $pv += $tp * $c['v']; $vol += $c['v'];
    }
    return $vol > 0 ? $pv / $vol : null;
}

/** Punti di swing (fractal) */
function ts_swings(array $candles, int $lr = 3): array {
    $highs = []; $lows = [];
    $n = count($candles);
    for ($i = $lr; $i < $n - $lr; $i++) {
        $isHigh = true; $isLow = true;
        for ($j = $i - $lr; $j <= $i + $lr; $j++) {
            if ($j === $i) continue;
            if ($candles[$j]['h'] >= $candles[$i]['h']) $isHigh = false;
            if ($candles[$j]['l'] <= $candles[$i]['l']) $isLow  = false;
        }
        if ($isHigh) $highs[] = ['i' => $i, 't' => $candles[$i]['t'], 'p' => $candles[$i]['h']];
        if ($isLow)  $lows[]  = ['i' => $i, 't' => $candles[$i]['t'], 'p' => $candles[$i]['l']];
    }
    return ['highs' => $highs, 'lows' => $lows];
}

/** Raggruppa i livelli vicini in zone di supporto/resistenza */
function ts_cluster_levels(array $points, float $tolerance): array {
    if (!$points) return [];
    usort($points, static fn($a, $b) => $a['p'] <=> $b['p']);
    $clusters = []; $cur = [$points[0]];
    for ($i = 1; $i < count($points); $i++) {
        if (abs($points[$i]['p'] - $cur[count($cur) - 1]['p']) <= $tolerance) {
            $cur[] = $points[$i];
        } else {
            $clusters[] = $cur; $cur = [$points[$i]];
        }
    }
    $clusters[] = $cur;
    $out = [];
    foreach ($clusters as $c) {
        $prices = array_map(static fn($x) => $x['p'], $c);
        $out[] = [
            'price'  => array_sum($prices) / count($prices),
            'touches'=> count($c),
            'last_t' => max(array_map(static fn($x) => $x['t'], $c)),
        ];
    }
    usort($out, static fn($a, $b) => $b['touches'] <=> $a['touches']);
    return $out;
}

/** Struttura di mercato: HH/HL (rialzista), LH/LL (ribassista) */
function ts_market_structure(array $swings): array {
    $h = array_slice($swings['highs'], -3);
    $l = array_slice($swings['lows'], -3);
    $trend = 'laterale';
    if (count($h) >= 2 && count($l) >= 2) {
        $hh = $h[count($h) - 1]['p'] > $h[count($h) - 2]['p'];
        $hl = $l[count($l) - 1]['p'] > $l[count($l) - 2]['p'];
        $lh = $h[count($h) - 1]['p'] < $h[count($h) - 2]['p'];
        $ll = $l[count($l) - 1]['p'] < $l[count($l) - 2]['p'];
        if ($hh && $hl) $trend = 'rialzista (HH/HL)';
        elseif ($lh && $ll) $trend = 'ribassista (LH/LL)';
        elseif ($hh && $ll) $trend = 'espansione della volatilita (range in allargamento)';
        elseif ($lh && $hl) $trend = 'compressione (triangolo/contrazione)';
    }
    return [
        'trend'      => $trend,
        'last_high'  => $h ? end($h) : null,
        'last_low'   => $l ? end($l) : null,
    ];
}

/** Ritracciamenti e estensioni di Fibonacci sull'ultimo swing rilevante */
function ts_fibonacci(array $candles, int $lookback = 120): array {
    $slice = array_slice($candles, -$lookback);
    if (count($slice) < 10) return [];
    $hi = max(array_map(static fn($c) => $c['h'], $slice));
    $lo = min(array_map(static fn($c) => $c['l'], $slice));
    $hiIdx = 0; $loIdx = 0;
    foreach ($slice as $i => $c) {
        if ($c['h'] === $hi) $hiIdx = $i;
        if ($c['l'] === $lo) $loIdx = $i;
    }
    $up = $loIdx < $hiIdx; // impulso rialzista se il minimo precede il massimo
    $range = $hi - $lo;
    if ($range <= 0) return [];
    $levels = [];
    foreach ([0.236, 0.382, 0.5, 0.618, 0.705, 0.786] as $f) {
        $levels['ret_' . $f] = $up ? $hi - $range * $f : $lo + $range * $f;
    }
    foreach ([1.272, 1.618, 2.0] as $f) {
        $levels['ext_' . $f] = $up ? $lo + $range * $f : $hi - $range * $f;
    }
    return ['direction' => $up ? 'impulso rialzista' : 'impulso ribassista', 'high' => $hi, 'low' => $lo, 'levels' => $levels];
}

/** Profilo dei volumi semplificato: POC e value area */
function ts_volume_profile(array $candles, int $bins = 24, int $lookback = 150): array {
    $slice = array_slice($candles, -$lookback);
    if (count($slice) < 20) return [];
    $hi = max(array_map(static fn($c) => $c['h'], $slice));
    $lo = min(array_map(static fn($c) => $c['l'], $slice));
    if ($hi <= $lo) return [];
    $step = ($hi - $lo) / $bins;
    $hist = array_fill(0, $bins, 0.0);
    foreach ($slice as $c) {
        $tp = ($c['h'] + $c['l'] + $c['c']) / 3;
        $b = (int)min($bins - 1, max(0, floor(($tp - $lo) / $step)));
        $hist[$b] += $c['v'] > 0 ? $c['v'] : 1;
    }
    $total = array_sum($hist);
    $pocIdx = array_search(max($hist), $hist, true);
    // Value area 70%
    $order = $hist; arsort($order);
    $acc = 0.0; $selected = [];
    foreach ($order as $idx => $v) {
        $selected[] = $idx; $acc += $v;
        if ($total > 0 && $acc / $total >= 0.7) break;
    }
    return [
        'poc'    => $lo + ($pocIdx + 0.5) * $step,
        'va_low' => $lo + (min($selected)) * $step,
        'va_high'=> $lo + (max($selected) + 1) * $step,
    ];
}

/** Percentile della volatilita corrente rispetto allo storico */
function ts_volatility_percentile(array $atr, array $close): ?float {
    $vals = [];
    foreach ($atr as $i => $a) {
        if ($a === null || empty($close[$i])) continue;
        $vals[] = $a / $close[$i] * 100;
    }
    if (count($vals) < 30) return null;
    $cur = end($vals);
    sort($vals);
    $below = 0;
    foreach ($vals as $v) { if ($v < $cur) $below++; }
    return round($below / count($vals) * 100, 1);
}

/** Rilevamento pattern candlestick sulle ultime candele */
function ts_candle_patterns(array $candles): array {
    $out = [];
    $n = count($candles);
    if ($n < 3) return $out;
    $c1 = $candles[$n - 1]; $c2 = $candles[$n - 2]; $c3 = $candles[$n - 3];
    $body1 = abs($c1['c'] - $c1['o']); $range1 = max(1e-12, $c1['h'] - $c1['l']);
    $upper = $c1['h'] - max($c1['c'], $c1['o']);
    $lower = min($c1['c'], $c1['o']) - $c1['l'];

    if ($body1 / $range1 < 0.1) $out[] = 'doji sull ultima candela';
    if ($lower > 2 * $body1 && $upper < $body1) $out[] = 'hammer / long lower wick (rifiuto dei minimi)';
    if ($upper > 2 * $body1 && $lower < $body1) $out[] = 'shooting star / long upper wick (rifiuto dei massimi)';
    if ($c1['c'] > $c1['o'] && $c2['c'] < $c2['o'] && $c1['c'] >= $c2['o'] && $c1['o'] <= $c2['c']) $out[] = 'engulfing rialzista';
    if ($c1['c'] < $c1['o'] && $c2['c'] > $c2['o'] && $c1['o'] >= $c2['c'] && $c1['c'] <= $c2['o']) $out[] = 'engulfing ribassista';
    if ($c1['c'] > $c1['o'] && $c2['c'] > $c2['o'] && $c3['c'] > $c3['o']) $out[] = 'tre candele rialziste consecutive';
    if ($c1['c'] < $c1['o'] && $c2['c'] < $c2['o'] && $c3['c'] < $c3['o']) $out[] = 'tre candele ribassiste consecutive';
    return $out;
}

/** Variazione percentuale su N candele */
function ts_change_pct(array $close, int $bars): ?float {
    $n = count($close);
    if ($n <= $bars) return null;
    $old = $close[$n - 1 - $bars];
    if (!$old) return null;
    return round((($close[$n - 1] - $old) / $old) * 100, 2);
}
