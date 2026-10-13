/*
 * Tep z Bluetooth snímače (standardní služba Heart Rate 0x180D, měření 0x2A37).
 *
 * Funguje s hrudním pásem i s aplikací, která tep z Apple Watch přeposílá jako snímač.
 * Prohlížeč: Chrome nebo Edge (Windows, macOS, Android), stránka přes HTTPS. Safari
 * a Firefox Web Bluetooth neumí.
 *
 * Výpočty (čtení zprávy, RMSSD, průměry po 5 s, pravidla upozornění) jsou čisté funkce,
 * testuje je tests/test_heartrate.js. TDHeartSensor obsluhuje samotné připojení.
 */
(function (root) {
  'use strict';

  /** Zpráva Heart Rate Measurement: tep (8 nebo 16 bitů), kontakt, R-R intervaly v ms. */
  function parseMeasurement(view) {
    const flags = view.getUint8(0);
    let offset = 1;
    const wide = (flags & 0x01) !== 0;
    const bpm = wide ? view.getUint16(offset, true) : view.getUint8(offset);
    offset += wide ? 2 : 1;
    const contactSupported = (flags & 0x04) !== 0;
    const contact = contactSupported ? (flags & 0x02) !== 0 : null;
    if (flags & 0x08) offset += 2; // spálená energie
    const rr = [];
    if (flags & 0x10) {
      for (; offset + 1 < view.byteLength; offset += 2) rr.push(Math.round(view.getUint16(offset, true) * 1000 / 1024));
    }
    return { bpm, contact, rr };
  }

  /**
   * RMSSD (ms) z R-R intervalů: odmocnina průměru čtverců rozdílů po sobě jdoucích intervalů.
   * Artefakty (intervaly mimo 300–2000 ms, skok přes 250 ms) se vynechají.
   */
  function rmssd(intervals) {
    const clean = intervals.filter(value => value >= 300 && value <= 2000);
    let sum = 0;
    let count = 0;
    for (let i = 1; i < clean.length; i += 1) {
      const diff = clean[i] - clean[i - 1];
      if (Math.abs(diff) > 250) continue;
      sum += diff * diff;
      count += 1;
    }
    return count >= 10 ? Math.round(Math.sqrt(sum / count) * 10) / 10 : null;
  }

  /**
   * Průměry po `seconds` (výchozí 5 s). Každé čtení přidá tep; uzavřené okno vrátí
   * { ts (unix s, začátek okna), bpm, rmssd z R-R za poslední minutu }.
   */
  function createBinner(seconds = 5) {
    let bin = null;
    let rrWindow = [];
    return {
      push(ms, bpm, rr = []) {
        const out = [];
        const start = Math.floor(ms / 1000 / seconds) * seconds;
        if (bin && bin.ts !== start) {
          out.push(close());
        }
        if (!bin) bin = { ts: start, sum: 0, n: 0 };
        if (bpm >= 25 && bpm <= 250) { bin.sum += bpm; bin.n += 1; }
        rr.forEach(value => rrWindow.push([ms, value]));
        rrWindow = rrWindow.filter(([at]) => ms - at <= 60000);
        return out.filter(Boolean);
      },
      flush() { const done = close(); return done ? [done] : []; },
    };
    function close() {
      if (!bin || !bin.n) { bin = null; return null; }
      const done = { ts: bin.ts, bpm: Math.round(bin.sum / bin.n * 10) / 10, rmssd: rmssd(rrWindow.map(([, value]) => value)) };
      bin = null;
      return done;
    }
  }

  function median(values) {
    if (!values.length) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
  }

  /** Klidový tep z okna klidového měření (medián, aspoň 6 vzorků). */
  function baselineOf(bins, from, to) {
    const inside = bins.filter(bin => bin.ts >= from && bin.ts <= to);
    if (inside.length < 6) return null;
    const rr = inside.map(bin => bin.rmssd).filter(value => value !== null && value !== undefined);
    return { bpm: Math.round(median(inside.map(bin => bin.bpm)) * 10) / 10, rmssd: rr.length >= 6 ? median(rr) : null };
  }

  /**
   * Má se teď upozornit? Jen s naučeným modelem (aspoň 15 ohodnocených obchodů s tepem).
   *  - high: tep drží nad hranicí po celou dobu `sustain` (aspoň 80 % pokrytí vzorky);
   *  - spike: během půl minuty vyskočil o hranici a je aspoň na ¾ hranice;
   *  - hrv: variabilita drží pod naučeným podílem klidové hodnoty.
   * Po upozornění je klid `cooldown` sekund.
   */
  function detectAlert({ bins, baseline, model, now, lastAlert = null, binSeconds = 5 }) {
    if (!model || !model.ready || !baseline || baseline.bpm == null || !bins.length) return null;
    const threshold = Number(model.threshold);
    if (!Number.isFinite(threshold) || threshold <= 0) return null;
    if (lastAlert != null && now - lastAlert < (model.cooldown || 600)) return null;
    const sustain = model.sustain || 90;
    const recent = bins.filter(bin => bin.ts > now - sustain - binSeconds);
    const latest = bins[bins.length - 1];
    if (now - latest.ts > 30) return null;
    const delta = Math.round((latest.bpm - baseline.bpm) * 10) / 10;
    const coverage = recent.length * binSeconds / sustain;
    if (coverage >= 0.8 && recent.every(bin => bin.bpm - baseline.bpm >= threshold)) {
      return { kind: 'high', delta, bpm: latest.bpm };
    }
    const last30 = bins.filter(bin => bin.ts > now - 30);
    const before = bins.filter(bin => bin.ts <= now - 30 && bin.ts > now - 150);
    if (last30.length && before.length >= 6) {
      const jump = Math.max(...last30.map(bin => bin.bpm)) - Math.min(...before.map(bin => bin.bpm));
      if (jump >= threshold && delta >= threshold * 0.75) return { kind: 'spike', delta, bpm: latest.bpm, jump: Math.round(jump * 10) / 10 };
    }
    if (model.hrv_ratio && baseline.rmssd) {
      const withRr = recent.filter(bin => bin.rmssd != null);
      if (withRr.length * binSeconds / sustain >= 0.8 && withRr.every(bin => bin.rmssd / baseline.rmssd <= model.hrv_ratio)) {
        return { kind: 'hrv', delta, bpm: latest.bpm, ratio: Math.round(latest.rmssd / baseline.rmssd * 100) / 100 };
      }
    }
    return null;
  }

  const api = { parseMeasurement, rmssd, createBinner, baselineOf, detectAlert, median };
  root.TDHeartMath = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;

  /* ---------------------------------------------------------------- připojení */

  /**
   * Snímač přes Web Bluetooth. Události: 'reading' {bpm, rr, contact, at}, 'status'
   * {state: 'connecting' | 'connected' | 'lost' | 'off', name, battery}.
   */
  class TDHeartSensor extends EventTarget {
    constructor() {
      super();
      this.device = null;
      this.characteristic = null;
      this.state = 'off';
      this.battery = null;
      this.retries = 0;
      this.onValue = event => {
        const reading = parseMeasurement(event.target.value);
        this.dispatchEvent(new CustomEvent('reading', { detail: { ...reading, at: Date.now() } }));
      };
      this.onDisconnect = () => this.reconnect();
    }

    static supported() {
      return typeof navigator !== 'undefined' && !!navigator.bluetooth && window.isSecureContext;
    }

    get name() { return this.device?.name || 'Snímač tepu'; }

    setState(state) {
      this.state = state;
      this.dispatchEvent(new CustomEvent('status', { detail: { state, name: this.name, battery: this.battery } }));
    }

    /** Výběr snímače (okno prohlížeče) a připojení. Musí přijít z klepnutí. */
    async connect() {
      if (!TDHeartSensor.supported()) throw new Error('Tenhle prohlížeč Bluetooth neumí. Na Windows otevři Trading Desk v Chromu nebo Edge.');
      const device = await navigator.bluetooth.requestDevice({ filters: [{ services: ['heart_rate'] }], optionalServices: ['battery_service'] });
      this.useDevice(device);
      await this.attach();
    }

    useDevice(device) {
      if (this.device && this.device !== device) this.device.removeEventListener('gattserverdisconnected', this.onDisconnect);
      this.device = device;
      device.addEventListener('gattserverdisconnected', this.onDisconnect);
      try { localStorage.setItem('td.heart.device', device.id || ''); } catch (error) { /* bez úložiště */ }
    }

    async attach() {
      this.setState('connecting');
      const server = await this.device.gatt.connect();
      const service = await server.getPrimaryService('heart_rate');
      if (this.characteristic) this.characteristic.removeEventListener('characteristicvaluechanged', this.onValue);
      this.characteristic = await service.getCharacteristic('heart_rate_measurement');
      this.characteristic.addEventListener('characteristicvaluechanged', this.onValue);
      await this.characteristic.startNotifications();
      this.retries = 0;
      try {
        const battery = await (await server.getPrimaryService('battery_service')).getCharacteristic('battery_level');
        this.battery = (await battery.readValue()).getUint8(0);
      } catch (error) { this.battery = null; }
      this.setState('connected');
    }

    /** Po výpadku signálu zkusí spojení obnovit (5×, s prodlevou), pak čeká na klepnutí. */
    async reconnect() {
      if (this.state === 'off' || !this.device) return;
      this.setState('lost');
      while (this.state === 'lost' && this.retries < 5) {
        this.retries += 1;
        await new Promise(resolve => setTimeout(resolve, 1500 * this.retries));
        if (this.state !== 'lost') return;
        try { await this.attach(); return; } catch (error) { /* další pokus */ }
      }
    }

    /** Snímač povolený už dřív (bez okna výběru), když to prohlížeč umí. */
    async reconnectKnown() {
      if (!TDHeartSensor.supported() || !navigator.bluetooth.getDevices) return false;
      let id = '';
      try { id = localStorage.getItem('td.heart.device') || ''; } catch (error) { id = ''; }
      const devices = await navigator.bluetooth.getDevices();
      const device = devices.find(item => item.id === id) || null;
      if (!device) return false;
      this.useDevice(device);
      try { await this.attach(); return true; } catch (error) { this.setState('lost'); return false; }
    }

    disconnect() {
      this.state = 'off';
      try { this.characteristic?.removeEventListener('characteristicvaluechanged', this.onValue); } catch (error) { /* nic */ }
      try { if (this.device?.gatt?.connected) this.device.gatt.disconnect(); } catch (error) { /* nic */ }
      this.setState('off');
    }
  }

  root.TDHeartSensor = TDHeartSensor;
})(typeof window !== 'undefined' ? window : globalThis);
