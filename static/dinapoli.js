'use strict';

/*
 * DiNapoli úrovně: retracementy F3 (.382) a F5 (.618), cíle expanze COP (.618),
 * OP (1.0) a XOP (1.618) a místa, kde se kryjí (confluence, agreement).
 * Stejný výpočet má server v lib/workspace.php (dn_analysis); test je porovnává.
 */
(function attachDiNapoli(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.DiNapoli = api;
})(typeof window !== 'undefined' ? window : globalThis, function createDiNapoli() {
  const RETRACEMENTS = { F3: 0.382, F5: 0.618 };
  const EXPANSIONS = { COP: 0.618, OP: 1.0, XOP: 1.618 };

  function toNumber(value) {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  const round = value => Math.round(value * 1e6) / 1e6;

  function swingLevels(swing, index) {
    const a = toNumber(swing.price_a);
    const b = toNumber(swing.price_b);
    const c = toNumber(swing.price_c);
    const label = String(swing.label || '').trim() || `S${index + 1}`;
    if (a === null || b === null || a === b) return [];
    const range = b - a;
    const levels = Object.entries(RETRACEMENTS).map(([kind, ratio]) => ({ swing: label, swing_index: index, kind, group: 'retracement', price: round(b - ratio * range) }));
    if (c !== null) {
      Object.entries(EXPANSIONS).forEach(([kind, ratio]) => levels.push({ swing: label, swing_index: index, kind, group: 'expansion', price: round(c + ratio * range) }));
    }
    return levels;
  }

  function autoTolerance(swings) {
    const prices = [];
    swings.forEach(swing => ['price_a', 'price_b'].forEach(key => {
      const price = toNumber(swing[key]);
      if (price !== null) prices.push(Math.abs(price));
    }));
    if (!prices.length) return 0;
    prices.sort((x, y) => x - y);
    return round(prices[Math.floor((prices.length - 1) / 2)] * 0.0005);
  }

  function analyze(swings, tolerance = null) {
    const levels = swings.flatMap((swing, index) => swingLevels(swing, index));
    const auto = autoTolerance(swings);
    const tol = toNumber(tolerance) !== null && toNumber(tolerance) > 0 ? toNumber(tolerance) : auto;
    levels.sort((x, y) => x.price - y.price);

    const groups = [];
    let group = [];
    levels.forEach(level => {
      if (group.length && level.price - group[group.length - 1].price > tol) {
        groups.push(group);
        group = [];
      }
      group.push(level);
    });
    if (group.length) groups.push(group);

    const clusters = [];
    groups.forEach(members => {
      if (members.length < 2 || new Set(members.map(m => m.swing_index)).size < 2) return;
      const retracementSwings = [...new Set(members.filter(m => m.group === 'retracement').map(m => m.swing_index))];
      const expansions = members.filter(m => m.group === 'expansion');
      const types = [];
      if (retracementSwings.length >= 2) types.push('confluence');
      if (retracementSwings.length && expansions.some(expansion => retracementSwings.some(index => index !== expansion.swing_index))) types.push('agreement');
      if (!types.length) return;
      const prices = members.map(m => m.price);
      clusters.push({ types, low: Math.min(...prices), high: Math.max(...prices), members: members.map(m => `${m.swing} ${m.kind}`) });
    });
    return { levels, clusters, tolerance: tol, auto_tolerance: auto };
  }

  return { RETRACEMENTS, EXPANSIONS, swingLevels, analyze, autoTolerance };
});
