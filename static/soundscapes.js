/*
 * Zvukové kulisy k dechovému cvičení. Nic se nestahuje: zvuk vzniká přímo v prohlížeči
 * (Web Audio), takže nejsou potřeba nahrávky, licence ani soubory na serveru.
 *
 * Každá kulisa umí:
 *   start(at)               spustí zdroje,
 *   stop(at)                zastaví je,
 *   schedule(until)         naplánuje události (údery misek, kapky, tóny) do času `until`,
 *   breath(kind, s, at)     přizpůsobí se fázi dechu (inhale / hold / exhale).
 * Bez volání breath() běží samy (ukázka, kulisa bez dýchání).
 *
 * Funguje i s OfflineAudioContext, takže se dá vyrenderovat a změřit v testu.
 */
(function (root) {
  'use strict';

  const SOUNDSCAPES = {
    ocean: { label: 'Oceán', note: 'Vlny, které dýchají s tebou' },
    drone: { label: 'Hluboký tón', note: 'Teplý tón bez melodie, uzemní' },
    bowls: { label: 'Tibetské misky', note: 'Úder misky na začátku nádechu' },
    rain: { label: 'Déšť', note: 'Rovnoměrný déšť ztiší myšlenky' },
    chimes: { label: 'Zvonkohra', note: 'Jemná pentatonická melodie' },
  };

  /* ------------------------------------------------------------ pomocníci */

  // Deterministický generátor, aby kulisa zněla pokaždé stejně a šla testovat.
  function random(seed) {
    let state = seed >>> 0;
    return function next() {
      state = (state + 0x6D2B79F5) >>> 0;
      let t = state;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  // Šum se smyčkou bez švu: konec se prolne se začátkem.
  function noiseBuffer(ctx, seconds, color, rand) {
    const length = Math.floor(ctx.sampleRate * seconds);
    const fade = Math.floor(ctx.sampleRate * 0.5);
    const buffer = ctx.createBuffer(2, length, ctx.sampleRate);
    for (let channel = 0; channel < 2; channel += 1) {
      const raw = new Float32Array(length + fade);
      let b0 = 0; let b1 = 0; let b2 = 0; let b3 = 0; let b4 = 0; let b5 = 0; let b6 = 0; let last = 0;
      for (let i = 0; i < raw.length; i += 1) {
        const white = rand() * 2 - 1;
        if (color === 'pink') {
          // Filtr Paula Kelleta: dostatečně přesný růžový šum.
          b0 = 0.99886 * b0 + white * 0.0555179; b1 = 0.99332 * b1 + white * 0.0750759;
          b2 = 0.96900 * b2 + white * 0.1538520; b3 = 0.86650 * b3 + white * 0.3104856;
          b4 = 0.55000 * b4 + white * 0.5329522; b5 = -0.7616 * b5 - white * 0.0168980;
          raw[i] = (b0 + b1 + b2 + b3 + b4 + b5 + b6 + white * 0.5362) * 0.11;
          b6 = white * 0.115926;
        } else if (color === 'brown') {
          last = (last + 0.02 * white) / 1.02;
          raw[i] = last * 3.5;
        } else {
          raw[i] = white;
        }
      }
      const data = buffer.getChannelData(channel);
      for (let i = 0; i < length; i += 1) {
        data[i] = i < fade ? raw[i] * (i / fade) + raw[length + i] * (1 - i / fade) : raw[i];
      }
    }
    return buffer;
  }

  // Dozvuk z exponenciálně doznívajícího šumu.
  function reverb(ctx, seconds, decay, rand) {
    const length = Math.floor(ctx.sampleRate * seconds);
    const impulse = ctx.createBuffer(2, length, ctx.sampleRate);
    for (let channel = 0; channel < 2; channel += 1) {
      const data = impulse.getChannelData(channel);
      for (let i = 0; i < length; i += 1) data[i] = (rand() * 2 - 1) * Math.pow(1 - i / length, decay);
    }
    const node = ctx.createConvolver();
    node.buffer = impulse;
    return node;
  }

  // Plynulý přechod parametru bez cvaknutí (navazuje na aktuální hodnotu).
  function glide(param, value, at, seconds) {
    param.cancelScheduledValues(at);
    param.setTargetAtTime(value, at, Math.max(0.05, seconds / 4));
  }

  function gain(ctx, value) {
    const node = ctx.createGain();
    node.gain.value = value;
    return node;
  }

  function filter(ctx, type, frequency, q) {
    const node = ctx.createBiquadFilter();
    node.type = type;
    node.frequency.value = frequency;
    node.Q.value = q;
    return node;
  }

  function oscillator(ctx, type, frequency, detune) {
    const node = ctx.createOscillator();
    node.type = type;
    node.frequency.value = frequency;
    node.detune.value = detune || 0;
    return node;
  }

  // Krátký tón se zvonivým doznivem; zdroj se po doznění sám zastaví.
  function bell(ctx, destination, frequency, at, peak, decay, pan) {
    const out = gain(ctx, 0);
    const panner = ctx.createStereoPanner ? ctx.createStereoPanner() : null;
    if (panner) { panner.pan.value = pan || 0; out.connect(panner).connect(destination); } else out.connect(destination);
    out.gain.setValueAtTime(0, at);
    out.gain.linearRampToValueAtTime(peak, at + 0.02);
    out.gain.setTargetAtTime(0, at + 0.02, decay / 5);
    [[1, 1], [2, 0.12]].forEach(([ratio, level]) => {
      const tone = oscillator(ctx, 'sine', frequency * ratio);
      const toneGain = gain(ctx, level);
      tone.connect(toneGain).connect(out);
      tone.start(at);
      tone.stop(at + decay + 0.5);
    });
  }

  /* ------------------------------------------------------------ kulisy */

  function base(ctx, output, seed) {
    const scape = {
      ctx,
      rand: random(seed),
      out: gain(ctx, 1),
      sources: [],
      synced: false,
      scheduled: 0,
      start(at) { this.sources.forEach(source => source.start(at)); },
      stop(at) { this.sources.forEach(source => { try { source.stop(at); } catch (error) { /* už zastavený */ } }); },
      schedule() {},
      breath() {},
    };
    scape.out.connect(output);
    return scape;
  }

  function withReverb(ctx, scape, wetLevel, seconds) {
    const wet = gain(ctx, wetLevel);
    const space = reverb(ctx, seconds || 3.5, 2.4, scape.rand);
    space.connect(wet).connect(scape.out);
    return space;
  }

  const BUILDERS = {
    // Vlny: hnědý šum, filtr se otevírá s nádechem (vlna přichází) a zavírá s výdechem.
    ocean(ctx, output, seed) {
      const scape = base(ctx, output, seed);
      const noise = ctx.createBufferSource();
      noise.buffer = noiseBuffer(ctx, 11, 'brown', scape.rand);
      noise.loop = true;
      const tone = filter(ctx, 'lowpass', 420, 0.4);
      const swell = gain(ctx, 0.32);
      noise.connect(tone).connect(swell).connect(scape.out);

      const foamNoise = ctx.createBufferSource();
      foamNoise.buffer = noiseBuffer(ctx, 7, 'pink', scape.rand);
      foamNoise.loop = true;
      const foamFilter = filter(ctx, 'bandpass', 2600, 0.5);
      const foam = gain(ctx, 0);
      foamNoise.connect(foamFilter).connect(foam).connect(scape.out);

      const pad = gain(ctx, 0.018);
      [110, 164.81].forEach(frequency => { const osc = oscillator(ctx, 'sine', frequency); osc.connect(pad); scape.sources.push(osc); });
      pad.connect(scape.out);
      scape.sources.push(noise, foamNoise);

      const wave = (kind, seconds, at) => {
        if (kind === 'inhale') {
          glide(tone.frequency, 1500, at, seconds);
          glide(swell.gain, 0.62, at, seconds);
          glide(foam.gain, 0.05, at + seconds * 0.5, seconds * 0.5);
        } else if (kind === 'exhale') {
          glide(tone.frequency, 360, at, seconds);
          glide(swell.gain, 0.26, at, seconds);
          glide(foam.gain, 0, at, seconds * 0.4);
        }
      };
      scape.breath = (kind, seconds, at) => { scape.synced = true; wave(kind, seconds, at); };
      // Bez dýchání: vlna každých zhruba deset sekund.
      scape.schedule = until => {
        if (scape.synced) return;
        let at = Math.max(scape.scheduled, ctx.currentTime);
        while (at < until) {
          const rise = 4 + scape.rand() * 1.5;
          const fall = 5 + scape.rand() * 2;
          wave('inhale', rise, at);
          wave('exhale', fall, at + rise);
          at += rise + fall;
        }
        scape.scheduled = at;
      };
      return scape;
    },

    // Teplý tón: dvě rozladěné pily přes dolní propust, kvinta a oktáva; filtr dýchá.
    drone(ctx, output, seed) {
      const scape = base(ctx, output, seed);
      const space = withReverb(ctx, scape, 0.35, 4);
      const root = 73.42;
      const body = filter(ctx, 'lowpass', 300, 0.5);
      const bodyGain = gain(ctx, 0.16);
      body.connect(bodyGain);
      bodyGain.connect(scape.out);
      bodyGain.connect(space);
      [-6, 6].forEach(cents => { const osc = oscillator(ctx, 'sawtooth', root, cents); osc.connect(body); scape.sources.push(osc); });
      [[root * 1.5, 'sine', 0.09], [root * 2, 'triangle', 0.035], [root * 6, 'sine', 0.006]].forEach(([frequency, type, level]) => {
        const osc = oscillator(ctx, type, frequency);
        const level_ = gain(ctx, level);
        osc.connect(level_);
        level_.connect(scape.out);
        level_.connect(space);
        scape.sources.push(osc);
      });
      // Pomalé „dýchání“ hlasitosti, aby tón nebyl mrtvý.
      const lfo = oscillator(ctx, 'sine', 0.05);
      const depth = gain(ctx, 0.03);
      lfo.connect(depth).connect(bodyGain.gain);
      scape.sources.push(lfo);

      scape.breath = (kind, seconds, at) => {
        scape.synced = true;
        if (kind === 'inhale') glide(body.frequency, 720, at, seconds);
        if (kind === 'exhale') glide(body.frequency, 230, at, seconds);
      };
      return scape;
    },

    // Misky: neharmonické alikvoty s pomalým zázněje a dlouhým doznivem.
    bowls(ctx, output, seed) {
      const scape = base(ctx, output, seed);
      const space = withReverb(ctx, scape, 0.45, 5);
      const hum = gain(ctx, 0.016);
      [98, 146.83].forEach(frequency => { const osc = oscillator(ctx, 'sine', frequency); osc.connect(hum); scape.sources.push(osc); });
      hum.connect(scape.out);
      const fundamentals = [174.61, 196, 220];
      let lastIndex = -1;

      const strike = (at, strength) => {
        let index = Math.floor(scape.rand() * fundamentals.length);
        if (index === lastIndex) index = (index + 1) % fundamentals.length;
        lastIndex = index;
        const fundamental = fundamentals[index];
        [[1, 1, 9], [2.76, 0.42, 6], [5.4, 0.18, 3.5], [8.93, 0.07, 2]].forEach(([ratio, level, decay]) => {
          [-0.7, 0.7].forEach(beat => {
            const osc = oscillator(ctx, 'sine', fundamental * ratio + beat);
            const env = gain(ctx, 0);
            env.gain.setValueAtTime(0, at);
            env.gain.linearRampToValueAtTime(0.09 * level * strength, at + 0.015);
            env.gain.setTargetAtTime(0, at + 0.015, decay / 5);
            osc.connect(env);
            env.connect(scape.out);
            env.connect(space);
            osc.start(at);
            osc.stop(at + decay + 1);
          });
        });
      };
      scape.strike = strike;
      scape.breath = (kind, seconds, at) => {
        scape.synced = true;
        if (kind === 'inhale') strike(at, 1);
      };
      scape.schedule = until => {
        if (scape.synced) return;
        let at = Math.max(scape.scheduled, ctx.currentTime + 0.1);
        while (at < until) {
          strike(at, 0.8 + scape.rand() * 0.2);
          at += 9 + scape.rand() * 4;
        }
        scape.scheduled = at;
      };
      return scape;
    },

    // Déšť: růžový šum jako podklad, k tomu jednotlivé kapky a tichý akord.
    rain(ctx, output, seed) {
      const scape = base(ctx, output, seed);
      const bed = ctx.createBufferSource();
      bed.buffer = noiseBuffer(ctx, 9, 'pink', scape.rand);
      bed.loop = true;
      const high = filter(ctx, 'highpass', 450, 0.5);
      const low = filter(ctx, 'lowpass', 5200, 0.5);
      bed.connect(high).connect(low).connect(gain(ctx, 0.5)).connect(scape.out);
      const drops = gain(ctx, 1);
      drops.connect(scape.out);
      const dropBuffer = noiseBuffer(ctx, 0.6, 'white', scape.rand);
      const pad = gain(ctx, 0.022);
      [130.81, 196, 246.94].forEach(frequency => { const osc = oscillator(ctx, 'sine', frequency); osc.connect(pad); scape.sources.push(osc); });
      pad.connect(scape.out);
      scape.sources.push(bed);

      scape.schedule = until => {
        let at = Math.max(scape.scheduled, ctx.currentTime + 0.05);
        while (at < until) {
          const source = ctx.createBufferSource();
          source.buffer = dropBuffer;
          const band = filter(ctx, 'bandpass', 1800 + scape.rand() * 3400, 3 + scape.rand() * 4);
          const env = gain(ctx, 0);
          const peak = 0.05 + scape.rand() * 0.09;
          env.gain.setValueAtTime(0, at);
          env.gain.linearRampToValueAtTime(peak, at + 0.003);
          env.gain.setTargetAtTime(0, at + 0.003, 0.012 + scape.rand() * 0.02);
          const panner = ctx.createStereoPanner ? ctx.createStereoPanner() : null;
          source.connect(band).connect(env);
          if (panner) { panner.pan.value = scape.rand() * 1.6 - 0.8; env.connect(panner).connect(drops); } else env.connect(drops);
          source.start(at, scape.rand() * 0.5);
          source.stop(at + 0.12);
          at += -Math.log(1 - scape.rand()) / 7;
        }
        scape.scheduled = at;
      };
      scape.breath = (kind, seconds, at) => {
        scape.synced = true;
        if (kind === 'inhale') glide(pad.gain, 0.04, at, seconds);
        if (kind === 'exhale') glide(pad.gain, 0.016, at, seconds);
      };
      return scape;
    },

    // Zvonkohra: teplý podklad a občasné tóny z pentatoniky C dur.
    chimes(ctx, output, seed) {
      const scape = base(ctx, output, seed);
      const space = withReverb(ctx, scape, 0.6, 4.5);
      const warmth = filter(ctx, 'lowpass', 900, 0.4);
      const padGain = gain(ctx, 0.05);
      warmth.connect(padGain);
      padGain.connect(scape.out);
      padGain.connect(space);
      [[130.81, -4], [196, 3], [293.66, -2], [329.63, 4]].forEach(([frequency, cents]) => {
        const osc = oscillator(ctx, 'triangle', frequency, cents);
        osc.connect(warmth);
        scape.sources.push(osc);
      });
      const notes = [523.25, 587.33, 659.25, 783.99, 880, 1046.5];
      let last = -1;
      scape.schedule = until => {
        let at = Math.max(scape.scheduled, ctx.currentTime + 0.3);
        while (at < until) {
          let index = Math.floor(scape.rand() * notes.length);
          if (index === last) index = (index + 2) % notes.length;
          last = index;
          bell(ctx, space, notes[index], at, 0.05, 3.2, scape.rand() * 1.2 - 0.6);
          bell(ctx, scape.out, notes[index], at, 0.022, 2.4, 0);
          at += 1.8 + scape.rand() * 2.7;
        }
        scape.scheduled = at;
      };
      scape.breath = (kind, seconds, at) => {
        scape.synced = true;
        if (kind === 'inhale') glide(warmth.frequency, 1700, at, seconds);
        if (kind === 'exhale') glide(warmth.frequency, 650, at, seconds);
      };
      return scape;
    },
  };

  // Vyrovnání hlasitosti, aby přepnutí kulisy neznamenalo skok (změřeno offline,
  // cíl kolem −27 dB RMS při hlasitosti 60).
  const LEVELS = { ocean: 1, drone: 0.7, bowls: 1.8, rain: 1.15, chimes: 1.3 };

  function createSoundscape(ctx, key, output, seed) {
    const builder = BUILDERS[key];
    if (!builder) throw new Error(`Neznámá kulisa: ${key}`);
    const scape = builder(ctx, output, seed || 20261003);
    scape.out.gain.value = LEVELS[key];
    return scape;
  }

  // Jemný zvuk při změně fáze, aby šlo dýchat i se zavřenýma očima.
  const CUE_TONES = { inhale: [659.25, 0.07], hold: [523.25, 0.035], exhale: [440, 0.06], end: [392, 0.08] };
  function playCue(ctx, output, kind, at) {
    const [frequency, peak] = CUE_TONES[kind] || CUE_TONES.hold;
    bell(ctx, output, frequency, at, peak, kind === 'end' ? 4 : 1.6, 0);
    if (kind === 'end') bell(ctx, output, frequency * 1.5, at + 0.35, peak * 0.7, 4, 0);
  }

  const api = { SOUNDSCAPES, KEYS: Object.keys(SOUNDSCAPES), createSoundscape, playCue, random };
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.TDSound = api;
})(typeof window !== 'undefined' ? window : globalThis);
