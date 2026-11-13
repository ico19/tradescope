<?php
/**
 * TradeScope 2.0 - modulo di analisi avanzata
 *
 * Aggiunge al motore 1.0: divergenze, regime di mercato, Smart Money Concepts
 * (BOS/CHoCH, order block, fair value gap, liquidity sweep), livelli ancorati
 * (open giornaliero/settimanale/mensile, VWAP di sessione), nodi di volume
 * (HVN/LVN, naked POC), lettura Wyckoff euristica, bande di proiezione
 * empiriche e punteggio di confluenza deterministico.
 *
 * Nessuna dipendenza esterna: tutto calcolato su candele OHLCV.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ utility */

function ts_pearson(array $a, array $b): ?float {
    $n = min(count($a), count($b));
    if ($n < 20) return null;
    $a = array_slice($a, -$n); $b = array_slice($b, -$n);
    $ma = array_sum($a) / $n; $mb = array_sum($b) / $n;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i] - $ma; $y = $b[$i] - $mb;
        $num += $x * $y; $da += $x * $x; $db += $y * $y;
    }
    if ($da <= 0 || $db <= 0) return null;
    return round($num / sqrt($da * $db), 2);
}

/** Rendimenti semplici barra su barra */
function ts_returns(array $close): array {
    $out = [];
    for ($i = 1; $i < count($close); $i++) {
        $p = (float)$close[$i - 1];
        $out[] = $p != 0.0 ? ($close[$i] - $p) / $p : 0.0;
    }
    return $out;
}

function ts_percentile_of(array $values, float $cur): ?float {
    $vals = array_values(array_filter($values, static fn($v) => $v !== null));
    if (count($vals) < 30) return null;
    sort($vals);
    $below = 0;
    foreach ($vals as $v) { if ($v < $cur) $below++; }
    return round($below / count($vals) * 100, 1);
}

/* -------------------------------------------------------------- divergenze */

/**
 * Divergenze regolari e nascoste su RSI e istogramma MACD, sugli ultimi swing.
 * @return array{lista:array<string>, punteggio:int}
 */
function ts_divergences(array $swings, array $rsi, array $macdHist): array {
    $list = []; $score = 0;
    $get = static function (array $series, int $i) {
        return isset($series[$i]) && $series[$i] !== null ? (float)$series[$i] : null;
    };

    $hi = array_slice($swings['highs'], -3);
    if (count($hi) >= 2) {
        $a = $hi[count($hi) - 2]; $b = $hi[count($hi) - 1];
        $ra = $get($rsi, $a['i']); $rb = $get($rsi, $b['i']);
        if ($ra !== null && $rb !== null) {
            if ($b['p'] > $a['p'] && $rb < $ra - 1.5) { $list[] = 'divergenza ribassista regolare su RSI (massimo piu alto, RSI piu basso)'; $score -= 10; }
            if ($b['p'] < $a['p'] && $rb > $ra + 1.5) { $list[] = 'divergenza rialzista nascosta su RSI (massimo piu basso, RSI piu alto)'; $score += 6; }
        }
        $ha = $get($macdHist, $a['i']); $hb = $get($macdHist, $b['i']);
        if ($ha !== null && $hb !== null && $b['p'] > $a['p'] && $hb < $ha) { $list[] = 'divergenza ribassista su istogramma MACD'; $score -= 6; }
    }

    $lo = array_slice($swings['lows'], -3);
    if (count($lo) >= 2) {
        $a = $lo[count($lo) - 2]; $b = $lo[count($lo) - 1];
        $ra = $get($rsi, $a['i']); $rb = $get($rsi, $b['i']);
        if ($ra !== null && $rb !== null) {
            if ($b['p'] < $a['p'] && $rb > $ra + 1.5) { $list[] = 'divergenza rialzista regolare su RSI (minimo piu basso, RSI piu alto)'; $score += 10; }
            if ($b['p'] > $a['p'] && $rb < $ra - 1.5) { $list[] = 'divergenza ribassista nascosta su RSI (minimo piu alto, RSI piu basso)'; $score -= 6; }
        }
        $ha = $get($macdHist, $a['i']); $hb = $get($macdHist, $b['i']);
        if ($ha !== null && $hb !== null && $b['p'] < $a['p'] && $hb > $ha) { $list[] = 'divergenza rialzista su istogramma MACD'; $score += 6; }
    }

    return ['lista' => $list, 'punteggio' => (int)ts_clamp((float)$score, -16, 16)];
}

/* ---------------------------------------------------------- regime mercato */

/**
 * Classifica il regime: trend, range, compressione o espansione di volatilita.
 * Determina quale famiglia di strategie ha senso applicare.
 */
