<?php
/**
 * TradeScope 2.0 - generatore di script Pine per TradingView
 *
 * Trasforma l'analisi in un indicatore Pine v5 da incollare nel Pine Editor di
 * TradingView: disegna sul grafico reale il percorso proiettato, la banda di
 * confidenza, ingresso/stop/target, i livelli chiave e le zone operative.
 * E' il modo per avere la proiezione dentro TradingView con i suoi strumenti.
 */
declare(strict_types=1);

function ts_pine_num($v): string {
    if ($v === null || !is_numeric($v)) return 'na';
    return rtrim(rtrim(number_format((float)$v, 8, '.', ''), '0'), '.') ?: '0';
}

function ts_pine_str(string $s): string {
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = str_replace('"', "'", $s);
    return mb_substr($s, 0, 240);
}

function ts_pine_script(array $p, string $symbol, string $timeframe): string {
    $path = $p['proiezione']['percorso'] ?? [];
    $mid = []; $low = []; $up = [];
    foreach ($path as $pt) {
        $mid[] = ts_pine_num($pt['price'] ?? null);
        $low[] = ts_pine_num($pt['lower'] ?? null);
        $up[]  = ts_pine_num($pt['upper'] ?? null);
    }
    if (!$mid) { $mid = ['na']; $low = ['na']; $up = ['na']; }

    $pl   = $p['piano_operativo'] ?? [];
    $bias = strtoupper((string)($p['bias'] ?? 'neutro'));
    $conf = (int)($p['confidenza'] ?? 0);
    $score = (int)($p['punteggio_confluenza'] ?? 0);
    $qual = (string)($p['qualita_setup'] ?? '-');
    $rr   = $pl['rischio_rendimento'] ?? null;

    $sup = array_slice(array_filter((array)($p['livelli']['supporti'] ?? []), 'is_numeric'), 0, 5);
    $res = array_slice(array_filter((array)($p['livelli']['resistenze'] ?? []), 'is_numeric'), 0, 5);

    $zones = [];
    foreach ((array)($p['zone_operative'] ?? []) as $z) {
        if (!is_numeric($z['da'] ?? null) || !is_numeric($z['a'] ?? null)) continue;
        $zones[] = [min((float)$z['da'], (float)$z['a']), max((float)$z['da'], (float)$z['a']), (string)($z['tipo'] ?? 'zona')];
        if (count($zones) >= 6) break;
    }

    $title = 'TradeScope ' . ts_pine_str($symbol) . ' ' . ts_pine_str(ts_tf_label($timeframe));
    $head  = ts_pine_str($bias . ' | confidenza ' . $conf . '% | confluenza ' . $score . ' | setup ' . $qual
           . ($rr !== null ? ' | R/R ' . $rr : ''));

    $s  = "//@version=5\n";
    $s .= "// Generato da TradeScope 2.0 il " . gmdate('Y-m-d H:i') . " UTC\n";
    $s .= "// Analisi: " . ts_pine_str((string)($p['sintesi'] ?? '')) . "\n";
    $s .= "indicator(\"{$title}\", overlay = true, max_lines_count = 500, max_boxes_count = 100, max_labels_count = 100)\n\n";

    $s .= "showProj  = input.bool(true,  \"Percorso proiettato\")\n";
    $s .= "showBand  = input.bool(true,  \"Banda di confidenza 80%\")\n";
    $s .= "showPlan  = input.bool(true,  \"Ingresso / stop / target\")\n";
    $s .= "showLevels= input.bool(true,  \"Supporti e resistenze\")\n";
    $s .= "showZones = input.bool(true,  \"Zone operative (order block / FVG)\")\n\n";

    $s .= "cUp   = color.new(#4ec9a5, 0)\n";
    $s .= "cDown = color.new(#e5644e, 0)\n";
    $s .= "cProj = color.new(#9184d9, 0)\n";
    $s .= "cBand = color.new(#9184d9, 70)\n\n";

    $s .= "projMid = array.from(" . implode(', ', $mid) . ")\n";
    $s .= "projLow = array.from(" . implode(', ', $low) . ")\n";
    $s .= "projUp  = array.from(" . implode(', ', $up)  . ")\n\n";

    $s .= "entry  = " . ts_pine_num($pl['ingresso'] ?? null) . "\n";
    $s .= "stopL  = " . ts_pine_num($pl['stop_loss'] ?? null) . "\n";
    $s .= "tgt1   = " . ts_pine_num($pl['target'][0] ?? null) . "\n";
    $s .= "tgt2   = " . ts_pine_num($pl['target'][1] ?? null) . "\n";
    $s .= "invLvl = " . ts_pine_num($p['livelli']['livello_invalidazione'] ?? null) . "\n\n";

    $s .= "if barstate.islast\n";
    $s .= "    n = array.size(projMid)\n";
    $s .= "    // percorso proiettato e banda di confidenza\n";
    $s .= "    for i = 0 to n - 2\n";
    $s .= "        if showProj\n";
    $s .= "            line.new(bar_index + i + 1, array.get(projMid, i), bar_index + i + 2, array.get(projMid, i + 1), xloc = xloc.bar_index, color = cProj, width = 2, style = line.style_dashed)\n";
    $s .= "        if showBand\n";
    $s .= "            line.new(bar_index + i + 1, array.get(projUp,  i), bar_index + i + 2, array.get(projUp,  i + 1), xloc = xloc.bar_index, color = cBand, width = 1, style = line.style_dotted)\n";
    $s .= "            line.new(bar_index + i + 1, array.get(projLow, i), bar_index + i + 2, array.get(projLow, i + 1), xloc = xloc.bar_index, color = cBand, width = 1, style = line.style_dotted)\n";
    $s .= "    if showProj and n > 0\n";
    $s .= "        label.new(bar_index + n, array.get(projMid, n - 1), \"proiezione\", xloc = xloc.bar_index, style = label.style_label_left, color = color.new(#161826, 20), textcolor = cProj, size = size.small)\n\n";

    $s .= "    // piano operativo\n";
    $s .= "    if showPlan\n";
    $s .= "        if not na(entry)\n";
    $s .= "            line.new(bar_index - 60, entry, bar_index + n + 4, entry, xloc = xloc.bar_index, color = cProj, width = 2)\n";
    $s .= "            label.new(bar_index + n + 4, entry, \"ingresso \" + str.tostring(entry), xloc = xloc.bar_index, style = label.style_label_left, color = color.new(#161826, 20), textcolor = cProj, size = size.small)\n";
    $s .= "        if not na(stopL)\n";
    $s .= "            line.new(bar_index - 60, stopL, bar_index + n + 4, stopL, xloc = xloc.bar_index, color = cDown, width = 2, style = line.style_dashed)\n";
    $s .= "            label.new(bar_index + n + 4, stopL, \"stop \" + str.tostring(stopL), xloc = xloc.bar_index, style = label.style_label_left, color = color.new(#161826, 20), textcolor = cDown, size = size.small)\n";
    $s .= "        if not na(tgt1)\n";
    $s .= "            line.new(bar_index - 60, tgt1, bar_index + n + 4, tgt1, xloc = xloc.bar_index, color = cUp, width = 2)\n";
    $s .= "            label.new(bar_index + n + 4, tgt1, \"target 1 \" + str.tostring(tgt1), xloc = xloc.bar_index, style = label.style_label_left, color = color.new(#161826, 20), textcolor = cUp, size = size.small)\n";
    $s .= "        if not na(tgt2)\n";
    $s .= "            line.new(bar_index - 60, tgt2, bar_index + n + 4, tgt2, xloc = xloc.bar_index, color = cUp, width = 1, style = line.style_dotted)\n";
    $s .= "            label.new(bar_index + n + 4, tgt2, \"target 2 \" + str.tostring(tgt2), xloc = xloc.bar_index, style = label.style_label_left, color = color.new(#161826, 20), textcolor = cUp, size = size.small)\n";
    $s .= "        if not na(invLvl)\n";
    $s .= "            line.new(bar_index - 60, invLvl, bar_index + n + 4, invLvl, xloc = xloc.bar_index, color = color.new(#e5644e, 40), width = 1, style = line.style_dotted)\n\n";

    if ($sup || $res) {
        $s .= "    // supporti e resistenze\n";
        $s .= "    if showLevels\n";
        foreach ($sup as $v) {
            $s .= "        line.new(bar_index - 120, " . ts_pine_num($v) . ", bar_index + n + 2, " . ts_pine_num($v) . ", xloc = xloc.bar_index, color = color.new(#4ec9a5, 45), width = 1)\n";
        }
        foreach ($res as $v) {
            $s .= "        line.new(bar_index - 120, " . ts_pine_num($v) . ", bar_index + n + 2, " . ts_pine_num($v) . ", xloc = xloc.bar_index, color = color.new(#e5644e, 45), width = 1)\n";
        }
        $s .= "\n";
    }

    if ($zones) {
        $s .= "    // zone operative\n";
        $s .= "    if showZones\n";
        foreach ($zones as $z) {
            $col = (stripos($z[2], 'rialz') !== false || stripos($z[2], 'domanda') !== false) ? '#4ec9a5' : '#e5644e';
            $s .= "        box.new(bar_index - 40, " . ts_pine_num($z[1]) . ", bar_index + n + 2, " . ts_pine_num($z[0])
                . ", xloc = xloc.bar_index, border_color = color.new({$col}, 40), bgcolor = color.new({$col}, 88), text = \"" . ts_pine_str($z[2]) . "\", text_color = color.new({$col}, 10), text_size = size.tiny)\n";
        }
        $s .= "\n";
    }

    $s .= "    // riepilogo\n";
    $s .= "    label.new(bar_index, high, \"" . $head . "\", xloc = xloc.bar_index, yloc = yloc.abovebar, style = label.style_label_down, color = color.new(#161826, 10), textcolor = color.new(#e9e9ed, 0), size = size.normal)\n";

    return $s;
}
