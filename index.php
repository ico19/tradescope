<?php
/**
 * TradeScope - dashboard principale
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_require();
ts_security_headers();

$title = (string)ts_opt('ui', 'title', 'TradeScope');
$stats = ts_performance_stats();
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ts_e($title) ?> - Dashboard</title>
<link rel="stylesheet" href="assets/app.css?v=<?= TS_VERSION ?>">
</head>
<body>

<header class="topbar">
  <div class="brand"><span class="brand-dot"></span><?= ts_e($title) ?></div>
  <div class="ctrl">
    <strong id="curSymbol" class="mono">--</strong>
    <select id="tfSelect" title="Timeframe operativo">
      <?php foreach (ts_supported_timeframes() as $tf): ?>
        <option value="<?= ts_e($tf) ?>"<?= $tf === '4h' ? ' selected' : '' ?>><?= ts_e(ts_tf_label($tf)) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="modelSelect" title="Modello di intelligenza artificiale usato per l'analisi manuale">
      <?php
      $activeProvider = ts_ai_active_provider();
      $curModel = ts_ai_default_model($activeProvider);
      foreach (ts_ai_catalog() as $prov => $models):
          $hasKey = ts_ai_api_key($prov) !== ''; ?>
        <optgroup label="<?= ts_e(ts_ai_provider_label($prov)) ?><?= $hasKey ? '' : ' (chiave mancante)' ?>">
          <?php foreach ($models as $id => $desc): ?>
            <option value="<?= ts_e($id) ?>"<?= $id === $curModel ? ' selected' : '' ?><?= $hasKey ? '' : ' disabled' ?>><?= ts_e($id) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <button class="btn" id="btnAnalyze">Analizza ora</button>
    <button class="btn ghost small" id="btnRefresh" title="Ricarica dati">&#8635;</button>
  </div>
  <div class="spacer"></div>
  <span class="small muted" id="lastUpdate"></span>
  <nav>
    <a href="pro.php">Dashboard 2.0</a>
    <a href="index.php" class="active">Dashboard</a>
    <a href="history.php">Storico</a>
    <a href="settings.php">Impostazioni</a>
    <a href="logout.php">Esci</a>
  </nav>
</header>

<div class="layout">

  <aside>
    <div class="card">
      <h2>Watchlist</h2>
      <div id="watchlist"><div class="body muted small">Caricamento...</div></div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Aggiungi strumento</h2>
      <div class="body">
        <form id="addForm">
          <div class="field">
            <label>Simbolo</label>
            <input type="text" name="symbol" placeholder="BTCUSDT / AAPL / EURUSD=X" required>
          </div>
          <div class="field">
            <label>Fonte dati</label>
            <select name="provider">
              <?php foreach (ts_providers() as $k => $v): ?>
                <option value="<?= ts_e($k) ?>"><?= ts_e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Timeframe</label>
            <select name="tf">
              <?php foreach (ts_supported_timeframes() as $tf): ?>
                <option value="<?= ts_e($tf) ?>"<?= $tf === '4h' ? ' selected' : '' ?>><?= ts_e(ts_tf_label($tf)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Etichetta (facoltativa)</label>
            <input type="text" name="label" placeholder="Bitcoin">
          </div>
          <div class="field">
            <label>Simbolo TradingView (facoltativo)</label>
            <input type="text" name="tv_symbol" placeholder="BINANCE:BTCUSDT">
          </div>
          <button class="btn" type="submit" style="width:100%">Aggiungi</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Affidabilita storica</h2>
      <div class="body small">
        <div class="lvl"><span class="muted">Analisi totali</span><span class="mono"><?= (int)$stats['totale'] ?></span></div>
        <div class="lvl"><span class="muted">Target raggiunti</span><span class="up mono"><?= (int)$stats['target'] ?></span></div>
        <div class="lvl"><span class="muted">Stop colpiti</span><span class="down mono"><?= (int)$stats['stop'] ?></span></div>
        <div class="lvl"><span class="muted">Win rate</span><span class="mono"><?= $stats['win_rate'] !== null ? ts_e((string)$stats['win_rate']) . '%' : '--' ?></span></div>
        <div class="lvl"><span class="muted">In corso</span><span class="mono"><?= (int)$stats['in_corso'] ?></span></div>
      </div>
    </div>
  </aside>

  <main>
    <div class="kpis">
      <div class="kpi"><div class="k">Prezzo</div><div class="v mono" id="kPrice">--</div></div>
      <div class="kpi"><div class="k">Variazione 24h</div><div class="v mono" id="kChange">--</div></div>
      <div class="kpi"><div class="k">Bias IA</div><div class="v" id="kBias">--</div></div>
      <div class="kpi"><div class="k">Confidenza</div><div class="v" id="kConf">--</div></div>
      <div class="kpi"><div class="k">Rischio / Rendimento</div><div class="v mono" id="kRR">--</div></div>
    </div>

    <div class="card">
      <div class="tabs">
        <div class="tab active" data-pane="paneProj">Proiezione IA</div>
        <div class="tab" data-pane="paneTV">Grafico live TradingView</div>
      </div>
      <div class="pane active" id="paneProj">
        <div id="chart"></div>
        <div class="legend">
          <span><i style="background:#f0b90b"></i>EMA 21</span>
          <span><i style="background:#4f8cff"></i>EMA 50</span>
          <span><i style="background:#b06ef2"></i>EMA 200</span>
          <span><i style="background:#00e0ff"></i>Percorso proiettato</span>
          <span><i style="background:rgba(0,224,255,.55)"></i>Banda di confidenza (~80%)</span>
          <span><i style="background:#16c784"></i>Target</span>
          <span><i style="background:#ea3943"></i>Stop</span>
        </div>
      </div>
      <div class="pane" id="paneTV">
        <div id="tvchart"></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Analisi IA</h2>
      <div id="analysisBox"><div class="body muted">Caricamento...</div></div>
    </div>
  </main>
</div>

<div class="toast" id="toast"></div>

<script>window.TS_CSRF = <?= json_encode(ts_csrf_token()) ?>;</script>
<script src="assets/vendor/lightweight-charts.standalone.production.js"></script>
<script src="https://s3.tradingview.com/tv.js"></script>
<script src="assets/chart.js?v=<?= TS_VERSION ?>"></script>
<script src="assets/app.js?v=<?= TS_VERSION ?>"></script>
</body>
</html>
