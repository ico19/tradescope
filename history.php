<?php
/**
 * TradeScope - storico analisi e verifica di affidabilita'
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_require();
ts_security_headers();

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? '')));
$detail = (int)($_GET['id'] ?? 0);
$title  = (string)ts_opt('ui', 'title', 'TradeScope');
$stats  = ts_performance_stats();

$sql = 'SELECT * FROM ts_analyses WHERE 1=1';
$args = [];
if ($symbol !== '') { $sql .= ' AND symbol = ?'; $args[] = $symbol; }
$sql .= ' ORDER BY id DESC LIMIT 150';
$st = ts_db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$symbols = ts_db()->query('SELECT DISTINCT symbol FROM ts_analyses ORDER BY symbol')->fetchAll();

$detailRow = null;
if ($detail > 0) {
    $s = ts_db()->prepare('SELECT * FROM ts_analyses WHERE id = ?');
    $s->execute([$detail]);
    $detailRow = $s->fetch() ?: null;
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ts_e($title) ?> - Storico</title>
<link rel="stylesheet" href="assets/app.css?v=<?= TS_VERSION ?>">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="brand-dot"></span><?= ts_e($title) ?></div>
  <div class="ctrl">
    <form method="get">
      <select name="symbol" onchange="this.form.submit()">
        <option value="">Tutti gli strumenti</option>
        <?php foreach ($symbols as $s): ?>
          <option value="<?= ts_e($s['symbol']) ?>"<?= $s['symbol'] === $symbol ? ' selected' : '' ?>><?= ts_e($s['symbol']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="spacer"></div>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="history.php" class="active">Storico</a>
    <a href="settings.php">Impostazioni</a>
    <a href="logout.php">Esci</a>
  </nav>
</header>

<div class="layout" style="grid-template-columns:1fr">
  <main>
    <div class="kpis">
      <div class="kpi"><div class="k">Analisi totali</div><div class="v mono"><?= (int)$stats['totale'] ?></div></div>
      <div class="kpi"><div class="k">Target raggiunti</div><div class="v mono up"><?= (int)$stats['target'] ?></div></div>
      <div class="kpi"><div class="k">Stop colpiti</div><div class="v mono down"><?= (int)$stats['stop'] ?></div></div>
      <div class="kpi"><div class="k">Win rate</div><div class="v mono"><?= $stats['win_rate'] !== null ? ts_e((string)$stats['win_rate']) . '%' : '--' ?></div></div>
      <div class="kpi"><div class="k">In corso</div><div class="v mono"><?= (int)$stats['in_corso'] ?></div></div>
    </div>

    <?php if ($detailRow):
      $p = json_decode((string)$detailRow['payload'], true) ?: []; ?>
      <div class="card" style="margin-bottom:16px">
        <h2>Dettaglio analisi #<?= (int)$detailRow['id'] ?> - <?= ts_e($detailRow['symbol']) ?> <?= ts_e($detailRow['timeframe']) ?></h2>
        <div class="body">
          <p class="small muted"><?= ts_e($detailRow['created_at']) ?> UTC - modello <?= ts_e((string)$detailRow['model']) ?> - prezzo al momento <?= ts_e(ts_fmt_price((float)$detailRow['price_at'])) ?></p>
          <p><?= ts_e((string)($p['sintesi'] ?? '')) ?></p>
          <pre class="code"><?= ts_e(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
          <a class="btn ghost small" href="history.php<?= $symbol ? '?symbol=' . urlencode($symbol) : '' ?>">Chiudi dettaglio</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Storico analisi</h2>
      <div class="body" style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th>Data (UTC)</th><th>Strumento</th><th>TF</th><th>Bias</th><th>Conf.</th>
            <th>Prezzo</th><th>Ingresso</th><th>Stop</th><th>Target</th><th>R/R</th>
            <th>Esito</th><th>MFE / MAE</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
            $outcome = $r['outcome'];
            $oc = $outcome === 'target' ? 'up' : ($outcome === 'stop' ? 'down' : 'flat'); ?>
            <tr>
              <td class="small mono"><?= ts_e(substr((string)$r['created_at'], 0, 16)) ?></td>
              <td><b><?= ts_e($r['symbol']) ?></b></td>
              <td class="small"><?= ts_e($r['timeframe']) ?></td>
              <td><span class="badge <?= ts_e((string)$r['bias']) ?>"><?= ts_e((string)$r['bias']) ?></span></td>
              <td class="mono"><?= (int)$r['confidence'] ?>%</td>
              <td class="mono"><?= ts_e(ts_fmt_price((float)$r['price_at'])) ?></td>
              <td class="mono"><?= $r['entry'] !== null ? ts_e(ts_fmt_price((float)$r['entry'])) : '--' ?></td>
              <td class="mono"><?= $r['stop_loss'] !== null ? ts_e(ts_fmt_price((float)$r['stop_loss'])) : '--' ?></td>
              <td class="mono"><?= $r['target1'] !== null ? ts_e(ts_fmt_price((float)$r['target1'])) : '--' ?></td>
              <td class="mono"><?= $r['risk_reward'] !== null ? ts_e(number_format((float)$r['risk_reward'], 2)) : '--' ?></td>
              <td class="<?= $oc ?>"><?= ts_e($outcome ?: 'in corso') ?></td>
              <td class="small mono"><?= $r['mfe'] !== null ? ts_e(number_format((float)$r['mfe'], 2)) . '% / ' . ts_e(number_format((float)$r['mae'], 2)) . '%' : '--' ?></td>
              <td><a class="small" href="history.php?id=<?= (int)$r['id'] ?><?= $symbol ? '&symbol=' . urlencode($symbol) : '' ?>">dettaglio</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="13" class="muted">Nessuna analisi registrata.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
</body>
</html>
