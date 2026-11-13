<?php
/**
 * TradeScope 2.0 - dashboard avanzata (motore v2)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_require();
ts_security_headers();
ts_db_migrate_v2();

$title = (string)ts_opt('ui', 'title', 'TradeScope');
$stats = ts_performance_stats_v2();
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ts_e($title) ?> 2.0 - Analisi avanzata</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/pro.css?v=<?= TS_VERSION ?>">
</head>
<body>

<header class="topbar">
  <div class="brand"><span class="dot"></span><b><?= ts_e($title) ?></b><span class="ver">2.0</span></div>
  <strong id="curSymbol" class="mono">--</strong>
  <select id="tfSelect" title="Timeframe operativo">
    <?php foreach (ts_supported_timeframes() as $tf): ?>
      <option value="<?= ts_e($tf) ?>"<?= $tf === '4h' ? ' selected' : '' ?>><?= ts_e(ts_tf_label($tf)) ?></option>
    <?php endforeach; ?>
  </select>
  <select id="modelSelect" title="Modello di intelligenza artificiale per l'analisi manuale">
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
  <button class="btn ghost" id="btnRefresh" title="Ricarica dati e snapshot">&#8635;</button>
  <div class="grow"></div>
  <span class="small dim" id="lastUpdate"></span>
  <nav>
    <a href="pro.php" class="active">2.0</a>
    <a href="index.php">Classica</a>
    <a href="history.php">Storico</a>
    <a href="settings.php">Impostazioni</a>
    <a href="logout.php">Esci</a>
  </nav>
</header>

<div class="wrap">

  <aside>
    <div class="card">
      <h2>Watchlist</h2>
      <div class="wl" id="watchlist"><div class="body muted small">Caricamento...</div></div>
    </div>

    <div class="card">
      <h2>Regime e volatilita</h2>
      <div id="regime"><div class="body muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Derivati e contesto</h2>
      <div id="deriv"><div class="body muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Affidabilita verificata</h2>
      <div class="body small">
        <div class="lvl"><span class="muted">analisi totali</span><span class="mono"><?= (int)$stats['totale'] ?></span></div>
        <div class="lvl"><span class="muted">target raggiunti</span><span class="mono up"><?= (int)$stats['target'] ?></span></div>
        <div class="lvl"><span class="muted">stop colpiti</span><span class="mono down"><?= (int)$stats['stop'] ?></span></div>
        <div class="lvl"><span class="muted">win rate</span><span class="mono"><?= $stats['win_rate'] !== null ? ts_e((string)$stats['win_rate']) . '%' : '--' ?></span></div>
        <div class="lvl"><span class="muted">valore atteso medio</span><span class="mono"><?= isset($stats['ev_medio_R']) && $stats['ev_medio_R'] !== null ? ts_e((string)$stats['ev_medio_R']) . ' R' : '--' ?></span></div>
        <?php foreach (($stats['per_qualita'] ?? []) as $q => $d): ?>
          <div class="lvl"><span class="muted">setup <?= ts_e((string)$q) ?> (<?= (int)$d['chiuse'] ?>)</span><span class="mono"><?= $d['win_rate'] !== null ? ts_e((string)$d['win_rate']) . '%' : '--' ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  <main>
    <div class="kpis">
      <div class="kpi"><div class="k">Prezzo</div><div class="v mono" id="kPrice">--</div></div>
      <div class="kpi"><div class="k">24 ore</div><div class="v mono" id="kChange">--</div></div>
      <div class="kpi">
        <div class="k">Confluenza</div><div class="v mono" id="kScore">--</div>
        <div class="gauge center"><i id="gaugeScore" style="width:0"></i></div>
        <div class="sub small" id="scoreNote"></div>
      </div>
      <div class="kpi"><div class="k">Bias IA</div><div class="v" id="kBias">--</div></div>
      <div class="kpi"><div class="k">Confidenza</div><div class="v mono" id="kConf">--</div></div>
      <div class="kpi"><div class="k">Qualita setup</div><div class="v" id="kQual">--</div></div>
      <div class="kpi"><div class="k">Rischio / rendimento</div><div class="v mono" id="kRR">--</div></div>
      <div class="kpi"><div class="k">Valore atteso</div><div class="v mono" id="kEV">--</div></div>
    </div>

    <div class="card">
      <h2>Consenso multi-timeframe</h2>
      <div class="body">
        <div class="mtf" id="mtf"></div>
        <div class="small muted" id="mtfSummary" style="margin-top:10px"></div>
        <div class="small" id="mtfText" style="margin-top:4px"></div>
      </div>
    </div>

    <div class="card">
      <div class="tabs">
        <div class="tab active" data-pane="paneProj">Proiezione IA</div>
        <div class="tab" data-pane="paneTV">TradingView live</div>
        <div class="grow"></div>
        <div class="tools">
          <button class="btn tiny ghost" id="btnPine">Esporta livelli in TradingView (Pine)</button>
        </div>
      </div>
      <div class="pane active" id="paneProj">
        <div id="chart"></div>
        <div class="legend">
          <span><i style="background:#e0b15c"></i>EMA 21</span>
          <span><i style="background:#6a9bd8"></i>EMA 50</span>
          <span><i style="background:#b06ef2"></i>EMA 200</span>
          <span><i style="background:#9184d9"></i>percorso proiettato</span>
          <span><i style="background:rgba(145,132,217,.55)"></i>banda di confidenza 80%</span>
          <span><i style="background:#4ec9a5"></i>target</span>
          <span><i style="background:#e5644e"></i>stop</span>
        </div>
      </div>
      <div class="pane" id="paneTV"><div id="tvchart"></div></div>
    </div>

    <div class="card" id="pineCard" style="display:none">
      <h2>Script Pine per TradingView</h2>
      <div class="body small muted">TradingView &rarr; Pine Editor &rarr; incolla &rarr; Aggiungi al grafico. Disegna percorso, banda, ingresso, stop, target, livelli e zone sul grafico reale, dove restano modificabili con gli strumenti di TradingView.</div>
      <pre class="pine" id="pineBox"></pre>
    </div>

    <div class="card">
      <h2>Piano operativo</h2>
      <div class="body" id="plan"><div class="muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Analisi</h2>
      <div id="analysis"><div class="body muted">Caricamento...</div></div>
    </div>

    <div class="card">
      <h2>Scenari</h2>
      <div id="scenari"><div class="body muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Zone operative e livelli</h2>
      <div id="zone"><div class="body muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Struttura istituzionale calcolata dal server</h2>
      <div id="smc"><div class="body muted small">--</div></div>
    </div>

    <div class="card">
      <h2>Da cosa nasce il punteggio di confluenza</h2>
      <div id="contrib"><div class="body muted small">--</div></div>
    </div>
  </main>
</div>

<div class="toast" id="toast"></div>

<script>window.TS_CSRF = <?= json_encode(ts_csrf_token()) ?>;</script>
<script src="assets/vendor/lightweight-charts.standalone.production.js"></script>
<script src="https://s3.tradingview.com/tv.js"></script>
<script src="assets/pro.js?v=<?= TS_VERSION ?>"></script>
</body>
</html>