function ts_regime(array $candles): array {
    $close = ts_col($candles, 'c');
    $n = count($close);
    $price = (float)$close[$n - 1];

    $adxAll = ts_adx($candles, 14);
    $adx    = (float)(ts_last($adxAll['adx']) ?? 0);
    $pdi    = (float)(ts_last($adxAll['plus_di']) ?? 0);
    $mdi    = (float)(ts_last($adxAll['minus_di']) ?? 0);

    $bb   = ts_bbands($close, 20, 2.0);
    $bw   = (float)(ts_last($bb['bandwidth']) ?? 0);
    $bwPc = ts_percentile_of($bb['bandwidth'], $bw);

    $atr    = ts_atr($candles, 14);
    $atrPct = [];
    foreach ($atr as $i => $a) { $atrPct[$i] = ($a === null || empty($close[$i])) ? null : $a / $close[$i] * 100; }
    $atrCur = (float)(ts_last($atrPct) ?? 0);
    $atrPc  = ts_percentile_of($atrPct, $atrCur);

    $ema50  = ts_ema($close, 50);
    $ema200 = ts_ema($close, 200);
    $slope  = null;
    if ($n > 60 && ts_last($ema50) !== null && ts_last($ema50, 10) !== null && ts_last($ema50, 10) != 0) {
        $slope = round((ts_last($ema50) - ts_last($ema50, 10)) / ts_last($ema50, 10) * 100, 2);
    }
    $above200 = ts_last($ema200) !== null ? $price > (float)ts_last($ema200) : null;

    $tipo = 'range'; $strategia = 'mean reversion sui bordi del range'; $affidabilita = 'media';
    if ($adx >= 22 && $pdi > $mdi) { $tipo = 'trend rialzista'; $strategia = 'trend following: comprare i ritracciamenti, non i massimi'; $affidabilita = 'alta'; }
    elseif ($adx >= 22 && $mdi > $pdi) { $tipo = 'trend ribassista'; $strategia = 'trend following short: vendere i rimbalzi sulle resistenze'; $affidabilita = 'alta'; }
    elseif ($bwPc !== null && $bwPc <= 20) { $tipo = 'compressione di volatilita (pre-espansione)'; $strategia = 'attesa di breakout con conferma di volume, niente ingressi dentro la compressione'; $affidabilita = 'bassa finche non rompe'; }
    elseif ($bwPc !== null && $bwPc >= 85) { $tipo = 'espansione di volatilita'; $strategia = 'ridurre la size, stop piu ampi, evitare mean reversion aggressiva'; $affidabilita = 'bassa'; }
    elseif ($adx < 18) { $tipo = 'range / assenza di trend'; $strategia = 'mean reversion sui bordi del range, target sul POC'; $affidabilita = 'media'; }

    return [
        'tipo' => $tipo,
        'strategia_coerente' => $strategia,
        'affidabilita_segnali' => $affidabilita,
        'adx14' => round($adx, 1),
        'di_dominante' => $pdi > $mdi ? '+DI (compratori)' : '-DI (venditori)',
        'ampiezza_bollinger_pct' => round($bw, 2),
        'ampiezza_bollinger_percentile' => $bwPc,
        'atr_pct' => round($atrCur, 2),
        'atr_percentile' => $atrPc,
        'pendenza_ema50_10_barre_pct' => $slope,
        'sopra_ema200' => $above200,
    ];
}

/* --------------------------------------------------- Smart Money Concepts */

/**
 * Struttura istituzionale: rottura di struttura, cambio di carattere,
 * order block non mitigati, fair value gap aperti, prese di liquidita,
 * massimi/minimi equali (liquidita in attesa).
 */
