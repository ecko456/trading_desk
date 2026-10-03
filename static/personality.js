/*
 * Osobnost a silné stránky v záložce Psychika: test Big Five (IPIP), zadání talentů
 * z reportu CliftonStrengths, souhrn a karta dýchání. Server (lib/personality.php)
 * z toho spočítá oblasti, na které se zaměří rychlý test, a doporučí dýchání a zvuk.
 */

const personality = {
  data: null,
  catalog: null,
  run: { items: [], index: 0, answers: {} },
  pick: { top: [], bottom: [], mode: 'top' },
};

const BIG5_LEVELS = { low: 'Nízko', mid: 'Středně', high: 'Vysoko' };
const BIG5_ORDER = ['S', 'C', 'E', 'O', 'A'];

async function refreshPersonality() {
  try {
    const result = await api('personality');
    personality.data = result.personality;
    personality.catalog = result.catalog;
    renderPersonality();
    renderBreathCard();
  } catch (error) { toast(error.message, 'error'); }
}

function themeChip(theme, rank) {
  return `<span class="theme-chip is-${escapeHtml(theme.domain)}">${rank ? `<b>${rank}</b>` : ''}${escapeHtml(theme.name)}</span>`;
}

function renderPersonality() {
  const root = $('#personalitySummary');
  const data = personality.data;
  $('#startBigFive').textContent = data?.big5 ? 'Zopakovat test' : 'Osobnostní test';
  $('#editStrengths').textContent = data?.strengths ? 'Upravit talenty' : 'Talenty z Gallupu';
  if (!data || (!data.big5 && !data.strengths)) {
    root.className = 'empty-state compact';
    root.innerHTML = '<p class="persona-empty">Zatím tu nic není. Máš dvě možnosti a můžeš použít obě: <strong>osobnostní test</strong> (50 výroků, asi 7 minut) nebo <strong>talenty z reportu CliftonStrengths</strong>, pokud máš Gallupův test hotový. Rychlý test se pak zaměří na to, kde u tebe hrozí jaká chyba, a dýchání vybere rytmus i zvuk podle tebe.</p>';
    return;
  }
  root.className = 'persona';

  const focus = data.focus || [];
  const focusHtml = focus.length
    ? `<ol class="persona-focus-list">${focus.map(item => `<li><strong>${escapeHtml(item.label)}</strong><span>${escapeHtml(item.reasons.join(', '))}</span></li>`).join('')}</ol>
       <p class="muted">Rychlý test dá těmhle oblastem vyšší váhu a přidá na ně až dvě cílené otázky. Den sám neshodí: osobnost je hypotéza, ne důkaz.</p>`
    : '<p class="muted">Z toho, co je vyplněné, nevychází žádná výrazná riziková oblast. Rychlý test běží podle vstupního profilu.</p>';

  const traits = data.big5?.traits || {};
  const big5Html = data.big5 ? `
    <section class="persona-block">
      <p class="eyebrow">Big Five · IPIP</p>
      <div class="persona-traits">${BIG5_ORDER.filter(key => traits[key]).map(key => {
        const trait = traits[key];
        return `<article class="persona-trait is-${trait.level}${trait.risk ? ' is-risk' : ''}">
          <div><strong>${escapeHtml(trait.label)}</strong><em>${BIG5_LEVELS[trait.level]}</em></div>
          <div class="profile-bar"><i data-share="${trait.share}"></i></div>
          <p>${escapeHtml(trait.text)}</p>
        </article>`;
      }).join('')}</div>
    </section>` : '';

  const strengths = data.strengths;
  const strengthsHtml = strengths ? `
    <section class="persona-block">
      <p class="eyebrow">Talenty · CliftonStrengths</p>
      <div class="persona-themes">${strengths.top.map((theme, index) => `
        <article class="persona-theme is-${escapeHtml(theme.domain)}">
          <header>${themeChip(theme, index + 1)}<small>${escapeHtml(theme.domain_label)}</small></header>
          <p><b>Pomáhá:</b> ${escapeHtml(theme.plus)}</p>
          <p><b>Pozor na:</b> ${escapeHtml(theme.minus)}</p>
        </article>`).join('')}</div>
      ${strengths.bottom.length ? `<p class="persona-bottom"><span>Nejslabší talenty:</span> ${strengths.bottom.map(theme => themeChip(theme)).join(' ')}</p>` : ''}
    </section>` : '';

  root.innerHTML = `
    <div class="persona-focus"><p class="eyebrow">Na co se rychlý test zaměří</p>${focusHtml}</div>
    <div class="persona-grid${data.big5 && strengths ? '' : ' is-single'}">${big5Html}${strengthsHtml}</div>`;
  $$('#personalitySummary .profile-bar i').forEach(bar => { bar.style.width = `${bar.dataset.share}%`; });
}

