<?php
/**
 * TradeScope - motore di analisi: snapshot tecnico, prompt, orchestrazione
 */
declare(strict_types=1);

/**
 * Costruisce lo snapshot tecnico completo di un simbolo.
 * @return array{ok:bool, snapshot:array, candles:array, error:string}
 */
function ts_build_snapshot(string $symbol, string $timeframe, string $provider, int $limit = 300): array {
    $fetch = ts_fetch_candles($symbol, $timeframe, $limit, $provider);
    if (!$fetch['ok']) return ['ok' => false, 'snapshot' => [], 'candles' => [], 'error' => $fetch['error']];

    $candles = $fetch['candles'];
    $close   = ts_col($candles, 'c');
    $vol     = ts_col($candles, 'v');
    $n       = count($candles);
    $price   = (float)end($close);

    $ema9   = ts_ema($close, 9);
    $ema21  = ts_ema($close, 21);
    $ema50  = ts_ema($close, 50);
    $ema200 = ts_ema($close, 200);
    $rsi    = ts_rsi($close, 14);
    $macd   = ts_macd($close);
    $atr    = ts_atr($candles, 14);
    $bb     = ts_bbands($close, 20, 2.0);
    $stoch  = ts_stoch($candles, 14, 3);
    $adx    = ts_adx($candles, 14);
    $obv    = ts_obv($candles);
    $vwap   = ts_vwap($candles, 50);
    $swings = ts_swings($candles, 3);
    $struct = ts_market_structure($swings);
    $fib    = ts_fibonacci($candles, min(150, $n));
    $vp     = ts_volume_profile($candles, 24, min(150, $n));

    $atrLast = (float)(ts_last($atr) ?? 0);
    $tol     = $atrLast > 0 ? $atrLast * 0.6 : $price * 0.005;

    $resPoints = array_filter($swings['highs'], static fn($p) => $p['p'] >= $price);
    $supPoints = array_filter($swings['lows'],  static fn($p) => $p['p'] <= $price);
    $resistances = array_slice(ts_cluster_levels(array_values($resPoints), $tol), 0, 4);
    $supports    = array_slice(ts_cluster_levels(array_values($supPoints), $tol), 0, 4);
    usort($resistances, static fn($a, $b) => $a['price'] <=> $b['price']);
    usort($supports,    static fn($a, $b) => $b['price'] <=> $a['price']);

    $volMa20 = ts_sma($vol, 20);
    $volRel  = (ts_last($volMa20) ?? 0) > 0 ? round($vol[$n - 1] / ts_last($volMa20), 2) : null;
    $obvSlope = null;
    if ($n > 25) {
        $obvSlope = ($obv[$n - 1] - $obv[$n - 21]) >= 0 ? 'in accumulo' : 'in distribuzione';
    }

    $ticker = ts_ticker_24h($symbol, $fetch['provider']);

    // Serie recente compatta (le ultime 60 candele) per il modello
    $recent = [];
    foreach (array_slice($candles, -60) as $c) {
        $recent[] = [
            gmdate('Y-m-d H:i', $c['t']),
            ts_round_price($c['o']), ts_round_price($c['h']),
            ts_round_price($c['l']), ts_round_price($c['c']),
            round($c['v'], 2),
        ];
    }

    // Contesto sul timeframe superiore
    $htf = null;
    $htfTf = ts_tf_higher($timeframe);
    $htfFetch = ts_fetch_candles($symbol, $htfTf, 200, $fetch['provider']);
    if ($htfFetch['ok'] && count($htfFetch['candles']) > 60) {
        $hc  = $htfFetch['candles'];
        $hcl = ts_col($hc, 'c');
        $hSw = ts_swings($hc, 3);
        $htf = [
            'timeframe' => $htfTf,
            'trend'     => ts_market_structure($hSw)['trend'],
            'rsi'       => round((float)(ts_last(ts_rsi($hcl, 14)) ?? 0), 1),
            'ema50'     => ts_round_price((float)(ts_last(ts_ema($hcl, 50)) ?? 0)),
            'ema200'    => ts_round_price((float)(ts_last(ts_ema($hcl, 200)) ?? 0)),
            'sopra_ema200' => (ts_last(ts_ema($hcl, 200)) !== null) ? ($price > ts_last(ts_ema($hcl, 200))) : null,
            'var_20_barre' => ts_change_pct($hcl, 20),
        ];
    }

    $snapshot = [
        'symbol'     => strtoupper($symbol),
        'provider'   => $fetch['provider'],
        'timeframe'  => $timeframe,
        'generato_il'=> gmdate('Y-m-d H:i') . ' UTC',
        'prezzo'     => ts_round_price($price),
        'ultima_candela_t' => (int)$candles[$n - 1]['t'],
        'ticker_24h' => $ticker ? [
            'variazione_pct' => round($ticker['change_pct'], 2),
            'max_24h' => ts_round_price($ticker['high']),
            'min_24h' => ts_round_price($ticker['low']),
            'volume_quote_24h' => round($ticker['volume']),
        ] : null,
        'variazioni' => [
            'ultime_5_barre'  => ts_change_pct($close, 5),
            'ultime_20_barre' => ts_change_pct($close, 20),
            'ultime_50_barre' => ts_change_pct($close, 50),
        ],
        'indicatori' => [
            'ema9'   => ts_round_price((float)(ts_last($ema9) ?? 0)),
            'ema21'  => ts_round_price((float)(ts_last($ema21) ?? 0)),
            'ema50'  => ts_round_price((float)(ts_last($ema50) ?? 0)),
            'ema200' => ts_last($ema200) !== null ? ts_round_price((float)ts_last($ema200)) : null,
            'rsi14'  => ts_last($rsi) !== null ? round((float)ts_last($rsi), 1) : null,
            'rsi14_precedente' => ts_last($rsi, 1) !== null ? round((float)ts_last($rsi, 1), 1) : null,
            'macd'        => ts_last($macd['macd']) !== null ? ts_round_price((float)ts_last($macd['macd'])) : null,
            'macd_signal' => ts_last($macd['signal']) !== null ? ts_round_price((float)ts_last($macd['signal'])) : null,
            'macd_hist'   => ts_last($macd['hist']) !== null ? ts_round_price((float)ts_last($macd['hist'])) : null,
            'macd_hist_precedente' => ts_last($macd['hist'], 1) !== null ? ts_round_price((float)ts_last($macd['hist'], 1)) : null,
            'atr14'       => ts_round_price($atrLast),
            'atr_pct'     => $price > 0 ? round($atrLast / $price * 100, 2) : null,
            'volatilita_percentile' => ts_volatility_percentile($atr, $close),
            'bollinger'   => [
                'superiore' => ts_last($bb['upper']) !== null ? ts_round_price((float)ts_last($bb['upper'])) : null,
                'media'     => ts_last($bb['middle']) !== null ? ts_round_price((float)ts_last($bb['middle'])) : null,
                'inferiore' => ts_last($bb['lower']) !== null ? ts_round_price((float)ts_last($bb['lower'])) : null,
                'ampiezza_pct' => ts_last($bb['bandwidth']) !== null ? round((float)ts_last($bb['bandwidth']), 2) : null,
            ],
            'stoch_k' => ts_last($stoch['k']) !== null ? round((float)ts_last($stoch['k']), 1) : null,
            'stoch_d' => ts_last($stoch['d']) !== null ? round((float)ts_last($stoch['d']), 1) : null,
            'adx14'   => ts_last($adx['adx']) !== null ? round((float)ts_last($adx['adx']), 1) : null,
            'plus_di' => ts_last($adx['plus_di']) !== null ? round((float)ts_last($adx['plus_di']), 1) : null,
            'minus_di'=> ts_last($adx['minus_di']) !== null ? round((float)ts_last($adx['minus_di']), 1) : null,
            'vwap50'  => $vwap !== null ? ts_round_price($vwap) : null,
            'volume_relativo' => $volRel,
            'obv_trend' => $obvSlope,
        ],
        'struttura' => [
            'trend' => $struct['trend'],
            'ultimo_massimo' => $struct['last_high'] ? ['prezzo' => ts_round_price($struct['last_high']['p']), 'data' => gmdate('Y-m-d H:i', $struct['last_high']['t'])] : null,
            'ultimo_minimo'  => $struct['last_low']  ? ['prezzo' => ts_round_price($struct['last_low']['p']),  'data' => gmdate('Y-m-d H:i', $struct['last_low']['t'])] : null,
        ],
        'livelli' => [
            'resistenze' => array_map(static fn($r) => ['prezzo' => ts_round_price($r['price']), 'tocchi' => $r['touches']], $resistances),
            'supporti'   => array_map(static fn($r) => ['prezzo' => ts_round_price($r['price']), 'tocchi' => $r['touches']], $supports),
            'massimo_periodo' => ts_round_price(max(ts_col($candles, 'h'))),
            'minimo_periodo'  => ts_round_price(min(ts_col($candles, 'l'))),
        ],
        'fibonacci' => $fib ? [
            'direzione' => $fib['direction'],
            'da' => ts_round_price($fib['low']), 'a' => ts_round_price($fib['high']),
            'livelli' => array_map(static fn($v) => ts_round_price((float)$v), $fib['levels']),
        ] : null,
        'volume_profile' => $vp ? [
            'poc' => ts_round_price($vp['poc']),
            'value_area_bassa' => ts_round_price($vp['va_low']),
            'value_area_alta'  => ts_round_price($vp['va_high']),
        ] : null,
        'pattern_candlestick' => ts_candle_patterns($candles),
        'timeframe_superiore' => $htf,
        'serie_recente' => $recent,
    ];

    return ['ok' => true, 'snapshot' => $snapshot, 'candles' => $candles, 'error' => ''];
}

