<?php
/**
 * TradeScope - esecuzione periodica delle analisi.
 *
 * Da riga di comando:  /usr/bin/php /percorso/cron.php
 * Da URL:              https://dominio/tradescope/cron.php?token=IL_TUO_TOKEN
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot(false);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string)ts_opt('engine', 'cron_token', '');
    $given = (string)($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo "Token non valido.\n";
        exit;
    }
}

@set_time_limit(0);
@ini_set('memory_limit', '256M');

$lock = TS_STORAGE . '/cron.lock';
if (is_file($lock) && (time() - (int)filemtime($lock)) < 900) {
    echo "Esecuzione gia' in corso, uscita.\n";
    exit;
}
@file_put_contents($lock, (string)time());

$startedAt = ts_now();
$db = ts_db();
$db->prepare('INSERT INTO ts_runs (started_at) VALUES (?)')->execute([$startedAt]);
$runId = (int)$db->lastInsertId();

$maxPerRun = (int)ts_opt('engine', 'max_per_run', 3);
$minMin    = (int)ts_opt('engine', 'min_minutes_between', 30);
$items     = ts_watchlist(true);
$detail    = [];
$analyzed  = 0; $errors = 0;

// Ordina per "analisi piu' vecchia prima"
$queue = [];
foreach ($items as $it) {
    $last = ts_last_analysis($it['symbol'], $it['timeframe']);
    $lastTs = $last ? strtotime((string)$last['created_at'] . ' UTC') : 0;
    if ($last && (time() - $lastTs) < $minMin * 60) continue;
    $queue[] = ['item' => $it, 'last' => $lastTs];
}
usort($queue, static fn($a, $b) => $a['last'] <=> $b['last']);
$queue = array_slice($queue, 0, max(1, $maxPerRun));

foreach ($queue as $q) {
    $it = $q['item'];
    $label = $it['symbol'] . ' ' . $it['timeframe'];
    try {
        $res = ts_run_analysis($it['symbol'], $it['timeframe'], $it['provider'], 'cron');
        if ($res['ok']) {
            $analyzed++;
            $detail[] = "OK   $label -> {$res['result']['bias']} ({$res['result']['confidenza']}%)";
        } else {
            $errors++;
            $detail[] = "ERR  $label -> {$res['error']}";
            ts_log('cron', 'analisi fallita', ['symbol' => $it['symbol'], 'error' => $res['error']]);
        }
    } catch (Throwable $e) {
        $errors++;
        $detail[] = "ERR  $label -> eccezione: " . $e->getMessage();
        ts_log('cron', 'eccezione', ['symbol' => $it['symbol'], 'msg' => $e->getMessage()]);
    }
}

// Manutenzione
try {
    $evaluated = ts_evaluate_open_analyses(25);
    if ($evaluated) $detail[] = "Esiti aggiornati: $evaluated";
    $purged = ts_purge_old();
    if ($purged) $detail[] = "Analisi eliminate per retention: $purged";
    ts_cache_gc(3600);
} catch (Throwable $e) {
    ts_log('cron', 'manutenzione fallita', ['msg' => $e->getMessage()]);
}

$db->prepare('UPDATE ts_runs SET finished_at = ?, analyzed = ?, errors = ?, detail = ? WHERE id = ?')
   ->execute([ts_now(), $analyzed, $errors, implode("\n", $detail), $runId]);

@unlink($lock);

echo "TradeScope cron - $startedAt UTC\n";
echo "Analizzati: $analyzed | Errori: $errors\n";
echo implode("\n", $detail) . "\n";
