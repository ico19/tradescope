<?php
/**
 * TradeScope - API JSON interna
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_require();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost && !ts_csrf_check($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    ts_json_out(['ok' => false, 'error' => 'token CSRF non valido'], 403);
}

try {
    switch ($action) {

        /* ---------------------------------------------------- candele + overlay */
        case 'candles': {
            $symbol   = (string)($_GET['symbol'] ?? '');
            $tf       = (string)($_GET['tf'] ?? '4h');
            $provider = (string)($_GET['provider'] ?? 'binance');
            $limit    = (int)($_GET['limit'] ?? 300);
            if ($symbol === '') ts_json_out(['ok' => false, 'error' => 'simbolo mancante'], 400);

            $fetch = ts_fetch_candles($symbol, $tf, max(60, min(1000, $limit)), $provider);
            if (!$fetch['ok']) ts_json_out(['ok' => false, 'error' => $fetch['error']], 502);

            $candles = $fetch['candles'];
            $close   = ts_col($candles, 'c');
            $ema21   = ts_ema($close, 21);
            $ema50   = ts_ema($close, 50);
            $ema200  = ts_ema($close, 200);

            $out = [];
            foreach ($candles as $i => $c) {
                $out[] = [
                    't' => $c['t'], 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'v' => $c['v'],
                    'e21' => $ema21[$i], 'e50' => $ema50[$i], 'e200' => $ema200[$i],
                ];
            }
            ts_json_out([
                'ok' => true, 'symbol' => strtoupper($symbol), 'timeframe' => $tf,
                'provider' => $fetch['provider'], 'tf_seconds' => ts_tf_seconds($tf),
                'candles' => $out,
                'ticker' => ts_ticker_24h($symbol, $fetch['provider']),
            ]);
        }

        /* ------------------------------------------------------ ultima analisi */
        case 'analysis': {
            $symbol = (string)($_GET['symbol'] ?? '');
            $tf     = (string)($_GET['tf'] ?? '4h');
            $row = ts_last_analysis($symbol, $tf);
            if (!$row) ts_json_out(['ok' => true, 'analysis' => null]);
            ts_json_out([
                'ok' => true,
                'analysis' => [
                    'id'         => (int)$row['id'],
                    'created_at' => $row['created_at'],
                    'price_at'   => (float)$row['price_at'],
                    'candle_time'=> (int)$row['candle_time'],
                    'model'      => $row['model'],
                    'source'     => $row['source'],
                    'outcome'    => $row['outcome'],
                    'payload'    => json_decode((string)$row['payload'], true),
                ],
            ]);
        }

        /* ------------------------------------------------------ esegui analisi */
        case 'analyze': {
            if (!$isPost) ts_json_out(['ok' => false, 'error' => 'metodo non consentito'], 405);
            $symbol   = (string)($_POST['symbol'] ?? '');
            $tf       = (string)($_POST['tf'] ?? '4h');
            $provider = (string)($_POST['provider'] ?? 'binance');
            $model    = (string)($_POST['model'] ?? '');
            $model    = ($model !== '' && array_key_exists($model, ts_ai_models())) ? $model : null;
            $aiProv   = (string)($_POST['ai_provider'] ?? '');
            $aiProv   = ($aiProv !== '' && array_key_exists($aiProv, ts_ai_providers())) ? $aiProv : null;
            if ($symbol === '') ts_json_out(['ok' => false, 'error' => 'simbolo mancante'], 400);

            @set_time_limit(180);
            $res = ts_run_analysis($symbol, $tf, $provider, 'manuale', $model, $aiProv);
            if (!$res['ok']) ts_json_out(['ok' => false, 'error' => $res['error']], 502);
            ts_json_out(['ok' => true, 'id' => $res['id'], 'analysis' => $res['result']]);
        }

        /* ---------------------------------------------------------- watchlist */
        case 'watchlist': {
            $items = ts_watchlist();
            $out = [];
            foreach ($items as $it) {
                $last = ts_last_analysis($it['symbol'], $it['timeframe']);
                $payload = $last ? json_decode((string)$last['payload'], true) : null;
                $out[] = [
                    'id'         => (int)$it['id'],
                    'symbol'     => $it['symbol'],
                    'label'      => $it['label'],
                    'provider'   => $it['provider'],
                    'timeframe'  => $it['timeframe'],
                    'asset_class'=> $it['asset_class'],
                    'tv_symbol'  => ts_tv_symbol($it),
                    'enabled'    => (int)$it['enabled'],
                    'last'       => $last ? [
                        'created_at' => $last['created_at'],
                        'bias'       => $last['bias'],
                        'confidence' => (float)$last['confidence'],
                        'price_at'   => (float)$last['price_at'],
                        'sintesi'    => mb_substr((string)($payload['sintesi'] ?? ''), 0, 200),
                    ] : null,
                ];
            }
            ts_json_out(['ok' => true, 'items' => $out]);
        }

        case 'watchlist_add': {
            if (!$isPost) ts_json_out(['ok' => false, 'error' => 'metodo non consentito'], 405);
            $symbol   = strtoupper(trim((string)($_POST['symbol'] ?? '')));
            $provider = (string)($_POST['provider'] ?? 'binance');
            $tf       = (string)($_POST['tf'] ?? '4h');
            $label    = trim((string)($_POST['label'] ?? ''));
            $tv       = trim((string)($_POST['tv_symbol'] ?? ''));
            $class    = (string)($_POST['asset_class'] ?? 'crypto');
            if ($symbol === '') ts_json_out(['ok' => false, 'error' => 'simbolo mancante'], 400);

            $check = ts_fetch_candles($symbol, $tf, 60, $provider);
            if (!$check['ok']) ts_json_out(['ok' => false, 'error' => 'simbolo non valido: ' . $check['error']], 400);

            $id = ts_watchlist_add($symbol, $check['provider'], $tf, $label, $class, $tv);
            ts_json_out(['ok' => true, 'id' => $id]);
        }

        case 'watchlist_remove': {
            if (!$isPost) ts_json_out(['ok' => false, 'error' => 'metodo non consentito'], 405);
            $st = ts_db()->prepare('DELETE FROM ts_watchlist WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            ts_json_out(['ok' => true]);
        }

        case 'watchlist_toggle': {
            if (!$isPost) ts_json_out(['ok' => false, 'error' => 'metodo non consentito'], 405);
            $st = ts_db()->prepare('UPDATE ts_watchlist SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            ts_json_out(['ok' => true]);
        }

        /* ------------------------------------------------------------ storico */
        case 'history': {
            $symbol = (string)($_GET['symbol'] ?? '');
            $tf     = (string)($_GET['tf'] ?? '');
            $sql = 'SELECT id, symbol, timeframe, created_at, price_at, bias, confidence, entry, stop_loss, target1, risk_reward, outcome, mfe, mae, model, source FROM ts_analyses WHERE 1=1';
            $args = [];
            if ($symbol !== '') { $sql .= ' AND symbol = ?'; $args[] = strtoupper($symbol); }
            if ($tf !== '')     { $sql .= ' AND timeframe = ?'; $args[] = $tf; }
            $sql .= ' ORDER BY id DESC LIMIT 200';
            $st = ts_db()->prepare($sql);
            $st->execute($args);
            ts_json_out(['ok' => true, 'items' => $st->fetchAll(), 'stats' => ts_performance_stats()]);
        }

        case 'stats':
            ts_json_out(['ok' => true, 'stats' => ts_performance_stats_v2()]);

        /* ------------------------------- snapshot tecnico (senza chiamare Claude) */
        case 'snapshot': {
            $symbol   = (string)($_GET['symbol'] ?? '');
            $tf       = (string)($_GET['tf'] ?? '4h');
            $provider = (string)($_GET['provider'] ?? 'binance');
            if ($symbol === '') ts_json_out(['ok' => false, 'error' => 'simbolo mancante'], 400);
            $res = ts_build_snapshot_v2($symbol, $tf, $provider, 400);
            if (!$res['ok']) ts_json_out(['ok' => false, 'error' => $res['error']], 502);
            $snap = $res['snapshot'];
            unset($snap['serie_recente']);
            $horizon = (int)ts_opt('engine', 'horizon_bars', 24);
            ts_json_out([
                'ok' => true,
                'snapshot' => $snap,
                'proiezione_statistica' => ts_empirical_bands($res['candles'], $horizon, (float)($snap['confluenza']['punteggio'] ?? 0)),
            ]);
        }

        /* --------------------------- script Pine da incollare in TradingView */
        case 'pine': {
            $symbol = (string)($_GET['symbol'] ?? '');
            $tf     = (string)($_GET['tf'] ?? '4h');
            $row = ts_last_analysis($symbol, $tf);
            if (!$row) ts_json_out(['ok' => false, 'error' => 'nessuna analisi disponibile per questo strumento'], 404);
            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) ts_json_out(['ok' => false, 'error' => 'analisi non leggibile'], 500);
            ts_json_out([
                'ok' => true,
                'symbol' => $row['symbol'],
                'timeframe' => $row['timeframe'],
                'created_at' => $row['created_at'],
                'pine' => ts_pine_script($payload, (string)$row['symbol'], (string)$row['timeframe']),
            ]);
        }

        /* ------------------------------------ test chiave del fornitore IA */
        case 'test_ai':
        case 'test_claude': {   // 'test_claude' mantenuto per compatibilita'
            if (!$isPost) ts_json_out(['ok' => false, 'error' => 'metodo non consentito'], 405);
            $prov = (string)($_POST['provider'] ?? '');
            $prov = ($prov !== '' && array_key_exists($prov, ts_ai_providers())) ? $prov : null;
            ts_json_out(ts_ai_test($prov));
        }

        default:
            ts_json_out(['ok' => false, 'error' => 'azione non riconosciuta'], 404);
    }
} catch (Throwable $e) {
    ts_log('api', 'eccezione', ['action' => $action, 'msg' => $e->getMessage()]);
    ts_json_out(['ok' => false, 'error' => 'errore interno: ' . $e->getMessage()], 500);
}