function ts_smc(array $candles, int $lr = 3): array {
    $n = count($candles);
    if ($n < 60) return [];
    $atr = (float)(ts_last(ts_atr($candles, 14)) ?? 0);
    $price = (float)$candles[$n - 1]['c'];
    $sw = ts_swings($candles, $lr);

    /* --- BOS / CHoCH: ultima rottura di uno swing con chiusura oltre il livello --- */
    $bos = null;
    $lastHigh = $sw['highs'] ? end($sw['highs']) : null;
    $lastLow  = $sw['lows']  ? end($sw['lows'])  : null;
    for ($i = $n - 1; $i > max(0, $n - 40); $i--) {
        $c = $candles[$i];
        if ($lastHigh && $lastHigh['i'] < $i && $c['c'] > $lastHigh['p']) {
            $bos = ['tipo' => 'BOS rialzista', 'livello' => ts_round_price($lastHigh['p']), 'barre_fa' => $n - 1 - $i];
            break;
        }
        if ($lastLow && $lastLow['i'] < $i && $c['c'] < $lastLow['p']) {
            $bos = ['tipo' => 'BOS ribassista', 'livello' => ts_round_price($lastLow['p']), 'barre_fa' => $n - 1 - $i];
            break;
        }
    }

    /* --- Fair value gap (imbalance a 3 candele) ancora aperti --- */
    $fvg = [];
    for ($i = $n - 2; $i >= max(2, $n - 80); $i--) {
        $a = $candles[$i - 2]; $c = $candles[$i];
        if ($c['l'] > $a['h'] && ($c['l'] - $a['h']) > $atr * 0.15) {
            $zLo = $a['h']; $zHi = $c['l']; $filled = false;
            for ($j = $i + 1; $j < $n; $j++) { if ($candles[$j]['l'] <= $zLo) { $filled = true; break; } }
            if (!$filled) $fvg[] = ['tipo' => 'FVG rialzista (supporto)', 'da' => ts_round_price($zLo), 'a' => ts_round_price($zHi), 'barre_fa' => $n - 1 - $i];
        }
        if ($c['h'] < $a['l'] && ($a['l'] - $c['h']) > $atr * 0.15) {
            $zLo = $c['h']; $zHi = $a['l']; $filled = false;
            for ($j = $i + 1; $j < $n; $j++) { if ($candles[$j]['h'] >= $zHi) { $filled = true; break; } }
            if (!$filled) $fvg[] = ['tipo' => 'FVG ribassista (resistenza)', 'da' => ts_round_price($zLo), 'a' => ts_round_price($zHi), 'barre_fa' => $n - 1 - $i];
        }
        if (count($fvg) >= 4) break;
    }

    /* --- Order block: ultima candela opposta prima di un impulso che rompe struttura --- */
    $ob = [];
    for ($i = $n - 3; $i >= max(3, $n - 60); $i--) {
        $c = $candles[$i]; $next = $candles[$i + 1]; $next2 = $candles[$i + 2];
        $body = abs($c['c'] - $c['o']);
        $impulseUp   = ($next['c'] - $next['o']) > $body * 1.2 && $next['c'] > $c['h'];
        $impulseDown = ($next['o'] - $next['c']) > $body * 1.2 && $next['c'] < $c['l'];
        if ($c['c'] < $c['o'] && $impulseUp && $next2['c'] >= $next['o']) {
            $touched = false;
            for ($j = $i + 2; $j < $n; $j++) { if ($candles[$j]['l'] <= $c['c']) { $touched = true; break; } }
            $ob[] = ['tipo' => 'order block rialzista (domanda)', 'da' => ts_round_price($c['l']), 'a' => ts_round_price($c['h']),
                     'stato' => $touched ? 'mitigato' : 'non mitigato', 'barre_fa' => $n - 1 - $i,
                     'distanza_pct' => $price > 0 ? round(($c['h'] - $price) / $price * 100, 2) : null];
        }
        if ($c['c'] > $c['o'] && $impulseDown && $next2['c'] <= $next['o']) {
            $touched = false;
            for ($j = $i + 2; $j < $n; $j++) { if ($candles[$j]['h'] >= $c['c']) { $touched = true; break; } }
            $ob[] = ['tipo' => 'order block ribassista (offerta)', 'da' => ts_round_price($c['l']), 'a' => ts_round_price($c['h']),
                     'stato' => $touched ? 'mitigato' : 'non mitigato', 'barre_fa' => $n - 1 - $i,
                     'distanza_pct' => $price > 0 ? round(($c['l'] - $price) / $price * 100, 2) : null];
        }
        if (count($ob) >= 4) break;
    }

    /* --- Liquidity sweep: estremo oltre uno swing con rientro nella stessa candela --- */
    $sweep = [];
    foreach (array_slice($sw['highs'], -4) as $h) {
        for ($i = $h['i'] + $lr + 1; $i < $n; $i++) {
            if ($candles[$i]['h'] > $h['p'] && $candles[$i]['c'] < $h['p']) {
                $sweep[] = ['tipo' => 'sweep dei massimi (liquidita presa sopra ' . ts_round_price($h['p']) . ')', 'barre_fa' => $n - 1 - $i];
                break;
            }
        }
    }
    foreach (array_slice($sw['lows'], -4) as $l) {
        for ($i = $l['i'] + $lr + 1; $i < $n; $i++) {
            if ($candles[$i]['l'] < $l['p'] && $candles[$i]['c'] > $l['p']) {
                $sweep[] = ['tipo' => 'sweep dei minimi (liquidita presa sotto ' . ts_round_price($l['p']) . ')', 'barre_fa' => $n - 1 - $i];
                break;
            }
        }
    }
    usort($sweep, static fn($a, $b) => $a['barre_fa'] <=> $b['barre_fa']);
    $sweep = array_slice($sweep, 0, 3);

    /* --- Equal highs / equal lows: liquidita in attesa --- */
    $equal = [];
    $tol = $atr * 0.2;
    $hs = array_slice($sw['highs'], -5); $ls = array_slice($sw['lows'], -5);
    for ($i = 1; $i < count($hs); $i++) {
        if ($tol > 0 && abs($hs[$i]['p'] - $hs[$i - 1]['p']) <= $tol) $equal[] = 'massimi equali attorno a ' . ts_round_price(($hs[$i]['p'] + $hs[$i - 1]['p']) / 2) . ' (liquidita sopra)';
    }
    for ($i = 1; $i < count($ls); $i++) {
        if ($tol > 0 && abs($ls[$i]['p'] - $ls[$i - 1]['p']) <= $tol) $equal[] = 'minimi equali attorno a ' . ts_round_price(($ls[$i]['p'] + $ls[$i - 1]['p']) / 2) . ' (liquidita sotto)';
    }

    return [
        'rottura_struttura' => $bos,
        'order_block' => $ob,
        'fair_value_gap' => $fvg,
        'prese_di_liquidita' => $sweep,
        'liquidita_in_attesa' => array_slice($equal, 0, 3),
    ];
}

