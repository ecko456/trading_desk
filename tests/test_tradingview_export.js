'use strict';

const assert = require('node:assert/strict');
const TradingViewZones = require('../static/tradingview-export.js');

const result = TradingViewZones.generate([
  {
    name: 'A long zóna',
    direction: 'long',
    price_low: '6354.25',
    price_high: '6361',
    source: 'Weekly VAH + VPOC',
  },
  {
    name: 'B short zóna',
    direction: 'short',
    price_low: '6402',
    price_high: '6409',
    source: 'LVN',
  },
  {
    name: 'Neúplná zóna',
    direction: 'both',
    price_low: '',
    price_high: '6420',
  },
], {
  market: 'ES',
  date: '2026-08-09',
  showLabels: true,
  includeSource: true,
  extendMode: 'right',
  fillTransparency: 86,
});

assert.equal(result.exported, 2);
assert.deepEqual(result.skipped, ['Neúplná zóna']);
assert.match(result.code, /^\/\/@version=6/);
assert.match(result.code, /indicator\("Trading Zones — ES"/);
assert.match(result.code, /zone1TopInput = input\.float\(6361\.0/);
assert.match(result.code, /zone1BottomInput = input\.float\(6354\.25/);
assert.match(result.code, /A long zóna \| Weekly VAH \+ VPOC/);
assert.match(result.code, /max_labels_count = 20/);
assert.match(result.code, /zone1Middle = \(zone1Top \+ zone1Bottom\) \/ 2\.0/);
assert.match(result.code, /label\.new\(x = zoneRight, y = zone1Middle/);
assert.match(result.code, /style = label\.style_label_left/);
assert.match(result.code, /label\.set_xy\(zone1Label, zoneRight, zone1Middle\)/);
assert.match(result.code, /extend = extend\.right/);
assert.match(result.code, /color\.rgb\(82, 199, 138\)/);
assert.match(result.code, /color\.rgb\(238, 124, 124\)/);

const reversed = TradingViewZones.zoneBounds({ price_low: 100, price_high: 90 });
assert.deepEqual(reversed, { bottom: 90, top: 100 });

console.log(`TradingView export: ${result.exported} zóny, ${result.code.split('\n').length} řádků Pine Scriptu.`);
