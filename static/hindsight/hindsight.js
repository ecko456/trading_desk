'use strict';

/*
 * Hindsight – jedna souvislá časová osa 5m svíček ES s náhledy (zóny, bias) a red news.
 * Svíčky přicházejí v UTC; seance a obchodní den počítá time.js (New York), popisky
 * jsou v pražském čase. Graf kreslí Lightweight Charts, vrstvy nad svíčkami vlastní
 * primitiv (Layers). Posun kolečkem, přiblížení i skoky po dnech řídí tenhle soubor,
 * vestavěné ovládání kolečkem je vypnuté.
 */
(function startHindsight() {
  const T = window.HsTime;
  const LWC = window.LightweightCharts;
  if (!T || !LWC) return;

  /** Všechny barvy grafu na jednom místě (rozhraní: :root v hindsight.css). */
  const COLORS = {
    background: '#0E1117',
    grid: '#1A1F2B',
    text: '#7C8496',
    crosshair: '#4B5264',
    up: '#26A69A',
    down: '#EF5350',
    volumeUp: 'rgba(38, 166, 154, 0.32)',
    volumeDown: 'rgba(239, 83, 80, 0.32)',
    sessions: { asia: '#6C7BFF', eu: '#2EC4B6', ny: '#FFB547' },
    sessionAlpha: 0.05,
    sessionLabelAlpha: 0.55,
    news: '#FF4D4F',
    newsAlpha: 0.4,
    zones: { support: '#26A69A', resistance: '#EF5350', vpoc: '#FFB547', other: '#8C96AA' },
    zoneFill: 0.12,
    zoneFillHover: 0.2,
    biasLong: 'rgba(38, 166, 154, 0.045)',
    biasShort: 'rgba(239, 83, 80, 0.045)',
    dayLine: '#2A3142',
    watermark: 'rgba(214, 218, 227, 0.04)',
    roll: '#B18CFF',
    draft: '#FFB547',
    minimapLine: '#5B6478',
    minimapWindow: '#FFB547',
    ideaProfit: 'rgba(38, 166, 154, 0.13)',
    ideaLoss: 'rgba(239, 83, 80, 0.13)',
    ideaBorder: 'rgba(214, 218, 227, 0.42)',
    ideaEntry: 'rgba(214, 218, 227, 0.75)',
    tradeWin: '#26A69A',
    tradeLoss: '#EF5350',
    tradeFlat: '#A3ABBD',
  };
  const FONT = "'Inter', system-ui, sans-serif";
  const MONO = "'JetBrains Mono', ui-monospace, monospace";
  const BAR = 300;
  const TICK = 0.25;
  const ANIM_MS = 260;
  const CHUNK_DAYS = 40;
  const ZONE_TYPES = { support: 'Support', resistance: 'Resistance', vpoc: 'VPOC', other: 'Jiná' };
  const LAYERS = ['sessions', 'news', 'zones', 'bias', 'ideas', 'trades', 'volume'];
  const OUTCOMES = { skipped: 'Nevzatý', missed: 'Propáslý', taken: 'Vzatý' };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const easeOut = t => 1 - Math.pow(1 - t, 3);
  const roundTick = value => Math.round(value / TICK) * TICK;
  const price = value => Number(value).toFixed(2);
  const number = new Intl.NumberFormat('cs-CZ');
  const money = new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 });

  function rgba(hex, alpha) {
    const value = parseInt(hex.slice(1), 16);
    return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
  }

  function czechDate(date) {
    const [y, m, d] = date.split('-').map(Number);
    return `${d}. ${m}. ${y}`;
  }

  async function api(action, options = {}) {
    const query = options.query ? `&${new URLSearchParams(options.query)}` : '';
    const response = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, {
      method: options.method || 'GET',
      headers: options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : undefined,
      body: options.body instanceof FormData ? options.body : options.body ? JSON.stringify(options.body) : undefined,
    });
    const type = response.headers.get('content-type') || '';
    const payload = type.includes('application/json') ? await response.json() : { error: await response.text() };
    if (response.status === 401 && payload.auth) {
      location.replace('./');
      throw new Error(payload.error);
    }
    if (!response.ok) {
      const error = new Error(payload.error || 'Server operaci nedokončil.');
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  /* ------------------------------------------------------------------ stav */

  const state = {
    admin: document.body.dataset.admin === '1',
    range: null,
    firstDate: null,
    lastDate: null,
    loadedFrom: null,
    loadingOlder: false,
    prefs: { layers: Object.fromEntries(LAYERS.map(layer => [layer, true])), snap: false },
    bars: [],
    ts: [],
    days: [],
    dayMap: new Map(),
    hasVolume: false,
    ann: { days: new Map(), zones: [], news: [], ideas: [], trades: [] },
    annRange: null,
    rolls: [],
    zoneMode: false,
    zoneKey: false,
    draft: null,
    hover: null,
    zoneRects: [],
    newsMarks: [],
    ideaKey: null,
    ideaMode: null,
    ideaDraft: null,
    ideaRects: [],
    tradeMarks: [],
    draftGeo: null,
    handleDrag: null,
    ideaClick: null,
    evalCache: new Map(),
    generation: 0,
  };

  /* ------------------------------------------------------------------ graf */

  const chartEl = $('#hsChart');
  const chart = LWC.createChart(chartEl, {
    autoSize: true,
    layout: {
      background: { type: 'solid', color: COLORS.background },
      textColor: COLORS.text,
      fontFamily: FONT,
      fontSize: 11,
      // Logo knihovny si vkládá vlastní <style>, který CSP nepustí; odkaz na TradingView je pod grafem.
      attributionLogo: false,
    },
    grid: { vertLines: { color: COLORS.grid }, horzLines: { color: COLORS.grid } },
    crosshair: {
      mode: 0,
      vertLine: { color: COLORS.crosshair, labelBackgroundColor: '#262D3D' },
      horzLine: { color: COLORS.crosshair, labelBackgroundColor: '#262D3D' },
    },
    rightPriceScale: { borderColor: COLORS.grid, scaleMargins: { top: 0.08, bottom: 0.12 } },
    timeScale: {
      borderColor: COLORS.grid,
      timeVisible: true,
      secondsVisible: false,
      rightOffset: 6,
      barSpacing: 6,
      minBarSpacing: 0.08,
      tickMarkFormatter: (time, type) => {
        // Značku dne dává knihovna svíčce po půlnoci UTC (v Praze 01:00/02:00) a po víkendu
        // i první svíčce týdne (pražská půlnoc). Datum se píše jednou za den.
        const parts = T.pragueParts(time);
        const date = `${parts.day}. ${parts.month}.`;
        if (type <= 1) return date;
        if (type === 2) {
          const midnight = time - parts.minutes * 60;
          const index = parts.minutes ? barAtOrBefore(midnight) : -1;
          const midnightTicked = index > 0 && state.ts[index] === midnight && Math.floor(state.ts[index - 1] / 86400) !== Math.floor(midnight / 86400);
          return midnightTicked ? T.pragueTime(time) : date;
        }
        return T.pragueTime(time);
      },
    },
    localization: {
      locale: 'cs-CZ',
      timeFormatter: time => {
        const parts = T.pragueParts(time);
        return `${T.WEEKDAYS[parts.weekday]} ${parts.day}. ${parts.month}. ${T.pragueTime(time)}`;
      },
      priceFormatter: value => price(value),
    },
    handleScroll: { mouseWheel: false, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
    handleScale: { mouseWheel: false, pinch: true, axisPressedMouseMove: { time: true, price: true }, axisDoubleClickReset: true },
    kineticScroll: { mouse: true, touch: true },
  });

  const candles = chart.addSeries(LWC.CandlestickSeries, {
    upColor: COLORS.up,
    downColor: COLORS.down,
    borderUpColor: COLORS.up,
    borderDownColor: COLORS.down,
    wickUpColor: COLORS.up,
    wickDownColor: COLORS.down,
    priceFormat: { type: 'price', precision: 2, minMove: TICK },
    priceLineVisible: false,
  });
  const volume = chart.addSeries(LWC.HistogramSeries, {
    priceScaleId: 'volume',
    priceFormat: { type: 'volume' },
    lastValueVisible: false,
    priceLineVisible: false,
  });
  chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.84, bottom: 0 }, visible: false });

  /* ------------------------------------------------------------------ čas → souřadnice */

  /** Poslední svíčka, která začala nejpozději v čase t (-1 když žádná). */
  function barAtOrBefore(t) {
    const list = state.ts;
    let low = 0;
    let high = list.length - 1;
    let found = -1;
    while (low <= high) {
      const middle = (low + high) >> 1;
      if (list[middle] <= t) {
        found = middle;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    return found;
  }

  /** Logická pozice levého okraje okamžiku t (mezery o víkendu a v přestávce se smrsknou). */
  function edgeOf(t) {
    const list = state.ts;
    if (!list.length) return 0;
    const index = barAtOrBefore(t);
    if (index < 0) return -0.5 - (list[0] - t) / BAR;
    const passed = (t - list[index]) / BAR;
    return index - 0.5 + (index === list.length - 1 ? passed : Math.min(1, passed));
  }

  /** Logická pozice okamžiku uvnitř svíčky (news, obchody). */
  function pointOf(t) {
    return edgeOf(t) + 0.5;
  }

  const frame = { x0: 0, spacing: 1, ok: false };
  function updateFrame() {
    const scale = chart.timeScale();
    const a = scale.logicalToCoordinate(0);
    const b = scale.logicalToCoordinate(1);
    frame.ok = a !== null && b !== null;
    if (frame.ok) {
      frame.x0 = a;
      frame.spacing = b - a;
    }
  }
  const xOf = logical => frame.x0 + logical * frame.spacing;
  const logicalAt = x => (x - frame.x0) / frame.spacing;

  /* ------------------------------------------------------------------ dny */

  function buildDays() {
    const days = [];
    const map = new Map();
    let current = null;
    let nextStart = -Infinity;
    const bars = state.bars;
    for (let index = 0; index < bars.length; index++) {
      const bar = bars[index];
      if (bar.time >= nextStart || current === null) {
        const date = T.tradeDate(bar.time);
        if (!current || current.date !== date) {
          const bounds = T.dayBounds(date);
          const sessions = T.sessions(date);
          const ny = sessions.find(session => session.key === 'ny');
          current = {
            date,
            start: bounds.start,
            next: T.nyToUtc(date, 18 * 60),
            first: index,
            last: index,
            sessions,
            rthStart: ny.start,
            rthEnd: ny.end,
            rthOpen: null,
            rthClose: null,
            rthLast: null,
            high: -Infinity,
            low: Infinity,
            close: bar.close,
          };
          days.push(current);
          map.set(date, current);
        }
        nextStart = current.next;
      }
      current.last = index;
      current.high = Math.max(current.high, bar.high);
      current.low = Math.min(current.low, bar.low);
      current.close = bar.close;
      if (bar.time >= current.rthStart && bar.time < current.rthEnd) {
        if (current.rthOpen === null) current.rthOpen = bar.open;
        current.rthClose = bar.close;
        current.rthLast = bar.time;
      }
    }
    days.forEach((day, position) => { day.index = position; });
    state.days = days;
    state.dayMap = map;
  }

  /** Den, do kterého patří svíčka s indexem. */
  function dayOfIndex(index) {
    const days = state.days;
    let low = 0;
    let high = days.length - 1;
    let found = 0;
    while (low <= high) {
      const middle = (low + high) >> 1;
      if (days[middle].first <= index) {
        found = middle;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    return days[found] || null;
  }

  /** Vyhodnocení biasu: RTH close proti RTH open (jen celý RTH den). */
  /** Platný bias dne: verze z otevření NY (po zamčení), jinak aktuální. */
  function officialBias(info) {
    if (!info) return '';
    return info.bias_official ?? info.bias ?? '';
  }

  /** Zóny a bias se zamykají při otevření NY (9:30 New York). */
  function lockTs(date) {
    return T.nyToUtc(date, 9 * 60 + 30);
  }

  function dayLocked(date) {
    return Date.now() / 1000 >= lockTs(date);
  }

  function backfillActive() {
    return state.admin && Boolean(state.prefs.backfill);
  }

  const BIAS_WORDS = { long: 'long', short: 'short', neutral: 'neutral', '': 'nezadaný' };

  function lockNote(date, what, plural = false) {
    const at = T.pragueTime(lockTs(date));
    if (!dayLocked(date)) return `${what} se ${plural ? 'zamknou' : 'zamkne'} při otevření NY v ${at}.`;
    if (backfillActive()) return 'Zpětné doplňování je zapnuté: změna se vezme, jako by byla před otevřením.';
    return `Den je zamčený od otevření NY (${at}). Změna se uloží jako dodatečná verze, vyhodnocení použije verzi z otevření.`;
  }

  function verdict(day, info) {
    const bias = officialBias(info);
    if (!day || (bias !== 'long' && bias !== 'short')) return null;
    if (day.rthOpen === null || day.rthLast === null || day.rthLast < day.rthEnd - BAR) return null;
    const move = day.rthClose - day.rthOpen;
    if (move === 0) return false;
    return bias === 'long' ? move > 0 : move < 0;
  }

  /**
   * Potenciální obchod proti svíčkám: od času vstupu (bez času od začátku dne) čeká na dotek
   * vstupní ceny, pak rozhodne, co přišlo dřív, stop, nebo cíl. Svíčka, která zasáhne obojí,
   * se počítá jako stop (horší případ). Bez stopu i cíle se počítá k poslední svíčce dne.
   */
  function evaluateIdea(idea) {
    const key = `${idea.id}|${idea.date}|${idea.direction}|${idea.entry}|${idea.stop}|${idea.target}|${idea.entry_ts}|${state.ts.length}`;
    if (!state.evalCache.has(key)) {
      if (state.evalCache.size > 800) state.evalCache.clear();
      state.evalCache.set(key, computeIdea(idea));
    }
    return state.evalCache.get(key);
  }

  function computeIdea(idea) {
    const day = state.dayMap.get(idea.date);
    if (!day) return { state: 'nodata' };
    const bars = state.bars;
    const long = idea.direction === 'long';
    const risk = Math.abs(idea.entry - idea.stop);
    let start = day.first;
    if (idea.entry_ts !== null && idea.entry_ts !== undefined) {
      const index = barAtOrBefore(idea.entry_ts);
      if (index > day.last) return { state: 'nodata' };
      start = Math.max(day.first, index);
    }
    let fill = -1;
    for (let k = start; k <= day.last; k++) {
      if (bars[k].low <= idea.entry && bars[k].high >= idea.entry) {
        fill = k;
        break;
      }
    }
    if (fill < 0) return { state: 'unfilled', start };
    const hitsStop = bar => (long ? bar.low <= idea.stop : bar.high >= idea.stop);
    const hitsTarget = bar => (long ? bar.high >= idea.target : bar.low <= idea.target);
    const finish = (name, end, points, ambiguous = false) => ({ state: name, start, fill, end, points, r: risk > 0 ? points / risk : 0, ambiguous });
    if (hitsStop(bars[fill])) return finish('sl', fill, -risk, true);
    for (let k = fill + 1; k <= day.last; k++) {
      const stop = hitsStop(bars[k]);
      if (stop) return finish('sl', k, -risk, hitsTarget(bars[k]));
      if (hitsTarget(bars[k])) return finish('tp', k, Math.abs(idea.target - idea.entry));
    }
    const close = bars[day.last].close;
    return finish('open', day.last, long ? close - idea.entry : idea.entry - close);
  }

  function signed(value, digits = 1) {
    return `${value >= 0 ? '+' : '−'}${Math.abs(value).toFixed(digits)}`;
  }

  function resultText(result) {
    if (!result || result.state === 'nodata') return 'bez svíček';
    if (result.state === 'unfilled') return 'vstup nezasažen';
    if (result.state === 'tp') return `TP ${signed(result.r)}R`;
    if (result.state === 'sl') return `SL ${signed(result.r)}R${result.ambiguous ? '?' : ''}`;
    return `konec dne ${signed(result.r)}R`;
  }

  /* ------------------------------------------------------------------ vrstvy v grafu */

  function visibleDays(width) {
    if (!frame.ok || !state.days.length) return [];
    const fromIndex = Math.floor(logicalAt(0)) - 1;
    const toIndex = Math.ceil(logicalAt(width)) + 1;
    return state.days.filter(day => day.last >= fromIndex && day.first <= toIndex);
  }

  function withMedia(target, draw) {
    target.useBitmapCoordinateSpace(scope => {
      const ctx = scope.context;
      ctx.save();
      ctx.scale(scope.horizontalPixelRatio, scope.verticalPixelRatio);
      draw(ctx, scope.mediaSize.width, scope.mediaSize.height);
      ctx.restore();
    });
  }

  function zoneEndDate(zone) {
    if (zone.valid_to === 'open') return null;
    return zone.valid_to || zone.valid_from;
  }

  const backgroundRenderer = {
    draw(target) {
      withMedia(target, (ctx, width, height) => {
        const layers = state.prefs.layers;
        const days = visibleDays(width);
        for (const day of days) {
          const x0 = xOf(edgeOf(day.start));
          const x1 = xOf(edgeOf(day.next));
          const info = state.ann.days.get(day.date);
          const tint = officialBias(info);
          if (layers.bias && (tint === 'long' || tint === 'short')) {
            ctx.fillStyle = tint === 'long' ? COLORS.biasLong : COLORS.biasShort;
            ctx.fillRect(x0, 0, x1 - x0, height);
          }
          const dayWidth = x1 - x0;
          if (layers.sessions && dayWidth >= 60) {
            for (const session of day.sessions) {
              const color = COLORS.sessions[session.key];
              const s0 = xOf(edgeOf(session.start));
              const s1 = xOf(edgeOf(session.end));
              if (s1 <= 0 || s0 >= width || s1 - s0 < 1) continue;
              ctx.fillStyle = rgba(color, COLORS.sessionAlpha);
              ctx.fillRect(s0, 0, s1 - s0, height);
              const label = `${session.label} ${T.pragueTime(session.start)}`;
              ctx.font = `500 10px ${FONT}`;
              if (Math.min(s1, width) - Math.max(s0, 0) > ctx.measureText(label).width + 12) {
                ctx.fillStyle = rgba(color, COLORS.sessionLabelAlpha);
                ctx.textBaseline = 'bottom';
                ctx.fillText(label, Math.max(s0, 0) + 5, height - 5);
              }
            }
          }
          // Hranice dne a vodoznak s datem.
          if (x0 > 0 && x0 < width && dayWidth >= 10) {
            ctx.fillStyle = COLORS.dayLine;
            ctx.fillRect(Math.round(x0), 0, 1, height);
          }
          if (dayWidth > 150) {
            const size = clamp(dayWidth / 9, 18, 44);
            ctx.font = `600 ${size}px ${FONT}`;
            ctx.fillStyle = COLORS.watermark;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(T.dateLabel(day.date), (x0 + x1) / 2, height * 0.52);
            ctx.textAlign = 'left';
          }
        }
        if (layers.zones) drawZones(ctx, width, height);
        state.ideaRects = [];
        if (layers.ideas) drawIdeas(ctx, width);
      });
    },
  };

  function drawZones(ctx, width) {
    state.zoneRects = [];
    if (!state.days.length) return;
    const lastEdge = xOf(state.ts.length - 0.5);
    for (const zone of state.ann.zones) {
      const start = T.dayBounds(zone.valid_from).start;
      const endDate = zoneEndDate(zone);
      const x0 = xOf(edgeOf(start));
      const x1 = endDate === null ? Math.max(width, lastEdge) : xOf(edgeOf(T.nyToUtc(endDate, 18 * 60)));
      if (x1 <= 0 || x0 >= width || x1 - x0 < 1) continue;
      const yTop = candles.priceToCoordinate(zone.price_high);
      const yBottom = candles.priceToCoordinate(zone.price_low);
      if (yTop === null || yBottom === null) continue;
      const top = Math.min(yTop, yBottom);
      const bottom = Math.max(yTop, yBottom);
      const h = Math.max(2, bottom - top);
      const color = COLORS.zones[zone.type] || COLORS.zones.other;
      const hovered = state.hover && state.hover.kind === 'zone' && state.hover.zone.id === zone.id;
      if (zone.removed || zone.later) {
        // Dodatečná zóna: slabší výplň a tečkovaný okraj; odstraněná po otevření jen obrys.
        if (zone.later) {
          ctx.fillStyle = rgba(color, hovered ? COLORS.zoneFill : COLORS.zoneFill * 0.5);
          ctx.fillRect(x0, top, x1 - x0, h);
        }
        ctx.strokeStyle = rgba(color, zone.removed ? 0.45 : 0.85);
        ctx.lineWidth = 1;
        ctx.setLineDash(zone.removed ? [6, 4] : [2, 3]);
        ctx.strokeRect(Math.round(x0) + 0.5, Math.round(top) + 0.5, Math.round(x1 - x0), Math.round(h));
        ctx.setLineDash([]);
      } else {
        ctx.fillStyle = rgba(color, hovered ? COLORS.zoneFillHover : COLORS.zoneFill);
        ctx.fillRect(x0, top, x1 - x0, h);
        if (x0 >= -3) {
          ctx.fillStyle = color;
          ctx.fillRect(x0, top, 3, h);
        }
      }
      const suffix = zone.removed ? ' · odstraněna po otevření' : zone.later ? ' · dodatečně' : '';
      const label = `${zone.name || ZONE_TYPES[zone.type] || 'Zóna'}  ${price(zone.price_low)}–${price(zone.price_high)}${suffix}`;
      ctx.font = `500 11px ${FONT}`;
      ctx.fillStyle = rgba(color, zone.removed ? 0.5 : 0.95);
      ctx.textBaseline = h >= 16 ? 'top' : 'bottom';
      ctx.fillText(label, Math.max(x0, 0) + 8, h >= 16 ? top + 3 : top - 2);
      state.zoneRects.push({ zone, x0, x1, top, bottom: top + h });
    }
  }

  function ideaGeometry(idea) {
    const day = state.dayMap.get(idea.date);
    if (!day) return null;
    const result = evaluateIdea(idea);
    const x0 = idea.entry_ts !== null && idea.entry_ts !== undefined ? xOf(pointOf(idea.entry_ts)) : xOf(edgeOf(day.start));
    let x1 = result.state === 'tp' || result.state === 'sl' ? xOf(result.end + 0.5) : xOf(edgeOf(day.next));
    if (x1 - x0 < 18) x1 = x0 + 18;
    const yE = candles.priceToCoordinate(idea.entry);
    const yS = candles.priceToCoordinate(idea.stop);
    const yT = candles.priceToCoordinate(idea.target);
    if (yE === null || yS === null || yT === null) return null;
    return { x0, x1, yE, yS, yT, result, top: Math.min(yE, yS, yT), bottom: Math.max(yE, yS, yT) };
  }

  /** Box pozice: zisková část k cíli, ztrátová ke stopu, čárkovaný okraj, čára vstupu. */
  function paintIdea(ctx, idea, geo, active, width) {
    const { x0, x1, yE, yS, yT, top, bottom, result } = geo;
    const w = x1 - x0;
    ctx.fillStyle = COLORS.ideaProfit;
    ctx.fillRect(x0, Math.min(yE, yT), w, Math.abs(yT - yE));
    ctx.fillStyle = COLORS.ideaLoss;
    ctx.fillRect(x0, Math.min(yE, yS), w, Math.abs(yS - yE));
    ctx.strokeStyle = active ? COLORS.draft : COLORS.ideaBorder;
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 3]);
    ctx.strokeRect(Math.round(x0) + 0.5, Math.round(top) + 0.5, Math.round(w), Math.round(bottom - top));
    ctx.setLineDash([]);
    ctx.fillStyle = COLORS.ideaEntry;
    ctx.fillRect(x0, Math.round(yE), w, 1);
    if (result.fill >= 0 && result.fill !== undefined) {
      ctx.beginPath();
      ctx.arc(xOf(result.fill), yE, 3, 0, Math.PI * 2);
      ctx.fill();
    }
    if (result.state === 'tp' || result.state === 'sl') {
      ctx.fillStyle = result.state === 'tp' ? COLORS.up : COLORS.down;
      ctx.beginPath();
      ctx.arc(xOf(result.end), result.state === 'tp' ? yT : yS, 3.5, 0, Math.PI * 2);
      ctx.fill();
    }
    const rr = Math.abs(idea.target - idea.entry) / Math.abs(idea.entry - idea.stop);
    const outcome = OUTCOMES[idea.outcome] ? ` · ${OUTCOMES[idea.outcome].toLowerCase()}` : '';
    const label = `${idea.direction === 'long' ? 'L' : 'S'}${idea.name ? ` ${idea.name}` : ''} · ${rr.toFixed(1)}R · ${resultText(result)}${outcome}`;
    ctx.font = `500 10.5px ${FONT}`;
    ctx.textBaseline = 'bottom';
    ctx.fillStyle = result.state === 'tp' ? COLORS.up : result.state === 'sl' ? COLORS.down : '#A3ABBD';
    ctx.fillText(label, clamp(x0, 0, Math.max(0, width - ctx.measureText(label).width - 4)) + 3, top - 3);
  }

  function drawIdeas(ctx, width) {
    for (const idea of state.ann.ideas) {
      if (state.ideaDraft && state.ideaDraft.id === idea.id) continue;
      const geo = ideaGeometry(idea);
      if (!geo || geo.x1 < 0 || geo.x0 > width) continue;
      paintIdea(ctx, idea, geo, false, width);
      state.ideaRects.push({ idea, x0: geo.x0, x1: geo.x1, top: geo.top, bottom: geo.bottom });
    }
  }

  /** Rozpracovaný potenciální obchod: táhla stopu, vstupu a cíle vpravo. */
  function drawIdeaDraft(ctx, width) {
    state.draftGeo = null;
    const idea = state.ideaDraft;
    if (!idea) return;
    const geo = ideaGeometry(idea);
    if (!geo) return;
    paintIdea(ctx, idea, geo, true, width);
    geo.hx = clamp(geo.x1, 8, width - 8);
    state.draftGeo = geo;
    const risk = Math.abs(idea.entry - idea.stop);
    const reward = Math.abs(idea.target - idea.entry);
    const lines = [
      [geo.yT, COLORS.up, `TP ${price(idea.target)}  ${reward.toFixed(2)} b. · ${(reward / risk).toFixed(1)}R`],
      [geo.yE, COLORS.draft, `Vstup ${price(idea.entry)}`],
      [geo.yS, COLORS.down, `SL ${price(idea.stop)}  ${risk.toFixed(2)} b.`],
    ];
    ctx.font = `500 10.5px ${MONO}`;
    ctx.textBaseline = 'middle';
    for (const [y, color, text] of lines) {
      ctx.fillStyle = color;
      ctx.fillRect(Math.round(geo.hx) - 5, Math.round(y) - 5, 10, 10);
      const textWidth = ctx.measureText(text).width;
      const tx = geo.hx + 10 + textWidth > width ? geo.hx - 12 - textWidth : geo.hx + 10;
      ctx.fillStyle = 'rgba(14, 17, 23, 0.8)';
      ctx.fillRect(tx - 3, y - 8, textWidth + 6, 16);
      ctx.fillStyle = color;
      ctx.fillText(text, tx, y);
    }
  }

  function tradeColor(trade) {
    let value = trade.result_r ?? trade.result_usd;
    if (value === null || value === undefined) {
      value = trade.exit !== null && trade.entry !== null ? (trade.direction === 'long' ? trade.exit - trade.entry : trade.entry - trade.exit) : 0;
    }
    return value > 0 ? COLORS.tradeWin : value < 0 ? COLORS.tradeLoss : COLORS.tradeFlat;
  }

  /** Šipka se špičkou přesně na ceně: nahoru = nákup, dolů = prodej. */
  function arrow(ctx, x, y, up, color) {
    ctx.fillStyle = color;
    ctx.strokeStyle = COLORS.background;
    ctx.lineWidth = 1;
    ctx.beginPath();
    if (up) {
      ctx.moveTo(x, y);
      ctx.lineTo(x - 6, y + 10);
      ctx.lineTo(x + 6, y + 10);
    } else {
      ctx.moveTo(x, y);
      ctx.lineTo(x - 6, y - 10);
      ctx.lineTo(x + 6, y - 10);
    }
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
  }

  function tradeResultLabel(trade) {
    if (trade.result_r !== null && trade.result_r !== undefined) return `${signed(trade.result_r, 2)}R`;
    if (trade.result_usd !== null && trade.result_usd !== undefined) return `${signed(trade.result_usd, 0)} $`;
    return '';
  }

  function drawTrades(ctx, width) {
    for (const trade of state.ann.trades) {
      if (trade.entry_ts === null || trade.entry === null) continue;
      const long = trade.direction === 'long';
      const xE = xOf(pointOf(trade.entry_ts));
      const yE = candles.priceToCoordinate(trade.entry);
      const hasExit = trade.exit_ts !== null && trade.exit !== null;
      const xX = hasExit ? xOf(pointOf(trade.exit_ts)) : null;
      const yX = hasExit ? candles.priceToCoordinate(trade.exit) : null;
      if (yE === null || Math.max(xE, xX ?? xE) < -20 || Math.min(xE, xX ?? xE) > width + 20) continue;
      const color = tradeColor(trade);
      if (hasExit && yX !== null) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.5;
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(xE, yE);
        ctx.lineTo(xX, yX);
        ctx.stroke();
        ctx.setLineDash([]);
        arrow(ctx, xX, yX, !long, color);
        const label = tradeResultLabel(trade);
        if (label) {
          ctx.font = `600 10.5px ${MONO}`;
          ctx.textBaseline = 'middle';
          ctx.fillStyle = color;
          ctx.fillText(label, xX + 9, long ? yX - 5 : yX + 5);
        }
      }
      arrow(ctx, xE, yE, long, color);
      state.tradeMarks.push({ trade, xE, yE, xX, yX });
    }
  }

  const foregroundRenderer = {
    draw(target) {
      withMedia(target, (ctx, width, height) => {
        state.newsMarks = [];
        // Roll: přechod na další kontrakt.
        for (const roll of state.rolls) {
          const x = Math.round(xOf(edgeOf(roll.ts)));
          if (x < 0 || x > width) continue;
          ctx.strokeStyle = COLORS.roll;
          ctx.globalAlpha = 0.7;
          ctx.setLineDash([2, 3]);
          ctx.beginPath();
          ctx.moveTo(x + 0.5, 0);
          ctx.lineTo(x + 0.5, height);
          ctx.stroke();
          ctx.setLineDash([]);
          ctx.globalAlpha = 1;
          ctx.font = `600 10.5px ${MONO}`;
          ctx.fillStyle = COLORS.roll;
          ctx.textBaseline = 'top';
          ctx.fillText(`ROLL ${roll.from} → ${roll.to}`, x + 6, 30);
        }
        if (state.prefs.layers.news) {
          for (const item of state.ann.news) {
            const x = Math.round(xOf(pointOf(item.ts)));
            if (x < -4 || x > width + 4) continue;
            ctx.strokeStyle = rgba(COLORS.news, COLORS.newsAlpha);
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 4]);
            ctx.beginPath();
            ctx.moveTo(x + 0.5, 22);
            ctx.lineTo(x + 0.5, height);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = COLORS.news;
            ctx.beginPath();
            ctx.arc(x + 0.5, 22, 3.5, 0, Math.PI * 2);
            ctx.fill();
            state.newsMarks.push({ item, x });
          }
        }
        state.tradeMarks = [];
        if (state.prefs.layers.trades) drawTrades(ctx, width);
        drawIdeaDraft(ctx, width);
        if (state.draft) {
          const d = state.draft;
          const left = Math.min(d.x0, d.x1);
          const right = Math.max(d.x0, d.x1);
          const top = Math.min(d.y0, d.y1);
          const bottom = Math.max(d.y0, d.y1);
          ctx.fillStyle = rgba(COLORS.draft, 0.12);
          ctx.fillRect(left, top, right - left, bottom - top);
          ctx.strokeStyle = COLORS.draft;
          ctx.setLineDash([5, 4]);
          ctx.strokeRect(left + 0.5, top + 0.5, right - left, bottom - top);
          ctx.setLineDash([]);
          const low = candles.coordinateToPrice(bottom);
          const high = candles.coordinateToPrice(top);
          if (low !== null && high !== null) {
            ctx.font = `500 11px ${MONO}`;
            ctx.fillStyle = COLORS.draft;
            ctx.textBaseline = 'bottom';
            ctx.fillText(`${price(roundTick(low))} – ${price(roundTick(high))}`, left + 6, top - 4);
          }
        }
      });
    },
  };

  const layers = {
    requestUpdate: () => {},
    attached({ requestUpdate }) { this.requestUpdate = requestUpdate; },
    detached() { this.requestUpdate = () => {}; },
    updateAllViews() { updateFrame(); },
    paneViews() { return this.views; },
    views: [
      { zOrder: () => 'bottom', renderer: () => backgroundRenderer },
      { zOrder: () => 'top', renderer: () => foregroundRenderer },
    ],
  };
  candles.attachPrimitive(layers);
  const redraw = () => layers.requestUpdate();

  /* ------------------------------------------------------------------ data */

  function toBars(rows) {
    return rows.map(row => ({ time: row[0], open: row[1], high: row[2], low: row[3], close: row[4], volume: row[5] }));
  }

  function applyBars(keepView) {
    const before = keepView ? chart.timeScale().getVisibleLogicalRange() : null;
    const centerTime = before ? state.ts[clamp(Math.round((before.from + before.to) / 2), 0, state.ts.length - 1)] : null;
    const oldCenterIndex = before ? clamp(Math.round((before.from + before.to) / 2), 0, state.ts.length - 1) : 0;
    state.ts = state.bars.map(bar => bar.time);
    state.hasVolume = state.bars.some(bar => bar.volume > 0);
    candles.setData(state.bars.map(bar => ({ time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close })));
    volume.setData(state.hasVolume ? state.bars.map(bar => ({ time: bar.time, value: bar.volume, color: bar.close >= bar.open ? COLORS.volumeUp : COLORS.volumeDown })) : []);
    buildDays();
    state.evalCache.clear();
    if (before && centerTime !== null && centerTime !== undefined) {
      // Starší svíčky se přidaly zleva: stejný výřez musí zůstat na místě.
      const shift = barAtOrBefore(centerTime) - oldCenterIndex;
      const now = chart.timeScale().getVisibleLogicalRange();
      const nowCenter = now ? Math.round((now.from + now.to) / 2) : null;
      const expectedCenter = oldCenterIndex + shift;
      if (now && nowCenter !== expectedCenter) {
        chart.timeScale().setVisibleLogicalRange({ from: before.from + shift, to: before.to + shift });
      }
    }
    syncVolumeChip();
    redraw();
  }

  function computeRolls() {
    const contracts = (state.range?.contracts || []).filter(contract => !contract.demo && contract.bars > 0 && contract.from_date);
    state.rolls = [];
    for (let index = 1; index < contracts.length; index++) {
      state.rolls.push({ ts: T.dayBounds(contracts[index].from_date).start, from: contracts[index - 1].contract, to: contracts[index].contract });
    }
  }

  async function loadAnnotations() {
    if (!state.firstDate) return;
    let from = state.firstDate;
    const limit = T.addDays(state.lastDate, -395);
    if (from < limit) from = limit;
    const data = await api('hindsight_annotations', { query: { from, to: state.lastDate } });
    state.annRange = { from, to: state.lastDate };
    state.ann = {
      days: new Map(data.days.map(day => [day.date, day])),
      zones: data.zones,
      news: data.news,
      ideas: data.ideas || [],
      trades: data.trades || [],
    };
    state.evalCache.clear();
    redraw();
    renderHeaders();
    renderMinimap();
  }

  async function loadOlder() {
    if (state.loadingOlder || !state.loadedFrom || state.loadedFrom <= state.firstDate) return false;
    state.loadingOlder = true;
    const generation = state.generation;
    try {
      const to = T.addDays(state.loadedFrom, -1);
      let from = T.addDays(to, -CHUNK_DAYS + 1);
      if (from < state.firstDate) from = state.firstDate;
      const data = await api('hindsight_bars', { query: { from, to } });
      if (generation !== state.generation) return false;
      const older = toBars(data.bars).filter(bar => !state.ts.length || bar.time < state.ts[0]);
      state.loadedFrom = from;
      if (older.length) {
        state.bars = older.concat(state.bars);
        applyBars(true);
      }
      renderHeaders();
      renderMinimap();
      return true;
    } finally {
      state.loadingOlder = false;
    }
  }

  /** Postupně dočte celý rok na pozadí, první obrazovka je tak hned. */
  async function loadRest() {
    const generation = state.generation;
    while (generation === state.generation && state.loadedFrom > state.firstDate) {
      try {
        const loaded = await loadOlder();
        if (!loaded) await new Promise(resolve => setTimeout(resolve, 120));
      } catch (error) {
        showToast(error.message);
        return;
      }
      await new Promise(resolve => setTimeout(resolve, 30));
    }
  }

  async function ensureLoaded(date) {
    while (state.loadedFrom && date < state.loadedFrom && state.loadedFrom > state.firstDate) {
      $('#hsLoading').hidden = false;
      const loaded = await loadOlder();
      if (!loaded) await new Promise(resolve => setTimeout(resolve, 80));
    }
    $('#hsLoading').hidden = true;
  }

  async function load() {
    state.generation += 1;
    closePop();
    $('#hsLoading').hidden = false;
    const range = await api('hindsight_range');
    state.range = range;
    state.prefs = range.prefs || state.prefs;
    syncLayerButtons();
    computeRolls();
    renderContracts();
    if (!range.first_ts) {
      state.bars = [];
      state.firstDate = state.lastDate = state.loadedFrom = null;
      applyBars(false);
      showEmpty();
      $('#hsLoading').hidden = true;
      renderHeaders();
      renderMinimap();
      return;
    }
    $('#hsEmpty').hidden = true;
    state.firstDate = T.tradeDate(range.first_ts);
    state.lastDate = T.tradeDate(range.last_ts);
    let from = T.addDays(state.lastDate, -CHUNK_DAYS + 1);
    if (from < state.firstDate) from = state.firstDate;
    const [bars] = await Promise.all([
      api('hindsight_bars', { query: { from, to: state.lastDate } }),
      loadAnnotations(),
    ]);
    state.loadedFrom = from;
    state.bars = toBars(bars.bars);
    applyBars(false);
    $('#hsLoading').hidden = true;
    const instrument = range.demo ? 'ES · 5m · ukázková data' : 'ES · 5m';
    $('#hsInstrument').textContent = instrument;
    $('#hsInstrument').dataset.base = instrument;
    const date = $('#hsDate');
    date.min = state.firstDate;
    date.max = state.lastDate;
    const lastDay = state.days[state.days.length - 1];
    if (lastDay) {
      if (state.prefs.snap) {
        setRange(dayRange(lastDay));
      } else {
        const width = Math.max(120, (lastDay.last - lastDay.first + 1) * 1.35);
        setRange({ from: state.ts.length - 1 + 6 - width, to: state.ts.length - 1 + 6 });
      }
    }
    renderLegend(state.bars[state.bars.length - 1]);
    renderHeaders();
    renderMinimap();
    loadRest();
  }

  function showEmpty() {
    const empty = $('#hsEmpty');
    empty.hidden = false;
    empty.innerHTML = state.admin
      ? `<h2>Zatím tu nejsou žádné svíčky</h2>
         <p>Nahraj export 5m svíček ES z ATAS. Při importu vybereš kontrakt a uloží se jen jeho období, takže data sedí přesně na ceny kontraktu.</p>
         <div class="hs-pop-actions"><button type="button" class="hs-btn" data-empty="demo">Vyzkoušet na ukázkových datech</button><button type="button" class="hs-btn hs-primary" data-empty="import">Nahrát export z ATAS</button></div>`
      : `<h2>Zatím tu nejsou žádné svíčky</h2><p>Svíčky ES nahrává správce Trading Desku. Až je nahraje, uvidíš tu svoje zóny, bias a news na jedné ose.</p>`;
  }

  /* ------------------------------------------------------------------ pohyb v čase */

  let animation = 0;
  let velocity = 0;
  let inertiaFrame = 0;

  const getRange = () => chart.timeScale().getVisibleLogicalRange();

  function limits(range) {
    const count = state.ts.length;
    const width = range.to - range.from;
    const slack = Math.min(width * 0.4, 90);
    const minFrom = -slack;
    const maxTo = count - 1 + slack;
    // Oddálení přes všechna data: vidět je všechno, nic navíc.
    if (width >= maxTo - minFrom) return { from: minFrom, to: maxTo };
    let { from, to } = range;
    if (from < minFrom) {
      to += minFrom - from;
      from = minFrom;
    }
    if (to > maxTo) {
      from -= to - maxTo;
      to = maxTo;
    }
    return { from, to };
  }

  function setRange(range) {
    if (!state.ts.length) return;
    chart.timeScale().setVisibleLogicalRange(limits(range));
  }

  function stopMotion() {
    if (animation) cancelAnimationFrame(animation);
    if (inertiaFrame) cancelAnimationFrame(inertiaFrame);
    animation = 0;
    inertiaFrame = 0;
    velocity = 0;
  }

  function animateTo(target, done) {
    const start = getRange();
    stopMotion();
    if (!start) return;
    const goal = limits(target);
    const began = performance.now();
    const step = now => {
      const t = clamp((now - began) / ANIM_MS, 0, 1);
      const k = easeOut(t);
      chart.timeScale().setVisibleLogicalRange({ from: start.from + (goal.from - start.from) * k, to: start.to + (goal.to - start.to) * k });
      if (t < 1) {
        animation = requestAnimationFrame(step);
      } else {
        animation = 0;
        if (done) done();
      }
    };
    animation = requestAnimationFrame(step);
  }

  function dayRange(day) {
    const length = day.last - day.first + 1;
    const pad = Math.max(1, length * 0.02);
    return { from: day.first - 0.5 - pad, to: day.last + 0.5 + pad };
  }

  function centerDay() {
    const range = getRange();
    if (!range || !state.days.length) return null;
    const index = clamp(Math.round((range.from + range.to) / 2), 0, state.ts.length - 1);
    return dayOfIndex(index);
  }

  function goToDay(day, animate = true) {
    if (!day) return;
    const range = getRange();
    let target;
    if (state.prefs.snap || !range) {
      target = dayRange(day);
    } else {
      const width = range.to - range.from;
      const middle = (day.first + day.last) / 2;
      target = { from: middle - width / 2, to: middle + width / 2 };
    }
    if (animate) animateTo(target);
    else setRange(target);
  }

  function stepDay(direction) {
    const day = centerDay();
    if (!day) return;
    let target = state.days[day.index + direction];
    if (!target && direction < 0 && state.loadedFrom > state.firstDate) {
      loadOlder().then(() => stepDay(direction));
      return;
    }
    if (!target) target = day;
    goToDay(target);
  }

  async function goToDate(date) {
    if (!state.days.length || !date) return;
    await ensureLoaded(date);
    const day = state.days.find(item => item.date >= date) || state.days[state.days.length - 1];
    goToDay(day);
  }

  function snapToCenter() {
    if (!state.prefs.snap) return;
    const day = centerDay();
    if (day) animateTo(dayRange(day));
  }

  let snapAccumulated = 0;
  let snapLockedUntil = 0;

  chartEl.addEventListener('wheel', event => {
    if (!state.ts.length) return;
    event.preventDefault();
    const range = getRange();
    if (!range) return;
    const scale = event.deltaMode === 1 ? 32 : event.deltaMode === 2 ? chartEl.clientWidth : 1;
    if (event.ctrlKey || event.metaKey) {
      // Přiblížení kolem kurzoru; Kolotoč se tím vypne.
      if (state.prefs.snap) setSnap(false);
      stopMotion();
      updateFrame();
      const anchor = logicalAt(event.offsetX);
      const factor = Math.exp(clamp(event.deltaY * scale, -300, 300) * 0.0022);
      const width = range.to - range.from;
      const paneWidth = chart.timeScale().width();
      const newWidth = clamp(width * factor, 24, paneWidth / 0.08);
      const ratio = newWidth / width;
      setRange({ from: anchor - (anchor - range.from) * ratio, to: anchor + (range.to - anchor) * ratio });
      return;
    }
    const delta = (Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY) * scale;
    if (state.prefs.snap) {
      const now = performance.now();
      if (now < snapLockedUntil) return;
      snapAccumulated += delta;
      if (Math.abs(snapAccumulated) >= 60) {
        stepDay(Math.sign(snapAccumulated));
        snapAccumulated = 0;
        snapLockedUntil = now + ANIM_MS + 60;
      }
      return;
    }
    if (animation) {
      cancelAnimationFrame(animation);
      animation = 0;
    }
    const spacing = chart.timeScale().options().barSpacing || 6;
    const bars = delta / spacing;
    if (Math.abs(delta) < 40) {
      // Touchpad: posouvá plynule sám, druhá setrvačnost by plavala.
      setRange({ from: range.from + bars, to: range.to + bars });
      return;
    }
    velocity += bars * 0.3;
    if (!inertiaFrame) inertiaFrame = requestAnimationFrame(inertia);
  }, { passive: false });

  function inertia() {
    inertiaFrame = 0;
    if (Math.abs(velocity) < 0.05) {
      velocity = 0;
      return;
    }
    const range = getRange();
    if (!range) return;
    setRange({ from: range.from + velocity, to: range.to + velocity });
    velocity *= 0.86;
    inertiaFrame = requestAnimationFrame(inertia);
  }

  // Po tažení myší v režimu Kolotoč graf dojede na nejbližší den.
  chartEl.addEventListener('pointerup', () => {
    if (state.prefs.snap && !state.zoneMode && !state.ideaMode && !state.handleDrag && popEl.hidden) setTimeout(snapToCenter, 380);
  });

  document.addEventListener('keydown', event => {
    if (event.target.closest && event.target.closest('input, textarea, select, [contenteditable], dialog[open]')) return;
    if (event.key === 'Escape') {
      if (state.draft) {
        state.draft = null;
        redraw();
      }
      $$('[data-idea-mode]').forEach(button => button.setAttribute('aria-pressed', 'false'));
      syncIdeaMode();
      closePop();
      return;
    }
    if (!$('#hsPop').hidden) return;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      stepDay(event.key === 'ArrowLeft' ? -1 : 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      goToDate(state.firstDate);
    } else if (event.key === 'End') {
      event.preventDefault();
      goToDay(state.days[state.days.length - 1]);
    } else if ((event.key === 'z' || event.key === 'Z') && !event.ctrlKey && !event.metaKey && !event.altKey) {
      if (!state.zoneKey) {
        state.zoneKey = true;
        syncZoneMode();
      }
    } else if (['l', 'L', 's', 'S'].includes(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
      const direction = event.key.toLowerCase() === 'l' ? 'long' : 'short';
      if (state.ideaKey !== direction) {
        state.ideaKey = direction;
        syncIdeaMode();
      }
    }
  });
  document.addEventListener('keyup', event => {
    if (event.key === 'z' || event.key === 'Z') {
      state.zoneKey = false;
      syncZoneMode();
    }
    if (['l', 'L', 's', 'S'].includes(event.key)) {
      state.ideaKey = null;
      syncIdeaMode();
    }
  });
  window.addEventListener('blur', () => {
    state.zoneKey = false;
    state.ideaKey = null;
    syncZoneMode();
    syncIdeaMode();
  });

  $$('[data-go]').forEach(button => button.addEventListener('click', () => {
    const go = button.dataset.go;
    if (go === 'prev') stepDay(-1);
    else if (go === 'next') stepDay(1);
    else if (go === 'first') goToDate(state.firstDate);
    else goToDay(state.days[state.days.length - 1]);
  }));
  $('#hsDate').addEventListener('change', event => goToDate(event.target.value));

  function setSnap(on) {
    state.prefs.snap = on;
    $('#hsSnap').setAttribute('aria-pressed', on ? 'true' : 'false');
    savePrefs();
    if (on) {
      const day = centerDay();
      if (day) animateTo(dayRange(day));
    }
  }
  $('#hsSnap').addEventListener('click', () => setSnap(!state.prefs.snap));

  /* ------------------------------------------------------------------ vrstvy a nastavení */

  let prefsTimer = 0;
  function savePrefs() {
    clearTimeout(prefsTimer);
    prefsTimer = setTimeout(() => {
      api('hindsight_prefs', { method: 'POST', body: state.prefs }).catch(error => showToast(error.message));
    }, 400);
  }

  function syncLayerButtons() {
    $$('[data-layer]').forEach(button => {
      button.setAttribute('aria-pressed', state.prefs.layers[button.dataset.layer] !== false ? 'true' : 'false');
    });
    $('#hsSnap').setAttribute('aria-pressed', state.prefs.snap ? 'true' : 'false');
    const backfill = $('#hsBackfill');
    if (backfill) backfill.setAttribute('aria-pressed', state.prefs.backfill ? 'true' : 'false');
    volume.applyOptions({ visible: state.prefs.layers.volume !== false });
  }

  function syncVolumeChip() {
    const chip = $('[data-layer="volume"]');
    chip.disabled = !state.hasVolume;
    chip.title = state.hasVolume ? '' : 'V nahraných svíčkách není objem (export z ATAS ho nemusí obsahovat).';
  }

  // Správce: zpětné doplňování pro prezentaci; ukládá se hned, ať platí pro další úpravu.
  const backfillButton = $('#hsBackfill');
  if (backfillButton) {
    backfillButton.addEventListener('click', async () => {
      state.prefs.backfill = !state.prefs.backfill;
      syncLayerButtons();
      try {
        await api('hindsight_prefs', { method: 'POST', body: state.prefs });
        showToast(state.prefs.backfill ? 'Zpětné doplňování zapnuté: úpravy minulých dnů se berou jako před otevřením.' : 'Zpětné doplňování vypnuté.');
      } catch (error) {
        showToast(error.message);
      }
    });
  }

  $$('[data-layer]').forEach(button => button.addEventListener('click', () => {
    const layer = button.dataset.layer;
    state.prefs.layers[layer] = state.prefs.layers[layer] === false;
    syncLayerButtons();
    savePrefs();
    redraw();
    renderHeaders();
  }));

  /* ------------------------------------------------------------------ hlavičky dnů */

  const daysEl = $('#hsDays');
  const headerPool = new Map();

  function headerContent(day, width) {
    const info = state.ann.days.get(day.date);
    const layers = state.prefs.layers;
    const [y, m, d] = day.date.split('-').map(Number);
    const weekday = T.WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
    const parts = [];
    if (width < 70) return `<span class="hs-day-date">${d}. ${m}.</span>`;
    parts.push(`<span class="hs-day-date"><small>${weekday}</small>${d}. ${m}.</span>`);
    if (layers.bias) {
      const bias = officialBias(info);
      const arrow = bias === 'long' ? '▲' : bias === 'short' ? '▼' : bias === 'neutral' ? '●' : '○';
      parts.push(`<span class="hs-bias is-${bias || 'empty'}" title="Bias: ${bias || 'nezadaný'}${info?.versions ? ' (verze z otevření NY)' : ''}">${arrow}</span>`);
      if (info?.bias_later) parts.push(`<span class="hs-later" title="Bias změněn po otevření NY: ${escapeHtml(BIAS_WORDS[bias] ?? bias)} → ${escapeHtml(BIAS_WORDS[info.bias] ?? info.bias)}">✎</span>`);
      const ok = verdict(day, info);
      if (ok !== null) parts.push(`<span class="hs-verdict ${ok ? 'is-ok' : 'is-bad'}" title="${ok ? 'Bias vyšel' : 'Bias nevyšel'} (RTH close proti RTH open)">${ok ? '✓' : '✗'}</span>`);
    }
    if (width >= 190 && info?.trades) {
      parts.push(`<span class="hs-trades" title="Obchody v deníku">${info.trades} obch.</span>`);
      const pnl = Number(info.pnl || 0);
      parts.push(`<span class="hs-pnl ${pnl >= 0 ? 'is-up' : 'is-down'}">${pnl >= 0 ? '+' : '−'}${money.format(Math.abs(pnl))} $</span>`);
    }
    return parts.join('');
  }

  function renderHeaders() {
    updateFrame();
    const width = chart.timeScale().width();
    const seen = new Set();
    if (frame.ok && width > 0) {
      for (const day of visibleDays(width)) {
        const x0 = xOf(edgeOf(day.start));
        const x1 = xOf(edgeOf(day.next));
        const left = Math.max(x0, 0) + 4;
        const right = Math.min(x1, width) - 4;
        const room = right - left;
        if (room < 40) continue;
        seen.add(day.date);
        let el = headerPool.get(day.date);
        if (!el) {
          el = document.createElement('button');
          el.type = 'button';
          el.className = 'hs-day';
          el.dataset.date = day.date;
          daysEl.appendChild(el);
          headerPool.set(day.date, el);
        }
        const info = state.ann.days.get(day.date);
        const key = `${room >= 190 ? 2 : room >= 70 ? 1 : 0}|${info?.bias || ''}|${info?.bias_official ?? ''}|${info?.bias_later ? 1 : 0}|${info?.trades || 0}|${info?.pnl || 0}|${state.prefs.layers.bias}|${day.rthLast}`;
        if (el.dataset.key !== key) {
          el.innerHTML = headerContent(day, room);
          el.dataset.key = key;
          el.setAttribute('aria-label', `${T.dateLabel(day.date, true)}: bias ${officialBias(info) || 'nezadaný'}${info?.bias_later ? ', po otevření změněn' : ''}`);
        }
        el.classList.toggle('is-compact', room < 70);
        el.style.transform = `translateX(${Math.round(left)}px)`;
        el.style.maxWidth = `${Math.round(room)}px`;
      }
    }
    for (const [date, el] of headerPool) {
      if (!seen.has(date)) {
        el.remove();
        headerPool.delete(date);
      }
    }
  }

  daysEl.addEventListener('click', event => {
    const el = event.target.closest('.hs-day');
    if (!el) return;
    const rect = el.getBoundingClientRect();
    openBiasPop(el.dataset.date, { x: rect.left, y: rect.bottom + 6 });
  });

  /* ------------------------------------------------------------------ legenda a tooltipy */

  const legendEl = $('#hsLegend');
  const tooltipEl = $('#hsTooltip');

  function renderLegend(bar) {
    if (!bar) {
      legendEl.textContent = '';
      return;
    }
    const change = bar.close - bar.open;
    const cls = change >= 0 ? 'is-up' : 'is-down';
    const parts = T.pragueParts(bar.time);
    legendEl.innerHTML = `<span><b>${T.WEEKDAYS[parts.weekday]} ${parts.day}. ${parts.month}. ${T.pragueTime(bar.time)}</b></span>`
      + `<span>O <b>${price(bar.open)}</b></span><span>H <b>${price(bar.high)}</b></span><span>L <b>${price(bar.low)}</b></span><span>C <b class="${cls}">${price(bar.close)}</b></span>`
      + (state.hasVolume ? `<span>V <b>${number.format(bar.volume)}</b></span>` : '');
  }

  function hitTest(x, y) {
    if (state.prefs.layers.trades) {
      for (const mark of state.tradeMarks) {
        const nearEntry = Math.hypot(mark.xE - x, mark.yE - y) <= 11;
        const nearExit = mark.xX !== null && mark.yX !== null && Math.hypot(mark.xX - x, mark.yX - y) <= 11;
        if (nearEntry || nearExit) return { kind: 'trade', trade: mark.trade };
      }
    }
    if (state.prefs.layers.news) {
      for (const mark of state.newsMarks) {
        if (Math.abs(mark.x - x) <= 4) return { kind: 'news', item: mark.item };
      }
    }
    if (state.prefs.layers.ideas) {
      for (let index = state.ideaRects.length - 1; index >= 0; index--) {
        const rect = state.ideaRects[index];
        if (x >= rect.x0 && x <= rect.x1 && y >= rect.top - 2 && y <= rect.bottom + 2) return { kind: 'idea', idea: rect.idea };
      }
    }
    if (state.prefs.layers.zones) {
      for (let index = state.zoneRects.length - 1; index >= 0; index--) {
        const rect = state.zoneRects[index];
        if (x >= rect.x0 && x <= rect.x1 && y >= rect.top - 2 && y <= rect.bottom + 2) return { kind: 'zone', zone: rect.zone };
      }
    }
    return null;
  }

  function tradeSummaryHtml(trade) {
    const times = trade.entry_ts !== null ? `${T.pragueTime(trade.entry_ts)}${trade.exit_ts !== null ? ` → ${T.pragueTime(trade.exit_ts)}` : ''}` : 'bez času';
    const prices = `${trade.entry !== null ? price(trade.entry) : '—'}${trade.exit !== null ? ` → ${price(trade.exit)}` : ''}`;
    const result = [trade.result_r !== null ? `${signed(trade.result_r, 2)}R` : '', trade.result_usd !== null ? `${signed(trade.result_usd, 0)} $` : ''].filter(Boolean).join(' · ');
    const linked = state.ann.ideas.find(idea => idea.trade_id === trade.id);
    return `<strong>${trade.direction === 'long' ? 'Long' : 'Short'} ${escapeHtml(trade.market)}${trade.strategy ? ` · ${escapeHtml(trade.strategy)}` : ''}</strong>`
      + `<span class="hs-mono">${escapeHtml(times)} · ${escapeHtml(prices)}${result ? ` · ${escapeHtml(result)}` : ''}</span>`
      + (linked ? `<p>Propojený potenciální obchod: ${escapeHtml(linked.name || (linked.direction === 'long' ? 'long' : 'short'))} ${price(linked.entry)}</p>` : '');
  }

  function ideaSummaryHtml(idea) {
    const result = evaluateIdea(idea);
    const risk = Math.abs(idea.entry - idea.stop);
    const reward = Math.abs(idea.target - idea.entry);
    const when = result.end !== undefined && state.ts[result.end] ? ` v ${T.pragueTime(state.ts[result.end])}` : '';
    const note = [OUTCOMES[idea.outcome] || '', idea.notes || ''].filter(Boolean).join(' · ');
    return `<strong>Potenciální ${idea.direction === 'long' ? 'long' : 'short'}${idea.name ? ` · ${escapeHtml(idea.name)}` : ''}</strong>`
      + `<span class="hs-mono">vstup ${price(idea.entry)} · SL ${price(idea.stop)} · cíl ${price(idea.target)} · ${(reward / risk).toFixed(1)}R</span>`
      + `<p>${escapeHtml(resultText(result))}${escapeHtml(when)}${result.points !== undefined ? ` (${escapeHtml(signed(result.points, 2))} b.)` : ''}${result.ambiguous ? ', stop i cíl v jedné svíčce: počítá se stop' : ''}${note ? `\n${escapeHtml(note)}` : ''}</p>`;
  }

  function validityText(zone) {
    if (zone.valid_to === 'open') return `od ${czechDate(zone.valid_from)}, dokud ji neukončíš`;
    if (!zone.valid_to || zone.valid_to === zone.valid_from) return `jen ${czechDate(zone.valid_from)}`;
    return `${czechDate(zone.valid_from)} – ${czechDate(zone.valid_to)}`;
  }

  function showTooltip(hit, point) {
    if (!hit) {
      tooltipEl.hidden = true;
      return;
    }
    if (hit.kind === 'news') {
      tooltipEl.innerHTML = `<strong>${escapeHtml(hit.item.title)}</strong><span class="hs-mono">Red news · ${escapeHtml(T.pragueTime(hit.item.ts))} · ${escapeHtml(czechDate(hit.item.date))}</span>`;
    } else if (hit.kind === 'trade') {
      tooltipEl.innerHTML = tradeSummaryHtml(hit.trade);
    } else if (hit.kind === 'idea') {
      tooltipEl.innerHTML = ideaSummaryHtml(hit.idea);
    } else {
      const zone = hit.zone;
      tooltipEl.innerHTML = `<strong>${escapeHtml(zone.name || ZONE_TYPES[zone.type])}</strong>`
        + `<span class="hs-mono">${escapeHtml(ZONE_TYPES[zone.type])} · ${price(zone.price_low)}–${price(zone.price_high)}</span>`
        + `<p>${escapeHtml(validityText(zone))}${zone.note ? `\n${escapeHtml(zone.note)}` : ''}${zone.later ? '\nPřidaná nebo změněná po otevření NY (dodatečně), vyhodnocení ji nepočítá.' : ''}${zone.removed ? '\nPři otevření NY v náhledu byla, potom byla odstraněna. Vyhodnocení ji počítá.' : ''}</p>`;
    }
    tooltipEl.hidden = false;
    const width = chartEl.clientWidth;
    const box = tooltipEl.getBoundingClientRect();
    const left = point.x + 14 + box.width > width ? point.x - box.width - 14 : point.x + 14;
    tooltipEl.style.left = `${Math.max(4, left)}px`;
    tooltipEl.style.top = `${clamp(point.y + 14, 4, chartEl.clientHeight - box.height - 4)}px`;
  }

  chart.subscribeCrosshairMove(param => {
    if (!param.point || param.time === undefined) {
      renderLegend(state.bars[state.bars.length - 1]);
      if (state.hover) {
        state.hover = null;
        redraw();
      }
      showTooltip(null);
      return;
    }
    const index = barAtOrBefore(param.time);
    renderLegend(state.bars[index]);
    if (state.draft || state.handleDrag) return;
    const hit = hitTest(param.point.x, param.point.y);
    const changed = hitKey(hit) !== hitKey(state.hover);
    state.hover = hit;
    if (changed) redraw();
    showTooltip(hit, param.point);
    chartEl.classList.toggle('is-pointer', Boolean(hit && hit.kind !== 'news'));
  });

  function hitKey(hit) {
    if (!hit) return '';
    const item = hit.zone || hit.item || hit.idea || hit.trade;
    return `${hit.kind}:${item ? item.id : ''}`;
  }

  chart.subscribeClick(param => {
    if (!param.point || state.zoneMode || state.ideaMode || suppressClick) return;
    const hit = hitTest(param.point.x, param.point.y);
    if (!hit || hit.kind === 'news') return;
    const rect = chartEl.getBoundingClientRect();
    const anchor = { x: rect.left + param.point.x, y: rect.top + param.point.y };
    if (hit.kind === 'zone') {
      if (hit.zone.removed) return;
      updateFrame();
      const index = clamp(Math.round(logicalAt(param.point.x)), 0, state.ts.length - 1);
      openZonePop(hit.zone, anchor, T.tradeDate(state.ts[index]));
    } else if (hit.kind === 'idea') {
      openIdeaPop({ ...hit.idea }, anchor);
    } else if (hit.kind === 'trade') {
      openTradePop(hit.trade, anchor);
    }
  });

  /* ------------------------------------------------------------------ kreslení zóny (Z + tažení) */

  let suppressClick = false;

  function syncZoneMode() {
    const on = state.zoneKey || $('#hsZoneMode').getAttribute('aria-pressed') === 'true';
    if (on === state.zoneMode) return;
    state.zoneMode = on;
    chartEl.classList.toggle('is-drawing', on);
    syncPointerModes();
    syncIdeaMode();
    if (!on && state.draft && !state.draft.active) {
      state.draft = null;
      redraw();
    }
  }

  $('#hsZoneMode').addEventListener('click', event => {
    const button = event.currentTarget;
    button.setAttribute('aria-pressed', button.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
    syncZoneMode();
  });

  function localPoint(event) {
    const rect = chartEl.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top, rect };
  }

  /** Táhlo rozpracovaného potenciálního obchodu pod kurzorem (stop, vstup, cíl). */
  function handleAt(x, y) {
    const geo = state.draftGeo;
    if (!geo || popEl.hidden) return null;
    if (x < Math.min(geo.x0, geo.hx) - 4 || x > Math.max(geo.x1, geo.hx) + 8) return null;
    const candidates = [['stop', geo.yS], ['target', geo.yT], ['entry', geo.yE]]
      .map(([name, y0]) => [name, Math.abs(y - y0)])
      .filter(([, distance]) => distance <= 6)
      .sort((a, b) => a[1] - b[1]);
    return candidates.length ? candidates[0][0] : null;
  }

  chartEl.addEventListener('pointerdown', event => {
    if (event.button !== 0 || !state.ts.length) return;
    const { x, y } = localPoint(event);
    if (x > chart.timeScale().width()) return;
    const handle = handleAt(x, y);
    if (handle) {
      event.preventDefault();
      event.stopPropagation();
      stopMotion();
      chartEl.setPointerCapture(event.pointerId);
      state.handleDrag = { handle, pointerId: event.pointerId };
      chart.applyOptions({ handleScroll: { pressedMouseMove: false }, kineticScroll: { mouse: false } });
      return;
    }
    if (state.zoneMode) {
      event.preventDefault();
      event.stopPropagation();
      stopMotion();
      closePop();
      chartEl.setPointerCapture(event.pointerId);
      state.draft = { x0: x, y0: y, x1: x, y1: y, active: true, pointerId: event.pointerId };
      redraw();
      return;
    }
    if (state.ideaMode) {
      event.preventDefault();
      event.stopPropagation();
      stopMotion();
      closePop();
      state.ideaClick = { x, y, pointerId: event.pointerId, direction: state.ideaMode };
    }
  }, true);

  chartEl.addEventListener('pointermove', event => {
    const { x, y, rect } = localPoint(event);
    if (state.handleDrag && event.pointerId === state.handleDrag.pointerId) {
      const value = candles.coordinateToPrice(clamp(y, 0, rect.height));
      if (value !== null) moveHandle(state.handleDrag.handle, roundTick(value));
      return;
    }
    if (state.draft && state.draft.active && event.pointerId === state.draft.pointerId) {
      state.draft.x1 = clamp(x, 0, chart.timeScale().width());
      state.draft.y1 = clamp(y, 0, rect.height);
      redraw();
      return;
    }
    chartEl.classList.toggle('is-resize', Boolean(!event.buttons && handleAt(x, y)));
  }, true);

  chartEl.addEventListener('pointerup', event => {
    if (state.handleDrag && event.pointerId === state.handleDrag.pointerId) {
      state.handleDrag = null;
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 0);
      syncPointerModes(true);
      return;
    }
    if (state.ideaClick && event.pointerId === state.ideaClick.pointerId) {
      const click = state.ideaClick;
      state.ideaClick = null;
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 0);
      placeIdea(click);
      return;
    }
    const draft = state.draft;
    if (!draft || !draft.active || event.pointerId !== draft.pointerId) return;
    draft.active = false;
    suppressClick = true;
    setTimeout(() => { suppressClick = false; }, 0);
    const top = Math.min(draft.y0, draft.y1);
    const bottom = Math.max(draft.y0, draft.y1);
    const high = candles.coordinateToPrice(top);
    const low = candles.coordinateToPrice(bottom);
    if (high === null || low === null || bottom - top < 3) {
      state.draft = null;
      redraw();
      return;
    }
    updateFrame();
    const index = clamp(Math.round(logicalAt(Math.min(draft.x0, draft.x1))), 0, state.ts.length - 1);
    const date = T.tradeDate(state.ts[index]);
    const rect = chartEl.getBoundingClientRect();
    openZonePop({ id: 0, valid_from: date, valid_to: 'open', price_low: roundTick(low), price_high: roundTick(high), type: guessZoneType(roundTick(low), roundTick(high), index), name: '', note: '' }, { x: rect.left + Math.max(draft.x0, draft.x1), y: rect.top + top }, date);
  }, true);

  /* ------------------------------------------------------------------ potenciální obchod (L / S + klik) */

  function syncPointerModes(force = false) {
    const busy = state.zoneMode || Boolean(state.ideaMode) || Boolean(state.handleDrag);
    if (!force && busy === state.pointerBusy) return;
    state.pointerBusy = busy;
    chart.applyOptions({ handleScroll: { pressedMouseMove: !busy, horzTouchDrag: !busy }, kineticScroll: { mouse: !busy, touch: !busy } });
  }

  function syncIdeaMode() {
    const button = state.ideaKey || ['long', 'short'].find(direction => $(`[data-idea-mode="${direction}"]`).getAttribute('aria-pressed') === 'true') || null;
    state.ideaMode = state.zoneMode ? null : button;
    chartEl.classList.toggle('is-placing', Boolean(state.ideaMode));
    syncPointerModes();
  }

  $$('[data-idea-mode]').forEach(button => button.addEventListener('click', () => {
    const on = button.getAttribute('aria-pressed') !== 'true';
    $$('[data-idea-mode]').forEach(other => other.setAttribute('aria-pressed', other === button && on ? 'true' : 'false'));
    syncIdeaMode();
  }));

  /** Výchozí stop: dvojnásobek průměrného rozpětí 5m svíčky toho dne, cíl 2R. */
  function defaultRisk(day) {
    if (!day) return 8;
    let sum = 0;
    for (let k = day.first; k <= day.last; k++) sum += state.bars[k].high - state.bars[k].low;
    const average = sum / Math.max(1, day.last - day.first + 1);
    return clamp(roundTick(average * 2), 2, 30);
  }

  function placeIdea(click) {
    updateFrame();
    const index = clamp(Math.round(logicalAt(click.x)), 0, state.ts.length - 1);
    const bar = state.bars[index];
    const value = candles.coordinateToPrice(click.y);
    if (!bar || value === null) return;
    const date = T.tradeDate(bar.time);
    const entry = roundTick(value);
    const risk = defaultRisk(state.dayMap.get(date));
    const long = click.direction === 'long';
    const rect = chartEl.getBoundingClientRect();
    openIdeaPop({
      id: 0, date, direction: click.direction, entry, entry_ts: bar.time,
      stop: long ? entry - risk : entry + risk, target: long ? entry + 2 * risk : entry - 2 * risk,
      name: '', notes: '', outcome: '', trade_id: null,
    }, { x: rect.left + click.x + 16, y: rect.top + click.y - 40 });
  }

  /** Posun táhla: stop a cíl zůstanou na správné straně vstupu, vstup posune celý obchod. */
  function moveHandle(handle, value) {
    const idea = state.ideaDraft;
    if (!idea) return;
    const long = idea.direction === 'long';
    if (handle === 'entry') {
      const shift = value - idea.entry;
      idea.entry = value;
      idea.stop = roundTick(idea.stop + shift);
      idea.target = roundTick(idea.target + shift);
    } else if (handle === 'stop') {
      idea.stop = long ? Math.min(value, idea.entry - TICK) : Math.max(value, idea.entry + TICK);
    } else {
      idea.target = long ? Math.max(value, idea.entry + TICK) : Math.min(value, idea.entry - TICK);
    }
    syncIdeaInputs();
    redraw();
  }

  /** Zóna pod cenou = support, nad cenou = resistance. */
  function guessZoneType(low, high, index) {
    const bar = state.bars[index];
    if (!bar) return 'other';
    if (high <= bar.close) return 'support';
    if (low >= bar.close) return 'resistance';
    return 'other';
  }

  /* ------------------------------------------------------------------ popover */

  const popEl = $('#hsPop');

  function openPop(html, anchor) {
    popEl.innerHTML = html;
    popEl.hidden = false;
    const box = popEl.getBoundingClientRect();
    const left = clamp(anchor.x, 8, window.innerWidth - box.width - 8);
    const top = anchor.y + box.height + 8 > window.innerHeight ? Math.max(8, anchor.y - box.height - 8) : anchor.y;
    popEl.style.left = `${left}px`;
    popEl.style.top = `${top}px`;
    const first = popEl.querySelector('[autofocus], input, textarea, button');
    if (first) first.focus({ preventScroll: true });
  }

  function closePop() {
    if (popEl.hidden) return;
    popEl.hidden = true;
    popEl.innerHTML = '';
    if (state.draft && !state.draft.active) state.draft = null;
    state.ideaDraft = null;
    state.draftGeo = null;
    redraw();
  }

  document.addEventListener('pointerdown', event => {
    if (!popEl.hidden && !popEl.contains(event.target) && !event.target.closest('.hs-day')) closePop();
  });
  popEl.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.stopPropagation();
      closePop();
    }
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      const save = popEl.querySelector('[data-act="save"]');
      if (save) save.click();
    }
  });

  function segValue(name) {
    const pressed = popEl.querySelector(`[data-seg="${name}"] [aria-pressed="true"]`);
    return pressed ? pressed.dataset.value : '';
  }

  popEl.addEventListener('click', event => {
    const segButton = event.target.closest('[data-seg] button');
    if (segButton) {
      // Výsledek jde i zrušit (druhým klikem), ostatní volby ne.
      const clear = segButton.parentElement.dataset.toggle === '1' && segButton.getAttribute('aria-pressed') === 'true';
      $$('button', segButton.parentElement).forEach(button => button.setAttribute('aria-pressed', button === segButton && !clear ? 'true' : 'false'));
      segButton.parentElement.dispatchEvent(new CustomEvent('segchange', { bubbles: true }));
    }
    if (event.target.closest('[data-act="cancel"]')) closePop();
  });

  function popError(message) {
    const el = popEl.querySelector('.hs-error');
    if (el) {
      el.textContent = message;
      el.hidden = false;
    }
  }

  function openBiasPop(date, anchor) {
    const info = state.ann.days.get(date) || {};
    const day = state.dayMap.get(date);
    const bias = info.bias || '';
    let rth = '';
    if (day && day.rthOpen !== null) {
      const move = day.rthClose - day.rthOpen;
      const done = day.rthLast !== null && day.rthLast >= day.rthEnd - BAR;
      rth = `RTH ${T.pragueTime(day.rthStart)}–${T.pragueTime(day.rthEnd)}: open ${price(day.rthOpen)} → ${done ? 'close' : 'zatím'} ${price(day.rthClose)} (${move >= 0 ? '+' : '−'}${price(Math.abs(move))} b.)${done ? '' : ', RTH ještě neskončilo'}`;
    }
    const option = (value, label) => `<button type="button" data-value="${value}" aria-pressed="${bias === value ? 'true' : 'false'}">${label}</button>`;
    openPop(`<h3>Bias <small>${escapeHtml(T.dateLabel(date, true))}</small></h3>
      <div class="hs-seg" data-seg="bias">${option('long', '▲ Long')}${option('short', '▼ Short')}${option('neutral', '● Neutral')}</div>
      <label class="hs-field">Poznámka<textarea name="note" maxlength="1000" placeholder="Proč tenhle bias?">${escapeHtml(info.bias_note || '')}</textarea></label>
      ${rth ? `<p class="hs-hint">${escapeHtml(rth)}</p>` : ''}
      ${info.bias_later ? `<p class="hs-hint hs-lock">Platný bias z otevření NY: <b>${escapeHtml(BIAS_WORDS[officialBias(info)] ?? officialBias(info))}</b>. Tady upravuješ aktuální verzi.</p>` : ''}
      <p class="hs-hint hs-lock">${escapeHtml(lockNote(date, 'Bias a zóny', true))} Stejný bias jako v denním náhledu ES.</p>
      ${info.versions ? '<div class="hs-versions" data-versions><p class="hs-sub">Verze</p><p class="hs-hint">Načítám…</p></div>' : ''}
      ${dayTradesHtml(date)}
      <p class="hs-error" hidden></p>
      <div class="hs-pop-actions"><span class="hs-grow"></span><button type="button" class="hs-btn" data-act="cancel">Zrušit</button><button type="button" class="hs-btn hs-primary" data-act="save">Uložit</button></div>`, anchor);
    if (info.versions) loadVersions(date);
    popEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
      const value = segValue('bias');
      if (!value) {
        popError('Vyber long, short nebo neutral.');
        return;
      }
      try {
        const saved = await api('hindsight_bias', { method: 'POST', body: { date, bias: value, note: popEl.querySelector('[name="note"]').value } });
        closePop();
        if (saved.later) showToast('Uloženo jako dodatečná verze (po otevření NY).');
        await loadAnnotations();
      } catch (error) {
        popError(error.message);
      }
    });
  }

  /** Obchody ES dne v popoveru hlavičky: klik otevře detail, kde jdou doplnit časy. */
  function dayTradesHtml(date) {
    const trades = state.ann.trades.filter(trade => trade.date === date);
    const ideas = state.ann.ideas.filter(idea => idea.date === date);
    if (!trades.length && !ideas.length) return '';
    const tradeRows = trades.map(trade => {
      const time = trade.entry_ts !== null ? T.pragueTime(trade.entry_ts) : 'bez času';
      const result = tradeResultLabel(trade);
      return `<button type="button" class="hs-list-row" data-open-trade="${trade.id}"><span class="hs-dir is-${trade.direction}">${trade.direction === 'long' ? 'L' : 'S'}</span><span>${escapeHtml(time)}</span><span class="hs-mono">${trade.entry !== null ? price(trade.entry) : '—'}${trade.exit !== null ? ` → ${price(trade.exit)}` : ''}</span><b class="${(trade.result_r ?? trade.result_usd ?? 0) >= 0 ? 'is-up' : 'is-down'}">${escapeHtml(result)}</b></button>`;
    }).join('');
    const ideaRows = ideas.map(idea => {
      const result = evaluateIdea(idea);
      return `<button type="button" class="hs-list-row" data-open-idea="${idea.id}"><span class="hs-dir is-${idea.direction}">${idea.direction === 'long' ? 'L' : 'S'}</span><span>${escapeHtml(idea.name || 'Potenciální')}</span><span class="hs-mono">${price(idea.entry)}</span><b class="${result.state === 'tp' ? 'is-up' : result.state === 'sl' ? 'is-down' : ''}">${escapeHtml(resultText(result))}</b></button>`;
    }).join('');
    return `<div class="hs-list">${trades.length ? `<p class="hs-sub">Obchody ES z deníku</p>${tradeRows}` : ''}${ideas.length ? `<p class="hs-sub">Potenciální obchody</p>${ideaRows}` : ''}</div>`;
  }

  popEl.addEventListener('click', event => {
    const tradeRow = event.target.closest('[data-open-trade]');
    const ideaRow = event.target.closest('[data-open-idea]');
    if (!tradeRow && !ideaRow) return;
    const box = popEl.getBoundingClientRect();
    const anchor = { x: box.left, y: box.top };
    if (tradeRow) {
      const trade = state.ann.trades.find(item => item.id === Number(tradeRow.dataset.openTrade));
      if (trade) openTradePop(trade, anchor);
    } else {
      const idea = state.ann.ideas.find(item => item.id === Number(ideaRow.dataset.openIdea));
      if (idea) {
        const day = state.dayMap.get(idea.date);
        if (day) goToDay(day);
        openIdeaPop({ ...idea }, anchor);
      }
    }
  });

  function openTradePop(trade, anchor) {
    const linked = state.ann.ideas.find(idea => idea.trade_id === trade.id);
    openPop(`<h3>${trade.direction === 'long' ? 'Long' : 'Short'} ${escapeHtml(trade.market)} <small>${escapeHtml(T.dateLabel(trade.date))}</small></h3>
      <p class="hs-mono hs-line">${trade.entry !== null ? price(trade.entry) : '—'}${trade.exit !== null ? ` → ${price(trade.exit)}` : ''}${trade.stop !== null ? ` · SL ${price(trade.stop)}` : ''} · ${escapeHtml([tradeResultLabel(trade), trade.result_usd !== null && trade.result_r !== null ? `${signed(trade.result_usd, 0)} $` : ''].filter(Boolean).join(' · ') || 'bez výsledku')}</p>
      <div class="hs-row">
        <label>Čas vstupu<input type="time" name="entry_time" value="${escapeHtml(trade.entry_time || '')}"></label>
        <label>Čas výstupu<input type="time" name="exit_time" value="${escapeHtml(trade.exit_time || '')}"></label>
      </div>
      <p class="hs-hint">Pražský čas. Podle něj obchod sedí v grafu; stejné pole je u obchodu v deníku.${linked ? ` Propojený potenciální obchod: ${escapeHtml(linked.name || linked.direction)} ${price(linked.entry)}.` : ''}</p>
      <p class="hs-error" hidden></p>
      <div class="hs-pop-actions"><span class="hs-grow"></span><button type="button" class="hs-btn" data-act="cancel">Zavřít</button><button type="button" class="hs-btn hs-primary" data-act="save">Uložit časy</button></div>`, anchor);
    popEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
      try {
        await api('hindsight_trade_times', { method: 'POST', body: { id: trade.id, entry_time: popEl.querySelector('[name="entry_time"]').value, exit_time: popEl.querySelector('[name="exit_time"]').value } });
        closePop();
        await loadAnnotations();
      } catch (error) {
        popError(error.message);
      }
    });
  }

  function syncIdeaInputs() {
    const idea = state.ideaDraft;
    if (!idea || popEl.hidden) return;
    for (const name of ['entry', 'stop', 'target']) {
      const input = popEl.querySelector(`[name="${name}"]`);
      if (input && document.activeElement !== input) input.value = price(idea[name]);
    }
    renderIdeaSummary();
  }

  function renderIdeaSummary() {
    const idea = state.ideaDraft;
    const el = popEl.querySelector('[data-idea-summary]');
    if (!idea || !el) return;
    const risk = Math.abs(idea.entry - idea.stop);
    const reward = Math.abs(idea.target - idea.entry);
    const result = evaluateIdea(idea);
    const when = result.end !== undefined && state.ts[result.end] ? ` v ${T.pragueTime(state.ts[result.end])}` : '';
    el.textContent = `Risk ${risk.toFixed(2)} b. · cíl ${reward.toFixed(2)} b. · ${risk > 0 ? (reward / risk).toFixed(2) : '–'}R\nV grafu: ${resultText(result)}${when}${result.ambiguous ? ' (stop i cíl v jedné svíčce, počítá se stop)' : ''}`;
    el.className = `hs-hint hs-summary ${result.state === 'tp' ? 'is-up' : result.state === 'sl' ? 'is-down' : ''}`;
  }

  function openIdeaPop(idea, anchor) {
    const isNew = !idea.id;
    state.ideaDraft = idea;
    const dirButton = (value, label) => `<button type="button" data-value="${value}" aria-pressed="${idea.direction === value ? 'true' : 'false'}">${label}</button>`;
    const outcomeButton = value => `<button type="button" data-value="${value}" aria-pressed="${idea.outcome === value ? 'true' : 'false'}">${OUTCOMES[value]}</button>`;
    const trades = state.ann.trades.filter(trade => trade.date === idea.date);
    const tradeOptions = trades.map(trade => `<option value="${trade.id}"${idea.trade_id === trade.id ? ' selected' : ''}>${trade.direction === 'long' ? 'L' : 'S'} ${trade.entry !== null ? price(trade.entry) : '—'}${trade.exit !== null ? ` → ${price(trade.exit)}` : ''} ${escapeHtml(tradeResultLabel(trade))}${trade.entry_ts !== null ? ` · ${T.pragueTime(trade.entry_ts)}` : ''}</option>`).join('');
    const when = idea.entry_ts !== null && idea.entry_ts !== undefined ? ` · ${T.pragueTime(idea.entry_ts)}` : ' · celý den';
    openPop(`<h3>${isNew ? 'Nový potenciální obchod' : 'Potenciální obchod'} <small>${escapeHtml(T.dateLabel(idea.date))}${escapeHtml(when)}</small></h3>
      <div class="hs-seg" data-seg="direction">${dirButton('long', '▲ Long')}${dirButton('short', '▼ Short')}</div>
      <div class="hs-row hs-row3">
        <label>Vstup<input class="hs-mono" name="entry" inputmode="decimal" value="${price(idea.entry)}"></label>
        <label>Stop loss<input class="hs-mono" name="stop" inputmode="decimal" value="${price(idea.stop)}"></label>
        <label>Cíl<input class="hs-mono" name="target" inputmode="decimal" value="${price(idea.target)}"></label>
      </div>
      <p class="hs-hint hs-summary" data-idea-summary></p>
      <p class="hs-sub">Jak to dopadlo</p>
      <div class="hs-seg" data-seg="outcome" data-toggle="1">${outcomeButton('skipped')}${outcomeButton('missed')}${outcomeButton('taken')}</div>
      <label class="hs-field">Realizovaný obchod<select name="trade_id"><option value="">${trades.length ? '— nepropojovat —' : 'v deníku není obchod ES z toho dne'}</option>${tradeOptions}</select></label>
      <label class="hs-field">Popisek<input name="name" maxlength="80" value="${escapeHtml(idea.name)}" placeholder="např. VAL reject"></label>
      <label class="hs-field">Poznámka<textarea name="notes" maxlength="1000">${escapeHtml(idea.notes)}</textarea></label>
      <p class="hs-hint">Stop, vstup a cíl jde táhnout přímo v grafu. Stejný scénář je v denním náhledu.</p>
      <p class="hs-error" hidden></p>
      <div class="hs-pop-actions">
        ${isNew ? '' : '<button type="button" class="hs-btn hs-danger" data-act="delete">Smazat</button>'}
        <span class="hs-grow"></span>
        <button type="button" class="hs-btn" data-act="cancel">Zrušit</button>
        <button type="button" class="hs-btn hs-primary" data-act="save">Uložit</button>
      </div>`, anchor);
    renderIdeaSummary();
    redraw();
    for (const name of ['entry', 'stop', 'target']) {
      popEl.querySelector(`[name="${name}"]`).addEventListener('input', event => {
        const value = Number(String(event.target.value).replace(',', '.'));
        if (!Number.isFinite(value) || value <= 0 || !state.ideaDraft) return;
        state.ideaDraft[name] = value;
        renderIdeaSummary();
        redraw();
      });
    }
    popEl.querySelector('[data-seg="direction"]').addEventListener('segchange', () => {
      const direction = segValue('direction');
      const draft = state.ideaDraft;
      if (!draft || !direction || direction === draft.direction) return;
      // Otočení směru zrcadlí stop a cíl kolem vstupu.
      draft.direction = direction;
      draft.stop = roundTick(2 * draft.entry - draft.stop);
      draft.target = roundTick(2 * draft.entry - draft.target);
      syncIdeaInputs();
      redraw();
    });
    popEl.querySelector('[data-seg="outcome"]').addEventListener('segchange', () => {
      if (segValue('outcome') === 'taken' && !popEl.querySelector('[name="trade_id"]').value && trades.length === 1) {
        popEl.querySelector('[name="trade_id"]').value = String(trades[0].id);
      }
    });
    popEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
      const draft = state.ideaDraft;
      if (!draft) return;
      const tradeId = popEl.querySelector('[name="trade_id"]').value;
      const body = {
        id: draft.id || undefined,
        date: draft.date,
        direction: draft.direction,
        entry: draft.entry,
        stop: draft.stop,
        target: draft.target,
        entry_ts: draft.entry_ts ?? null,
        outcome: segValue('outcome'),
        trade_id: tradeId ? Number(tradeId) : null,
        name: popEl.querySelector('[name="name"]').value,
        notes: popEl.querySelector('[name="notes"]').value,
      };
      try {
        await api('hindsight_idea', { method: draft.id ? 'PUT' : 'POST', body });
        closePop();
        await loadAnnotations();
      } catch (error) {
        popError(error.message);
      }
    });
    const remove = popEl.querySelector('[data-act="delete"]');
    if (remove) {
      remove.addEventListener('click', async () => {
        if (remove.dataset.confirm !== '1') {
          remove.dataset.confirm = '1';
          remove.textContent = 'Opravdu smazat?';
          return;
        }
        try {
          await api('hindsight_idea', { method: 'DELETE', query: { id: idea.id } });
          closePop();
          await loadAnnotations();
        } catch (error) {
          popError(error.message);
        }
      });
    }
  }

  /** Historie verzí dne: 1 = při otevření NY, další dodatečně. */
  async function loadVersions(date) {
    const box = popEl.querySelector('[data-versions]');
    try {
      const data = await api('hindsight_versions', { query: { date } });
      if (!box.isConnected) return;
      const arrow = bias => (bias === 'long' ? '▲' : bias === 'short' ? '▼' : bias === 'neutral' ? '●' : '○');
      box.innerHTML = '<p class="hs-sub">Verze</p>' + data.versions.map(version => {
        const ts = Math.floor(Date.parse(version.saved_at) / 1000);
        const parts = T.pragueParts(ts);
        const when = version.kind === 'locked' ? 'při otevření NY' : `dodatečně ${parts.day}. ${parts.month}. ${T.pragueTime(ts)}`;
        const zones = version.zones.length === 1 ? '1 zóna' : version.zones.length >= 2 && version.zones.length <= 4 ? `${version.zones.length} zóny` : `${version.zones.length} zón`;
        return `<div class="hs-version${version.kind === 'locked' ? ' is-locked' : ''}"><b>${version.version}</b><span>${escapeHtml(when)}</span><span class="hs-bias is-${escapeHtml(version.bias || 'empty')}">${arrow(version.bias)}</span><span class="hs-mono">${escapeHtml(zones)}</span></div>`;
      }).join('');
    } catch (error) {
      if (box.isConnected) box.innerHTML = `<p class="hs-error">${escapeHtml(error.message)}</p>`;
    }
  }

  function openZonePop(zone, anchor, clickedDate) {
    const isNew = !zone.id;
    const validity = zone.valid_to === 'open' ? 'open' : zone.valid_to && zone.valid_to !== zone.valid_from ? 'date' : 'day';
    const typeButton = (value) => `<button type="button" data-value="${value}" aria-pressed="${zone.type === value ? 'true' : 'false'}">${ZONE_TYPES[value]}</button>`;
    const canEnd = !isNew && clickedDate && clickedDate >= zone.valid_from && (zone.valid_to === 'open' || (zone.valid_to && zone.valid_to > clickedDate));
    openPop(`<h3>${isNew ? 'Nová zóna' : 'Zóna'} <small>od ${escapeHtml(T.dateLabel(zone.valid_from))}</small></h3>
      <div class="hs-seg" data-seg="type">${Object.keys(ZONE_TYPES).map(typeButton).join('')}</div>
      <label class="hs-field">Popisek<input name="name" maxlength="80" value="${escapeHtml(zone.name)}" placeholder="např. VAH, pdH, 5000"></label>
      <div class="hs-row">
        <label>Spodní cena<input class="hs-mono" name="price_low" inputmode="decimal" value="${price(zone.price_low)}"></label>
        <label>Horní cena<input class="hs-mono" name="price_high" inputmode="decimal" value="${price(zone.price_high)}"></label>
      </div>
      <div class="hs-row hs-validity">
        <label>Platnost<select name="validity">
          <option value="open"${validity === 'open' ? ' selected' : ''}>Dokud ji neukončím</option>
          <option value="date"${validity === 'date' ? ' selected' : ''}>Do data</option>
          <option value="day"${validity === 'day' ? ' selected' : ''}>Jen tento den</option>
        </select></label>
        <label>Do data<input type="date" name="valid_to" min="${zone.valid_from}" value="${validity === 'date' ? escapeHtml(zone.valid_to) : ''}"${validity === 'date' ? '' : ' disabled'}></label>
      </div>
      <label class="hs-field">Poznámka<textarea name="note" maxlength="1000">${escapeHtml(zone.note)}</textarea></label>
      <p class="hs-hint hs-lock">${escapeHtml(zone.later ? 'Zóna je dodatečná (přidaná nebo změněná po otevření NY). ' : '')}${escapeHtml(lockNote(zone.valid_from, 'Zóna'))}${dayLocked(zone.valid_from) && !backfillActive() ? ' Popisek, poznámka a platnost se dají měnit kdykoli.' : ''}</p>
      <p class="hs-error" hidden></p>
      <div class="hs-pop-actions">
        ${isNew ? '' : '<button type="button" class="hs-btn hs-danger" data-act="delete">Smazat</button>'}
        ${canEnd ? `<button type="button" class="hs-btn" data-act="end" title="Zóna bude platit do ${escapeHtml(czechDate(clickedDate))} včetně">Ukončit ${escapeHtml(T.dateLabel(clickedDate))}</button>` : ''}
        <span class="hs-grow"></span>
        <button type="button" class="hs-btn" data-act="cancel">Zrušit</button>
        <button type="button" class="hs-btn hs-primary" data-act="save">Uložit</button>
      </div>`, anchor);
    const validitySelect = popEl.querySelector('[name="validity"]');
    const validTo = popEl.querySelector('[name="valid_to"]');
    validitySelect.addEventListener('change', () => {
      validTo.disabled = validitySelect.value !== 'date';
      if (!validTo.disabled && !validTo.value) validTo.value = zone.valid_from;
    });
    const nameInput = popEl.querySelector('[name="name"]');
    nameInput.focus({ preventScroll: true });

    const save = async overrides => {
      const read = name => popEl.querySelector(`[name="${name}"]`).value;
      const low = Number(String(read('price_low')).replace(',', '.'));
      const high = Number(String(read('price_high')).replace(',', '.'));
      if (!Number.isFinite(low) || !Number.isFinite(high) || low <= 0 || high <= 0) {
        popError('Zadej spodní i horní cenu.');
        return;
      }
      const mode = validitySelect.value;
      const body = {
        id: zone.id || undefined,
        date: zone.valid_from,
        type: segValue('type') || 'other',
        name: read('name'),
        note: read('note'),
        price_low: Math.min(low, high),
        price_high: Math.max(low, high),
        valid_to: mode === 'open' ? 'open' : mode === 'date' ? validTo.value : '',
        ...overrides,
      };
      if (mode === 'date' && !body.valid_to) {
        popError('Vyber datum, do kdy zóna platí.');
        return;
      }
      try {
        const saved = await api('hindsight_zone', { method: zone.id ? 'PUT' : 'POST', body });
        state.draft = null;
        closePop();
        if (saved.later) showToast('Zóna uložená jako dodatečná verze (po otevření NY).');
        await loadAnnotations();
      } catch (error) {
        popError(error.message);
      }
    };
    popEl.querySelector('[data-act="save"]').addEventListener('click', () => save({}));
    const end = popEl.querySelector('[data-act="end"]');
    if (end) end.addEventListener('click', () => save({ valid_to: clickedDate }));
    const remove = popEl.querySelector('[data-act="delete"]');
    if (remove) {
      remove.addEventListener('click', async () => {
        if (remove.dataset.confirm !== '1') {
          remove.dataset.confirm = '1';
          remove.textContent = 'Opravdu smazat?';
          return;
        }
        try {
          await api('hindsight_zone', { method: 'DELETE', query: { id: zone.id } });
          closePop();
          await loadAnnotations();
        } catch (error) {
          popError(error.message);
        }
      });
    }
  }

  /* ------------------------------------------------------------------ minimapa */

  const miniWrap = $('#hsMinimapWrap');
  const miniCanvas = $('#hsMinimap');
  const mini = { dates: [], index: new Map(), width: 0 };

  function minimapDates() {
    if (!state.firstDate) return [];
    const dates = [];
    for (let date = state.firstDate; date <= state.lastDate; date = T.addDays(date, 1)) {
      const [y, m, d] = date.split('-').map(Number);
      const weekday = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
      if (weekday !== 0 && weekday !== 6) dates.push(date);
      if (dates.length > 800) break;
    }
    return dates;
  }

  function renderMinimap() {
    const width = miniWrap.clientWidth;
    const height = miniWrap.clientHeight;
    const ratio = window.devicePixelRatio || 1;
    if (miniCanvas.width !== Math.round(width * ratio) || miniCanvas.height !== Math.round(height * ratio)) {
      miniCanvas.width = Math.round(width * ratio);
      miniCanvas.height = Math.round(height * ratio);
    }
    const ctx = miniCanvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    if (mini.dates.length === 0 || mini.dates[0] !== state.firstDate || mini.dates[mini.dates.length - 1] !== state.lastDate) {
      mini.dates = minimapDates();
      mini.index = new Map(mini.dates.map((date, position) => [date, position]));
    }
    const count = mini.dates.length;
    mini.width = width;
    if (!count) return;
    const column = width / count;
    const closes = [];
    let min = Infinity;
    let max = -Infinity;
    mini.dates.forEach((date, position) => {
      const day = state.dayMap.get(date);
      if (day) {
        closes.push([position, day.close]);
        min = Math.min(min, day.low);
        max = Math.max(max, day.high);
      }
    });
    // Barevný pruh: vyšel / nevyšel bias, zadaný bez výsledku, bez biasu.
    const stripe = 7;
    mini.dates.forEach((date, position) => {
      const info = state.ann.days.get(date);
      const day = state.dayMap.get(date);
      const ok = verdict(day, info);
      let color = day ? '#1F2533' : '#151A24';
      if (ok === true) color = COLORS.up;
      else if (ok === false) color = COLORS.down;
      else if (officialBias(info)) color = '#3A4357';
      ctx.fillStyle = color;
      ctx.fillRect(position * column + (column > 3 ? 0.5 : 0), height - stripe - 3, Math.max(1, column - (column > 3 ? 1 : 0)), stripe);
    });
    // Průběh ceny (denní close) nad pruhem.
    if (closes.length > 1 && max > min) {
      ctx.strokeStyle = COLORS.minimapLine;
      ctx.lineWidth = 1;
      ctx.beginPath();
      closes.forEach(([position, close], i) => {
        const x = (position + 0.5) * column;
        const y = 4 + (1 - (close - min) / (max - min)) * (height - stripe - 12);
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      });
      ctx.stroke();
    }
    // Začátky měsíců.
    ctx.font = `500 9.5px ${FONT}`;
    ctx.fillStyle = '#4B5264';
    ctx.textBaseline = 'top';
    let lastMonth = '';
    mini.dates.forEach((date, position) => {
      const month = date.slice(0, 7);
      if (month !== lastMonth) {
        if (lastMonth) {
          ctx.fillRect(Math.round(position * column), 0, 1, height - stripe - 4);
        }
        ctx.fillText(`${Number(date.slice(5, 7))}/${date.slice(2, 4)}`, position * column + 3, 3);
        lastMonth = month;
      }
    });
    // Okno s tím, co je právě v grafu.
    const view = visibleDateSpan();
    if (view) {
      const from = mini.index.get(view.from);
      const to = mini.index.get(view.to);
      if (from !== undefined && to !== undefined) {
        const x0 = from * column;
        const x1 = (to + 1) * column;
        ctx.fillStyle = rgba(COLORS.minimapWindow, 0.1);
        ctx.fillRect(x0, 0, Math.max(3, x1 - x0), height);
        ctx.strokeStyle = COLORS.minimapWindow;
        ctx.lineWidth = 1;
        ctx.strokeRect(Math.round(x0) + 0.5, 0.5, Math.max(3, Math.round(x1 - x0) - 1), height - 1);
        mini.window = { x0, x1 };
      }
    }
  }

  function visibleDateSpan() {
    const range = getRange();
    if (!range || !state.days.length) return null;
    const count = state.ts.length;
    const first = dayOfIndex(clamp(Math.ceil(range.from), 0, count - 1));
    const last = dayOfIndex(clamp(Math.floor(range.to), 0, count - 1));
    return first && last ? { from: first.date, to: last.date } : null;
  }

  function minimapDateAt(x) {
    if (!mini.dates.length) return null;
    return mini.dates[clamp(Math.floor(x / (mini.width / mini.dates.length)), 0, mini.dates.length - 1)];
  }

  let miniDrag = null;
  miniWrap.addEventListener('pointerdown', event => {
    if (!mini.dates.length) return;
    const x = event.offsetX;
    miniWrap.setPointerCapture(event.pointerId);
    const inside = mini.window && x >= mini.window.x0 - 2 && x <= mini.window.x1 + 2;
    miniDrag = { startX: x, moved: false, inside, lastDate: null };
  });
  miniWrap.addEventListener('pointermove', event => {
    if (!miniDrag) return;
    if (Math.abs(event.offsetX - miniDrag.startX) > 3) miniDrag.moved = true;
    if (!miniDrag.moved) return;
    const date = minimapDateAt(event.offsetX);
    if (!date || date === miniDrag.lastDate) return;
    miniDrag.lastDate = date;
    const day = state.dayMap.get(date) || state.days.find(item => item.date >= date);
    if (day && date >= state.loadedFrom) {
      stopMotion();
      goToDay(day, false);
    }
  });
  miniWrap.addEventListener('pointerup', event => {
    if (!miniDrag) return;
    const drag = miniDrag;
    miniDrag = null;
    const date = minimapDateAt(event.offsetX);
    if (!drag.moved || (date && date < state.loadedFrom)) {
      goToDate(date);
    } else if (state.prefs.snap) {
      snapToCenter();
    }
  });

  /* ------------------------------------------------------------------ překreslení při pohybu */

  let uiFrame = 0;
  function scheduleUi() {
    if (uiFrame) return;
    uiFrame = requestAnimationFrame(() => {
      uiFrame = 0;
      renderHeaders();
      renderMinimap();
      const day = centerDay();
      const input = $('#hsDate');
      if (day && document.activeElement !== input) input.value = day.date;
      updateInstrument(day);
    });
  }

  function updateInstrument(day) {
    const label = $('#hsInstrument');
    if (!day || !state.range) return;
    const contract = (state.range.contracts || []).find(item => !item.demo && item.from_date && item.from_date <= day.date && item.last_date >= day.date);
    const text = `${label.dataset.base || 'ES · 5m'}${contract ? ` · ${contract.contract}` : ''}`;
    if (label.textContent !== text) label.textContent = text;
  }

  chart.timeScale().subscribeVisibleLogicalRangeChange(scheduleUi);
  new ResizeObserver(() => {
    scheduleUi();
    const day = state.prefs.snap ? centerDay() : null;
    if (day) {
      setRange(dayRange(day));
    } else {
      const range = getRange();
      if (range) setRange(range);
    }
  }).observe(chartEl);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { redraw(); scheduleUi(); });

  /* ------------------------------------------------------------------ hlášky */

  let toastTimer = 0;
  function showToast(message) {
    const el = $('#hsLoading');
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      el.hidden = true;
      el.textContent = 'Načítám svíčky…';
    }, 5000);
  }

  /* ------------------------------------------------------------------ nápověda */

  $('#hsHelp').addEventListener('click', () => $('#hsHelpDialog').showModal());

  /* ------------------------------------------------------------------ data (správce) */

  const dataDialog = $('#hsDataDialog');

  function renderContracts() {
    if (!dataDialog || !state.range) return;
    const select = $('#hsImportContract');
    const options = state.range.contract_options || [];
    const previous = select.value;
    // Kontrakt se vybírá pokaždé ručně, nic není předvybrané.
    select.innerHTML = '<option value="">Vyber kontrakt…</option>' + options.map(option => `<option value="${escapeHtml(option.contract)}">${escapeHtml(option.contract)} · ${escapeHtml(contractMonth(option.expiry))}${option.current ? ' (aktuální)' : ''}</option>`).join('');
    select.value = previous && options.some(option => option.contract === previous) ? previous : '';
    renderPeriod();
    const list = $('#hsContracts');
    const contracts = state.range.contracts || [];
    list.innerHTML = contracts.length ? contracts.map(contract => {
      const span = contract.first_ts ? `${T.dateLabel(T.tradeDate(contract.first_ts))} – ${T.dateLabel(T.tradeDate(contract.last_ts))}` : 'bez svíček';
      const period = contract.from_date ? `období ${czechDate(contract.from_date)} – ${czechDate(contract.last_date)}` : 'ukázková data';
      return `<div class="hs-contract"><b>${escapeHtml(contract.demo ? 'Ukázka' : contract.contract)}</b><span>${number.format(contract.bars)} svíček · ${escapeHtml(span)}<br>${escapeHtml(period)}${contract.source ? ` · ${escapeHtml(contract.source)}` : ''}</span><button type="button" class="hs-btn hs-danger" data-delete-contract="${escapeHtml(contract.contract)}">Smazat</button></div>`;
    }).join('') : '<p class="hs-note">Zatím žádné.</p>';
  }

  function contractMonth(expiry) {
    const months = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    return `${months[Number(expiry.slice(5, 7)) - 1]} ${expiry.slice(0, 4)}`;
  }

  function renderPeriod() {
    const select = $('#hsImportContract');
    const option = (state.range?.contract_options || []).find(item => item.contract === select.value);
    $('#hsImportPeriod').innerHTML = option
      ? `Uloží se jen svíčky kontraktu <b>${escapeHtml(option.contract)}</b> od <b>${escapeHtml(czechDate(option.from_date))}</b> (roll z předchozího kontraktu, 18:00 New York den předem) do <b>${escapeHtml(czechDate(option.last_date))}</b>.`
      : 'Vyber kontrakt, ke kterému export patří. Uloží se jen jeho období.';
  }

  function importSummary(result) {
    const lines = [`${result.contract}: uloženo ${number.format(result.bars)} svíček (nové ${number.format(result.new)}, přepsané ${number.format(result.updated)}), obchodní dny ${czechDate(T.tradeDate(result.from_ts))} až ${czechDate(T.tradeDate(result.to_ts))}.`];
    if (result.skipped) lines.push(`Nečitelné řádky: ${number.format(result.skipped)}.`);
    (result.outside || []).forEach(item => {
      lines.push(`Mimo období ${result.contract}: ${number.format(item.bars)} svíček patří kontraktu ${item.contract}${item.from_date ? ` (${czechDate(item.from_date)} – ${czechDate(item.last_date)})` : ''}. Neuložil jsem je; pro to období vyexportuj z ATAS přímo ${item.contract}.`);
    });
    return lines.join('\n');
  }

  if (dataDialog) {
    const openData = () => {
      $('#hsImportContract').value = '';
      renderContracts();
      $('#hsImportResult').textContent = '';
      dataDialog.showModal();
    };
    $('#hsDataButton').addEventListener('click', openData);
    $('#hsImportContract').addEventListener('change', renderPeriod);
    dataDialog.addEventListener('click', event => {
      if (event.target.closest('[data-close]')) dataDialog.close();
    });
    $('#hsImportForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const result = $('#hsImportResult');
      const submit = $('#hsImportSubmit');
      if (!form.contract.value) {
        result.className = 'hs-result is-error';
        result.textContent = 'Vyber kontrakt, ke kterému soubor patří.';
        return;
      }
      if (!form.file.files.length) {
        result.className = 'hs-result is-error';
        result.textContent = 'Vyber soubor.';
        return;
      }
      submit.disabled = true;
      result.className = 'hs-result';
      result.textContent = 'Nahrávám…';
      try {
        const payload = await api('hindsight_import', { method: 'POST', body: new FormData(form) });
        result.className = 'hs-result is-ok';
        result.textContent = importSummary(payload);
        form.file.value = '';
        form.contract.value = '';
        await load();
        renderContracts();
      } catch (error) {
        result.className = 'hs-result is-error';
        const outside = error.payload?.outside || [];
        result.textContent = [error.message, ...outside.map(item => `Soubor obsahuje ${number.format(item.bars)} svíček kontraktu ${item.contract} (${czechDate(item.from_date)} – ${czechDate(item.last_date)}).`)].join('\n');
      } finally {
        submit.disabled = false;
      }
    });
    $('#hsDemoButton').addEventListener('click', async () => {
      const result = $('#hsImportResult');
      try {
        const payload = await api('hindsight_demo', { method: 'POST' });
        result.className = 'hs-result is-ok';
        result.textContent = `Ukázková data: ${number.format(payload.bars)} svíček za ${payload.days} dní. Jakmile nahraješ skutečný export, ukázka se v grafu přestane používat.`;
        await load();
        renderContracts();
      } catch (error) {
        result.className = 'hs-result is-error';
        result.textContent = error.message;
      }
    });
    $('#hsContracts').addEventListener('click', async event => {
      const button = event.target.closest('[data-delete-contract]');
      if (!button) return;
      if (button.dataset.confirm !== '1') {
        button.dataset.confirm = '1';
        button.textContent = 'Opravdu?';
        return;
      }
      try {
        await api('hindsight_contract', { method: 'DELETE', query: { contract: button.dataset.deleteContract } });
        await load();
        renderContracts();
      } catch (error) {
        const result = $('#hsImportResult');
        result.className = 'hs-result is-error';
        result.textContent = error.message;
      }
    });
    $('#hsEmpty').addEventListener('click', event => {
      const action = event.target.closest('[data-empty]')?.dataset.empty;
      if (action === 'import') openData();
      if (action === 'demo') $('#hsDemoButton').click();
    });
  }

  /* ------------------------------------------------------------------ start */

  load().catch(error => {
    $('#hsLoading').hidden = true;
    const empty = $('#hsEmpty');
    empty.hidden = false;
    empty.innerHTML = `<h2>Hindsight se nenačetl</h2><p>${escapeHtml(error.message)}</p>`;
  });
})();