/* ------------------------------------------------------- livelli ancorati */

/** Open e range di riferimento (giorno, settimana, mese) + VWAP di settimana */
function ts_anchored_levels(array $candles): array {
    $n = count($candles);
    if ($n < 20) return [];
    $byDay = []; $byWeek = []; $byMonth = [];
    foreach ($candles as $c) {
        $byDay[gmdate('Y-m-d', $c['t'])][]   = $c;
        $byWeek[gmdate('o-W', $c['t'])][]    = $c;
        $byMonth[gmdate('Y-m', $c['t'])][]   = $c;
    }
    $agg = static function (array $group): array {
        $first = $group[0]; $last = $group[count($group) - 1];
        return [
            'open'  => ts_round_price($first['o']),
            'high'  => ts_round_price(max(array_map(static fn($c) => $c['h'], $group))),
            'low'   => ts_round_price(min(array_map(static fn($c) => $c['l'], $group))),
            'close' => ts_round_price($last['c']),
        ];
    };
    $dayKeys = array_keys($byDay); $weekKeys = array_keys($byWeek); $monthKeys = array_keys($byMonth);
    $out = [];
    $out['giorno_corrente']  = $agg($byDay[end($dayKeys)]);
    if (count($dayKeys) >= 2)   $out['giorno_precedente']     = $agg($byDay[$dayKeys[count($dayKeys) - 2]]);
    $out['settimana_corrente'] = $agg($byWeek[end($weekKeys)]);
    if (count($weekKeys) >= 2)  $out['settimana_precedente']  = $agg($byWeek[$weekKeys[count($weekKeys) - 2]]);
    $out['mese_corrente'] = $agg($byMonth[end($monthKeys)]);

    $week = $byWeek[end($weekKeys)];
    $pv = 0.0; $vol = 0.0;
    foreach ($week as $c) { $tp = ($c['h'] + $c['l'] + $c['c']) / 3; $pv += $tp * $c['v']; $vol += $c['v']; }
    $out['vwap_settimanale'] = $vol > 0 ? ts_round_price($pv / $vol) : null;

    $hour = (int)gmdate('G', $candles[$n - 1]['t']);
    $out['sessione_ultima_candela'] = $hour < 7 ? 'Asia' : ($hour < 12 ? 'Londra' : ($hour < 20 ? 'New York' : 'post-chiusura USA'));
    return $out;
}

/* ---------------------------------------------------------- nodi di volume */

/** HVN / LVN e naked POC (POC di finestre passate mai piu toccati) */
function ts_volume_nodes(array $candles, int $bins = 30, int $lookback = 200): array {
    $slice = array_slice($candles, -$lookback);
    if (count($slice) < 40) return [];
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
    $max = max($hist); $avg = array_sum($hist) / $bins;
    $hvn = []; $lvn = [];
    foreach ($hist as $i => $v) {
        $mid = ts_round_price($lo + ($i + 0.5) * $step);
        if ($v >= $avg * 1.6) $hvn[] = $mid;
        if ($v <= $avg * 0.35) $lvn[] = $mid;
    }

    /* naked POC: POC di finestre di 30 barre non piu attraversati dal prezzo */
    $naked = [];
    $total = count($candles);
    for ($start = $total - 60; $start >= max(0, $total - 300); $start -= 30) {
        $win = array_slice($candles, $start, 30);
        if (count($win) < 20) continue;
        $wHi = max(array_map(static fn($c) => $c['h'], $win));
        $wLo = min(array_map(static fn($c) => $c['l'], $win));
        if ($wHi <= $wLo) continue;
        $wStep = ($wHi - $wLo) / 12;
        $wHist = array_fill(0, 12, 0.0);
        foreach ($win as $c) {
            $tp = ($c['h'] + $c['l'] + $c['c']) / 3;
            $b = (int)min(11, max(0, floor(($tp - $wLo) / $wStep)));
            $wHist[$b] += $c['v'] > 0 ? $c['v'] : 1;
        }
        $poc = $wLo + ((int)array_search(max($wHist), $wHist, true) + 0.5) * $wStep;
        $touched = false;
        foreach (array_slice($candles, $start + 30) as $c) {
            if ($c['l'] <= $poc && $c['h'] >= $poc) { $touched = true; break; }
        }
        if (!$touched) $naked[] = ts_round_price($poc);
        if (count($naked) >= 3) break;
    }

    return [
        'hvn' => array_slice($hvn, 0, 5),
        'lvn' => array_slice($lvn, 0, 5),
        'naked_poc' => $naked,
        'volume_max_bin_su_media' => round($max / max(1e-9, $avg), 2),
    ];
}

