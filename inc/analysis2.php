<?php
/**
 * TradeScope 2.0 - motore di analisi v2
 *
 * Differenze rispetto alla 1.0:
 *  - snapshot esteso: regime, divergenze, Smart Money Concepts, livelli ancorati,
 *    nodi di volume, lettura Wyckoff, derivati crypto, contesto di mercato,
 *    correlazione con BTC;
 *  - consenso multi-timeframe reale su 4 timeframe (lettura + punteggio ciascuno);
 *  - punteggio di confluenza deterministico calcolato dal server, che vincola
 *    la confidenza dichiarabile dal modello (anti-sovrastima);
 *  - bande di proiezione empiriche (distribuzione reale dei rendimenti);
 *  - retroazione sullo storico verificato: il modello vede il proprio win rate
 *    e gli ultimi esiti su quello strumento e si calibra;
 *  - validazione numerica severa del piano operativo e della proiezione.
 */
declare(strict_types=1);

/** Timeframe inferiore, usato per il timing d'ingresso */
function ts_tf_lower(string $tf): string {
    $map = ['15m'=>'15m','30m'=>'15m','1h'=>'15m','2h'=>'30m','4h'=>'1h','6h'=>'1h','12h'=>'2h','1d'=>'4h','1w'=>'1d'];
    return $map[$tf] ?? $tf;
}

/** Colonne aggiuntive della 2.0 (idempotente) */
function ts_db_migrate_v2(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = ['score DOUBLE NULL', 'quality VARCHAR(4) NULL', 'regime VARCHAR(64) NULL', 'ev DOUBLE NULL', 'engine VARCHAR(8) NULL'];
    if (ts_db_driver() !== 'mysql') {
        $cols = ['score DOUBLE NULL', 'quality TEXT NULL', 'regime TEXT NULL', 'ev DOUBLE NULL', 'engine TEXT NULL'];
    }
    foreach ($cols as $c) {
        try { ts_db()->exec('ALTER TABLE ts_analyses ADD COLUMN ' . $c); }
        catch (Throwable $e) { /* colonna gia' presente */ }
    }
}

/* ------------------------------------------------------------- snapshot v2 */

/**
 * Snapshot completo 2.0.
 * @return array{ok:bool, snapshot:array, candles:array, error:string}
 */
function ts_build_snapshot_v2(string $symbol, string $timeframe, string $provider, int $limit = 400): array {
    $base = ts_build_snapshot($symbol, $timeframe, $provider, $limit);
    if (!$base['ok']) return $base;

    $snap    = $base['snapshot'];
    $candles = $base['candles'];
    $prov    = (string)$snap['provider'];
    $close   = ts_col($candles, 'c');

    $regime  = ts_regime($candles);
    $swings  = ts_swings($candles, 3);
    $macd    = ts_macd($close);
    $snap['regime']         = $regime;
    $snap['divergenze']     = ts_divergences($swings, ts_rsi($close, 14), $macd['hist']);
    $snap['smart_money']    = ts_smc($candles, 3);
    $snap['livelli_ancorati'] = ts_anchored_levels($candles);
    $snap['nodi_volume']    = ts_volume_nodes($candles);
    $snap['wyckoff']        = ts_wyckoff_hint($candles, $regime);

    /* --- consenso multi-timeframe --- */
    $mtf = [];
    $tfs = array_values(array_unique([
        ts_tf_lower($timeframe),
        $timeframe,
        ts_tf_higher($timeframe),
        ts_tf_higher(ts_tf_higher($timeframe)),
    ]));
    foreach ($tfs as $tf) {
        if ($tf === $timeframe) {
            $mtf[$tf] = ts_tf_read($candles);
            $mtf[$tf]['ruolo'] = 'timeframe operativo';
            continue;
        }
        $f = ts_fetch_candles($symbol, $tf, 260, $prov);
        if (!$f['ok'] || count($f['candles']) < 60) continue;
        $mtf[$tf] = ts_tf_read($f['candles']);
        $mtf[$tf]['ruolo'] = ts_tf_seconds($tf) < ts_tf_seconds($timeframe) ? 'timing di ingresso' : 'contesto superiore';
    }
    $snap['multi_timeframe'] = $mtf;

    $sum = 0; $cnt = 0; $agree = 0;
    foreach ($mtf as $r) { $sum += (int)$r['punteggio']; $cnt++; }
    $avg = $cnt ? $sum / $cnt : 0;
    foreach ($mtf as $r) { if (($avg >= 0 && $r['punteggio'] >= 0) || ($avg < 0 && $r['punteggio'] < 0)) $agree++; }
    $snap['consenso_mtf'] = [
        'punteggio_medio' => round($avg, 1),
        'timeframe_allineati' => $agree . '/' . max(1, $cnt),
        'direzione' => $avg >= 15 ? 'long' : ($avg <= -15 ? 'short' : 'nessuna direzione dominante'),
    ];

    /* --- derivati e contesto (solo crypto) --- */
    if (ts_is_crypto($prov) && (int)ts_opt('engine', 'use_derivatives', 1) === 1) {
        $snap['derivati']    = ts_derivatives($symbol, $prov);
        $snap['contesto_mercato'] = ts_market_context();
        $snap['correlazione_btc'] = strtoupper($symbol) === 'BTCUSDT' ? null : ts_btc_correlation($candles, $timeframe, $prov);
    }

    /* --- punteggio di confluenza deterministico --- */
    $snap['confluenza'] = ts_confluence_score($snap);

    return ['ok' => true, 'snapshot' => $snap, 'candles' => $candles, 'error' => ''];
}