function renderBreathCard() {
  const recommendation = personality.data?.recommendation;
  const preferences = personality.data?.preferences || {};
  if (!recommendation) return;
  const patterns = window.TDBreath?.PATTERNS || {};
  const sounds = window.TDSound?.SOUNDSCAPES || {};
  const patternKey = preferences.pattern || recommendation.pattern;
  const musicKey = preferences.music || recommendation.music;
  const minutes = preferences.minutes || 3;
  const patternNote = preferences.pattern && preferences.pattern !== recommendation.pattern ? 'Tvoje volba.' : recommendation.pattern_reason;
  const musicNote = preferences.music && preferences.music !== recommendation.music ? 'Tvoje volba.' : recommendation.music_reason;
  $('#breathCardBody').innerHTML = `
    <div class="breath-card-orb" aria-hidden="true"><i></i></div>
    <dl class="breath-card-facts">
      <div><dt>Rytmus</dt><dd>${escapeHtml(patterns[patternKey]?.label || '')} · ${minutes} min</dd><p>${escapeHtml(patternNote || '')}</p></div>
      <div><dt>Zvuk</dt><dd>${escapeHtml(musicKey === 'off' ? 'Bez hudby' : sounds[musicKey]?.label || '')}</dd><p>${escapeHtml(musicNote || '')}</p></div>
    </dl>`;
}

/* ---------------------------------------------------------------- test Big Five */

async function openBigFive() {
  if (!personality.catalog) await refreshPersonality();
  if (!personality.catalog) return;
  personality.run.items = personality.catalog.big5_items;
  personality.run.answers = { ...(personality.data?.big5?.answers || {}) };
  personality.run.index = 0;
  $('#bigFiveDialog').showModal();
  showBigFiveItem();
}

function showBigFiveItem() {
  const run = personality.run;
  const item = run.items[run.index];
  if (!item) { finishBigFive(); return; }
  $('#bigFiveProgress').textContent = `Výrok ${run.index + 1} z ${run.items.length}`;
  $('#bigFiveBar').style.width = `${(run.index / run.items.length) * 100}%`;
  $('#bigFiveText').textContent = item.text;
  const current = run.answers[item.key];
  $('#bigFiveOptions').innerHTML = personality.catalog.big5_scale
    .map((label, value) => `<button class="psych-option${current === value ? ' is-selected' : ''}" type="button" data-value="${value}"><span>${value + 1}</span>${escapeHtml(label)}</button>`)
    .join('');
  $('#bigFiveBack').disabled = run.index === 0;
}

function answerBigFive(value) {
  const run = personality.run;
  const item = run.items[run.index];
  if (!item) return;
  run.answers[item.key] = value;
  run.index += 1;
  showBigFiveItem();
}

async function finishBigFive() {
  try {
    const result = await api('personality', { method: 'POST', body: { big5_answers: personality.run.answers } });
    personality.data = result.personality;
    $('#bigFiveDialog').close();
    renderPersonality();
    renderBreathCard();
    state.psychQuestions = [];
    toast('Osobnost je uložená. Rychlý test a dýchání se jí teď přizpůsobí.');
  } catch (error) { toast(error.message, 'error'); }
}

/* ---------------------------------------------------------------- talenty */

async function openStrengths() {
  if (!personality.catalog) await refreshPersonality();
  if (!personality.catalog) return;
  const saved = personality.data?.strengths;
  personality.pick = { top: (saved?.top || []).map(theme => theme.key), bottom: (saved?.bottom || []).map(theme => theme.key), mode: 'top' };
  renderStrengthPicker();
  $('#strengthsDialog').showModal();
}