/* -------------------------------------------------------- lettura Wyckoff */

/** Euristica di fase: accumulo, distribuzione, markup, markdown, spring/upthrust */
function ts_wyckoff_hint(array $candles, array $regime): array {
    $n = count($candles);
    if ($n < 80) return [];
    $close = ts_col($candles, 'c'); $vol = ts_col($candles, 'v');
    $recent = array_slice($candles, -40);
    $hi = max(array_map(static fn($c) => $c['h'], $recent));
    $lo = min(array_map(static fn($c) => $c['l'], $recent));
    $range = $hi - $lo;
    $price = (float)$close[$n - 1];
    $volRecent = array_sum(array_slice($vol, -20)) / 20;
    $volBefore = array_sum(array_slice($vol, -60, 40)) / 40;
    $volTrend = $volBefore > 0 ? round($volRecent / $volBefore, 2) : null;
    $posInRange = $range > 0 ? round(($price - $lo) / $range * 100, 1) : null;

    $fase = 'indeterminata'; $note = '';
    $isRange = str_contains($regime['tipo'] ?? '', 'range') || str_contains($regime['tipo'] ?? '', 'compressione');

    if ($isRange && $volTrend !== null && $volTrend < 0.9 && $posInRange !== null && $posInRange < 45) {
        $fase = 'possibile accumulo (fase C/D)';
        $note = 'range con volumi in calo e prezzo nella parte bassa: cercare spring sotto il minimo con rientro immediato.';
    } elseif ($isRange && $volTrend !== null && $volTrend < 0.9 && $posInRange !== null && $posInRange > 55) {
        $fase = 'possibile distribuzione (fase C/D)';
        $note = 'range con volumi in calo e prezzo nella parte alta: attenzione a upthrust sopra il massimo con rientro.';
    } elseif (str_contains($regime['tipo'] ?? '', 'rialzista')) {
        $fase = 'markup'; $note = 'trend in sviluppo: acquistare ritracciamenti su domanda, non rincorrere le estensioni.';
    } elseif (str_contains($regime['tipo'] ?? '', 'ribassista')) {
        $fase = 'markdown'; $note = 'distribuzione in atto: vendere i rimbalzi su offerta, non anticipare i minimi.';
    }

    /* spring / upthrust sulle ultime 10 barre */
    $event = null;
    $prev = array_slice($candles, -40, 30);
    if ($prev) {
        $pLo = min(array_map(static fn($c) => $c['l'], $prev));
        $pHi = max(array_map(static fn($c) => $c['h'], $prev));
        foreach (array_slice($candles, -10) as $k => $c) {
            if ($c['l'] < $pLo && $c['c'] > $pLo) $event = 'spring: minimo del range violato e riassorbito (' . (10 - $k) . ' barre fa)';
            if ($c['h'] > $pHi && $c['c'] < $pHi) $event = 'upthrust: massimo del range violato e riassorbito (' . (10 - $k) . ' barre fa)';
        }
    }

    return [
        'fase' => $fase,
        'nota' => $note,
        'evento' => $event,
        'posizione_nel_range_pct' => $posInRange,
        'volume_20_su_40_precedenti' => $volTrend,
    ];
}

/* ------------------------------------------------- proiezione statistica */

/**
 * Bande di proiezione empiriche: distribuzione reale dei rendimenti a h barre
 * osservata sullo storico, invece della semplice ATR*sqrt(t).
 * @return array<int, array{offset:int, price:float, lower:float, upper:float, q25:float, q75:float}>
 */
