/* TradeScope - logica della dashboard */
(function () {
  'use strict';

  var state = {
    symbol: null, tf: '4h', provider: 'binance', tvSymbol: null,
    analysis: null, candles: [], tfSeconds: 14400, autoTimer: null
  };
  var CSRF = window.TS_CSRF || '';

  /* ------------------------------------------------------------- helpers */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

  function toast(msg, type) {
    var t = $('#toast');
    t.className = 'toast ' + (type || '');
    t.innerHTML = msg;
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.style.display = 'none'; }, type === 'err' ? 8000 : 4000);
  }

  function api(action, params, method) {
    var url = 'api.php?action=' + encodeURIComponent(action);
    var opt = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (method === 'POST') {
      var fd = new FormData();
      fd.append('csrf', CSRF);
      Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
      opt.method = 'POST'; opt.body = fd;
      opt.headers['X-CSRF-Token'] = CSRF;
    } else {
      Object.keys(params || {}).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(params[k]); });
    }
    return fetch(url, opt).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'errore sconosciuto');
      return j;
    });
  }

  function fmt(n, dec) {
    if (n === null || n === undefined || isNaN(Number(n))) return '--';
    n = Number(n);
    var d = dec !== undefined ? dec : (Math.abs(n) >= 1000 ? 2 : Math.abs(n) >= 1 ? 4 : 6);
    return n.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: d });
  }
  function pct(n) { return (n === null || n === undefined || isNaN(Number(n))) ? '--' : (Number(n) > 0 ? '+' : '') + Number(n).toFixed(2) + '%'; }
  function cls(n) { return Number(n) > 0 ? 'up' : Number(n) < 0 ? 'down' : 'flat'; }
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[<>&"]/g, function (c) { return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c]; }); }
  function ago(iso) {
    if (!iso) return '--';
    var d = new Date(iso.replace(' ', 'T') + 'Z'), s = (Date.now() - d.getTime()) / 1000;
    if (s < 60) return 'adesso';
    if (s < 3600) return Math.round(s / 60) + ' min fa';
    if (s < 86400) return Math.round(s / 3600) + ' h fa';
    return Math.round(s / 86400) + ' g fa';
  }

  /* ----------------------------------------------------------- watchlist */
  function loadWatchlist() {
    return api('watchlist').then(function (j) {
      var box = $('#watchlist');
      if (!j.items.length) {
        box.innerHTML = '<div class="body muted small">Nessuno strumento. Aggiungine uno qui sotto.</div>';
        return;
      }
      box.innerHTML = j.items.map(function (it) {
        var b = it.last ? it.last.bias : null;
        var badge = b ? '<span class="badge ' + esc(b) + '">' + esc(b) + ' ' + Math.round(it.last.confidence) + '%</span>'
                      : '<span class="badge off">nessuna analisi</span>';
        return '<div class="wl-item' + (it.symbol === state.symbol && it.timeframe === state.tf ? ' active' : '') + '"' +
               ' data-symbol="' + esc(it.symbol) + '" data-tf="' + esc(it.timeframe) + '"' +
               ' data-provider="' + esc(it.provider) + '" data-tv="' + esc(it.tv_symbol) + '" data-id="' + it.id + '">' +
               '<div class="wl-row"><span class="wl-sym">' + esc(it.label || it.symbol) + '</span>' + badge + '</div>' +
               '<div class="wl-meta"><span>' + esc(it.timeframe) + '</span><span>' + esc(it.provider) + '</span>' +
               '<span>' + (it.last ? ago(it.last.created_at) : '') + '</span></div></div>';
      }).join('');

      $$('.wl-item', box).forEach(function (el) {
        el.addEventListener('click', function () {
          select(el.dataset.symbol, el.dataset.tf, el.dataset.provider, el.dataset.tv);
        });
      });

      if (!state.symbol && j.items.length) {
        var f = j.items[0];
        select(f.symbol, f.timeframe, f.provider, f.tv_symbol);
      }
    }).catch(function (e) { toast('Watchlist: ' + esc(e.message), 'err'); });
  }

  /* ------------------------------------------------------- selezione strumento */
  function select(symbol, tf, provider, tvSymbol) {
    state.symbol = symbol; state.tf = tf || state.tf;
    state.provider = provider || 'binance';
    state.tvSymbol = tvSymbol || (state.provider === 'binance' ? 'BINANCE:' + symbol : symbol);
    $('#curSymbol').textContent = symbol;
    $('#tfSelect').value = state.tf;
    $$('.wl-item').forEach(function (el) {
      el.classList.toggle('active', el.dataset.symbol === symbol && el.dataset.tf === state.tf);
    });
    loadChart();
    loadAnalysis();
    loadTradingView();
  }

  /* ------------------------------------------------------------- grafico */
  function loadChart() {
    return api('candles', { symbol: state.symbol, tf: state.tf, provider: state.provider, limit: 300 })
      .then(function (j) {
        state.candles = j.candles;
        state.tfSeconds = j.tf_seconds;
        state.provider = j.provider;
        if (!window.TSChart.ready()) window.TSChart.init($('#chart'));
        window.TSChart.setCandles(j.candles);
        var last = j.candles[j.candles.length - 1];
        var prev = j.candles[j.candles.length - 2] || last;
        var chg = j.ticker ? j.ticker.change_pct : ((last.c - prev.c) / prev.c * 100);
        $('#kPrice').textContent = fmt(last.c);
        $('#kChange').innerHTML = '<span class="' + cls(chg) + '">' + pct(chg) + '</span>';
        if (state.analysis) applyProjection();
      })
      .catch(function (e) { toast('Dati di mercato: ' + esc(e.message), 'err'); });
  }

  function applyProjection() {
    var a = state.analysis;
    if (!a || !state.candles.length) return;
    var anchorT = a.meta && a.meta.candle_time ? a.meta.candle_time : state.candles[state.candles.length - 1].t;
    var anchorP = a.meta && a.meta.price_at ? a.meta.price_at : state.candles[state.candles.length - 1].c;
    var path = (a.payload.proiezione && a.payload.proiezione.percorso) || [];
    window.TSChart.setProjection(path, anchorT, anchorP, state.tfSeconds);
    window.TSChart.setLevels(a.payload.piano_operativo, a.payload.livelli);
  }

  /* ---------------------------------------------------- widget TradingView */
  function loadTradingView() {
    var el = $('#tvchart');
    if (!el || !window.TradingView) return;
    var map = { '15m': '15', '30m': '30', '1h': '60', '2h': '120', '4h': '240', '6h': '360', '12h': '720', '1d': 'D', '1w': 'W' };
    el.innerHTML = '';
    new window.TradingView.widget({
      container_id: 'tvchart',
      symbol: state.tvSymbol,
      interval: map[state.tf] || '240',
      timezone: 'Europe/Rome',
      theme: 'dark',
      style: '1',
      locale: 'it',
      autosize: true,
      hide_side_toolbar: false,
      allow_symbol_change: true,
      withdateranges: true,
      studies: ['STD;EMA', 'STD;RSI', 'STD;MACD'],
      backgroundColor: 'rgba(17,23,37,1)',
      gridColor: 'rgba(35,44,66,1)'
    });
  }

  /* ------------------------------------------------------------- analisi */
  function loadAnalysis() {
    return api('analysis', { symbol: state.symbol, tf: state.tf }).then(function (j) {
      if (!j.analysis) {
        state.analysis = null;
        window.TSChart.clearProjection();
        renderEmpty();
        return;
      }
      state.analysis = {
        payload: j.analysis.payload,
        meta: {
          candle_time: j.analysis.candle_time,
          price_at: j.analysis.price_at,
          created_at: j.analysis.created_at,
          model: j.analysis.model,
          source: j.analysis.source
        }
      };
      renderAnalysis();
      applyProjection();
    }).catch(function (e) { toast('Analisi: ' + esc(e.message), 'err'); });
  }

  function renderEmpty() {
    $('#kBias').innerHTML = '--';
    $('#kConf').innerHTML = '--';
    $('#kRR').textContent = '--';
    $('#analysisBox').innerHTML = '<div class="body muted">Nessuna analisi disponibile per questo strumento. ' +
      'Premi <b>Analizza ora</b> per generarne una con l\'IA.</div>';
    $('#lastUpdate').textContent = '';
  }

  function renderAnalysis() {
    var p = state.analysis.payload, m = state.analysis.meta;
    var plan = p.piano_operativo || {};
    $('#kBias').innerHTML = '<span class="badge ' + esc(p.bias) + '">' + esc(p.bias) + '</span>';
    $('#kConf').innerHTML = '<div class="gauge"><span class="mono">' + Math.round(p.confidenza) + '%</span>' +
      '<div class="bar"><span style="width:' + Math.round(p.confidenza) + '%"></span></div></div>';
    $('#kRR').textContent = plan.rischio_rendimento ? Number(plan.rischio_rendimento).toFixed(2) : '--';
    $('#lastUpdate').textContent = 'Aggiornata ' + ago(m.created_at) + ' - ' + esc(m.model) + ' (' + esc(m.source) + ')';

    var lv = p.livelli || {};
    var html = '';

    html += '<div class="body">';
    html += '<p style="margin-top:0">' + esc(p.sintesi) + '</p>';
    if (p.struttura_mercato) html += '<p class="muted small"><b>Struttura:</b> ' + esc(p.struttura_mercato) + '</p>';
    if (p.proiezione_fallback) html += '<div class="alert warn small">Proiezione grafica sostituita dal modello statistico di riserva (percorso non valido restituito dal modello).</div>';
    html += '</div>';

    html += '<div class="grid-2" style="padding:0 14px 14px">';

    /* piano operativo */
    html += '<div><h3 style="border:none;padding:0 0 8px">Piano operativo</h3><div class="plan">';
    html += box('Direzione', esc(plan.direzione || p.bias));
    html += box('Ingresso', fmt(plan.ingresso));
    html += box('Stop loss', fmt(plan.stop_loss));
    html += box('Target 1', fmt(plan.target && plan.target[0]));
    if (plan.target && plan.target[1]) html += box('Target 2', fmt(plan.target[1]));
    html += box('R/R', plan.rischio_rendimento ? Number(plan.rischio_rendimento).toFixed(2) : '--');
    html += '</div>';
    if (plan.zona_ingresso && plan.zona_ingresso.length === 2)
      html += '<p class="small muted"><b>Zona di ingresso:</b> ' + fmt(plan.zona_ingresso[0]) + ' - ' + fmt(plan.zona_ingresso[1]) + '</p>';
    if (plan.condizioni_ingresso) html += '<p class="small"><b>Trigger:</b> ' + esc(plan.condizioni_ingresso) + '</p>';
    if (plan.invalidazione) html += '<p class="small"><b>Invalidazione:</b> ' + esc(plan.invalidazione) + '</p>';
    if (p.gestione_rischio) html += '<p class="small muted">' + esc(p.gestione_rischio) + '</p>';
    html += '</div>';

    /* livelli */
    html += '<div><h3 style="border:none;padding:0 0 8px">Livelli chiave</h3>';
    (lv.resistenze || []).slice(0, 5).forEach(function (r) {
      html += '<div class="lvl"><span class="muted">Resistenza</span><span class="down mono">' + fmt(r) + '</span></div>';
    });
    (lv.supporti || []).slice(0, 5).forEach(function (s) {
      html += '<div class="lvl"><span class="muted">Supporto</span><span class="up mono">' + fmt(s) + '</span></div>';
    });
    if (lv.livello_invalidazione)
      html += '<div class="lvl"><span class="muted">Invalidazione</span><span class="flat mono">' + fmt(lv.livello_invalidazione) + '</span></div>';
    html += '</div>';

    /* scenari */
    html += '<div><h3 style="border:none;padding:0 0 8px">Scenari</h3>';
    (p.scenari || []).forEach(function (s) {
      html += '<div class="scenario"><div class="head"><span>' + esc(s.nome) + '</span>' +
        '<span class="mono">' + Math.round(s.probabilita || 0) + '%</span></div>' +
        '<div class="prob"><span style="width:' + Math.round(s.probabilita || 0) + '%"></span></div>' +
        '<div class="small muted">' + esc(s.direzione) + (s.target ? ' - target ' + fmt(s.target) : '') + '</div>' +
        '<div class="small" style="margin-top:6px">' + esc(s.descrizione) + '</div></div>';
    });
    html += '</div>';

    /* fattori e monitoraggio */
    html += '<div><h3 style="border:none;padding:0 0 8px">Fattori chiave</h3><ul class="clean small">';
    (p.fattori_chiave || []).forEach(function (f) { html += '<li>' + esc(f) + '</li>'; });
    html += '</ul>';
    var li = p.lettura_indicatori || {};
    if (Object.keys(li).length) {
      html += '<h3 style="border:none;padding:12px 0 8px">Lettura tecnica</h3><div class="small">';
      ['trend', 'momentum', 'volatilita', 'volumi'].forEach(function (k) {
        if (li[k]) html += '<p style="margin:4px 0"><b>' + k + ':</b> ' + esc(li[k]) + '</p>';
      });
      html += '</div>';
    }
    if ((p.cosa_monitorare || []).length) {
      html += '<h3 style="border:none;padding:12px 0 8px">Da monitorare</h3><ul class="clean small">';
      p.cosa_monitorare.forEach(function (f) { html += '<li>' + esc(f) + '</li>'; });
      html += '</ul>';
    }
    html += '</div>';

    html += '</div>';
    $('#analysisBox').innerHTML = html;
  }

  function box(k, v) {
    return '<div class="box"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>';
  }

  /* ------------------------------------------------------- azioni utente */
  function runAnalysis() {
    var btn = $('#btnAnalyze');
    btn.disabled = true;
    var old = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span>Analisi in corso...';
    api('analyze', { symbol: state.symbol, tf: state.tf, provider: state.provider, model: $('#modelSelect') ? $('#modelSelect').value : '' }, 'POST')
      .then(function () {
        toast('Analisi completata', 'ok');
        return Promise.all([loadAnalysis(), loadWatchlist()]);
      })
      .catch(function (e) { toast('Analisi non riuscita: ' + esc(e.message), 'err'); })
      .then(function () { btn.disabled = false; btn.innerHTML = old; });
  }

  function addSymbol(ev) {
    ev.preventDefault();
    var f = ev.target;
    var btn = $('button[type=submit]', f);
    btn.disabled = true;
    api('watchlist_add', {
      symbol: f.symbol.value, provider: f.provider.value, tf: f.tf.value,
      label: f.label.value, asset_class: f.provider.value === 'yahoo' ? 'altro' : 'crypto',
      tv_symbol: f.tv_symbol.value
    }, 'POST').then(function () {
      toast('Strumento aggiunto', 'ok');
      f.reset();
      return loadWatchlist();
    }).catch(function (e) { toast(esc(e.message), 'err'); })
      .then(function () { btn.disabled = false; });
  }

  /* ------------------------------------------------------------- avvio */
  document.addEventListener('DOMContentLoaded', function () {
    window.TSChart.init($('#chart'));

    $('#tfSelect').addEventListener('change', function () {
      state.tf = this.value;
      select(state.symbol, state.tf, state.provider, state.tvSymbol);
    });
    $('#btnAnalyze').addEventListener('click', runAnalysis);
    $('#btnRefresh').addEventListener('click', function () { loadChart(); loadAnalysis(); });
    $('#addForm').addEventListener('submit', addSymbol);

    $$('.tab').forEach(function (t) {
      t.addEventListener('click', function () {
        $$('.tab').forEach(function (x) { x.classList.remove('active'); });
        $$('.pane').forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        $('#' + t.dataset.pane).classList.add('active');
        if (t.dataset.pane === 'paneTV') loadTradingView();
      });
    });

    loadWatchlist();

    // aggiornamento automatico dei prezzi ogni 60 secondi
    state.autoTimer = setInterval(function () {
      if (state.symbol && !document.hidden) loadChart();
    }, 60000);
  });
})();
