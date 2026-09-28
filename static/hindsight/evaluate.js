'use strict';

/*
 * Hindsight – vyhodnocení. Čisté funkce nad svíčkami, bez DOM; testy jsou
 * v tests/test_hindsight_eval.js. Svíčka: { time, open, high, low, close }.
 * Den je úsek pole svíček first..last (obchodní den od 18:00 New York).
 */
(function attachEval(root) {
  const DEFAULTS = {
    window: 'day', // zóna: celý obchodní den, nebo jen RTH
    bounce: 8, // zóna držela: odraz aspoň o tolik bodů od okraje zóny
    breakBy: 4, // zóna proražená: 5m close za zónou o víc než tolik bodů
    includeLater: false, // počítat i zóny přidané nebo změněné po otevření NY
    neutralBand: 0, // neutral bias je správně, když se RTH pohne méně (0 = nepočítat)
    sameBar: 'stop', // svíčka se stopem i cílem: stop (horší případ), nebo cíl
  };

  function number(value, min, max, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.min(max, Math.max(min, parsed)) : fallback;
  }

  /** Pravidla s výchozími hodnotami a rozumnými mezemi. */
  function config(raw) {
    const value = Object.assign({}, DEFAULTS, raw || {});
    return {
      window: value.window === 'rth' ? 'rth' : 'day',
      bounce: number(value.bounce, 0.25, 500, DEFAULTS.bounce),
      breakBy: number(value.breakBy, 0, 500, DEFAULTS.breakBy),
      includeLater: Boolean(value.includeLater),
      neutralBand: number(value.neutralBand, 0, 500, 0),
      sameBar: value.sameBar === 'target' ? 'target' : 'stop',
    };
  }

  function configKey(cfg) {
    return `${cfg.window}|${cfg.bounce}|${cfg.breakBy}|${cfg.includeLater ? 1 : 0}|${cfg.neutralBand}|${cfg.sameBar}`;
  }

  /**
   * Zóna v jednom dni. Nejdřív první dotek (svíčka zasáhne zónu). Strana přístupu
   * podle close předchozí svíčky: shora = zóna má podržet cenu nahoře (support),
   * zdola = dole (resistance); když předchozí close leží v zóně, rozhodne typ zóny.
   * Držela = odraz aspoň o `bounce` bodů od okraje zóny dřív, než 5m svíčka zavře
   * za zónou o víc než `breakBy`. Maximum svíčky přišlo dřív než její close, proto
   * odraz a průraz v jedné svíčce znamená „držela“. Svíčka doteku se počítá jen
   * svým close (její maximum mohlo být ještě před dotekem).
   * Výsledek: held, broken, undecided (dotek bez rozhodnutí), untouched.
   */
  function zoneDay(bars, first, last, zone, cfg, rth) {
    let from = first;
    let to = last;
    if (cfg.window === 'rth' && rth) {
      while (from <= last && bars[from].time < rth.start) from++;
      while (to >= from && bars[to].time >= rth.end) to--;
    }
    const low = Math.min(zone.price_low, zone.price_high);
    const high = Math.max(zone.price_low, zone.price_high);
    let touch = -1;
    for (let k = from; k <= to; k++) {
      if (bars[k].low <= high && bars[k].high >= low) {
        touch = k;
        break;
      }
    }
    if (touch < 0) return { result: 'untouched' };
    const reference = touch > first ? bars[touch - 1].close : bars[touch].open;
    let side;
    if (reference > high) side = 'above';
    else if (reference < low) side = 'below';
    else if (zone.type === 'support') side = 'above';
    else if (zone.type === 'resistance') side = 'below';
    else side = reference >= (low + high) / 2 ? 'above' : 'below';
    const above = side === 'above';
    const broke = bar => (above ? bar.close < low - cfg.breakBy : bar.close > high + cfg.breakBy);
    const bounced = bar => (above ? bar.high >= high + cfg.bounce : bar.low <= low - cfg.bounce);
    const base = { touch, side, low, high };
    if (broke(bars[touch])) return { ...base, result: 'broken', decide: touch };
    for (let k = touch + 1; k <= to; k++) {
      if (bounced(bars[k])) return { ...base, result: 'held', decide: k };
      if (broke(bars[k])) return { ...base, result: 'broken', decide: k };
    }
    return { ...base, result: 'undecided', decide: to };
  }

  /**
   * Bias dne proti RTH: long správně, když RTH close > RTH open, short obráceně.
   * Neutral se hodnotí jen s nastaveným pásmem (RTH se pohne méně než neutralBand).
   * rth: { open, close, complete }; null = den nejde vyhodnotit.
   */
  function biasDay(rth, bias, cfg) {
    if (!rth || rth.open === null || rth.close === null || !rth.complete) return null;
    const move = rth.close - rth.open;
    if (bias === 'long' || bias === 'short') {
      return { result: (bias === 'long' ? move > 0 : move < 0) ? 'right' : 'wrong', move };
    }
    if (bias === 'neutral' && cfg.neutralBand > 0) {
      return { result: Math.abs(move) < cfg.neutralBand ? 'right' : 'wrong', move };
    }
    return null;
  }

  /**
   * Potenciální obchod od svíčky start do konce dne (last): čeká na dotek vstupu,
   * pak rozhodne, co přišlo dřív, stop, nebo cíl. Svíčka se stopem i cílem podle
   * cfg.sameBar; ve svíčce vstupu se v režimu „stop“ počítá i dotek stopu.
   * Bez obojího výsledek k close poslední svíčky dne.
   */
  function idea(bars, start, last, trade, cfg) {
    const long = trade.direction === 'long';
    const risk = Math.abs(trade.entry - trade.stop);
    let fill = -1;
    for (let k = start; k <= last; k++) {
      if (bars[k].low <= trade.entry && bars[k].high >= trade.entry) {
        fill = k;
        break;
      }
    }
    if (fill < 0) return { state: 'unfilled', start };
    const hitsStop = bar => (long ? bar.low <= trade.stop : bar.high >= trade.stop);
    const hitsTarget = bar => (long ? bar.high >= trade.target : bar.low <= trade.target);
    const reward = Math.abs(trade.target - trade.entry);
    const finish = (name, end, points, ambiguous = false) => ({ state: name, start, fill, end, points, r: risk > 0 ? points / risk : 0, ambiguous });
    if (cfg.sameBar === 'stop' && hitsStop(bars[fill])) return finish('sl', fill, -risk, true);
    for (let k = fill + 1; k <= last; k++) {
      const stop = hitsStop(bars[k]);
      const target = hitsTarget(bars[k]);
      if (stop && target) return cfg.sameBar === 'target' ? finish('tp', k, reward, true) : finish('sl', k, -risk, true);
      if (stop) return finish('sl', k, -risk);
      if (target) return finish('tp', k, reward);
    }
    const close = bars[last].close;
    return finish('open', last, long ? close - trade.entry : trade.entry - close);
  }

  function emptyCounts() {
    return { total: 0, held: 0, broken: 0, undecided: 0, untouched: 0 };
  }

  /**
   * Souhrn za období. Vstup:
   *   zones:  [{ type, result }]                         (zóna-den)
   *   bias:   [{ bias, result: 'right'|'wrong'|null, changed }]  (den)
   *   ideas:  [{ outcome, state, r, points, tradeR }]    (potenciální obchod)
   *   trades: [{ result_r, result_usd }]                 (realizované)
   */
  function summarize(input) {
    const zones = { ...emptyCounts(), touched: 0, byType: {} };
    for (const item of input.zones || []) {
      zones.total++;
      zones[item.result]++;
      if (item.result !== 'untouched') zones.touched++;
      const type = zones.byType[item.type] || (zones.byType[item.type] = emptyCounts());
      type.total++;
      type[item.result]++;
    }
    zones.holdRate = zones.held + zones.broken ? zones.held / (zones.held + zones.broken) : null;

    const bias = { days: 0, scored: 0, right: 0, wrong: 0, none: 0, changed: 0, long: { right: 0, wrong: 0 }, short: { right: 0, wrong: 0 }, neutral: { right: 0, wrong: 0, days: 0 } };
    for (const item of input.bias || []) {
      bias.days++;
      if (item.changed) bias.changed++;
      if (item.bias === 'neutral') bias.neutral.days++;
      if (!item.bias) bias.none++;
      if (!item.result) continue;
      bias.scored++;
      bias[item.result]++;
      bias[item.bias][item.result]++;
    }
    bias.rate = bias.scored ? bias.right / bias.scored : null;

    const outcomeKeys = ['skipped', 'missed', 'taken', ''];
    const ideas = { total: 0, tp: 0, sl: 0, open: 0, unfilled: 0, nodata: 0, r: 0, left: { r: 0, points: 0, count: 0 }, saved: { r: 0, count: 0 }, taken: { potential: 0, realized: 0, linked: 0 }, byOutcome: {} };
    for (const key of outcomeKeys) ideas.byOutcome[key] = { count: 0, tp: 0, sl: 0, r: 0 };
    for (const item of input.ideas || []) {
      ideas.total++;
      ideas[item.state] = (ideas[item.state] || 0) + 1;
      const group = ideas.byOutcome[outcomeKeys.includes(item.outcome) ? item.outcome : ''];
      group.count++;
      if (item.state === 'tp') group.tp++;
      if (item.state === 'sl') group.sl++;
      if (typeof item.r === 'number' && item.state !== 'unfilled' && item.state !== 'nodata') {
        ideas.r += item.r;
        group.r += item.r;
        // Nevzaté a propáslé: zisk, který zůstal na stole, a ztráta, které ses vyhnul.
        if (item.outcome === 'skipped' || item.outcome === 'missed') {
          if (item.r > 0) {
            ideas.left.r += item.r;
            ideas.left.points += item.points || 0;
            ideas.left.count++;
          } else if (item.r < 0) {
            ideas.saved.r += -item.r;
            ideas.saved.count++;
          }
        }
        if (item.outcome === 'taken') {
          ideas.taken.potential += item.r;
          if (typeof item.tradeR === 'number') {
            ideas.taken.realized += item.tradeR;
            ideas.taken.linked++;
          }
        }
      }
    }

    const trades = { count: 0, r: 0, usd: 0, withR: 0 };
    for (const item of input.trades || []) {
      trades.count++;
      if (typeof item.result_r === 'number') {
        trades.r += item.result_r;
        trades.withR++;
      }
      if (typeof item.result_usd === 'number') trades.usd += item.result_usd;
    }
    return { zones, bias, ideas, trades };
  }

  const api = { DEFAULTS, config, configKey, zoneDay, biasDay, idea, summarize };
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.HsEval = api;
})(typeof window !== 'undefined' ? window : globalThis);