function ts_empirical_bands(array $candles, int $horizon, float $driftBias = 0.0): array {
    $close = ts_col($candles, 'c');
    $n = count($close);
    $price = (float)$close[$n - 1];
    $win = array_slice($close, -min(500, $n));
    $m = count($win);

    $atr = (float)(ts_last(ts_atr($candles, 14)) ?? ($price * 0.01));
    $lookback = min(30, $n - 1);
    $mom = $lookback > 0 ? ($price - $close[$n - 1 - $lookback]) / $lookback : 0.0;

    $out = [];
    for ($h = 1; $h <= $horizon; $h++) {
        $rets = [];
        for ($i = 0; $i + $h < $m; $i++) {
            if ($win[$i] == 0.0) continue;
            $rets[] = ($win[$i + $h] - $win[$i]) / $win[$i];
        }
        if (count($rets) >= 30) {
            sort($rets);
            $q = static function (array $a, float $p) {
                $idx = (int)round(($p / 100) * (count($a) - 1));
                return (float)$a[max(0, min(count($a) - 1, $idx))];
            };
            $lo = $q($rets, 10); $hi = $q($rets, 90);
            $q25 = $q($rets, 25); $q75 = $q($rets, 75);
        } else {
            $band = $atr * sqrt($h) * 1.1 / max(1e-9, $price);
            $lo = -$band; $hi = $band; $q25 = -$band * 0.5; $q75 = $band * 0.5;
        }
        // percorso centrale: momentum smorzato + bias direzionale del punteggio di confluenza
        $mid = $price + $mom * 0.45 * $h + $price * ($driftBias * 0.0022 * sqrt($h));
        $out[] = [
            'offset' => $h,
            'price'  => ts_round_price($mid),
            'lower'  => ts_round_price($mid + $price * $lo * 0.9),
            'upper'  => ts_round_price($mid + $price * $hi * 0.9),
            'q25'    => ts_round_price($mid + $price * $q25 * 0.9),
            'q75'    => ts_round_price($mid + $price * $q75 * 0.9),
        ];
    }
    return $out;
}

/* ---------------------------------------------- lettura compatta per MTF */

/** Lettura sintetica di un timeframe, usata per il consenso multi-timeframe */
function ts_tf_read(array $candles): array {
    $close = ts_col($candles, 'c');
    $n = count($close);
    $price = (float)$close[$n - 1];
    $e21 = ts_last(ts_ema($close, 21)); $e50 = ts_last(ts_ema($close, 50)); $e200 = ts_last(ts_ema($close, 200));
    $rsi = ts_last(ts_rsi($close, 14));
    $adxA = ts_adx($candles, 14);
    $adx = ts_last($adxA['adx']); $pdi = ts_last($adxA['plus_di']); $mdi = ts_last($adxA['minus_di']);
    $struct = ts_market_structure(ts_swings($candles, 3));
    $macd = ts_macd($close);
    $hist = ts_last($macd['hist']);

    $score = 0;
    if ($e21 !== null && $e50 !== null) $score += $e21 > $e50 ? 12 : -12;
    if ($e200 !== null) $score += $price > $e200 ? 16 : -16;
    if (str_contains($struct['trend'], 'rialzista')) $score += 16;
    if (str_contains($struct['trend'], 'ribassista')) $score -= 16;
    if ($adx !== null && $adx >= 20 && $pdi !== null && $mdi !== null) $score += $pdi > $mdi ? 12 : -12;
    if ($hist !== null) $score += $hist > 0 ? 8 : -8;
    if ($rsi !== null) { if ($rsi > 55) $score += 6; elseif ($rsi < 45) $score -= 6; }

    return [
        'prezzo' => ts_round_price($price),
        'struttura' => $struct['trend'],
        'rsi14' => $rsi !== null ? round((float)$rsi, 1) : null,
        'adx14' => $adx !== null ? round((float)$adx, 1) : null,
        'sopra_ema200' => $e200 !== null ? $price > (float)$e200 : null,
        'ema21_sopra_ema50' => ($e21 !== null && $e50 !== null) ? $e21 > $e50 : null,
        'macd_hist_positivo' => $hist !== null ? $hist > 0 : null,
        'punteggio' => (int)ts_clamp((float)$score, -70, 70),
        'bias' => $score >= 18 ? 'long' : ($score <= -18 ? 'short' : 'neutro'),
    ];
}

/* -------------------------------------------------- punteggio confluenza */

/**
 * Punteggio deterministico di confluenza (-100 .. +100) con dettaglio dei
 * contributi: e' il riferimento oggettivo su cui il modello deve calibrarsi.
 */