function renderStrengthPicker() {
  const pick = personality.pick;
  const catalog = personality.catalog;
  const byKey = Object.fromEntries(catalog.themes.map(theme => [theme.key, theme]));
  $$('[data-strength-mode]').forEach(button => button.classList.toggle('is-on', button.dataset.strengthMode === pick.mode));
  const list = pick[pick.mode];
  const max = pick.mode === 'top' ? 10 : 5;
  $('#strengthsHint').textContent = pick.mode === 'top'
    ? `Klikej v pořadí z reportu, od 1. talentu. Stačí prvních pět, víc (až deset) zpřesní výsledek. Vybráno ${list.length} z ${max}.`
    : `Jen pokud máš report všech 34 talentů: posledních pět (30. až 34.). Vybráno ${list.length} z ${max}.`;
  $('#strengthsSelected').innerHTML = list.length
    ? list.map((key, index) => `<li>${themeChip(byKey[key], index + 1)}<span class="strength-en">${escapeHtml(byKey[key].en)}</span>
        <span class="strength-actions"><button class="mini-button" type="button" data-strength-move="-1" data-key="${key}" aria-label="Posunout výš"${index === 0 ? ' disabled' : ''}>↑</button><button class="mini-button" type="button" data-strength-move="1" data-key="${key}" aria-label="Posunout níž"${index === list.length - 1 ? ' disabled' : ''}>↓</button><button class="mini-button danger" type="button" data-strength-remove="${key}" aria-label="Odebrat">×</button></span></li>`).join('')
    : '<li class="tp-empty">Zatím nic. Klikni na talenty níže.</li>';
  const other = pick.mode === 'top' ? pick.bottom : pick.top;
  $('#strengthsCatalog').innerHTML = Object.entries(catalog.domains).map(([domain, label]) => `
    <div class="strength-domain is-${domain}"><p>${escapeHtml(label)}</p><div>${catalog.themes.filter(theme => theme.domain === domain).map(theme => {
      const index = list.indexOf(theme.key);
      const taken = other.includes(theme.key);
      return `<button type="button" class="strength-option is-${domain}${index >= 0 ? ' is-on' : ''}" data-strength="${theme.key}"${taken ? ' disabled' : ''} title="${escapeHtml(theme.en)}">${index >= 0 ? `<b>${index + 1}</b>` : ''}${escapeHtml(theme.name)}</button>`;
    }).join('')}</div></div>`).join('');
}

function toggleStrength(key) {
  const pick = personality.pick;
  const list = pick[pick.mode];
  const max = pick.mode === 'top' ? 10 : 5;
  const index = list.indexOf(key);
  if (index >= 0) list.splice(index, 1);
  else if (list.length < max) list.push(key);
  else toast(`Víc než ${max} talentů tu není potřeba.`, 'error');
  renderStrengthPicker();
}

async function saveStrengths() {
  try {
    const result = await api('personality', { method: 'POST', body: { strengths: { top: personality.pick.top, bottom: personality.pick.bottom } } });
    personality.data = result.personality;
    $('#strengthsDialog').close();
    renderPersonality();
    renderBreathCard();
    state.psychQuestions = [];
    toast(personality.pick.top.length ? 'Talenty jsou uložené.' : 'Talenty jsou smazané.');
  } catch (error) { toast(error.message, 'error'); }
}

/* ---------------------------------------------------------------- vazby */

function bindPersonalityEvents() {
  $('#startBigFive').addEventListener('click', openBigFive);
  $('#editStrengths').addEventListener('click', openStrengths);
  $('#startBreathing').addEventListener('click', () => window.openBreathing());

  $('#bigFiveOptions').addEventListener('click', event => {
    const button = event.target.closest('[data-value]');
    if (button) answerBigFive(Number(button.dataset.value));
  });
  $('#bigFiveBack').addEventListener('click', () => {
    if (personality.run.index === 0) return;
    personality.run.index -= 1;
    showBigFiveItem();
  });
  $('#bigFiveForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') $('#bigFiveDialog').close();
  });
  $('#bigFiveDialog').addEventListener('keydown', event => {
    if (!/^[1-5]$/.test(event.key)) return;
    event.preventDefault();
    answerBigFive(Number(event.key) - 1);
  });

  $('#strengthsDialog').addEventListener('click', event => {
    const mode = event.target.closest('[data-strength-mode]');
    if (mode) { personality.pick.mode = mode.dataset.strengthMode; renderStrengthPicker(); return; }
    const option = event.target.closest('[data-strength]');
    if (option) { toggleStrength(option.dataset.strength); return; }
    const remove = event.target.closest('[data-strength-remove]');
    if (remove) { toggleStrength(remove.dataset.strengthRemove); return; }
    const move = event.target.closest('[data-strength-move]');
    if (move) {
      const list = personality.pick[personality.pick.mode];
      const index = list.indexOf(move.dataset.key);
      const target = index + Number(move.dataset.strengthMove);
      if (index >= 0 && target >= 0 && target < list.length) [list[index], list[target]] = [list[target], list[index]];
      renderStrengthPicker();
    }
  });
  $('#strengthsForm').addEventListener('submit', event => {
    event.preventDefault();
    if (event.submitter?.value === 'cancel') { $('#strengthsDialog').close(); return; }
    saveStrengths();
  });
}
