<?php
/**
 * TradeScope 2.0 - dati derivati e contesto di mercato crypto
 *
 * Funding rate, open interest, rapporto long/short, base perpetual-spot,
 * indice Fear & Greed, dominance BTC. Tutte le chiamate sono in cache e
 * tolleranti ai guasti: se un endpoint non risponde, il campo diventa null
 * e l'analisi procede senza quel dato.
 */
declare(strict_types=1);

function ts_is_crypto(string $provider): bool {
    return in_array($provider, ['binance', 'bybit'], true);
}

/** Simbolo perpetual corrispondente (USDT-M) */
function ts_perp_symbol(string $symbol): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol));
}

/**
 * Dati derivati sul perpetual del simbolo.
 * @return array|null
 */
function ts_derivatives(string $symbol, string $provider = 'binance'): ?array {
    if (!ts_is_crypto($provider)) return null;
    $sym = ts_perp_symbol($symbol);
    $ttl = 120;
    $out = ['fonte' => 'Binance Futures', 'simbolo' => $sym];
    $ok = false;

    /* funding + mark/index price */
    $r = ts_http_cached('https://fapi.binance.com/fapi/v1/premiumIndex?symbol=' . rawurlencode($sym), $ttl);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d['lastFundingRate'])) {
            $rate = (float)$d['lastFundingRate'];
            $mark = (float)($d['markPrice'] ?? 0); $index = (float)($d['indexPrice'] ?? 0);
            $out['funding_pct'] = round($rate * 100, 4);
            $out['funding_annuo_pct'] = round($rate * 3 * 365 * 100, 1);
            $out['prossimo_funding'] = isset($d['nextFundingTime']) ? gmdate('Y-m-d H:i', (int)((int)$d['nextFundingTime'] / 1000)) . ' UTC' : null;
            $out['base_perp_spot_pct'] = ($index > 0) ? round(($mark - $index) / $index * 100, 3) : null;
            $ok = true;
        }
    }

    /* open interest corrente e storico */
    $r = ts_http_cached('https://fapi.binance.com/fapi/v1/openInterest?symbol=' . rawurlencode($sym), $ttl);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d['openInterest'])) { $out['open_interest'] = round((float)$d['openInterest'], 2); $ok = true; }
    }
    $r = ts_http_cached('https://fapi.binance.com/futures/data/openInterestHist?symbol=' . rawurlencode($sym) . '&period=1h&limit=25', 300);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (is_array($d) && count($d) >= 5) {
            $first = (float)($d[0]['sumOpenInterest'] ?? 0);
            $last  = (float)($d[count($d) - 1]['sumOpenInterest'] ?? 0);
            if ($first > 0) { $out['oi_var_pct_24h'] = round(($last - $first) / $first * 100, 2); $ok = true; }
            $out['oi_valore_quote'] = round((float)($d[count($d) - 1]['sumOpenInterestValue'] ?? 0));
        }
    }

    /* posizionamento */
    $r = ts_http_cached('https://fapi.binance.com/futures/data/globalLongShortAccountRatio?symbol=' . rawurlencode($sym) . '&period=1h&limit=1', 300);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d[0]['longShortRatio'])) { $out['long_short_ratio_conti'] = round((float)$d[0]['longShortRatio'], 3); $ok = true; }
    }
    $r = ts_http_cached('https://fapi.binance.com/futures/data/topLongShortPositionRatio?symbol=' . rawurlencode($sym) . '&period=1h&limit=1', 300);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d[0]['longShortRatio'])) $out['long_short_ratio_big_trader'] = round((float)$d[0]['longShortRatio'], 3);
    }
    $r = ts_http_cached('https://fapi.binance.com/futures/data/takerlongshortRatio?symbol=' . rawurlencode($sym) . '&period=1h&limit=1', 300);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d[0]['buySellRatio'])) $out['taker_buy_sell_ratio'] = round((float)$d[0]['buySellRatio'], 3);
    }

    /* fallback Bybit se Binance e' bloccato dall'IP del server */
    if (!$ok) {
        $r = ts_http_cached('https://api.bybit.com/v5/market/tickers?category=linear&symbol=' . rawurlencode($sym), $ttl);
        if ($r['ok']) {
            $d = json_decode($r['body'], true);
            $t = $d['result']['list'][0] ?? null;
            if ($t) {
                $out = [
                    'fonte' => 'Bybit Linear',
                    'simbolo' => $sym,
                    'funding_pct' => isset($t['fundingRate']) ? round((float)$t['fundingRate'] * 100, 4) : null,
                    'funding_annuo_pct' => isset($t['fundingRate']) ? round((float)$t['fundingRate'] * 3 * 365 * 100, 1) : null,
                    'open_interest' => isset($t['openInterest']) ? round((float)$t['openInterest'], 2) : null,
                    'oi_valore_quote' => isset($t['openInterestValue']) ? round((float)$t['openInterestValue']) : null,
                ];
                $ok = true;
            }
        }
    }
    if (!$ok) return null;

    /* lettura operativa dei dati */
    $letture = [];
    $f = $out['funding_pct'] ?? null;
    if ($f !== null) {
        if ($f > 0.05)       $letture[] = 'funding molto positivo: long affollati e costosi, il mercato e' . "'" . ' vulnerabile a long squeeze';
        elseif ($f > 0.015)  $letture[] = 'funding positivo: prevalenza di long, normale in trend rialzista';
        elseif ($f < -0.03)  $letture[] = 'funding negativo: short affollati, possibile short squeeze rialzista';
        else                 $letture[] = 'funding neutro: posizionamento equilibrato';
    }
    $oi = $out['oi_var_pct_24h'] ?? null;
    if ($oi !== null) {
        if ($oi > 5)       $letture[] = 'open interest in forte aumento (+' . $oi . '%): nuovo denaro entra, il movimento in corso ha carburante';
        elseif ($oi < -5)  $letture[] = 'open interest in forte calo (' . $oi . '%): chiusura di posizioni, movimento guidato da liquidazioni';
    }
    $ls = $out['long_short_ratio_conti'] ?? null;
    if ($ls !== null) {
        if ($ls > 2.2)      $letture[] = 'rapporto long/short retail ' . $ls . ': sentiment euforico, spesso contrarian';
        elseif ($ls < 0.8)  $letture[] = 'rapporto long/short retail ' . $ls . ': pessimismo diffuso, spesso contrarian';
    }
    $out['letture'] = $letture;
    return $out;
}