/** Proiezione algoritmica di riferimento basata su ATR e trend (usata come base e come fallback) */
function ts_baseline_projection(array $candles, int $horizon): array {
    $close = ts_col($candles, 'c');
    $n = count($close);
    $price = (float)$close[$n - 1];
    $atr = (float)(ts_last(ts_atr($candles, 14)) ?? ($price * 0.01));
    $lookback = min(30, $n - 1);
    $drift = $lookback > 0 ? ($price - $close[$n - 1 - $lookback]) / $lookback : 0.0;
    $drift *= 0.45; // smorzamento del momentum
    $path = [];
    for ($i = 1; $i <= $horizon; $i++) {
        $mid = $price + $drift * $i;
        $band = $atr * sqrt($i) * 1.1;
        $path[] = [
            'offset' => $i,
            'price'  => ts_round_price($mid),
            'lower'  => ts_round_price($mid - $band),
            'upper'  => ts_round_price($mid + $band),
        ];
    }
    return $path;
}

function ts_system_prompt(): string {
    return <<<'SYS'
Sei un analista quantitativo senior specializzato in mercati crypto, azionari, forex e materie prime.
Operi come motore di analisi di una piattaforma privata: le tue risposte vengono lette da un software e
visualizzate su un grafico. Il tuo compito e' produrre l'analisi piu' accurata, concreta e operativa possibile.

METODO DI LAVORO
1. Parti dal contesto macro-strutturale (timeframe superiore) e scendi al timeframe operativo.
2. Valuta insieme: struttura di mercato (HH/HL o LH/LL), trend delle medie mobili, momentum (RSI, MACD,
   Stocastico), forza del trend (ADX/DI), volatilita' (ATR, ampiezza Bollinger, percentile), volumi
   (volume relativo, OBV, POC e value area), livelli chiave (supporti/resistenze, Fibonacci, VWAP) e
   pattern di prezzo/candlestick.
3. Cerca sempre le confluenze: un livello vale se e' confermato da piu' fattori indipendenti.
4. Applica una gestione del rischio professionale: lo stop va oltre l'invalidazione strutturale
   (tipicamente 1-1,5 ATR oltre il livello), i target su resistenze/supporti reali, il rapporto
   rischio/rendimento deve essere realistico e coerente con la volatilita' misurata.
5. La proiezione di prezzo deve essere coerente con ATR e struttura: nessun movimento impossibile,
   la banda di confidenza si allarga con la radice del tempo.
6. Sii onesto sulla probabilita': se il quadro e' contrastante, usa bias "neutro" e confidenza bassa.
   La confidenza deve riflettere la reale confluenza dei segnali, non l'entusiasmo.

REGOLE DI OUTPUT
- Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo prima o dopo, senza blocchi markdown.
- Tutti i testi in italiano, professionali e sintetici.
- Tutti i prezzi come numeri (non stringhe), sulla stessa scala dei dati forniti.
- Nessuna premessa, nessun disclaimer, nessun riferimento ai tuoi limiti: solo analisi.

SCHEMA JSON RICHIESTO
{
  "bias": "long" | "short" | "neutro",
  "confidenza": 0-100,
  "orizzonte_barre": intero,
  "sintesi": "2-4 frasi con la lettura complessiva",
  "struttura_mercato": "descrizione della struttura attuale",
  "fattori_chiave": ["fattore 1", "fattore 2", "..."],
  "lettura_indicatori": {
    "trend": "...", "momentum": "...", "volatilita": "...", "volumi": "..."
  },
  "livelli": {
    "supporti": [numero, ...],
    "resistenze": [numero, ...],
    "livello_invalidazione": numero
  },
  "scenari": [
    {"nome": "principale", "probabilita": 0-100, "direzione": "rialzo|ribasso|laterale",
     "target": numero, "descrizione": "cosa deve accadere e come si sviluppa"},
    {"nome": "alternativo", "probabilita": 0-100, "direzione": "...", "target": numero, "descrizione": "..."},
    {"nome": "invalidazione", "probabilita": 0-100, "direzione": "...", "target": numero, "descrizione": "..."}
  ],
  "proiezione": {
    "orizzonte_barre": intero,
    "percorso": [{"offset": 1, "price": numero, "lower": numero, "upper": numero}, ...]
  },
  "piano_operativo": {
    "direzione": "long|short|attesa",
    "zona_ingresso": [numero_min, numero_max],
    "ingresso": numero,
    "stop_loss": numero,
    "target": [numero, numero],
    "rischio_rendimento": numero,
    "dimensione_posizione_pct_rischio": numero,
    "condizioni_ingresso": "trigger operativo preciso",
    "invalidazione": "cosa annulla il piano"
  },
  "gestione_rischio": "note operative su rischio, volatilita' e gestione della posizione",
  "cosa_monitorare": ["evento o livello 1", "..."]
}

VINCOLI SULLA PROIEZIONE
- "percorso" deve contenere esattamente un punto per ogni barra dell'orizzonte richiesto, con offset da 1 a N.
- "price" e' il percorso piu' probabile, "lower"/"upper" i limiti della banda di confidenza (circa 80%).
- lower < price < upper per ogni punto; la banda si allarga progressivamente.
- Il percorso deve partire da un valore vicino al prezzo attuale e muoversi in modo coerente con lo scenario principale.
SYS;
}

