/* TradeScope - grafico proiezione (TradingView Lightweight Charts v5) */
(function (global) {
  'use strict';

  var LWC = global.LightweightCharts;
  var chart = null, candles = null, volume = null;
  var e21 = null, e50 = null, e200 = null;
  var projMid = null, projUp = null, projLow = null;
  var priceLines = [];
  var container = null, lastRows = [];

  var C = {
    up: '#16c784', down: '#ea3943', grid: '#1c2437', text: '#94a1bd',
    e21: '#f0b90b', e50: '#4f8cff', e200: '#b06ef2',
    proj: '#00e0ff', band: 'rgba(0,224,255,0.70)',
    entry: '#4f8cff', stop: '#ea3943', tp: '#16c784'
  };

  function init(el) {
    if (!LWC) { console.error('Lightweight Charts non caricato'); return; }
    container = el;
    chart = LWC.createChart(el, {
      layout: { background: { color: 'transparent' }, textColor: C.text, fontSize: 11,
                attributionLogo: false },
      grid: { vertLines: { color: C.grid }, horzLines: { color: C.grid } },
      rightPriceScale: { borderColor: '#232c42', scaleMargins: { top: 0.08, bottom: 0.24 } },
      timeScale: { borderColor: '#232c42', timeVisible: true, secondsVisible: false, rightOffset: 6 },
      crosshair: { mode: LWC.CrosshairMode ? LWC.CrosshairMode.Normal : 0 },
      localization: { locale: 'it-IT' },
      autoSize: false
    });

    candles = chart.addSeries(LWC.CandlestickSeries, {
      upColor: C.up, downColor: C.down, borderVisible: false,
      wickUpColor: C.up, wickDownColor: C.down, baseLineVisible: false
    });

    volume = chart.addSeries(LWC.HistogramSeries, {
      priceFormat: { type: 'volume' }, priceScaleId: 'vol', color: 'rgba(79,140,255,.35)',
      lastValueVisible: false, priceLineVisible: false, baseLineVisible: false
    });
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });

    e21  = chart.addSeries(LWC.LineSeries, { color: C.e21,  lineWidth: 1, priceLineVisible: false, lastValueVisible: false, baseLineVisible: false });
    e50  = chart.addSeries(LWC.LineSeries, { color: C.e50,  lineWidth: 1, priceLineVisible: false, lastValueVisible: false, baseLineVisible: false });
    e200 = chart.addSeries(LWC.LineSeries, { color: C.e200, lineWidth: 2, priceLineVisible: false, lastValueVisible: false, baseLineVisible: false });

    projUp  = chart.addSeries(LWC.LineSeries, { color: C.band, lineWidth: 1, lineStyle: LWC.LineStyle.Dotted, priceLineVisible: false, lastValueVisible: false, baseLineVisible: false, crosshairMarkerVisible: false });
    projLow = chart.addSeries(LWC.LineSeries, { color: C.band, lineWidth: 1, lineStyle: LWC.LineStyle.Dotted, priceLineVisible: false, lastValueVisible: false, baseLineVisible: false, crosshairMarkerVisible: false });
    projMid = chart.addSeries(LWC.LineSeries, { color: C.proj, lineWidth: 2, lineStyle: LWC.LineStyle.Dashed, priceLineVisible: false, lastValueVisible: true, baseLineVisible: false });

    if (global.ResizeObserver) {
      new ResizeObserver(function () {
        chart.applyOptions({ width: el.clientWidth, height: el.clientHeight });
      }).observe(el);
    }
    chart.applyOptions({ width: el.clientWidth, height: el.clientHeight });
  }

  function setCandles(rows) {
    if (!chart) return;
    var c = [], v = [], a21 = [], a50 = [], a200 = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      c.push({ time: r.t, open: r.o, high: r.h, low: r.l, close: r.c });
      v.push({ time: r.t, value: r.v, color: r.c >= r.o ? 'rgba(22,199,132,.30)' : 'rgba(234,57,67,.30)' });
      if (r.e21  !== null && r.e21  !== undefined) a21.push({ time: r.t, value: r.e21 });
      if (r.e50  !== null && r.e50  !== undefined) a50.push({ time: r.t, value: r.e50 });
      if (r.e200 !== null && r.e200 !== undefined) a200.push({ time: r.t, value: r.e200 });
    }
    lastRows = rows;
    candles.setData(c); volume.setData(v);
    e21.setData(a21); e50.setData(a50); e200.setData(a200);
    chart.timeScale().fitContent();
  }

  /** path: [{offset, price, lower, upper}], anchorTime: t ultima candela, tfSeconds: durata barra */
  function setProjection(path, anchorTime, anchorPrice, tfSeconds) {
    if (!chart) return;
    if (!path || !path.length) { projMid.setData([]); projUp.setData([]); projLow.setData([]); return; }
    var mid = [{ time: anchorTime, value: anchorPrice }];
    var up  = [{ time: anchorTime, value: anchorPrice }];
    var lo  = [{ time: anchorTime, value: anchorPrice }];
    var seen = {};
    for (var i = 0; i < path.length; i++) {
      var p = path[i];
      var off = p.offset ? Number(p.offset) : (i + 1);
      var t = anchorTime + off * tfSeconds;
      if (seen[t]) continue;
      seen[t] = 1;
      mid.push({ time: t, value: Number(p.price) });
      up.push({ time: t, value: Number(p.upper !== undefined ? p.upper : p.price) });
      lo.push({ time: t, value: Number(p.lower !== undefined ? p.lower : p.price) });
    }
    function asc(a, b) { return a.time - b.time; }
    mid.sort(asc); up.sort(asc); lo.sort(asc);
    projMid.setData(mid); projUp.setData(up); projLow.setData(lo);

    // Inquadratura: ultime 90 barre di storico + tutta la proiezione
    try {
      var from = lastRows.length > 90 ? lastRows[lastRows.length - 90].t : (lastRows[0] ? lastRows[0].t : anchorTime);
      var to = mid[mid.length - 1].time + tfSeconds * 3;
      chart.timeScale().setVisibleRange({ from: from, to: to });
    } catch (e) { chart.timeScale().fitContent(); }
  }

  /** Linee orizzontali di piano operativo e livelli chiave */
  function setLevels(plan, levels) {
    if (!candles) return;
    priceLines.forEach(function (l) { try { candles.removePriceLine(l); } catch (e) {} });
    priceLines = [];
    function add(price, color, title, style, axisLabel) {
      if (price === null || price === undefined || isNaN(Number(price)) || Number(price) <= 0) return;
      priceLines.push(candles.createPriceLine({
        price: Number(price), color: color, lineWidth: 1,
        lineStyle: style === undefined ? LWC.LineStyle.Dashed : style,
        axisLabelVisible: axisLabel !== false, title: title
      }));
    }
    if (plan) {
      add(plan.ingresso, C.entry, 'Ingresso', LWC.LineStyle.Solid);
      add(plan.stop_loss, C.stop, 'Stop');
      if (Array.isArray(plan.target)) {
        plan.target.forEach(function (t, i) { add(t, C.tp, 'TP' + (i + 1)); });
      }
    }
    if (levels) {
      (levels.supporti || []).slice(0, 3).forEach(function (s) { add(s, 'rgba(22,199,132,.45)', 'Supporto', LWC.LineStyle.Dotted, false); });
      (levels.resistenze || []).slice(0, 3).forEach(function (s) { add(s, 'rgba(234,57,67,.45)', 'Resistenza', LWC.LineStyle.Dotted, false); });
    }
  }

  function clearProjection() {
    if (projMid) { projMid.setData([]); projUp.setData([]); projLow.setData([]); }
  }

  global.TSChart = {
    init: init, setCandles: setCandles, setProjection: setProjection,
    setLevels: setLevels, clearProjection: clearProjection,
    ready: function () { return !!chart; }
  };
})(window);
