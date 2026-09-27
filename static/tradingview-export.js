'use strict';

(function attachTradingViewZones(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.TradingViewZones = api;
})(typeof window !== 'undefined' ? window : globalThis, function createTradingViewZones() {
  const MAX_ZONES = 50;
  const MAX_LEVELS = 50;

  function compactText(value, limit = 160) {
    const text = String(value ?? '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
    return text.length > limit ? `${text.slice(0, limit - 1).trim()}…` : text;
  }

  function pineString(value) {
    return compactText(value).replaceAll('\\', '\\\\').replaceAll('"', '\\"');
  }

  function pineNumber(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return null;
    const fixed = number.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
    return fixed.includes('.') ? fixed : `${fixed}.0`;
  }

  function zoneBounds(zone) {
    const lowValue = zone?.price_low;
    const highValue = zone?.price_high;
    if (lowValue === null || lowValue === undefined || String(lowValue).trim() === '' || highValue === null || highValue === undefined || String(highValue).trim() === '') return null;
    const low = Number(lowValue);
    const high = Number(highValue);
    if (!Number.isFinite(low) || !Number.isFinite(high) || low === high) return null;
    return { bottom: Math.min(low, high), top: Math.max(low, high) };
  }

  function zoneName(zone, index) {
    return compactText(zone?.name || `Zóna ${index + 1}`, 80);
  }

  // Vlastní barva (reference, hodnoty profilu) projde jen v přesném tvaru color.rgb(r, g, b).
  function customColor(value) {
    const text = String(value ?? '').trim();
    return /^color\.rgb\(\d{1,3}, \d{1,3}, \d{1,3}\)$/.test(text) ? text : null;
  }

  function zoneColor(direction) {
    if (direction === 'long') return 'color.rgb(82, 199, 138)';
    if (direction === 'short') return 'color.rgb(238, 124, 124)';
    return 'color.rgb(45, 179, 166)';
  }

  function zoneBlock(item, index, options) {
    const number = index + 1;
    const variable = `zone${number}`;
    const name = zoneName(item.zone, item.originalIndex);
    const source = compactText(item.zone.source, 110);
    const label = options.includeSource && source ? `${name} | ${source}` : name;
    const group = `${number} · ${name}`;
    const top = pineNumber(item.bounds.top);
    const bottom = pineNumber(item.bounds.bottom);
    const color = customColor(item.zone.pine_color) || zoneColor(item.zone.direction);
    const extend = options.extendMode === 'both' ? 'extend.both' : 'extend.right';

    return `// ${number}. ${pineString(name)}\n` +
      `${variable}Enabled = input.bool(true, "Zobrazit zónu", group = "${pineString(group)}")\n` +
      `${variable}TopInput = input.float(${top}, "Horní hranice", group = "${pineString(group)}")\n` +
      `${variable}BottomInput = input.float(${bottom}, "Spodní hranice", group = "${pineString(group)}")\n` +
      `${variable}Top = math.max(${variable}TopInput, ${variable}BottomInput)\n` +
      `${variable}Bottom = math.min(${variable}TopInput, ${variable}BottomInput)\n` +
      `${variable}Middle = (${variable}Top + ${variable}Bottom) / 2.0\n` +
      `${variable}Color = ${color}\n` +
      `${variable}Text = "${pineString(label)}"\n` +
      `var box ${variable}Box = na\n` +
      `var label ${variable}Label = na\n` +
      `if barstate.islast\n` +
      `    if ${variable}Enabled\n` +
      `        if na(${variable}Box)\n` +
      `            ${variable}Box := box.new(left = zoneLeft, top = ${variable}Top, right = zoneRight, bottom = ${variable}Bottom, xloc = xloc.bar_index, extend = ${extend}, border_color = ${variable}Color, border_width = 2, bgcolor = color.new(${variable}Color, fillTransparency))\n` +
      `        else\n` +
      `            box.set_left(${variable}Box, zoneLeft)\n` +
      `            box.set_right(${variable}Box, zoneRight)\n` +
      `            box.set_top(${variable}Box, ${variable}Top)\n` +
      `            box.set_bottom(${variable}Box, ${variable}Bottom)\n` +
      `            box.set_extend(${variable}Box, ${extend})\n` +
      `            box.set_border_color(${variable}Box, ${variable}Color)\n` +
      `            box.set_bgcolor(${variable}Box, color.new(${variable}Color, fillTransparency))\n` +
      `        if showLabels\n` +
      `            if na(${variable}Label)\n` +
      `                ${variable}Label := label.new(x = zoneRight, y = ${variable}Middle, text = ${variable}Text, xloc = xloc.bar_index, yloc = yloc.price, style = label.style_label_left, color = color.new(${variable}Color, 12), textcolor = color.white, size = size.small, textalign = text.align_left)\n` +
      `            else\n` +
      `                label.set_xy(${variable}Label, zoneRight, ${variable}Middle)\n` +
      `                label.set_text(${variable}Label, ${variable}Text)\n` +
      `                label.set_style(${variable}Label, label.style_label_left)\n` +
      `                label.set_color(${variable}Label, color.new(${variable}Color, 12))\n` +
      `                label.set_textcolor(${variable}Label, color.white)\n` +
      `                label.set_size(${variable}Label, size.small)\n` +
      `                label.set_textalign(${variable}Label, text.align_left)\n` +
      `        else if not na(${variable}Label)\n` +
      `            label.delete(${variable}Label)\n` +
      `            ${variable}Label := na\n` +
      `    else\n` +
      `        if not na(${variable}Box)\n` +
      `            box.delete(${variable}Box)\n` +
      `            ${variable}Box := na\n` +
      `        if not na(${variable}Label)\n` +
      `            label.delete(${variable}Label)\n` +
      `            ${variable}Label := na\n`;
  }

  function levelPrice(level) {
    const raw = level?.price;
    if (raw === null || raw === undefined || String(raw).trim() === '') return null;
    const price = Number(raw);
    return Number.isFinite(price) ? price : null;
  }

  function levelName(level, index) {
    return compactText(level?.name || `Level ${index + 1}`, 80);
  }

  function levelColor(kind) {
    if (kind === 'support') return 'color.rgb(82, 199, 138)';
    if (kind === 'resistance') return 'color.rgb(238, 124, 124)';
    return 'color.rgb(228, 182, 101)';
  }

  function levelStyle(style) {
    if (style === 'dashed') return 'line.style_dashed';
    if (style === 'dotted') return 'line.style_dotted';
    return 'line.style_solid';
  }

  function levelBlock(item, index, options) {
    const number = index + 1;
    const variable = `level${number}`;
    const name = levelName(item.level, item.originalIndex);
    const source = compactText(item.level.source, 110);
    const label = options.includeSource && source ? `${name} | ${source}` : name;
    const group = `Level ${number} · ${name}`;
    const color = customColor(item.level.pine_color) || levelColor(item.level.kind);
    const style = levelStyle(item.level.line_style);
    const extend = options.extendMode === 'both' ? 'extend.both' : 'extend.right';

    return `// Level ${number}. ${pineString(name)}\n` +
      `${variable}Enabled = input.bool(true, "Zobrazit level", group = "${pineString(group)}")\n` +
      `${variable}Price = input.float(${pineNumber(item.price)}, "Cena", group = "${pineString(group)}")\n` +
      `${variable}Color = ${color}\n` +
      `${variable}Text = "${pineString(label)}"\n` +
      `var line ${variable}Line = na\n` +
      `var label ${variable}Label = na\n` +
      `if barstate.islast\n` +
      `    if ${variable}Enabled\n` +
      `        if na(${variable}Line)\n` +
      `            ${variable}Line := line.new(x1 = zoneLeft, y1 = ${variable}Price, x2 = zoneRight, y2 = ${variable}Price, xloc = xloc.bar_index, extend = ${extend}, color = ${variable}Color, width = 2, style = ${style})\n` +
      `        else\n` +
      `            line.set_xy1(${variable}Line, zoneLeft, ${variable}Price)\n` +
      `            line.set_xy2(${variable}Line, zoneRight, ${variable}Price)\n` +
      `            line.set_extend(${variable}Line, ${extend})\n` +
      `            line.set_color(${variable}Line, ${variable}Color)\n` +
      `            line.set_style(${variable}Line, ${style})\n` +
      `        if showLabels\n` +
      `            if na(${variable}Label)\n` +
      `                ${variable}Label := label.new(x = zoneRight, y = ${variable}Price, text = ${variable}Text, xloc = xloc.bar_index, yloc = yloc.price, style = label.style_label_left, color = color.new(${variable}Color, 12), textcolor = color.white, size = size.small, textalign = text.align_left)\n` +
      `            else\n` +
      `                label.set_xy(${variable}Label, zoneRight, ${variable}Price)\n` +
      `                label.set_text(${variable}Label, ${variable}Text)\n` +
      `                label.set_color(${variable}Label, color.new(${variable}Color, 12))\n` +
      `        else if not na(${variable}Label)\n` +
      `            label.delete(${variable}Label)\n` +
      `            ${variable}Label := na\n` +
      `    else\n` +
      `        if not na(${variable}Line)\n` +
      `            line.delete(${variable}Line)\n` +
      `            ${variable}Line := na\n` +
      `        if not na(${variable}Label)\n` +
      `            label.delete(${variable}Label)\n` +
      `            ${variable}Label := na\n`;
  }

  function generate(zones, settings = {}) {
    const options = {
      market: compactText(settings.market || 'ES', 16),
      date: compactText(settings.date || '', 16),
      showLabels: settings.showLabels !== false,
      includeSource: settings.includeSource === true,
      extendMode: settings.extendMode === 'both' ? 'both' : 'right',
      fillTransparency: Math.max(0, Math.min(100, Math.round(Number(settings.fillTransparency) || 86))),
    };
    const skipped = [];
    const prepared = [];
    (Array.isArray(zones) ? zones : []).forEach((zone, originalIndex) => {
      const bounds = zoneBounds(zone);
      if (!bounds) {
        skipped.push(zoneName(zone, originalIndex));
        return;
      }
      if (prepared.length < MAX_ZONES) prepared.push({ zone, bounds, originalIndex });
      else skipped.push(zoneName(zone, originalIndex));
    });

    const preparedLevels = [];
    (Array.isArray(settings.levels) ? settings.levels : []).forEach((level, originalIndex) => {
      const price = levelPrice(level);
      if (price === null) {
        skipped.push(levelName(level, originalIndex));
        return;
      }
      if (preparedLevels.length < MAX_LEVELS) preparedLevels.push({ level, price, originalIndex });
      else skipped.push(levelName(level, originalIndex));
    });

    if (!prepared.length && !preparedLevels.length) {
      return { code: '', exported: 0, exportedLevels: 0, skipped, maxZones: MAX_ZONES, maxLevels: MAX_LEVELS };
    }

    const title = `Trading Zones — ${options.market}`;
    const meta = options.date ? `// Trh: ${pineString(options.market)} | Náhled: ${pineString(options.date)}` : `// Trh: ${pineString(options.market)}`;
    const maxDrawings = Math.max(20, Math.min(500, prepared.length + preparedLevels.length + 5));
    const header = `//@version=6\n` +
      `indicator("${pineString(title)}", overlay = true, max_boxes_count = ${maxDrawings}, max_labels_count = ${maxDrawings}, max_lines_count = ${maxDrawings})\n\n` +
      `// Vygenerováno v Trading Desk\n${meta}\n` +
      `showLabels = input.bool(${options.showLabels ? 'true' : 'false'}, "Popisky vpravo od ceny", group = "Vzhled")\n` +
      `historyBars = input.int(300, "Délka zóny doleva (bary)", minval = 1, maxval = 500, group = "Vzhled")\n` +
      `labelOffset = input.int(5, "Posun popisku doprava (bary)", minval = 0, maxval = 100, group = "Vzhled")\n` +
      `fillTransparency = input.int(${options.fillTransparency}, "Průhlednost výplně", minval = 0, maxval = 100, group = "Vzhled")\n` +
      `zoneLeft = math.max(0, bar_index - historyBars)\n` +
      `zoneRight = bar_index + labelOffset\n\n`;
    const blocks = prepared.map((item, index) => zoneBlock(item, index, options)).join('\n');
    const levelBlocks = preparedLevels.map((item, index) => levelBlock(item, index, options)).join('\n');
    const body = [blocks, levelBlocks].filter(Boolean).join('\n');

    return {
      code: `${header}${body}`,
      exported: prepared.length,
      exportedLevels: preparedLevels.length,
      skipped,
      maxZones: MAX_ZONES,
      maxLevels: MAX_LEVELS,
    };
  }

  return { generate, zoneBounds, levelPrice, pineNumber, pineString, customColor, MAX_ZONES, MAX_LEVELS };
});