/* -------------------------------------------------- retroazione sullo storico */

/** Esiti verificati recenti: serve al modello per calibrare la confidenza */
function ts_accuracy_feedback(string $symbol, string $timeframe): array {
    $out = ['globale' => ts_performance_stats()];
    try {
        $st = ts_db()->prepare('SELECT bias, confidence, outcome, mfe, mae, created_at, price_at
                                FROM ts_analyses WHERE symbol = ? AND timeframe = ? AND outcome IS NOT NULL
                                ORDER BY id DESC LIMIT 8');
        $st->execute([strtoupper($symbol), $timeframe]);
        $rows = $st->fetchAll();
        $t = 0; $s = 0;
        foreach ($rows as $r) {
            if ($r['outcome'] === 'target') $t++;
            if ($r['outcome'] === 'stop') $s++;
        }
        $out['strumento'] = [
            'analisi_chiuse' => count($rows),
            'target' => $t, 'stop' => $s,
            'win_rate_pct' => ($t + $s) > 0 ? round($t / ($t + $s) * 100, 1) : null,
            'ultimi_esiti' => array_map(static fn($r) => [
                'data' => $r['created_at'], 'bias' => $r['bias'],
                'confidenza' => (float)$r['confidence'], 'esito' => $r['outcome'],
                'mfe_pct' => $r['mfe'], 'mae_pct' => $r['mae'],
            ], $rows),
        ];

        /* affidabilita' per fascia di confidenza dichiarata */
        $st2 = ts_db()->query("SELECT
              CASE WHEN confidence >= 75 THEN 'alta (>=75)' WHEN confidence >= 60 THEN 'media (60-74)' ELSE 'bassa (<60)' END AS fascia,
              SUM(CASE WHEN outcome = 'target' THEN 1 ELSE 0 END) AS t,
              SUM(CASE WHEN outcome = 'stop' THEN 1 ELSE 0 END) AS s
            FROM ts_analyses WHERE outcome IN ('target','stop') GROUP BY fascia");
        $cal = [];
        foreach ($st2->fetchAll() as $r) {
            $tt = (int)$r['t']; $ss = (int)$r['s'];
            if ($tt + $ss >= 3) $cal[$r['fascia']] = round($tt / ($tt + $ss) * 100, 1) . '% di target su ' . ($tt + $ss) . ' analisi';
        }
        if ($cal) $out['calibrazione_storica'] = $cal;
    } catch (Throwable $e) {
        // storico non disponibile: si procede senza retroazione
    }
    return $out;
}

/* ------------------------------------------------------------------ prompt */

function ts_system_prompt_v2(): string {
    return <<<'SYS'
Sei il motore di analisi quantitativa di TradeScope: analista senior di mercati crypto, azionari,
forex e materie prime, con impostazione da trader professionista discrezionale supportato da modelli.
Le tue risposte non vengono lette da un umano come testo libero: vengono interpretate da un software,
disegnate su un grafico e verificate automaticamente sui prezzi reali delle barre successive.
Ogni analisi entra in uno storico che misura quante volte il target e' stato raggiunto prima dello stop.
Il tuo obiettivo unico e' massimizzare quella percentuale, non produrre analisi interessanti.

## GERARCHIA DEL RAGIONAMENTO (rispettala nell'ordine)
1. REGIME. Prima di ogni cosa stabilisci in che regime sei: trend direzionale, range, compressione di
   volatilita', espansione. Il regime decide quali strumenti sono validi: in trend valgono continuazioni,
   pullback su medie e order block; in range valgono mean reversion sui bordi e target sul POC; in
   compressione si aspetta il breakout con volume; in espansione si riduce la size e si allargano gli stop.
   Applicare uno strumento fuori dal suo regime e' il principale errore da evitare.
2. STRUTTURA E TIMEFRAME SUPERIORE. Il contesto superiore ha sempre la precedenza. Non proporre long
   controtendenza sul timeframe superiore senza una prova di inversione (rottura di struttura confermata,
   presa di liquidita' con rientro, divergenza in area chiave con volumi).
3. LIQUIDITA' E LIVELLI. Ragiona in zone, non in linee: order block non mitigati, fair value gap aperti,
   massimi/minimi equali (liquidita' da prendere), naked POC, VWAP, open settimanale, Fibonacci 0,5-0,705.
   Il prezzo tende a raggiungere la liquidita' prima di invertire: se sotto ci sono minimi equali evidenti,
   uno sweep prima del rialzo e' lo scenario piu' probabile e va detto.
4. MOMENTUM E CONFERME. RSI, MACD, ADX/DI, volumi, OBV servono per confermare o negare la tesi
   strutturale, mai per generarla da soli. RSI in ipercomprato in trend forte non e' un segnale short.
5. DERIVATI (crypto). Funding, open interest e long/short ratio dicono dove sta il rischio di squeeze:
   funding molto positivo con OI crescente su massimi = long affollati, il movimento contrario e' violento;
   funding negativo in area di supporto = benzina per uno squeeze rialzista.
6. CORRELAZIONE. Un altcoin in direzione opposta a BTC, con correlazione alta, e' un setup a bassa
   probabilita': dichiaralo e abbassa la confidenza.

## COME COSTRUIRE IL PIANO OPERATIVO
- Il piano deve essere eseguibile: prezzo di ingresso o zona, trigger oggettivo, stop, due target.
- Lo stop va oltre l'invalidazione strutturale, tipicamente 1-1,5 ATR oltre il livello che nega la tesi,
  mai a una distanza arbitraria e mai dentro il rumore (minimo 0,8 ATR dall'ingresso).
- I target vanno su ostacoli reali (resistenze/supporti con piu' tocchi, POC, estensioni di Fibonacci,
  massimi/minimi di periodo), non su multipli arbitrari del rischio.
- Il rapporto rischio/rendimento sul primo target deve essere >= 1,3; se la struttura non lo consente,
  la risposta corretta e' direzione "attesa": nessun trade e' una decisione professionale valida.
- Dichiara la probabilita' stimata di raggiungere il target 1 prima dello stop: deve essere coerente con
  il rapporto rischio/rendimento (se R/R e' 3, una probabilita' del 75% non e' credibile).

## CALIBRAZIONE DELLA CONFIDENZA (vincolante)
Ti viene fornito un PUNTEGGIO DI CONFLUENZA calcolato dal server (-100 ribassista, +100 rialzista) con il
dettaglio dei contributi, e un valore "confidenza_massima_consentita". Regole:
- La tua confidenza non puo' superare quel valore.
- Se il tuo bias e' opposto al segno del punteggio, la confidenza deve stare sotto 45 e devi spiegare in
  "fattori_chiave" perche' ti discosti dal punteggio.
- Se ti vengono forniti win rate storici, tienili in conto: se le analisi ad alta confidenza su questo
  strumento hanno storicamente fallito, abbassa la confidenza e stringi i target.
- La confidenza e' una probabilita', non entusiasmo: 50 significa "non lo so".

## PROIEZIONE DI PREZZO
Ti viene fornita una PROIEZIONE STATISTICA DI RIFERIMENTO costruita sulla distribuzione reale dei
rendimenti a N barre di questo strumento (quantili 10/25/50/75/90). Regole:
- Parti da quella distribuzione e deformala secondo lo scenario principale: non inventare ampiezze.
- Il percorso deve essere realistico: i mercati non salgono in linea retta. Se lo scenario prevede uno
  sweep, un ritracciamento su un order block o un test del supporto, il percorso deve mostrarlo.
- "lower"/"upper" sono la banda di confidenza ~80%: deve allargarsi con l'orizzonte, mai restringersi.
- Il primo punto deve essere entro ~0,5 ATR dal prezzo attuale.
- Il percorso deve essere coerente con il piano: se il piano e' long con ingresso su ritracciamento,
  il percorso deve scendere verso la zona di ingresso prima di salire.

## REGOLE DI OUTPUT
- Rispondi ESCLUSIVAMENTE con un oggetto JSON valido: nessun testo prima o dopo, nessun blocco markdown.
- Tutti i testi in italiano, professionali, densi, senza retorica.
- Tutti i prezzi come numeri sulla stessa scala dei dati forniti.
- Nessuna premessa, nessun disclaimer, nessun riferimento a limiti o cautele generiche: solo analisi
  operativa. Le avvertenze inutili degradano la qualita' del prodotto.

## SCHEMA JSON RICHIESTO
{
  "bias": "long" | "short" | "neutro",
  "confidenza": 0-100,
  "qualita_setup": "A" | "B" | "C",
  "regime_letto": "descrizione del regime e di cosa implica operativamente",
  "orizzonte_barre": intero,
  "sintesi": "3-5 frasi: cosa sta facendo il prezzo, perche', cosa serve per confermare",
  "struttura_mercato": "lettura strutturale (HH/HL, BOS/CHoCH, fase)",
  "fattori_chiave": ["fattore concreto con livello o valore", "..."],
  "fattori_contrari": ["cosa rende questa tesi fragile", "..."],
  "lettura_indicatori": {"trend": "...", "momentum": "...", "volatilita": "...", "volumi": "...", "derivati": "..."},
  "multi_timeframe": "come si compongono i timeframe e quale comanda",
  "livelli": {"supporti": [numero, ...], "resistenze": [numero, ...], "livello_invalidazione": numero},
  "zone_operative": [
    {"tipo": "order block rialzista|FVG ribassista|liquidita|value area|...", "da": numero, "a": numero,
     "ruolo": "cosa fare se il prezzo arriva qui"}
  ],
  "scenari": [
    {"nome": "principale", "probabilita": 0-100, "direzione": "rialzo|ribasso|laterale", "target": numero,
     "descrizione": "sequenza attesa, con i livelli"},
    {"nome": "alternativo", "probabilita": 0-100, "direzione": "...", "target": numero, "descrizione": "..."},
    {"nome": "invalidazione", "probabilita": 0-100, "direzione": "...", "target": numero, "descrizione": "..."}
  ],
  "proiezione": {"orizzonte_barre": intero,
    "percorso": [{"offset": 1, "price": numero, "lower": numero, "upper": numero}, ...]},
  "piano_operativo": {
    "direzione": "long|short|attesa",
    "zona_ingresso": [numero_min, numero_max],
    "ingresso": numero,
    "stop_loss": numero,
    "target": [numero, numero],
    "rischio_rendimento": numero,
    "probabilita_target1_pct": 0-100,
    "dimensione_posizione_pct_rischio": numero,
    "condizioni_ingresso": "trigger oggettivo e verificabile",
    "invalidazione": "cosa annulla il piano",
    "gestione": "come gestire la posizione: parziali, stop a pareggio, trailing"
  },
  "gestione_rischio": "note su volatilita' misurata, size e rischio specifico di questo strumento",
  "cosa_monitorare": ["livello o evento con soglia numerica", "..."],
  "invalidazione_tesi": "una frase: il singolo fatto che dimostra che questa analisi era sbagliata"
}
SYS;
}

function ts_user_prompt_v2(array $snap, int $horizon, array $bands, array $prev, array $feedback): string {
    $tf = (string)$snap['timeframe'];
    $out  = "RICHIESTA DI ANALISI\n";
    $out .= "Strumento: {$snap['symbol']} (fonte dati: {$snap['provider']})\n";
    $out .= "Timeframe operativo: " . ts_tf_label($tf) . "\n";
    $out .= "Orizzonte di proiezione: {$horizon} barre da " . ts_tf_label($tf) . "\n";
    $out .= "Prezzo corrente: {$snap['prezzo']}\n";
    $out .= "Momento della richiesta: {$snap['generato_il']}\n\n";

    $conf = $snap['confluenza'] ?? [];
    $out .= "PUNTEGGIO DI CONFLUENZA CALCOLATO DAL SERVER (vincolante per la calibrazione)\n";
    $out .= json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    $clean = $snap;
    unset($clean['serie_recente'], $clean['confluenza']);
    $out .= "SNAPSHOT TECNICO COMPLETO (calcolato dal server su dati reali di mercato)\n";
    $out .= json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    $out .= "ULTIME 60 CANDELE [data UTC | open | high | low | close | volume]\n";
    foreach ($snap['serie_recente'] as $r) { $out .= implode(' | ', $r) . "\n"; }
    $out .= "\n";

    $out .= "PROIEZIONE STATISTICA DI RIFERIMENTO\n";
    $out .= "Distribuzione reale dei rendimenti a h barre di questo strumento: price = mediana con momentum\n";
    $out .= "smorzato, lower/upper = quantili 10/90, q25/q75 = quantili intermedi.\n";
    $out .= json_encode(array_slice($bands, 0, $horizon), JSON_UNESCAPED_SLASHES) . "\n\n";

    if (!empty($feedback['strumento']['analisi_chiuse'])) {
        $out .= "STORICO VERIFICATO DELLE TUE ANALISI PRECEDENTI (esiti reali, usali per calibrarti)\n";
        $out .= json_encode($feedback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    if ($prev) {
        $out .= "ANALISI PRECEDENTE SU QUESTO STRUMENTO (continuita' di lettura: se cambi idea, spiega perche')\n";
        $out .= json_encode($prev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    $out .= "Produci ora l'oggetto JSON completo secondo lo schema, con esattamente {$horizon} punti di proiezione. Solo JSON.";
    return $out;
}

/* ------------------------------------------------------------- validazione */

function ts_normalize_v2(array $r, array $snap, int $horizon, array $bands): array {
    $price = (float)$snap['prezzo'];
    $conf  = $snap['confluenza'] ?? [];
    $atr   = (float)($snap['indicatori']['atr14'] ?? ($price * 0.01));
    if ($atr <= 0) $atr = $price * 0.01;

    /* bias */
    $bias = strtolower((string)($r['bias'] ?? 'neutro'));
    if (!in_array($bias, ['long', 'short', 'neutro'], true)) {
        $bias = in_array($bias, ['rialzista', 'buy', 'bullish'], true) ? 'long'
              : (in_array($bias, ['ribassista', 'sell', 'bearish'], true) ? 'short' : 'neutro');
    }
    $r['bias'] = $bias;

    /* confidenza: vincolata dal punteggio di confluenza */
    $c = (float)($r['confidenza'] ?? 50);
    $maxC = (float)($conf['confidenza_massima_consentita'] ?? 80);
    $score = (int)($conf['punteggio'] ?? 0);
    $note = [];
    if ($c > $maxC) { $note[] = 'confidenza ridotta da ' . round($c) . ' a ' . round($maxC) . ' dal limite di confluenza'; $c = $maxC; }
    $contrario = ($score >= 25 && $bias === 'short') || ($score <= -25 && $bias === 'long');
    if ($contrario && $c > 45) { $note[] = 'bias opposto al punteggio di confluenza: confidenza limitata a 45'; $c = 45; }
    $r['confidenza'] = (int)ts_clamp($c, 0, 95);
    $r['punteggio_confluenza'] = $score;
    $r['qualita_setup'] = (string)($conf['qualita_setup'] ?? ($r['qualita_setup'] ?? 'C'));
    $r['contributi_confluenza'] = $conf['contributi'] ?? [];
    $r['consenso_mtf_server'] = $snap['consenso_mtf'] ?? null;
    $r['regime_server'] = $snap['regime']['tipo'] ?? null;
    $r['orizzonte_barre'] = (int)($r['orizzonte_barre'] ?? $horizon);

    /* proiezione */
    $path = $r['proiezione']['percorso'] ?? [];
    $clean = [];
    if (is_array($path) && count($path) >= max(3, (int)($horizon * 0.5))) {
        $i = 0; $prevBand = 0.0;
        foreach ($path as $p) {
            if (!is_array($p) || !isset($p['price'])) continue;
            $i++;
            $mid = (float)$p['price'];
            if ($price > 0 && ($mid <= 0 || abs($mid - $price) / $price > 0.6)) { $clean = []; break; }
            $ref = $bands[min($i, count($bands)) - 1] ?? null;
            if ($i === 1 && abs($mid - $price) > $atr * 1.2) {
                $mid = $price + ($mid > $price ? 1 : -1) * $atr * 0.5;   // primo punto ancorato al prezzo
            }
            $lo = isset($p['lower']) ? (float)$p['lower'] : $mid - $atr * sqrt($i);
            $up = isset($p['upper']) ? (float)$p['upper'] : $mid + $atr * sqrt($i);
            if ($lo > $up) { [$lo, $up] = [$up, $lo]; }
            $lo = min($lo, $mid); $up = max($up, $mid);
            /* la banda non puo' restringersi ne' scendere sotto meta' della banda statistica */
            if ($ref) {
                $statBand = max(1e-9, ((float)$ref['upper'] - (float)$ref['lower']) / 2);
                $half = max(($up - $lo) / 2, $statBand * 0.55);
                $half = min($half, $statBand * 2.0);
                $lo = $mid - $half; $up = $mid + $half;
            }
            $band = ($up - $lo) / 2;
            if ($band < $prevBand) { $lo = $mid - $prevBand; $up = $mid + $prevBand; $band = $prevBand; }
            $prevBand = $band;
            $clean[] = [
                'offset' => (int)($p['offset'] ?? $i),
                'price'  => ts_round_price($mid),
                'lower'  => ts_round_price($lo),
                'upper'  => ts_round_price($up),
            ];
            if (count($clean) >= $horizon) break;
        }
    }
    if (!$clean) {
        $clean = array_map(static fn($b) => ['offset' => $b['offset'], 'price' => $b['price'], 'lower' => $b['lower'], 'upper' => $b['upper']], $bands);
        $r['proiezione_fallback'] = true;
        $note[] = 'proiezione del modello non valida: usata la proiezione statistica';
    }
    $r['proiezione'] = ['orizzonte_barre' => count($clean), 'percorso' => array_values($clean)];

    /* piano operativo */
    $pl = is_array($r['piano_operativo'] ?? null) ? $r['piano_operativo'] : [];
    foreach (['ingresso', 'stop_loss', 'probabilita_target1_pct', 'dimensione_posizione_pct_rischio'] as $k) {
        if (isset($pl[$k])) $pl[$k] = (float)$pl[$k];
    }
    if (isset($pl['target']) && is_array($pl['target'])) {
        $pl['target'] = array_values(array_map(static fn($t) => ts_round_price((float)$t), array_filter($pl['target'], static fn($t) => is_numeric($t))));
    }
    $dir = strtolower((string)($pl['direzione'] ?? $bias));
    if (!empty($pl['ingresso']) && !empty($pl['stop_loss']) && !empty($pl['target'][0])) {
        $long = $dir === 'long' || (float)$pl['target'][0] > (float)$pl['ingresso'];
        $risk = abs((float)$pl['ingresso'] - (float)$pl['stop_loss']);

        /* stop troppo stretto: allargato a 0,8 ATR oltre l'ingresso */
        if ($risk < $atr * 0.8) {
            $pl['stop_loss'] = ts_round_price($long ? (float)$pl['ingresso'] - $atr * 0.8 : (float)$pl['ingresso'] + $atr * 0.8);
            $risk = $atr * 0.8;
            $note[] = 'stop allargato a 0,8 ATR: era dentro il rumore di mercato';
        }
        /* coerenza direzionale di stop e target */
        if (($long && (float)$pl['stop_loss'] >= (float)$pl['ingresso']) || (!$long && (float)$pl['stop_loss'] <= (float)$pl['ingresso'])) {
            $pl['stop_loss'] = ts_round_price($long ? (float)$pl['ingresso'] - $atr : (float)$pl['ingresso'] + $atr);
            $note[] = 'stop incoerente con la direzione: ricalcolato a 1 ATR';
            $risk = $atr;
        }
        $rew = abs((float)$pl['target'][0] - (float)$pl['ingresso']);
        $pl['rischio_rendimento'] = $risk > 0 ? round($rew / $risk, 2) : null;

        /* valore atteso con la probabilita' dichiarata */
        $p1 = isset($pl['probabilita_target1_pct']) ? ts_clamp((float)$pl['probabilita_target1_pct'], 0, 100) / 100 : null;
        if ($p1 === null) {
            $p1 = ts_clamp(0.34 + abs($score) * 0.0032, 0.3, 0.66);
            $pl['probabilita_target1_pct'] = round($p1 * 100);
        }
        $rr = (float)($pl['rischio_rendimento'] ?? 0);
        $r['valore_atteso_R'] = round($p1 * $rr - (1 - $p1), 2);

        if ($rr < 1.2 && $dir !== 'attesa') {
            $pl['avviso'] = 'rapporto rischio/rendimento sotto 1,2: setup non conveniente, meglio attendere una struttura migliore';
        }
    }
    $pl['direzione'] = $dir;
    $r['piano_operativo'] = $pl;

    /* operativita' consigliata dal server */
    $qual = (string)$r['qualita_setup'];
    $r['operativita_consigliata'] = ($qual === 'A' && $r['confidenza'] >= 60 && ($r['valore_atteso_R'] ?? 0) > 0.15)
        ? 'eseguibile: setup con confluenza alta'
        : (($qual === 'B' && $r['confidenza'] >= 55)
            ? 'eseguibile con size ridotta: confluenza parziale'
            : 'attendere: confluenza insufficiente, nessun vantaggio statistico misurabile');
    if ($note) $r['note_validazione'] = $note;

    return $r;
}

/* --------------------------------------------------------------------- run */

/**
 * Analisi completa 2.0 con salvataggio.
 * @return array{ok:bool, id:int, result:array, error:string}
 */
function ts_run_analysis_v2(string $symbol, string $timeframe, string $provider, string $source = 'cron', ?string $model = null, ?string $aiProvider = null): array {
    ts_db_migrate_v2();
    $limit   = max(300, (int)ts_opt('engine', 'candles', 400));
    $snapRes = ts_build_snapshot_v2($symbol, $timeframe, $provider, $limit);
    if (!$snapRes['ok']) return ['ok' => false, 'id' => 0, 'result' => [], 'error' => $snapRes['error']];

    $snap    = $snapRes['snapshot'];
    $candles = $snapRes['candles'];
    $price   = (float)$snap['prezzo'];
    $horizon = (int)ts_opt('engine', 'horizon_bars', 24);
    $score   = (int)($snap['confluenza']['punteggio'] ?? 0);
    $bands   = ts_empirical_bands($candles, $horizon, (float)$score);

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
                'esito' => $last['outcome'],
            ];
        }
    }
    $feedback = ts_accuracy_feedback($symbol, $timeframe);

    $res = ts_ai_call(ts_system_prompt_v2(), ts_user_prompt_v2($snap, $horizon, $bands, $prev, $feedback), $model, $aiProvider, (int)ts_opt('claude', 'max_tokens', 6000));
    if (!$res['ok']) return ['ok' => false, 'id' => 0, 'result' => [], 'error' => $res['error']];

    $parsed = ts_extract_json($res['text']);
    if (!is_array($parsed)) {
        ts_log('analysis', 'risposta non interpretabile', ['symbol' => $symbol, 'preview' => mb_substr($res['text'], 0, 300)]);
        return ['ok' => false, 'id' => 0, 'result' => [], 'error' => 'risposta del modello non interpretabile'];
    }

    $result = ts_normalize_v2($parsed, $snap, $horizon, $bands);
    $result['_meta'] = [
        'symbol'    => strtoupper($symbol),
        'timeframe' => $timeframe,
        'provider'  => $snap['provider'],
        'prezzo'    => $price,
        'model'     => $res['model'],
        'ai'        => $res['provider'],
        'generato'  => ts_now(),
        'candle_t'  => (int)$snap['ultima_candela_t'],
        'tf_seconds'=> ts_tf_seconds($timeframe),
        'engine'    => '2.0',
        'proiezione_statistica' => $bands,
    ];

    $pl = $result['piano_operativo'] ?? [];
    $st = ts_db()->prepare('INSERT INTO ts_analyses
        (symbol, provider, timeframe, created_at, candle_time, price_at, bias, confidence, horizon_bars,
         entry, stop_loss, target1, target2, risk_reward, model, tokens_in, tokens_out, source, payload, snapshot,
         score, quality, regime, ev, engine)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        strtoupper($symbol), $snap['provider'], $timeframe, ts_now(),
        (int)$snap['ultima_candela_t'], $price,
        $result['bias'], $result['confidenza'], (int)($result['proiezione']['orizzonte_barre'] ?? $horizon),
        $pl['ingresso'] ?? null, $pl['stop_loss'] ?? null,
        $pl['target'][0] ?? null, $pl['target'][1] ?? null, $pl['rischio_rendimento'] ?? null,
        $res['model'], $res['usage']['in'] ?? 0, $res['usage']['out'] ?? 0, $source,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $score, $result['qualita_setup'] ?? null, $snap['regime']['tipo'] ?? null,
        $result['valore_atteso_R'] ?? null, '2.0',
    ]);

    return ['ok' => true, 'id' => (int)ts_db()->lastInsertId(), 'result' => $result, 'error' => ''];
}

/** Statistiche 2.0: affidabilita' per qualita' di setup e per regime */
function ts_performance_stats_v2(): array {
    ts_db_migrate_v2();
    $out = ts_performance_stats();
    try {
        $rows = ts_db()->query("SELECT quality,
                SUM(CASE WHEN outcome='target' THEN 1 ELSE 0 END) t,
                SUM(CASE WHEN outcome='stop' THEN 1 ELSE 0 END) s
            FROM ts_analyses WHERE outcome IN ('target','stop') AND quality IS NOT NULL GROUP BY quality")->fetchAll();
        $q = [];
        foreach ($rows as $r) {
            $t = (int)$r['t']; $s = (int)$r['s'];
            $q[(string)$r['quality']] = ['chiuse' => $t + $s, 'win_rate' => ($t + $s) ? round($t / ($t + $s) * 100, 1) : null];
        }
        $out['per_qualita'] = $q;
        $ev = ts_db()->query("SELECT AVG(ev) a FROM ts_analyses WHERE ev IS NOT NULL")->fetch();
        $out['ev_medio_R'] = isset($ev['a']) ? round((float)$ev['a'], 2) : null;
    } catch (Throwable $e) { /* colonne 2.0 non ancora presenti */ }
    return $out;
}