/**
 * Contesto generale del mercato crypto (indipendente dal simbolo).
 * Fear & Greed, dominance BTC, andamento BTC/ETH.
 */
function ts_market_context(): ?array {
    $out = [];

    $r = ts_http_cached('https://api.alternative.me/fng/?limit=2&format=json', 1800);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        if (isset($d['data'][0]['value'])) {
            $out['fear_greed'] = (int)$d['data'][0]['value'];
            $out['fear_greed_classe'] = (string)($d['data'][0]['value_classification'] ?? '');
            if (isset($d['data'][1]['value'])) $out['fear_greed_ieri'] = (int)$d['data'][1]['value'];
        }
    }

    $r = ts_http_cached('https://api.coingecko.com/api/v3/global', 1800);
    if ($r['ok']) {
        $d = json_decode($r['body'], true);
        $g = $d['data'] ?? null;
        if ($g) {
            $out['dominance_btc_pct'] = isset($g['market_cap_percentage']['btc']) ? round((float)$g['market_cap_percentage']['btc'], 2) : null;
            $out['dominance_eth_pct'] = isset($g['market_cap_percentage']['eth']) ? round((float)$g['market_cap_percentage']['eth'], 2) : null;
            $out['var_capitalizzazione_24h_pct'] = isset($g['market_cap_change_percentage_24h_usd']) ? round((float)$g['market_cap_change_percentage_24h_usd'], 2) : null;
        }
    }

    foreach (['BTCUSDT' => 'btc_24h_pct', 'ETHUSDT' => 'eth_24h_pct'] as $sym => $key) {
        $t = ts_ticker_24h($sym, 'binance');
        if ($t) $out[$key] = round($t['change_pct'], 2);
    }

    return $out ?: null;
}

/**
 * Correlazione dei rendimenti con BTC (utile per gli altcoin: un alt in
 * controtendenza rispetto a BTC ha probabilita' di riuscita molto piu' bassa).
 */
function ts_btc_correlation(array $candles, string $tf, string $provider): ?array {
    if (!ts_is_crypto($provider)) return null;
    $btc = ts_fetch_candles('BTCUSDT', $tf, min(300, count($candles)), $provider);
    if (!$btc['ok']) return null;
    $a = ts_returns(ts_col($candles, 'c'));
    $b = ts_returns(ts_col($btc['candles'], 'c'));
    $r = ts_pearson($a, $b);
    if ($r === null) return null;
    $btcClose = ts_col($btc['candles'], 'c');
    return [
        'correlazione_60_barre' => $r,
        'btc_var_20_barre_pct' => ts_change_pct($btcClose, 20),
        'nota' => $r >= 0.7
            ? 'alta correlazione con BTC: il setup regge solo se BTC non va in direzione opposta'
            : ($r <= 0.3 ? 'bassa correlazione con BTC: movimento idiosincratico' : 'correlazione media con BTC'),
    ];
}