function ts_user_prompt(array $snapshot, int $horizon, array $baseline, array $previous = []): string {
    $tf = $snapshot['timeframe'];
    $out  = "ANALISI RICHIESTA\n";
    $out .= "Strumento: {$snapshot['symbol']} ({$snapshot['provider']})\n";
    $out .= "Timeframe operativo: " . ts_tf_label($tf) . "\n";
    $out .= "Orizzonte di proiezione richiesto: {$horizon} barre da " . ts_tf_label($tf) . "\n";
    $out .= "Prezzo corrente: {$snapshot['prezzo']}\n\n";

    $clean = $snapshot;
    unset($clean['serie_recente']);
    $out .= "SNAPSHOT TECNICO (calcolato dal server, dati reali di mercato)\n";
    $out .= json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    $out .= "ULTIME 60 CANDELE [data UTC, open, high, low, close, volume]\n";
    foreach ($snapshot['serie_recente'] as $r) {
        $out .= implode(' | ', $r) . "\n";
    }
    $out .= "\n";

    $out .= "PROIEZIONE ALGORITMICA DI RIFERIMENTO (random walk con drift smorzato e banda ATR*sqrt(t))\n";
    $out .= "Usala come base statistica: correggila secondo struttura, livelli e scenario che ritieni piu' probabile.\n";
    $out .= json_encode(array_slice($baseline, 0, $horizon), JSON_UNESCAPED_SLASHES) . "\n\n";

    if ($previous) {
        $out .= "ANALISI PRECEDENTE SU QUESTO STRUMENTO (per continuita' di lettura)\n";
        $out .= json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    $out .= "Produci ora l'oggetto JSON completo secondo lo schema. Solo JSON.";
    return $out;
}

/** Normalizza e valida la risposta del modello */
function ts_normalize_result(array $r, float $price, int $horizon, array $baseline): array {
    $bias = strtolower((string)($r['bias'] ?? 'neutro'));
    if (!in_array($bias, ['long', 'short', 'neutro'], true)) {
        $bias = in_array($bias, ['rialzista', 'buy', 'bullish'], true) ? 'long'
              : (in_array($bias, ['ribassista', 'sell', 'bearish'], true) ? 'short' : 'neutro');
    }
    $r['bias'] = $bias;
    $r['confidenza'] = (int)ts_clamp((float)($r['confidenza'] ?? 50), 0, 100);
    $r['orizzonte_barre'] = (int)($r['orizzonte_barre'] ?? $horizon);

    // Proiezione: validazione punto per punto
    $path = $r['proiezione']['percorso'] ?? [];
    $clean = [];
    if (is_array($path) && count($path) >= max(3, (int)($horizon * 0.5))) {
        $i = 0;
        foreach ($path as $p) {
            if (!is_array($p) || !isset($p['price'])) continue;
            $i++;
            $mid = (float)$p['price'];
            // scarta valori assurdi (oltre +/-60% dal prezzo corrente)
            if ($price > 0 && ($mid <= 0 || abs($mid - $price) / $price > 0.6)) { $clean = []; break; }
            $lo = isset($p['lower']) ? (float)$p['lower'] : $mid * 0.99;
            $up = isset($p['upper']) ? (float)$p['upper'] : $mid * 1.01;
            if ($lo > $mid) [$lo, $mid] = [$mid, $lo];
            if ($up < $mid) [$up, $mid] = [$mid, $up];
            $clean[] = [
                'offset' => (int)($p['offset'] ?? $i),
                'price'  => ts_round_price($mid),
                'lower'  => ts_round_price(min($lo, $mid)),
                'upper'  => ts_round_price(max($up, $mid)),
            ];
            if (count($clean) >= $horizon) break;
        }
    }
    if (!$clean) {
        $clean = $baseline;
        $r['proiezione_fallback'] = true;
    }
    $r['proiezione'] = ['orizzonte_barre' => count($clean), 'percorso' => array_values($clean)];

    // Piano operativo: coerenza numerica
    $pl = is_array($r['piano_operativo'] ?? null) ? $r['piano_operativo'] : [];
    foreach (['ingresso', 'stop_loss', 'rischio_rendimento', 'dimensione_posizione_pct_rischio'] as $k) {
        if (isset($pl[$k])) $pl[$k] = (float)$pl[$k];
    }
    if (isset($pl['target']) && is_array($pl['target'])) {
        $pl['target'] = array_values(array_map(static fn($t) => ts_round_price((float)$t), $pl['target']));
    }
    if (!empty($pl['ingresso']) && !empty($pl['stop_loss']) && !empty($pl['target'][0])) {
        $risk = abs($pl['ingresso'] - $pl['stop_loss']);
        $rew  = abs($pl['target'][0] - $pl['ingresso']);
        $pl['rischio_rendimento'] = $risk > 0 ? round($rew / $risk, 2) : null;
    }
    $r['piano_operativo'] = $pl;

    return $r;
}

/**
 * Esegue l'analisi completa di uno strumento e la salva.
 * @return array{ok:bool, id:int, result:array, error:string}
 */
function ts_run_analysis(string $symbol, string $timeframe, string $provider, string $source = 'cron', ?string $model = null, ?string $aiProvider = null): array {
    // TradeScope 2.0: il motore v2 (confluenza, multi-timeframe, derivati, calibrazione
    // sullo storico) sostituisce il v1. Impostare engine.use_v2 = 0 per tornare al v1.
    if ((int)ts_opt('engine', 'use_v2', 1) === 1 && function_exists('ts_run_analysis_v2')) {
        return ts_run_analysis_v2($symbol, $timeframe, $provider, $source, $model, $aiProvider);
    }
    $limit  = (int)ts_opt('engine', 'candles', 300);
    $snapRes = ts_build_snapshot($symbol, $timeframe, $provider, $limit);
    if (!$snapRes['ok']) {
        return ['ok' => false, 'id' => 0, 'result' => [], 'error' => $snapRes['error']];
    }
    $snapshot = $snapRes['snapshot'];
    $candles  = $snapRes['candles'];
    $price    = (float)$snapshot['prezzo'];
    $horizon  = (int)ts_opt('engine', 'horizon_bars', 24);
    $baseline = ts_baseline_projection($candles, $horizon);

    // Continuita': ultima analisi salvata sullo stesso strumento
    $prev = [];
    $last = ts_last_analysis($symbol, $timeframe);
    if ($last) {
        $p = json_decode((string)$last['payload'], true);
        if (is_array($p)) {
            $prev = [
                'data' => $last['created_at'],
                'bias' => $p['bias'] ?? null,
                'confidenza' => $p['confidenza'] ?? null,
                'sintesi' => mb_substr((string)($p['sintesi'] ?? ''), 0, 400),
                'prezzo_allora' => (float)$last['price_at'],
            ];
        }
    }

    $res = ts_ai_call(ts_system_prompt(), ts_user_prompt($snapshot, $horizon, $baseline, $prev), $model, $aiProvider);
    if (!$res['ok']) {
        return ['ok' => false, 'id' => 0, 'result' => [], 'error' => $res['error']];
    }
    $parsed = ts_extract_json($res['text']);
    if (!is_array($parsed)) {
        ts_log('analysis', 'risposta non interpretabile', ['symbol' => $symbol, 'preview' => mb_substr($res['text'], 0, 300)]);
        return ['ok' => false, 'id' => 0, 'result' => [], 'error' => 'risposta del modello non interpretabile'];
    }
    $result = ts_normalize_result($parsed, $price, $horizon, $baseline);
    $result['_meta'] = [
        'symbol'    => strtoupper($symbol),
        'timeframe' => $timeframe,
        'provider'  => $snapshot['provider'],
        'prezzo'    => $price,
        'model'     => $res['model'],
        'ai'        => $res['provider'],
        'generato'  => ts_now(),
        'candle_t'  => (int)$snapshot['ultima_candela_t'],
        'tf_seconds'=> ts_tf_seconds($timeframe),
    ];

    $pl = $result['piano_operativo'] ?? [];
    $st = ts_db()->prepare('INSERT INTO ts_analyses
        (symbol, provider, timeframe, created_at, candle_time, price_at, bias, confidence, horizon_bars,
         entry, stop_loss, target1, target2, risk_reward, model, tokens_in, tokens_out, source, payload, snapshot)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        strtoupper($symbol), $snapshot['provider'], $timeframe, ts_now(),
        (int)$snapshot['ultima_candela_t'], $price,
        $result['bias'], $result['confidenza'], (int)($result['proiezione']['orizzonte_barre'] ?? $horizon),
        $pl['ingresso'] ?? null, $pl['stop_loss'] ?? null,
        $pl['target'][0] ?? null, $pl['target'][1] ?? null, $pl['rischio_rendimento'] ?? null,
        $res['model'], $res['usage']['in'] ?? 0, $res['usage']['out'] ?? 0, $source,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $id = (int)ts_db()->lastInsertId();

    return ['ok' => true, 'id' => $id, 'result' => $result, 'error' => ''];
}

function ts_last_analysis(string $symbol, string $timeframe): ?array {
    $st = ts_db()->prepare('SELECT * FROM ts_analyses WHERE symbol = ? AND timeframe = ? ORDER BY id DESC LIMIT 1');
    $st->execute([strtoupper($symbol), $timeframe]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Watchlist */
function ts_watchlist(bool $onlyEnabled = false): array {
    $sql = 'SELECT * FROM ts_watchlist' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order ASC, id ASC';
    return ts_db()->query($sql)->fetchAll();
}

function ts_watchlist_add(string $symbol, string $provider, string $timeframe, string $label = '', string $assetClass = 'crypto', string $tvSymbol = ''): int {
    $st = ts_db()->prepare('INSERT INTO ts_watchlist (symbol, provider, tv_symbol, label, asset_class, timeframe, enabled, sort_order, created_at)
                            VALUES (?,?,?,?,?,?,1,0,?)');
    $st->execute([strtoupper(trim($symbol)), $provider, $tvSymbol ?: null, $label ?: null, $assetClass, $timeframe, ts_now()]);
    return (int)ts_db()->lastInsertId();
}

/**
 * Valuta l'esito delle analisi passate confrontandole con il prezzo effettivo.
 * Aggiorna outcome/mfe/mae per le analisi non ancora chiuse.
 */
function ts_evaluate_open_analyses(int $limit = 20): int {
    $st = ts_db()->prepare('SELECT * FROM ts_analyses WHERE outcome IS NULL AND entry IS NOT NULL AND stop_loss IS NOT NULL ORDER BY id ASC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $done = 0;

    foreach ($rows as $row) {
        $tfSec   = ts_tf_seconds($row['timeframe']);
        $horizon = max(1, (int)$row['horizon_bars']);
        $deadline = (int)$row['candle_time'] + $tfSec * $horizon;
        $expired  = time() > $deadline;

        $fetch = ts_fetch_candles($row['symbol'], $row['timeframe'], min(400, $horizon + 60), $row['provider']);
        if (!$fetch['ok']) continue;
        $after = array_values(array_filter($fetch['candles'], static fn($c) => $c['t'] > (int)$row['candle_time']));
        if (!$after) { if ($expired) { ts_set_outcome((int)$row['id'], 'scaduta', null, null); $done++; } continue; }
        $after = array_slice($after, 0, $horizon);

        $entry = (float)$row['entry']; $stop = (float)$row['stop_loss'];
        $t1 = $row['target1'] !== null ? (float)$row['target1'] : null;
        $long = $row['bias'] === 'long' || ($t1 !== null && $t1 > $entry);

        $mfe = 0.0; $mae = 0.0; $outcome = null;
        foreach ($after as $c) {
            if ($long) {
                $mfe = max($mfe, ($c['h'] - $entry) / max(1e-12, $entry) * 100);
                $mae = min($mae, ($c['l'] - $entry) / max(1e-12, $entry) * 100);
                if ($c['l'] <= $stop) { $outcome = 'stop'; break; }
                if ($t1 !== null && $c['h'] >= $t1) { $outcome = 'target'; break; }
            } else {
                $mfe = max($mfe, ($entry - $c['l']) / max(1e-12, $entry) * 100);
                $mae = min($mae, ($entry - $c['h']) / max(1e-12, $entry) * 100);
                if ($c['h'] >= $stop) { $outcome = 'stop'; break; }
                if ($t1 !== null && $c['l'] <= $t1) { $outcome = 'target'; break; }
            }
        }
        if ($outcome === null && $expired) $outcome = 'scaduta';
        if ($outcome !== null) {
            ts_set_outcome((int)$row['id'], $outcome, round($mfe, 2), round($mae, 2));
            $done++;
        }
    }
    return $done;
}

function ts_set_outcome(int $id, string $outcome, ?float $mfe, ?float $mae): void {
    $st = ts_db()->prepare('UPDATE ts_analyses SET outcome = ?, outcome_at = ?, mfe = ?, mae = ? WHERE id = ?');
    $st->execute([$outcome, ts_now(), $mfe, $mae, $id]);
}

/** Statistiche di affidabilita' storica */
function ts_performance_stats(): array {
    $pdo = ts_db();
    $tot = (int)$pdo->query('SELECT COUNT(*) c FROM ts_analyses')->fetch()['c'];
    $res = $pdo->query("SELECT outcome, COUNT(*) c FROM ts_analyses WHERE outcome IS NOT NULL GROUP BY outcome")->fetchAll();
    $map = ['target' => 0, 'stop' => 0, 'scaduta' => 0];
    foreach ($res as $r) { $map[$r['outcome']] = (int)$r['c']; }
    $closed = $map['target'] + $map['stop'];
    return [
        'totale'     => $tot,
        'target'     => $map['target'],
        'stop'       => $map['stop'],
        'scadute'    => $map['scaduta'],
        'win_rate'   => $closed > 0 ? round($map['target'] / $closed * 100, 1) : null,
        'in_corso'   => (int)$pdo->query('SELECT COUNT(*) c FROM ts_analyses WHERE outcome IS NULL')->fetch()['c'],
    ];
}

/** Pulizia storico oltre la retention */
function ts_purge_old(): int {
    $days = (int)ts_opt('engine', 'retention_days', 120);
    if ($days <= 0) return 0;
    $st = ts_db()->prepare('DELETE FROM ts_analyses WHERE created_at < ?');
    $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    return $st->rowCount();
}