function ts_confluence_score(array $s): array {
    $c = [];
    $ind = $s['indicatori'] ?? [];
    $price = (float)($s['prezzo'] ?? 0);
    $add = static function (string $fattore, float $v, string $nota) use (&$c) {
        if (abs($v) < 0.5) return;
        $c[] = ['fattore' => $fattore, 'contributo' => round($v, 1), 'nota' => $nota];
    };

    /* trend di fondo */
    $htf = $s['timeframe_superiore'] ?? null;
    if ($htf && isset($htf['sopra_ema200'])) {
        $add('trend timeframe superiore', $htf['sopra_ema200'] ? 16 : -16,
             ($htf['sopra_ema200'] ? 'prezzo sopra' : 'prezzo sotto') . ' EMA200 su ' . ts_tf_label((string)$htf['timeframe']));
    }
    if ($htf && isset($htf['trend'])) {
        if (str_contains((string)$htf['trend'], 'rialzista')) $add('struttura timeframe superiore', 10, (string)$htf['trend']);
        if (str_contains((string)$htf['trend'], 'ribassista')) $add('struttura timeframe superiore', -10, (string)$htf['trend']);
    }

    /* struttura operativa */
    $tr = (string)($s['struttura']['trend'] ?? '');
    if (str_contains($tr, 'rialzista')) $add('struttura operativa', 12, $tr);
    elseif (str_contains($tr, 'ribassista')) $add('struttura operativa', -12, $tr);
    elseif (str_contains($tr, 'compressione')) $add('struttura operativa', 0, $tr);

    /* medie */
    $e9 = $ind['ema9'] ?? null; $e21 = $ind['ema21'] ?? null; $e50 = $ind['ema50'] ?? null; $e200 = $ind['ema200'] ?? null;
    if ($e9 && $e21 && $e50) {
        if ($e9 > $e21 && $e21 > $e50) $add('allineamento EMA', 10, 'EMA 9 > 21 > 50');
        elseif ($e9 < $e21 && $e21 < $e50) $add('allineamento EMA', -10, 'EMA 9 < 21 < 50');
    }
    if ($e200 && $price) $add('posizione vs EMA200', $price > $e200 ? 6 : -6, $price > $e200 ? 'sopra EMA200' : 'sotto EMA200');

    /* forza del trend */
    $adx = $ind['adx14'] ?? null; $pdi = $ind['plus_di'] ?? null; $mdi = $ind['minus_di'] ?? null;
    if ($adx !== null && $pdi !== null && $mdi !== null) {
        $w = $adx >= 30 ? 10 : ($adx >= 22 ? 7 : ($adx >= 18 ? 3 : 0));
        $add('forza del trend (ADX ' . $adx . ')', $pdi > $mdi ? $w : -$w, $pdi > $mdi ? '+DI dominante' : '-DI dominante');
    }

    /* momentum */
    $rsi = $ind['rsi14'] ?? null; $rsiPrev = $ind['rsi14_precedente'] ?? null;
    if ($rsi !== null) {
        if ($rsi >= 70) $add('RSI in ipercomprato', -4, 'RSI ' . $rsi . ': rischio ritracciamento, non segnale short in trend');
        elseif ($rsi <= 30) $add('RSI in ipervenduto', 4, 'RSI ' . $rsi . ': rischio rimbalzo, non segnale long in downtrend');
        elseif ($rsi > 55) $add('momentum RSI', 6, 'RSI ' . $rsi . ' in zona rialzista');
        elseif ($rsi < 45) $add('momentum RSI', -6, 'RSI ' . $rsi . ' in zona ribassista');
        if ($rsiPrev !== null) $add('direzione RSI', $rsi > $rsiPrev ? 2 : -2, 'RSI ' . ($rsi > $rsiPrev ? 'in salita' : 'in discesa'));
    }
    $mh = $ind['macd_hist'] ?? null; $mhp = $ind['macd_hist_precedente'] ?? null;
    if ($mh !== null) {
        $add('MACD', $mh > 0 ? 6 : -6, 'istogramma ' . ($mh > 0 ? 'positivo' : 'negativo'));
        if ($mhp !== null) $add('accelerazione MACD', abs($mh) > abs($mhp) ? ($mh > 0 ? 3 : -3) : ($mh > 0 ? -1 : 1), abs($mh) > abs($mhp) ? 'momentum in espansione' : 'momentum in esaurimento');
    }

    /* volumi */
    $vr = $ind['volume_relativo'] ?? null; $obv = $ind['obv_trend'] ?? null;
    if ($obv) $add('OBV', str_contains((string)$obv, 'accumulo') ? 6 : -6, 'volumi ' . $obv);
    if ($vr !== null && $vr >= 1.5) $add('volume relativo', $mh !== null && $mh > 0 ? 4 : -4, 'volume ' . $vr . 'x la media: movimento partecipato');

    /* prezzo vs VWAP e POC */
    $vwap = $ind['vwap50'] ?? null;
    if ($vwap && $price) $add('posizione vs VWAP', $price > $vwap ? 4 : -4, $price > $vwap ? 'sopra VWAP' : 'sotto VWAP');
    $poc = $s['volume_profile']['poc'] ?? null;
    if ($poc && $price) $add('posizione vs POC', $price > $poc ? 4 : -4, $price > $poc ? 'sopra il POC (accettazione alta)' : 'sotto il POC (accettazione bassa)');

    /* divergenze */
    if (!empty($s['divergenze']['punteggio'])) {
        $add('divergenze', (float)$s['divergenze']['punteggio'], implode('; ', $s['divergenze']['lista'] ?? []));
    }

    /* SMC */
    $smc = $s['smart_money'] ?? [];
    if (!empty($smc['rottura_struttura']['tipo'])) {
        $t = (string)$smc['rottura_struttura']['tipo'];
        $recent = ((int)($smc['rottura_struttura']['barre_fa'] ?? 99)) <= 10;
        $add('rottura di struttura', (str_contains($t, 'rialzista') ? 10 : -10) * ($recent ? 1 : 0.6), $t . ' ' . ($smc['rottura_struttura']['barre_fa'] ?? '?') . ' barre fa');
    }
    foreach (($smc['prese_di_liquidita'] ?? []) as $sw) {
        if ((int)($sw['barre_fa'] ?? 99) <= 5) {
            $add('presa di liquidita recente', str_contains((string)$sw['tipo'], 'minimi') ? 8 : -8, (string)$sw['tipo']);
        }
    }
    foreach (($smc['order_block'] ?? []) as $o) {
        if (($o['stato'] ?? '') === 'non mitigato' && abs((float)($o['distanza_pct'] ?? 99)) <= 2.5) {
            $add('order block vicino', str_contains((string)$o['tipo'], 'rialzista') ? 5 : -5, $o['tipo'] . ' a ' . $o['distanza_pct'] . '%');
        }
    }

    /* regime: penalizza i segnali direzionali quando il regime non li supporta */
    $reg = (string)($s['regime']['tipo'] ?? '');
    $penale = 1.0;
    if (str_contains($reg, 'compressione')) $penale = 0.6;
    elseif (str_contains($reg, 'espansione')) $penale = 0.75;
    elseif (str_contains($reg, 'range')) $penale = 0.8;

    /* derivati crypto */
    $der = $s['derivati'] ?? null;
    if ($der) {
        $f = $der['funding_pct'] ?? null;
        if ($f !== null) {
            if ($f > 0.05) $add('funding elevato', -6, 'long affollati (funding ' . $f . '% per periodo): rischio long squeeze');
            elseif ($f < -0.03) $add('funding negativo', 6, 'short affollati (funding ' . $f . '%): benzina per squeeze rialzista');
        }
        $oi = $der['oi_var_pct_24h'] ?? null;
        $chg = $s['ticker_24h']['variazione_pct'] ?? null;
        if ($oi !== null && $chg !== null) {
            if ($oi > 3 && $chg > 0) $add('open interest', 5, 'OI +' . $oi . '% con prezzo in salita: nuovi long, trend sostenuto');
            if ($oi > 3 && $chg < 0) $add('open interest', -5, 'OI +' . $oi . '% con prezzo in discesa: nuovi short, pressione ribassista');
            if ($oi < -3) $add('open interest', $chg > 0 ? 3 : -3, 'OI ' . $oi . '%: chiusura di posizioni (movimento da liquidazioni)');
        }
    }

    /* consenso multi-timeframe */
    $mtf = $s['multi_timeframe'] ?? [];
    if ($mtf) {
        $sum = 0; $cnt = 0;
        foreach ($mtf as $tf => $r) { if (isset($r['punteggio'])) { $sum += (int)$r['punteggio']; $cnt++; } }
        if ($cnt) {
            $avg = $sum / $cnt;
            $add('consenso multi-timeframe', ts_clamp($avg * 0.28, -18, 18), 'media dei punteggi sui ' . $cnt . ' timeframe: ' . round($avg, 1));
        }
    }

    $raw = 0.0;
    foreach ($c as $x) { $raw += (float)$x['contributo']; }
    $score = (int)round(ts_clamp($raw * $penale, -100, 100));
    $abs = abs($score);
    $qual = $abs >= 55 ? 'A' : ($abs >= 32 ? 'B' : 'C');

    usort($c, static fn($a, $b) => abs($b['contributo']) <=> abs($a['contributo']));

    return [
        'punteggio' => $score,
        'direzione' => $score >= 18 ? 'long' : ($score <= -18 ? 'short' : 'neutro'),
        'qualita_setup' => $qual,
        'confidenza_massima_consentita' => (int)ts_clamp(38 + $abs * 0.55, 35, 88),
        'moltiplicatore_regime' => $penale,
        'contributi' => array_slice($c, 0, 14),
    ];
}
