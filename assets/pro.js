/* TradeScope 2.0 - dashboard avanzata */
(function () {
  'use strict';
  var LWC = window.LightweightCharts;
  var C = { up:'#4ec9a5', down:'#e5644e', accent:'#9184d9', band:'rgba(145,132,217,.55)',
            e21:'#e0b15c', e50:'#6a9bd8', e200:'#b06ef2', text:'#9aa0b4', grid:'rgba(46,51,72,.5)' };

  var S = { symbol:null, tf:'4h', provider:'binance', tvSymbol:null, items:[], analysis:null, tfSeconds:14400 };
  var chart, sCandles, sVol, sE21, sE50, sE200, sMid, sLow, sUp, priceLines = [], tvWidget = null;

  /* ------------------------------------------------------------------ util */
  function $(id) { return document.getElementById(id); }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function fmt(n, d) {
    if (n === null || n === undefined || isNaN(n)) return '--';
    var a = Math.abs(n);
    var dec = d != null ? d : (a >= 1000 ? 2 : a >= 1 ? 4 : a >= 0.01 ? 6 : 8);
    return Number(n).toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: dec });
  }
  function pct(n) { return (n === null || n === undefined || isNaN(n)) ? '--' : (n > 0 ? '+' : '') + Number(n).toFixed(2) + '%'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return ({ '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;' })[c]; }); }
  var toastT;
  function toast(msg, ms) {
    var t = $('toast'); t.innerHTML = msg; t.classList.add('on');
    clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('on'); }, ms || 3600);
  }
  function api(action, params, post) {
    var q = new URLSearchParams(params || {});
    if (!post) {
      return fetch('api.php?action=' + action + '&' + q.toString(), { credentials:'same-origin' }).then(function (r) { return r.json(); });
    }
    q.append('csrf', window.TS_CSRF);
    return fetch('api.php?action=' + action, {
      method:'POST', credentials:'same-origin',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:q.toString()
    }).then(function (r) { return r.json(); });
  }

  /* ----------------------------------------------------------------- chart */
  function initChart() {
    if (!LWC) return;
    chart = LWC.createChart($('chart'), {
      autoSize:true,
      width:$('chart').clientWidth, height:$('chart').clientHeight || 460,
      layout:{ background:{ color:'transparent' }, textColor:C.text, fontSize:11, attributionLogo:false },
      grid:{ vertLines:{ color:C.grid }, horzLines:{ color:C.grid } },
      rightPriceScale:{ borderColor:'#2e3348', scaleMargins:{ top:0.08, bottom:0.22 } },
      timeScale:{ borderColor:'#2e3348', timeVisible:true, secondsVisible:false, rightOffset:14 },
      crosshair:{ mode:0, vertLine:{ color:'#4b5372', labelBackgroundColor:'#252a3d' },
                  horzLine:{ color:'#4b5372', labelBackgroundColor:'#252a3d' } },
      localization:{ locale:'it-IT' }
    });
    sCandles = chart.addSeries(LWC.CandlestickSeries, { upColor:C.up, downColor:C.down, borderVisible:false, wickUpColor:C.up, wickDownColor:C.down });
    sVol = chart.addSeries(LWC.HistogramSeries, { priceFormat:{ type:'volume' }, priceScaleId:'vol', color:'rgba(145,132,217,.28)', lastValueVisible:false, priceLineVisible:false });
    chart.priceScale('vol').applyOptions({ scaleMargins:{ top:0.85, bottom:0 } });
    var ln = function (color, w) { return chart.addSeries(LWC.LineSeries, { color:color, lineWidth:w, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false }); };
    sE21 = ln(C.e21, 1); sE50 = ln(C.e50, 1); sE200 = ln(C.e200, 2);
    sUp  = chart.addSeries(LWC.LineSeries, { color:C.band, lineWidth:1, lineStyle:LWC.LineStyle.Dotted, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false });
    sLow = chart.addSeries(LWC.LineSeries, { color:C.band, lineWidth:1, lineStyle:LWC.LineStyle.Dotted, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false });
    sMid = chart.addSeries(LWC.LineSeries, { color:C.accent, lineWidth:2, lineStyle:LWC.LineStyle.Dashed, priceLineVisible:false, lastValueVisible:true });
    if (window.ResizeObserver) new ResizeObserver(function () { fitChart(); }).observe($('chart'));
    fitChart();
  }

  function fitChart(tries) {
    tries = tries || 0;
    var box = $('chart');
    if (!chart || !box) return;
    var w = box.clientWidth, h = box.clientHeight || 460;
    if (w > 0) { chart.resize(w, h); return; }
    if (tries < 40) requestAnimationFrame(function () { fitChart(tries + 1); });
  }

  function drawCandles(d) {
    S.tfSeconds = d.tf_seconds || S.tfSeconds;
    sCandles.setData(d.candles.map(function (c) { return { time:c.t, open:c.o, high:c.h, low:c.l, close:c.c }; }));
    sVol.setData(d.candles.map(function (c) { return { time:c.t, value:c.v, color: c.c >= c.o ? 'rgba(78,201,165,.22)' : 'rgba(229,100,78,.22)' }; }));
    var line = function (key) { return d.candles.filter(function (c) { return c[key] !== null; }).map(function (c) { return { time:c.t, value:c[key] }; }); };
    sE21.setData(line('e21')); sE50.setData(line('e50')); sE200.setData(line('e200'));
    S.lastTime = d.candles[d.candles.length - 1].t;
    S.lastPrice = d.candles[d.candles.length - 1].c;
  }

  function drawProjection(p) {
    priceLines.forEach(function (l) { try { sCandles.removePriceLine(l); } catch (e) {} });
    priceLines = [];
    if (!p || !p.proiezione || !p.proiezione.percorso || !S.lastTime) { sMid.setData([]); sLow.setData([]); sUp.setData([]); return; }
    var t0 = S.lastTime, step = S.tfSeconds;
    var mid = [{ time:t0, value:S.lastPrice }], lo = [{ time:t0, value:S.lastPrice }], up = [{ time:t0, value:S.lastPrice }];
    p.proiezione.percorso.forEach(function (pt, i) {
      var t = t0 + step * (i + 1);
      mid.push({ time:t, value:pt.price });
      lo.push({ time:t, value:pt.lower });
      up.push({ time:t, value:pt.upper });
    });
    sMid.setData(mid); sLow.setData(lo); sUp.setData(up);

    var pl = p.piano_operativo || {};
    var add = function (price, title, color, style) {
      if (price === null || price === undefined || isNaN(price)) return;
      priceLines.push(sCandles.createPriceLine({ price:Number(price), color:color, lineWidth:1,
        lineStyle: style === undefined ? LWC.LineStyle.Solid : style, axisLabelVisible:true, title:title }));
    };
    add(pl.ingresso, 'ingresso', C.accent);
    add(pl.stop_loss, 'stop', C.down, LWC.LineStyle.Dashed);
    if (pl.target) { add(pl.target[0], 'T1', C.up); add(pl.target[1], 'T2', C.up, LWC.LineStyle.Dotted); }
    if (p.livelli && p.livelli.livello_invalidazione) add(p.livelli.livello_invalidazione, 'invalidazione', 'rgba(229,100,78,.5)', LWC.LineStyle.Dotted);
    chart.timeScale().scrollToRealTime();
  }

  /* ------------------------------------------------------------- watchlist */
  function loadWatchlist(selectFirst) {
    return api('watchlist').then(function (r) {
      if (!r.ok) return;
      S.items = r.items;
      var box = $('watchlist'); box.innerHTML = '';
      if (!r.items.length) { box.appendChild(el('div', 'body muted small', 'Nessuno strumento. Aggiungilo dalla <a href="index.php">dashboard 1.0</a>.')); return; }
      r.items.forEach(function (it) {
        var last = it.last || null;
        var bias = last ? last.bias : null;
        var d = el('div', 'wl-item' + (S.symbol === it.symbol && S.tf === it.timeframe ? ' sel' : ''));
        d.innerHTML = '<span class="sym">' + esc(it.symbol) + '</span>'
          + '<span class="badge ' + (bias || 'neutro') + ' small">' + (bias ? esc(bias) + ' ' + Math.round(last.confidence) + '%' : 'da analizzare') + '</span>'
          + '<span class="meta">' + esc(it.label || it.provider) + ' · ' + esc(it.timeframe)
          + (last ? ' · ' + esc(last.created_at.replace(' ', ' ')) + ' UTC' : '') + '</span>';
        d.onclick = function () { select(it); };
        box.appendChild(d);
      });
      if (selectFirst && !S.symbol) select(r.items[0]);
    });
  }

  function select(it) {
    S.symbol = it.symbol; S.provider = it.provider; S.tf = it.timeframe; S.tvSymbol = it.tv_symbol;
    $('tfSelect').value = it.timeframe;
    $('curSymbol').textContent = it.symbol;
    document.querySelectorAll('.wl-item').forEach(function (n) { n.classList.remove('sel'); });
    loadWatchlist(false);
    refresh();
    if (tvWidget) { tvWidget = null; if ($('paneTV').classList.contains('active')) initTV(); }
  }

  /* ------------------------------------------------------------- rendering */
  function refresh() {
    if (!S.symbol) return;
    $('lastUpdate').innerHTML = '<span class="spin"></span> aggiornamento';
    Promise.all([
      api('candles', { symbol:S.symbol, tf:S.tf, provider:S.provider, limit:320 }),
      api('analysis', { symbol:S.symbol, tf:S.tf })
    ]).then(function (res) {
      var c = res[0], a = res[1];
      if (c.ok) { drawCandles(c); renderTicker(c); }
      else toast('Dati non disponibili: ' + esc(c.error || ''));
      S.analysis = (a.ok && a.analysis) ? a.analysis : null;
      renderAnalysis(S.analysis);
      drawProjection(S.analysis ? S.analysis.payload : null);
      $('lastUpdate').textContent = 'aggiornato ' + new Date().toLocaleTimeString('it-IT');
    });
  }

  function renderTicker(c) {
    var last = c.candles[c.candles.length - 1];
    $('kPrice').textContent = fmt(last.c);
    var ch = c.ticker ? c.ticker.change_pct : null;
    var e = $('kChange');
    e.textContent = pct(ch);
    e.className = 'v mono ' + (ch > 0 ? 'up' : ch < 0 ? 'down' : '');
  }

  function biasBadge(b, conf) {
    return '<span class="badge ' + esc(b || 'neutro') + '">' + esc((b || 'neutro').toUpperCase())
      + (conf != null ? ' · ' + Math.round(conf) + '%' : '') + '</span>';
  }

  function renderAnalysis(a) {
    var box = $('analysis');
    if (!a) {
      box.innerHTML = '<div class="body muted">Nessuna analisi per ' + esc(S.symbol || '') + '. Premi <b>Analizza ora</b>: il motore 2.0 calcola snapshot, confluenza e proiezione.</div>';
      ['kBias','kConf','kQual','kRR','kEV'].forEach(function (k) { $(k).textContent = '--'; });
      $('mtf').innerHTML = ''; $('gaugeScore').style.width = '0';
      return;
    }
    var p = a.payload || {};
    var pl = p.piano_operativo || {};
    var score = p.punteggio_confluenza || 0;

    /* KPI */
    $('kBias').innerHTML = biasBadge(p.bias, null);
    $('kConf').textContent = (p.confidenza != null ? p.confidenza + '%' : '--');
    $('kQual').innerHTML = '<span class="badge ' + esc(String(p.qualita_setup || 'c').toLowerCase()) + '">setup ' + esc(p.qualita_setup || '-') + '</span>';
    $('kRR').textContent = pl.rischio_rendimento != null ? Number(pl.rischio_rendimento).toFixed(2) : '--';
    $('kEV').textContent = p.valore_atteso_R != null ? (p.valore_atteso_R > 0 ? '+' : '') + Number(p.valore_atteso_R).toFixed(2) + ' R' : '--';
    $('kEV').className = 'v mono ' + (p.valore_atteso_R > 0 ? 'up' : p.valore_atteso_R < 0 ? 'down' : '');
    $('kScore').textContent = (score > 0 ? '+' : '') + score;
    $('kScore').className = 'v mono ' + (score >= 18 ? 'up' : score <= -18 ? 'down' : '');
    var g = $('gaugeScore');
    g.style.background = score >= 0 ? C.up : C.down;
    g.style.width = Math.min(50, Math.abs(score) / 2) + '%';
    g.style.left = score >= 0 ? '50%' : (50 - Math.min(50, Math.abs(score) / 2)) + '%';
    $('scoreNote').textContent = (p.operativita_consigliata || '');

    /* consenso multi-timeframe */
    var mtf = (p.consenso_mtf_server || {});
    var m = $('mtf'); m.innerHTML = '';
    var reads = (a.payload._meta && a.payload._meta.mtf) ? a.payload._meta.mtf : null;
    if (p.multi_timeframe) $('mtfText').textContent = p.multi_timeframe;
    $('mtfSummary').innerHTML = mtf.direzione
      ? 'direzione ' + esc(mtf.direzione) + ' · punteggio medio <b class="mono">' + esc(mtf.punteggio_medio) + '</b> · allineati ' + esc(mtf.timeframe_allineati)
      : '';

    /* piano operativo */
    var plan = $('plan');
    if (pl.direzione) {
      var zona = (pl.zona_ingresso && pl.zona_ingresso.length === 2) ? fmt(pl.zona_ingresso[0]) + ' – ' + fmt(pl.zona_ingresso[1]) : '--';
      plan.innerHTML =
        '<div class="plan">'
        + item('direzione', String(pl.direzione).toUpperCase())
        + item('zona ingresso', zona)
        + item('ingresso', fmt(pl.ingresso))
        + item('stop loss', fmt(pl.stop_loss))
        + item('target 1', fmt(pl.target && pl.target[0]))
        + item('target 2', fmt(pl.target && pl.target[1]))
        + item('R/R', pl.rischio_rendimento != null ? Number(pl.rischio_rendimento).toFixed(2) : '--')
        + item('prob. T1', pl.probabilita_target1_pct != null ? Math.round(pl.probabilita_target1_pct) + '%' : '--')
        + '</div>'
        + (pl.condizioni_ingresso ? '<div class="lvl"><span class="muted">trigger</span><span>' + esc(pl.condizioni_ingresso) + '</span></div>' : '')
        + (pl.gestione ? '<div class="lvl"><span class="muted">gestione</span><span>' + esc(pl.gestione) + '</span></div>' : '')
        + (pl.invalidazione ? '<div class="lvl"><span class="muted">invalidazione</span><span>' + esc(pl.invalidazione) + '</span></div>' : '')
        + (pl.avviso ? '<div class="note">' + esc(pl.avviso) + '</div>' : '');
    } else plan.innerHTML = '<div class="muted">nessun piano operativo</div>';

    /* analisi testuale */
    box.innerHTML =
      '<div class="body">'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">'
        + biasBadge(p.bias, p.confidenza)
        + '<span class="badge">' + esc(p.regime_server || p.regime_letto || '') + '</span>'
        + '<span class="badge">' + esc(a.model || '') + '</span>'
        + '<span class="badge">' + esc(a.created_at) + ' UTC</span>'
      + '</div>'
      + '<p style="margin:0 0 10px;text-wrap:pretty">' + esc(p.sintesi || '') + '</p>'
      + (p.struttura_mercato ? '<p class="muted" style="margin:0 0 10px">' + esc(p.struttura_mercato) + '</p>' : '')
      + '<div class="grid2">'
        + listBlock('Fattori a favore', p.fattori_chiave)
        + listBlock('Fattori contrari', p.fattori_contrari)
        + indBlock(p.lettura_indicatori)
        + listBlock('Cosa monitorare', p.cosa_monitorare)
      + '</div>'
      + (p.invalidazione_tesi ? '<div class="note">invalidazione della tesi: ' + esc(p.invalidazione_tesi) + '</div>' : '')
      + (p.note_validazione ? p.note_validazione.map(function (n) { return '<div class="note">' + esc(n) + '</div>'; }).join('') : '')
      + '</div>';

    /* scenari */
    var sc = $('scenari');
    sc.innerHTML = '<div class="body grid2">' + (p.scenari || []).map(function (s) {
      return '<div class="scen"><h4>' + esc(s.nome || '') + ' · <span class="muted">' + esc(s.direzione || '') + '</span></h4>'
        + '<div class="p">' + (s.probabilita != null ? Math.round(s.probabilita) + '%' : '--') + '</div>'
        + '<div class="bar"><i style="width:' + Math.max(0, Math.min(100, s.probabilita || 0)) + '%"></i></div>'
        + '<div class="small muted">target <span class="mono">' + fmt(s.target) + '</span></div>'
        + '<div class="small" style="margin-top:5px">' + esc(s.descrizione || '') + '</div></div>';
    }).join('') + '</div>';

    /* zone operative e livelli */
    var z = $('zone');
    var zs = (p.zone_operative || []).map(function (x) {
      return '<div class="zone"><span class="px">' + fmt(x.da) + ' – ' + fmt(x.a) + '</span><span>' + esc(x.tipo || '') + '</span>'
        + (x.ruolo ? '<span class="role">' + esc(x.ruolo) + '</span>' : '') + '</div>';
    }).join('');
    var lv = p.livelli || {};
    z.innerHTML = '<div class="body">'
      + (zs || '<div class="muted small">nessuna zona segnalata</div>')
      + '<div style="margin-top:12px">'
        + rowLine('resistenze', (lv.resistenze || []).map(function (v) { return fmt(v); }).join('  ·  '))
        + rowLine('supporti', (lv.supporti || []).map(function (v) { return fmt(v); }).join('  ·  '))
        + rowLine('invalidazione', fmt(lv.livello_invalidazione))
      + '</div></div>';

    /* contributi della confluenza */
    var cb = $('contrib');
    cb.innerHTML = '<div class="body">' + (p.contributi_confluenza || []).map(function (c) {
      var v = Number(c.contributo) || 0, w = Math.min(50, Math.abs(v) * 2.6);
      return '<div class="contrib"><span>' + esc(c.fattore) + '</span>'
        + '<span class="mono ' + (v > 0 ? 'up' : 'down') + '">' + (v > 0 ? '+' : '') + v + '</span>'
        + (c.nota ? '<span class="n">' + esc(c.nota) + '</span>' : '')
        + '<span class="cbar"><i style="' + (v >= 0 ? 'left:50%' : 'right:50%') + ';width:' + w + '%;background:' + (v >= 0 ? C.up : C.down) + '"></i></span></div>';
    }).join('') + '</div>';

    function item(k, v) { return '<div class="p-item"><div class="k">' + k + '</div><div class="v">' + esc(v) + '</div></div>'; }
    function rowLine(k, v) { return '<div class="lvl"><span class="muted">' + k + '</span><span class="mono">' + (v || '--') + '</span></div>'; }
    function listBlock(t, arr) {
      if (!arr || !arr.length) return '';
      return '<div><div class="k small dim" style="text-transform:uppercase;letter-spacing:.07em">' + t + '</div>'
        + '<ul class="clean small">' + arr.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
    }
    function indBlock(o) {
      if (!o) return '';
      return '<div><div class="k small dim" style="text-transform:uppercase;letter-spacing:.07em">Lettura tecnica</div>'
        + Object.keys(o).map(function (k) { return '<div class="lvl small"><span class="muted">' + esc(k) + '</span><span style="max-width:65%;text-align:right">' + esc(o[k]) + '</span></div>'; }).join('') + '</div>';
    }
  }

  /* --------------------------------------------------------- snapshot MTF */
  function loadSnapshot() {
    if (!S.symbol) return;
    $('mtf').innerHTML = '<span class="spin"></span>';
    api('snapshot', { symbol:S.symbol, tf:S.tf, provider:S.provider }).then(function (r) {
      if (!r.ok) { $('mtf').innerHTML = '<span class="muted small">' + esc(r.error) + '</span>'; return; }
      var s = r.snapshot, m = $('mtf'); m.innerHTML = '';
      Object.keys(s.multi_timeframe || {}).forEach(function (tf) {
        var d = s.multi_timeframe[tf];
        var cls = d.punteggio >= 18 ? 'up' : d.punteggio <= -18 ? 'down' : 'muted';
        m.appendChild(el('div', 'tfbox',
          '<div class="t">' + esc(tf) + ' <span class="dim small">' + esc(d.ruolo || '') + '</span></div>'
          + '<div class="s mono ' + cls + '">' + (d.punteggio > 0 ? '+' : '') + d.punteggio + '</div>'
          + '<div class="small muted">' + esc(d.struttura) + '</div>'
          + '<div class="small dim">RSI ' + esc(d.rsi14) + ' · ADX ' + esc(d.adx14) + ' · ' + (d.sopra_ema200 ? 'sopra' : 'sotto') + ' EMA200</div>'));
      });
      /* regime, derivati, contesto */
      var reg = s.regime || {}, der = s.derivati, ctx = s.contesto_mercato, cor = s.correlazione_btc;
      $('regime').innerHTML = '<div class="body small">'
        + rl('regime', reg.tipo) + rl('strategia coerente', reg.strategia_coerente)
        + rl('ADX 14', reg.adx14 + ' · ' + (reg.di_dominante || ''))
        + rl('ampiezza Bollinger', reg.ampiezza_bollinger_pct + '% (percentile ' + reg.ampiezza_bollinger_percentile + ')')
        + rl('ATR', reg.atr_pct + '% (percentile ' + reg.atr_percentile + ')')
        + rl('fase Wyckoff', (s.wyckoff || {}).fase + ((s.wyckoff || {}).evento ? ' · ' + s.wyckoff.evento : ''))
        + '</div>';
      $('deriv').innerHTML = '<div class="body small">'
        + (der ? rl('funding', der.funding_pct + '% (' + der.funding_annuo_pct + '% annuo)')
              + rl('open interest 24h', pct(der.oi_var_pct_24h))
              + rl('long/short retail', der.long_short_ratio_conti)
              + rl('taker buy/sell', der.taker_buy_sell_ratio)
              + (der.letture || []).map(function (x) { return '<div class="small muted" style="margin-top:6px">· ' + esc(x) + '</div>'; }).join('')
           : '<div class="muted">nessun dato derivato per questo strumento</div>')
        + (ctx ? '<div style="margin-top:10px;border-top:1px solid #2e3348;padding-top:8px">'
              + rl('Fear &amp; Greed', ctx.fear_greed + ' · ' + (ctx.fear_greed_classe || ''))
              + rl('dominance BTC', ctx.dominance_btc_pct + '%')
              + rl('BTC 24h', pct(ctx.btc_24h_pct)) + '</div>' : '')
        + (cor ? rl('correlazione BTC', cor.correlazione_60_barre + ' · ' + cor.nota) : '')
        + '</div>';
      /* zone SMC calcolate dal server */
      var smc = s.smart_money || {};
      $('smc').innerHTML = '<div class="body small">'
        + rl('rottura struttura', smc.rottura_struttura ? smc.rottura_struttura.tipo + ' a ' + fmt(smc.rottura_struttura.livello) + ' (' + smc.rottura_struttura.barre_fa + ' barre fa)' : '--')
        + (smc.order_block || []).map(function (o) { return '<div class="zone"><span class="px">' + fmt(o.da) + ' – ' + fmt(o.a) + '</span><span>' + esc(o.tipo) + ' · ' + esc(o.stato) + ' · ' + pct(o.distanza_pct) + '</span></div>'; }).join('')
        + (smc.fair_value_gap || []).map(function (o) { return '<div class="zone"><span class="px">' + fmt(o.da) + ' – ' + fmt(o.a) + '</span><span>' + esc(o.tipo) + '</span></div>'; }).join('')
        + (smc.prese_di_liquidita || []).map(function (o) { return '<div class="small muted">· ' + esc(o.tipo) + ' (' + o.barre_fa + ' barre fa)</div>'; }).join('')
        + (smc.liquidita_in_attesa || []).map(function (o) { return '<div class="small muted">· ' + esc(o) + '</div>'; }).join('')
        + '</div>';
      function rl(k, v) { return '<div class="lvl"><span class="muted">' + k + '</span><span style="max-width:62%;text-align:right">' + esc(v == null ? '--' : v) + '</span></div>'; }
    });
  }

  /* ---------------------------------------------------------------- azioni */
  function analyze() {
    if (!S.symbol) return;
    var b = $('btnAnalyze'); b.disabled = true; b.innerHTML = '<span class="spin"></span> analisi in corso';
    api('analyze', { symbol:S.symbol, tf:S.tf, provider:S.provider, model:$('modelSelect').value }, true)
      .then(function (r) {
        b.disabled = false; b.textContent = 'Analizza ora';
        if (!r.ok) { toast('Errore: ' + esc(r.error || '')); return; }
        toast('Analisi completata · confluenza ' + (r.analysis.punteggio_confluenza > 0 ? '+' : '') + r.analysis.punteggio_confluenza + ' · setup ' + r.analysis.qualita_setup);
        refresh(); loadWatchlist(false);
      }).catch(function () { b.disabled = false; b.textContent = 'Analizza ora'; toast('Errore di rete'); });
  }

  function loadPine() {
    if (!S.symbol) return;
    api('pine', { symbol:S.symbol, tf:S.tf }).then(function (r) {
      if (!r.ok) { toast(esc(r.error)); return; }
      $('pineBox').textContent = r.pine;
      $('pineCard').style.display = 'block';
      if (navigator.clipboard) navigator.clipboard.writeText(r.pine).then(function () {
        toast('Script Pine copiato: aprilo in TradingView → Pine Editor → incolla → Aggiungi al grafico');
      }, function () { toast('Script generato: copialo dal riquadro in basso'); });
      else toast('Script generato: copialo dal riquadro in basso');
    });
  }

  function initTV() {
    if (!window.TradingView || tvWidget || !S.tvSymbol) return;
    tvWidget = new TradingView.widget({
      container_id:'tvchart', symbol:S.tvSymbol, interval:tvInterval(S.tf), theme:'dark', style:'1',
      locale:'it', autosize:true, timezone:'Europe/Rome', hide_side_toolbar:false, withdateranges:true,
      allow_symbol_change:true, studies:['RSI@tv-basicstudies'], toolbar_bg:'#161826'
    });
  }
  function tvInterval(tf) {
    return { '15m':'15','30m':'30','1h':'60','2h':'120','4h':'240','6h':'360','12h':'720','1d':'D','1w':'W' }[tf] || '240';
  }

  /* ------------------------------------------------------------------ init */
  document.addEventListener('DOMContentLoaded', function () {
    initChart();
    loadWatchlist(true).then(function () { if (S.symbol) loadSnapshot(); });
    $('btnAnalyze').onclick = analyze;
    $('btnRefresh').onclick = function () { refresh(); loadSnapshot(); };
    $('btnPine').onclick = loadPine;
    $('tfSelect').onchange = function () { S.tf = this.value; refresh(); loadSnapshot(); if (tvWidget) { $('tvchart').innerHTML = ''; tvWidget = null; if ($('paneTV').classList.contains('active')) initTV(); } };
    document.querySelectorAll('.tab').forEach(function (t) {
      t.onclick = function () {
        document.querySelectorAll('.tab').forEach(function (x) { x.classList.remove('active'); });
        document.querySelectorAll('.pane').forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        $(t.dataset.pane).classList.add('active');
        if (t.dataset.pane === 'paneTV') initTV();
        else fitChart();
      };
    });
    setInterval(function () { if (!document.hidden) refresh(); }, 90000);
  });
})();
