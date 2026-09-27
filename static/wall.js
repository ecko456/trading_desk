'use strict';

/*
 * Společná nástěnka: příspěvky, sdílení z deníku, reakce a komentáře.
 * Načítá se před app.js a používá jeho pomocníky ($, api, toast, escapeHtml…)
 * až při volání, proto tu na nejvyšší úrovni jen definujeme funkce.
 */

const wallState = {
  kind: '',
  author: 0,
  authorName: '',
  oldest: 0,
  hasMore: false,
  loading: false,
  posts: new Map(),
  members: [],
  shares: new Map(),
  composerFiles: [],
  share: null,
  pickerTab: 'plan',
};

const WALL_REACTIONS = {
  like: { label: 'Líbí se', icon: '<path d="M12 20.2s-7.5-4.4-7.5-10A4.3 4.3 0 0 1 12 7.4a4.3 4.3 0 0 1 7.5 2.8c0 5.6-7.5 10-7.5 10z"/>' },
  fire: { label: 'Silné', icon: '<path d="M12 21c-3.9 0-6.5-2.6-6.5-6.1 0-3.4 2.4-5.2 3.6-8.4.6 1.7 1.6 2.8 2.7 3.3.2-2.6 1.4-4.9 3.4-6.3-.3 2.6.7 4.5 2 6.1 1 1.3 1.8 2.9 1.8 5.1 0 3.6-3 6.3-7 6.3z"/><path d="M12 21c-1.7 0-2.9-1.2-2.9-2.8 0-1.8 1.5-2.6 2.2-4.2.9 1.5 3.6 2.3 3.6 4.4 0 1.6-1.3 2.6-2.9 2.6z"/>' },
  target: { label: 'Přesné', icon: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4.2"/><circle cx="12" cy="12" r="1"/>' },
  think: { label: 'Zajímavé', icon: '<path d="M9 18h6M10 21h4"/><path d="M12 3.5a5.8 5.8 0 0 0-3.4 10.5c.6.5.9 1.1.9 1.8v.2h5v-.2c0-.7.3-1.3.9-1.8A5.8 5.8 0 0 0 12 3.5z"/>' },
};

const WALL_KIND_LABELS = { plan: 'Náhled', trade: 'Obchod', strategy: 'Strategie', note: 'Příspěvek' };
const PA_LABELS = { long: 'Long', short: 'Short', balance: 'Balance', neutral: 'Balance' };

function svgIcon(paths, extra = '') {
  return `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"${extra}>${paths}</svg>`;
}

const ICONS = {
  comment: '<path d="M5 17.5 3.5 21l4.2-1.8A8.5 8.5 0 1 0 5 17.5z"/>',
  share: '<circle cx="17.5" cy="6" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><circle cx="17.5" cy="18" r="2.5"/><path d="m8.7 10.8 6.6-3.6M8.7 13.2l6.6 3.6"/>',
  send: '<path d="M4 12 20 4l-4.5 16-3.5-6.5z"/><path d="m12 13.5 8-9.5"/>',
  trash: '<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/>',
  lock: '<rect x="5.5" y="10.5" width="13" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
};

/* ---------------------------------------------------------------- pomocníci */

function initialsOf(name) {
  const parts = String(name || '?').trim().split(/\s+/).slice(0, 2);
  return parts.map(part => part.charAt(0)).join('').toUpperCase() || '?';
}

function avatarHtml(author, size = '') {
  const admin = author?.role === 'admin' ? ' is-admin' : '';
  return `<span class="avatar${size ? ` avatar-${size}` : ''}${admin}" data-hue="${Number(author?.avatar_hue ?? 42)}" aria-hidden="true">${escapeHtml(initialsOf(author?.display_name))}</span>`;
}

/** Barvu avatara nastaví CSSOM; inline styly v HTML by zakázala bezpečnostní politika. */
function applyHues(root = document) {
  $$('[data-hue]', root).forEach(element => element.style.setProperty('--hue', element.dataset.hue));
}

function timeAgo(iso) {
  const then = new Date(iso);
  const seconds = Math.max(0, (Date.now() - then.getTime()) / 1000);
  if (seconds < 60) return 'právě teď';
  if (seconds < 3600) return `před ${Math.floor(seconds / 60)} min`;
  if (seconds < 86400) return `před ${Math.floor(seconds / 3600)} h`;
  if (seconds < 172800) return 'včera';
  if (seconds < 7 * 86400) return `před ${Math.floor(seconds / 86400)} dny`;
  return new Intl.DateTimeFormat('cs-CZ', { day: 'numeric', month: 'long', year: then.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }).format(then);
}

function fullTime(iso) {
  return new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(iso));
}

function snapNumber(value, digits = 2) {
  if (value === null || value === undefined || value === '') return '—';
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: digits }).format(number);
}

function snapR(value) {
  if (value === null || value === undefined || value === '') return '—';
  const number = Number(value);
  return `${number > 0 ? '+' : ''}${snapNumber(number)}R`;
}

function textBlock(value) {
  const text = String(value ?? '').trim();
  return text ? `<p class="snap-text">${escapeHtml(text)}</p>` : '';
}

function shareKey(kind, id) {
  return `${kind}:${Number(id)}`;
}

function isShared(kind, id) {
  return wallState.shares.has(shareKey(kind, id));
}

function sharedMark(kind, id) {
  return isShared(kind, id) ? `<span class="share-status" title="Sdíleno na nástěnce">${svgIcon(ICONS.share)}Na nástěnce</span>` : '';
}

function shareButton(kind, id) {
  return `<button class="mini-button" type="button" data-share-kind="${kind}" data-share-id="${Number(id)}">${isShared(kind, id) ? 'Aktualizovat' : 'Sdílet'}</button>`;
}

/* ---------------------------------------------------------------- snímky */

function biasSeal(bias) {
  const tone = bias === 'long' ? 'long' : bias === 'short' ? 'short' : 'balance';
  const word = bias === 'long' ? 'LONG' : bias === 'short' ? 'SHORT' : 'BALANCE';
  return `<div class="bias-seal is-${tone}"><span>Pracovní bias</span><strong>${word}</strong></div>`;
}

function biasCell(label, value) {
  if (!value) return '';
  const tone = value === 'long' ? 'direction-long' : value === 'short' ? 'direction-short' : 'direction-neutral';
  return `<div><span>${escapeHtml(label)}</span><strong class="${tone}">${escapeHtml(PA_LABELS[value] || value)}</strong></div>`;
}

function directionPill(direction) {
  const label = direction === 'long' ? 'LONG' : direction === 'short' ? 'SHORT' : direction === 'both' ? 'LONG I SHORT' : '';
  return label ? `<span class="dir-pill is-${escapeHtml(direction)}">${label}</span>` : '';
}

function priceRange(low, high) {
  const a = snapNumber(low);
  const b = snapNumber(high);
  if (a === '—' && b === '—') return '';
  return a === b || b === '—' ? a : a === '—' ? b : `${a} – ${b}`;
}

/** Svislý žebříček cen: co je nad a co pod, bez nutnosti kreslit graf. */
function priceLadder(snapshot) {
  const rows = [];
  const push = (price, tone, label) => {
    const number = Number(price);
    if (price !== null && price !== undefined && price !== '' && Number.isFinite(number)) rows.push({ price: number, tone, label });
  };
  const weekly = snapshot.plan_type === 'weekly';
  push(snapshot.ref_high, 'level', weekly ? 'High minulého týdne' : 'High předchozího dne');
  push(snapshot.ref_vah, 'va', weekly ? 'VAH minulého týdne' : 'VAH předchozího dne');
  push(snapshot.ref_poc, 'poc', weekly ? 'POC minulého týdne' : 'POC předchozího dne');
  push(snapshot.ref_val, 'va', weekly ? 'VAL minulého týdne' : 'VAL předchozího dne');
  push(snapshot.ref_low, 'level', weekly ? 'Low minulého týdne' : 'Low předchozího dne');
  if (!weekly) {
    push(snapshot.weekly_context?.vah, 'va', 'Týdenní VAH');
    push(snapshot.weekly_context?.val, 'va', 'Týdenní VAL');
  }
  (snapshot.zones || []).forEach(zone => {
    const low = Number(zone.price_low);
    const high = Number(zone.price_high);
    const mid = Number.isFinite(low) && Number.isFinite(high) ? (low + high) / 2 : Number.isFinite(low) ? low : high;
    push(mid, zone.direction || 'both', `${zone.name || 'Zóna'} · ${priceRange(zone.price_low, zone.price_high)}`);
  });
  (snapshot.levels || []).forEach(level => push(level.price, 'level', level.name || 'Level'));
  (snapshot.refs || []).filter(ref => ref.status !== 'filled').forEach(ref => push(ref.price_low ?? ref.price_high, 'ref', ref.note || (ref.kind || 'reference').replace('_', ' ')));
  if (rows.length < 2) return '';
  rows.sort((a, b) => b.price - a.price);
  return `<p class="snap-section-title">Mapa ceny</p><div class="snap-ladder">${rows.slice(0, 18).map(row => `<div class="ladder-row" data-tone="${row.tone}"><b>${snapNumber(row.price)}</b><i></i><span>${escapeHtml(row.label)}</span></div>`).join('')}</div>`;
}

function renderPlanSnapshot(snapshot, post) {
  const weekly = snapshot.plan_type === 'weekly';
  const title = weekly ? `Týden ${isoWeek(snapshot.plan_date)}` : prettyDate(snapshot.plan_date);
  const bias = [
    weekly ? biasCell('PA Monthly', snapshot.pa_monthly) : '',
    biasCell('PA Weekly', snapshot.pa_weekly),
    weekly ? '' : biasCell('PA Daily', snapshot.pa_daily),
    biasCell('MP Weekly', snapshot.mp_weekly),
    weekly ? '' : biasCell('MP Daily', snapshot.mp_daily),
  ].join('');
  const zones = (snapshot.zones || []).map(zone => {
    const sides = [];
    if (zone.direction === 'long' || zone.direction === 'both') {
      if (zone.long_entry) sides.push(`<p><b>Long vstup:</b> ${escapeHtml(zone.long_entry)}</p>`);
      if (zone.long_skip) sides.push(`<p><b>Long neberu:</b> ${escapeHtml(zone.long_skip)}</p>`);
    }
    if (zone.direction === 'short' || zone.direction === 'both') {
      if (zone.short_entry) sides.push(`<p><b>Short vstup:</b> ${escapeHtml(zone.short_entry)}</p>`);
      if (zone.short_skip) sides.push(`<p><b>Short neberu:</b> ${escapeHtml(zone.short_skip)}</p>`);
    }
    const context = zone.va_context?.text ? `<span class="badge">${escapeHtml(zone.va_context.text)}</span>` : '';
    return `<article class="snap-zone" data-direction="${escapeHtml(zone.direction || '')}">
      <header><strong>${escapeHtml(zone.name || 'Zóna')}</strong>${directionPill(zone.direction)}${context}<span class="price">${priceRange(zone.price_low, zone.price_high)}</span></header>
      ${zone.source ? `<p>${escapeHtml(zone.source)}</p>` : ''}${sides.join('')}
    </article>`;
  }).join('');
  const levels = (snapshot.levels || []).filter(level => level.price !== null && level.price !== '').map(level => `<span class="badge">${escapeHtml(level.name || 'Level')} · ${snapNumber(level.price)}</span>`).join('');
  const risks = [snapshot.important_news ? `<p class="snap-text"><strong>Red news:</strong> ${escapeHtml(snapshot.important_news)}</p>` : '', snapshot.no_trade_conditions ? `<p class="snap-text"><strong>Neobchoduji, když:</strong> ${escapeHtml(snapshot.no_trade_conditions)}</p>` : ''].join('');
  return `<div class="snap">
    <div class="snap-head"><div><h3>${escapeHtml(weekly ? 'Týdenní náhled' : 'Denní náhled')} · ${escapeHtml(snapshot.market || post.market || '')}</h3><p>${escapeHtml(title)} · ${escapeHtml(tradeTypeLabel(snapshot.session))}</p></div>${biasSeal(snapshot.bias)}</div>
    ${bias ? `<div class="snap-bias">${bias}</div>` : ''}
    ${textBlock(snapshot.bias_description)}
    ${zones ? `<p class="snap-section-title">Zóny</p><div class="snap-zones">${zones}</div>` : ''}
    ${levels ? `<p class="snap-section-title">Klíčové levely</p><div class="snap-chips">${levels}</div>` : ''}
    ${priceLadder(snapshot)}
    ${risks}
    ${snapshot.general_notes ? textBlock(snapshot.general_notes) : ''}
  </div>`;
}

function renderTradeSnapshot(snapshot) {
  const r = Number(snapshot.result_r);
  const tone = !Number.isFinite(r) || snapshot.result_r === null ? '' : r >= 0 ? 'is-win' : 'is-loss';
  const facts = [
    ['Entry', snapNumber(snapshot.entry_price)],
    ['Exit', snapNumber(snapshot.exit_price)],
    ['Stop loss', snapNumber(snapshot.stop_loss)],
    ['Plánovaný TP', snapNumber(snapshot.target_price)],
    ['Setup', snapshot.strategy || '—'],
    ['Podle plánu', snapshot.followed_plan === 1 || snapshot.followed_plan === '1' ? 'Ano' : snapshot.followed_plan === 0 || snapshot.followed_plan === '0' ? 'Ne' : '—'],
  ];
  if (snapshot.execution_rating) facts.push(['Exekuce', `${snapshot.execution_rating} / 5`]);
  if ('result_usd' in snapshot) facts.push(['Výsledek $', displayMoney(snapshot.result_usd)], ['Risk', displayMoney(snapshot.risk_amount)]);
  const emotions = snapshot.emotion ? String(snapshot.emotion).split(',').filter(Boolean).map(value => `<span class="badge">${escapeHtml(value.replaceAll('_', ' '))}</span>`).join('') : '';
  return `<div class="snap">
    <div class="snap-head"><div><h3>${escapeHtml(snapshot.market || '')} ${directionPill(snapshot.direction)}</h3><p>${escapeHtml(prettyDate(snapshot.trade_date))} · ${escapeHtml(tradeTypeLabel(snapshot.session))}</p></div></div>
    <div class="snap-trade">
      <div class="r-hero ${tone}"><span>Výsledek</span><strong class="${r >= 0 ? 'value-positive' : 'value-negative'}">${snapR(snapshot.result_r)}</strong><small>${'result_usd' in snapshot ? displayMoney(snapshot.result_usd) : 'v násobcích risku'}</small></div>
      <dl class="snap-facts">${facts.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl>
    </div>
    ${emotions ? `<div class="snap-chips">${emotions}</div>` : ''}
    ${snapshot.mistake ? `<p class="snap-text"><strong>Chyba / odchylka:</strong> ${escapeHtml(snapshot.mistake)}</p>` : ''}
    ${snapshot.notes ? textBlock(snapshot.notes) : ''}
  </div>`;
}

function renderStrategySnapshot(snapshot) {
  const stats = snapshot.stats;
  const tiles = stats ? [
    ['Obchodů', snapNumber(stats.trades, 0)],
    ['Celkem', snapR(stats.total_r)],
    ['Průměr', stats.expectancy_r === null ? '—' : snapR(stats.expectancy_r)],
    ['Profit factor', stats.profit_factor === null ? '—' : snapNumber(stats.profit_factor)],
  ] : [];
  const chips = [snapshot.timeframe, strategyStyleLabels[snapshot.style]].filter(Boolean).map(value => `<span class="badge badge-gold">${escapeHtml(value)}</span>`).join('');
  return `<div class="snap">
    <div class="snap-head"><div><h3>${escapeHtml(snapshot.name || 'Strategie')}</h3><p>Strategie a setup</p></div><div class="snap-chips">${chips}</div></div>
    ${tiles.length ? `<div class="snap-stats">${tiles.map(([label, value]) => `<div><span>${label}</span><strong>${escapeHtml(value)}</strong></div>`).join('')}</div>` : ''}
    ${textBlock(snapshot.notes)}
  </div>`;
}

function renderMedia(post) {
  const media = post.media || [];
  if (!media.length) return '';
  const count = media.length === 1 ? 'count-1' : media.length === 2 ? 'count-2' : media.length === 3 ? 'count-3' : 'count-many';
  const shown = media.slice(0, 3);
  return `<div class="post-media ${count}">${shown.map((item, index) => `<figure data-media-url="${escapeHtml(item.url)}" data-media-caption="${escapeHtml(item.caption || '')}"><img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.caption || 'Graf')}" loading="lazy">${index === 2 && media.length > 3 ? `<span class="more">+${media.length - 3}</span>` : ''}</figure>`).join('')}</div>`;
}

function renderReactions(post) {
  const counts = post.reactions?.counts || {};
  const mine = post.reactions?.mine;
  const buttons = Object.entries(WALL_REACTIONS).map(([kind, meta]) => {
    const count = Number(counts[kind] || 0);
    return `<button class="reaction${mine === kind ? ' is-mine' : ''}" type="button" data-react="${kind}" data-post="${post.id}" title="${meta.label}" aria-pressed="${mine === kind}">${svgIcon(meta.icon)}${count ? `<em>${count}</em>` : ''}<span class="visually-hidden">${meta.label}</span></button>`;
  }).join('');
  const people = post.reactions?.people || [];
  const peopleText = people.length ? `${people.slice(0, 2).join(', ')}${people.length > 2 ? ` a ${people.length - 2} dalších` : ''}` : '';
  return `${buttons}<button class="comment-toggle" type="button" data-toggle-comments="${post.id}">${svgIcon(ICONS.comment)}${post.comment_count ? `<em>${post.comment_count}</em>` : 'Komentovat'}</button><span class="reaction-people">${escapeHtml(peopleText)}</span>`;
}

function renderComment(comment) {
  return `<div class="comment">${avatarHtml(comment.author, 'sm')}<div class="comment-bubble"><header><strong>${escapeHtml(comment.author.display_name)}</strong><time datetime="${escapeHtml(comment.created_at)}" title="${escapeHtml(fullTime(comment.created_at))}">${escapeHtml(timeAgo(comment.created_at))}</time>${comment.can_delete ? `<button class="mini-button danger" type="button" data-delete-comment="${comment.id}">Smazat</button>` : ''}</header><p>${escapeHtml(comment.body)}</p></div></div>`;
}

function renderComments(post, open) {
  const comments = post.comments || [];
  const hidden = post.comment_count - comments.length;
  return `<div class="comments" data-comments="${post.id}"${open || comments.length ? '' : ' hidden'}>
    ${hidden > 0 ? `<button class="text-button comments-more" type="button" data-all-comments="${post.id}">Zobrazit ${hidden === 1 ? 'další komentář' : `dalších ${hidden} komentářů`}</button>` : ''}
    ${comments.map(renderComment).join('')}
    <form class="comment-form" data-comment-form="${post.id}">${avatarHtml({ display_name: $('#chipName')?.textContent, avatar_hue: document.body.dataset.userHue, role: document.body.dataset.userRole }, 'sm')}<textarea name="body" rows="1" maxlength="2000" placeholder="Napiš komentář…" aria-label="Komentář"></textarea><button class="icon-button" type="submit" aria-label="Odeslat komentář">${svgIcon(ICONS.send)}</button></form>
  </div>`;
}

function renderPost(post, openComments = false) {
  const snapshot = post.snapshot || {};
  const content = post.kind === 'plan' ? renderPlanSnapshot(snapshot, post) : post.kind === 'trade' ? renderTradeSnapshot(snapshot) : post.kind === 'strategy' ? renderStrategySnapshot(snapshot) : '';
  const edited = post.updated_at !== post.created_at ? ` · aktualizováno ${escapeHtml(timeAgo(post.updated_at))}` : '';
  return `<article class="surface post" data-post-id="${post.id}">
    <header class="post-head">
      ${avatarHtml(post.author)}
      <div class="post-author"><button type="button" data-wall-author="${post.author.id}" data-author-name="${escapeHtml(post.author.display_name)}">${escapeHtml(post.author.display_name)}</button>${post.author.role === 'admin' ? '<span class="role-tag">správce</span>' : ''}<small><time datetime="${escapeHtml(post.created_at)}" title="${escapeHtml(fullTime(post.created_at))}">${escapeHtml(timeAgo(post.created_at))}</time>${edited}</small></div>
      <span class="badge post-kind${post.kind === 'note' ? '' : ' badge-gold'}">${escapeHtml(WALL_KIND_LABELS[post.kind] || '')}${post.market ? ` · ${escapeHtml(post.market)}` : ''}</span>
      ${post.can_delete ? `<button class="icon-button" type="button" data-delete-post="${post.id}" title="Smazat příspěvek" aria-label="Smazat příspěvek">${svgIcon(ICONS.trash)}</button>` : ''}
    </header>
    ${post.body ? `<p class="post-text">${escapeHtml(post.body)}</p>` : ''}
    ${content ? `<div class="post-content">${content}</div>` : ''}
    ${renderMedia(post)}
    <footer class="post-foot">${renderReactions(post)}</footer>
    ${renderComments(post, openComments)}
  </article>`;
}

function mountPost(post, { replace = null, prepend = false, openComments = false } = {}) {
  wallState.posts.set(post.id, post);
  const holder = document.createElement('div');
  holder.innerHTML = renderPost(post, openComments).trim();
  const element = holder.firstElementChild;
  applyHues(element);
  if (replace) replace.replaceWith(element);
  else if (prepend) $('#wallPosts').prepend(element);
  else $('#wallPosts').append(element);
  return element;
}

function updatePost(post, openComments = false) {
  const current = $(`[data-post-id="${post.id}"]`);
  if (!current) return;
  const wasOpen = openComments || !$('.comments', current)?.hidden;
  mountPost(post, { replace: current, openComments: wasOpen });
}

/* ---------------------------------------------------------------- načítání */

async function refreshWall({ more = false } = {}) {
  if (wallState.loading) return;
  wallState.loading = true;
  const list = $('#wallPosts');
  if (!more) {
    wallState.oldest = 0;
    wallState.posts.clear();
    list.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>';
  }
  try {
    const query = {};
    if (wallState.kind) query.kind = wallState.kind;
    if (wallState.author) query.author = wallState.author;
    if (more && wallState.oldest) query.before = wallState.oldest;
    const result = await api('wall', { query });
    if (!more) list.innerHTML = '';
    (result.items || []).forEach(post => mountPost(post));
    const ids = (result.items || []).map(post => post.id);
    if (ids.length) wallState.oldest = Math.min(...ids);
    wallState.hasMore = Boolean(result.has_more);
    $('#wallMore').hidden = !wallState.hasMore;
    if (!list.children.length) {
      list.innerHTML = `<div class="empty-state">${wallState.author || wallState.kind ? 'Tady zatím nic není.' : 'Nástěnka je zatím prázdná. Buď první: napiš postřeh, nebo sdílej náhled či obchod ze svého deníku.'}</div>`;
    }
    if (!more && !wallState.author && !wallState.kind) setWallBadge(0);
  } catch (error) {
    toast(error.message, 'error');
    if (!more) list.innerHTML = '<div class="empty-state">Nástěnku se nepodařilo načíst.</div>';
  } finally {
    wallState.loading = false;
  }
}

async function refreshMembers() {
  try {
    const result = await api('members');
    wallState.members = result.items || [];
    renderMembers();
  } catch (error) { /* seznam členů není nutný pro nástěnku */ }
}

function renderMembers() {
  const list = $('#memberList');
  if (!list) return;
  list.innerHTML = wallState.members.map(member => `<button class="member-row${wallState.author === member.id ? ' is-active' : ''}" type="button" data-wall-author="${member.id}" data-author-name="${escapeHtml(member.display_name)}">${avatarHtml(member, 'sm')}<span><strong>${escapeHtml(member.display_name)}</strong><small>${member.role === 'admin' ? 'správce · ' : ''}${member.posts} ${member.posts === 1 ? 'příspěvek' : member.posts >= 2 && member.posts <= 4 ? 'příspěvky' : 'příspěvků'}</small></span></button>`).join('') || '<p class="muted">Zatím tu nikdo není.</p>';
  applyHues(list);
}

async function refreshShares() {
  try {
    const result = await api('shares');
    wallState.shares = new Map((result.items || []).map(item => [shareKey(item.kind, item.source_id), item.post_id]));
  } catch (error) { /* bez stavu sdílení se jen neukáže štítek */ }
}

function setWallBadge(count) {
  const flag = $('#navWallFlag');
  if (!flag) return;
  flag.hidden = !count;
  flag.textContent = count > 99 ? '99+' : String(count);
}

function filterWallByAuthor(id, name) {
  wallState.author = Number(id) || 0;
  wallState.authorName = name || '';
  $('#wallAuthorNote').hidden = !wallState.author;
  $('#wallAuthorText').textContent = wallState.author ? `Příspěvky: ${wallState.authorName}` : '';
  renderMembers();
  if (!$('#view-wall').classList.contains('is-active')) activateView('wall');
  else refreshWall();
}

/* ---------------------------------------------------------------- psaní příspěvku */

function renderComposerPreviews() {
  const box = $('#composerPreviews');
  box.innerHTML = '';
  wallState.composerFiles.forEach((item, index) => {
    const figure = document.createElement('figure');
    const image = document.createElement('img');
    image.src = item.url;
    image.alt = item.file.name;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', 'Odebrat obrázek');
    remove.addEventListener('click', () => {
      URL.revokeObjectURL(item.url);
      wallState.composerFiles.splice(index, 1);
      renderComposerPreviews();
    });
    figure.append(image, remove);
    box.append(figure);
  });
}

async function submitComposer(event) {
  event.preventDefault();
  const text = $('#composerText').value.trim();
  if (!text && !wallState.composerFiles.length) {
    toast('Napiš text nebo přidej graf.', 'error');
    return;
  }
  const button = $('#composerForm button[type="submit"]');
  button.disabled = true;
  try {
    const body = new FormData();
    body.append('body', text);
    wallState.composerFiles.forEach(item => body.append('images[]', item.file));
    const post = await api('wall_post', { method: 'POST', body });
    $('#composerText').value = '';
    wallState.composerFiles.forEach(item => URL.revokeObjectURL(item.url));
    wallState.composerFiles = [];
    renderComposerPreviews();
    updateComposerCount();
    $('#wallPosts .empty-state')?.remove();
    mountPost(post, { prepend: true });
    toast('Příspěvek je na nástěnce.');
    refreshMembers();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

function updateComposerCount() {
  const length = $('#composerText').value.length;
  $('#composerCount').textContent = length > 3500 ? `${length} / 4000` : '';
}

/* ---------------------------------------------------------------- sdílení */

const SHARE_OPTIONS = {
  plan: [
    ['charts', true, 'Grafy náhledu', 'Screenshoty připojené k náhledu.'],
    ['notes', false, 'Poznámky', 'Pole Poznámky z náhledu. Bias, zóny a rizika se sdílí vždy.'],
  ],
  trade: [
    ['charts', true, 'Grafy obchodu', 'Screenshoty entry a exitu.'],
    ['money', false, 'Částky v dolarech', 'Výsledek, risk a poplatky v $. Bez nich uvidí ostatní jen R.'],
    ['notes', false, 'Poznámky a emoce', 'Chyba, poučení a emoce z obchodu.'],
  ],
  strategy: [
    ['stats', true, 'Statistiky', 'Počet obchodů, celkové a průměrné R a profit factor.'],
    ['charts', true, 'Ukázkové grafy', 'Screenshoty setupu.'],
  ],
};

function shareSubject(kind, id) {
  if (kind === 'plan') {
    const plan = state.plans.find(item => Number(item.id) === Number(id));
    if (!plan && state.currentPlan && Number(state.currentPlan.id) === Number(id)) {
      return { title: `${state.currentPlan.plan_type === 'weekly' ? 'Týdenní' : 'Denní'} náhled ${state.currentPlan.market}`, text: prettyDate(state.currentPlan.plan_date) };
    }
    return plan ? { title: `${plan.plan_type === 'weekly' ? 'Týdenní' : 'Denní'} náhled ${plan.market}`, text: `${plan.plan_type === 'weekly' ? `Týden ${isoWeek(plan.plan_date)}` : prettyDate(plan.plan_date)} · bias ${directionLabel(plan.bias)}` } : { title: 'Náhled trhu', text: '' };
  }
  if (kind === 'trade') {
    const trade = state.trades.find(item => Number(item.id) === Number(id));
    return trade ? { title: `Obchod ${trade.market} · ${directionLabel(trade.direction)}`, text: `${prettyDate(trade.trade_date)} · ${snapR(trade.result_r)}${trade.strategy ? ` · ${trade.strategy}` : ''}` } : { title: 'Obchod', text: '' };
  }
  const strategy = state.strategies.find(item => Number(item.id) === Number(id)) || state.currentStrategy;
  return { title: `Strategie ${strategy?.name || ''}`.trim(), text: strategy?.timeframe || 'Pravidla a výkonnost setupu' };
}

function openShareDialog(kind, id) {
  if (!Number(id)) {
    toast('Nejdřív položku ulož, pak ji půjde sdílet.', 'error');
    return;
  }
  wallState.share = { kind, id: Number(id) };
  const subject = shareSubject(kind, id);
  const shared = isShared(kind, id);
  $('#shareDialogTitle').textContent = shared ? 'Aktualizovat na nástěnce' : 'Sdílet s komunitou';
  $('#shareSummaryTitle').textContent = subject.title;
  $('#shareSummaryText').textContent = subject.text;
  $('#shareOptions').innerHTML = (SHARE_OPTIONS[kind] || []).map(([name, checked, label, hint]) => `<label class="toggle-control"><input type="checkbox" name="${name}"${checked ? ' checked' : ''}><span><strong>${label}</strong><small>${hint}</small></span></label>`).join('');
  $('#shareForm').elements.note.value = '';
  $('#unshareButton').hidden = !shared;
  $('#shareSubmit').lastChild.textContent = shared ? 'Aktualizovat' : 'Sdílet';
  $('#shareDialog').showModal();
}

async function submitShare(event) {
  if (event.submitter?.value === 'cancel') return;
  event.preventDefault();
  const form = event.currentTarget;
  const options = { note: form.elements.note.value };
  $$('#shareOptions input[type="checkbox"]').forEach(input => { options[input.name] = input.checked; });
  const button = $('#shareSubmit');
  button.disabled = true;
  try {
    const post = await api('share', { method: 'POST', body: { kind: wallState.share.kind, id: wallState.share.id, options } });
    wallState.shares.set(shareKey(wallState.share.kind, wallState.share.id), post.id);
    $('#shareDialog').close();
    toast('Sdíleno na nástěnce.');
    afterShareChange();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

async function unshareCurrent() {
  if (!wallState.share || !confirm('Odebrat z nástěnky? Smažou se i komentáře a reakce pod příspěvkem.')) return;
  try {
    await api('share', { method: 'DELETE', query: { kind: wallState.share.kind, id: wallState.share.id } });
    wallState.shares.delete(shareKey(wallState.share.kind, wallState.share.id));
    $('#shareDialog').close();
    toast('Odebráno z nástěnky.');
    afterShareChange();
  } catch (error) { toast(error.message, 'error'); }
}

/** Po změně sdílení přepíše štítky v tabulkách a případně nástěnku. */
function afterShareChange() {
  renderTradeTable();
  renderArchive();
  if ($('#view-strategies').classList.contains('is-active')) refreshStrategyStats();
  if ($('#view-wall').classList.contains('is-active')) refreshWall();
  if ($('#sharePickerDialog').open) renderPicker();
}

async function shareCurrentPlan() {
  const button = $('#sharePlan');
  button.disabled = true;
  try {
    const plan = await savePlan({ quiet: true });
    openShareDialog('plan', plan.id);
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

function renderPicker() {
  const tab = wallState.pickerTab;
  $$('[data-picker-tab]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.pickerTab === tab)));
  let rows = [];
  if (tab === 'plan') {
    rows = state.plans.slice(0, 40).map(plan => ({
      id: plan.id,
      title: `${plan.plan_type === 'weekly' ? `Týden ${isoWeek(plan.plan_date)}` : prettyDate(plan.plan_date)} · ${plan.market}`,
      text: `${plan.plan_type === 'weekly' ? 'Týdenní' : 'Denní'} náhled · bias ${directionLabel(plan.bias)} · ${Number(plan.zone_count || 0)} zón`,
    }));
  } else if (tab === 'trade') {
    rows = state.trades.slice(0, 60).map(trade => ({
      id: trade.id,
      title: `${prettyDate(trade.trade_date)} · ${trade.market} ${directionLabel(trade.direction)}`,
      text: `${snapR(trade.result_r)}${trade.strategy ? ` · ${trade.strategy}` : ''}`,
    }));
  } else {
    rows = state.strategies.map(strategy => ({ id: strategy.id, title: strategy.name, text: [strategy.timeframe, `${strategy.trade_count || 0} obchodů`].filter(Boolean).join(' · ') }));
  }
  $('#pickerList').innerHTML = rows.map(row => `<div class="picker-row"><div><strong>${escapeHtml(row.title)}</strong><small>${escapeHtml(row.text)}</small></div>${sharedMark(tab, row.id)}${shareButton(tab, row.id)}</div>`).join('') || '<div class="empty-state compact">V deníku tu zatím nic není.</div>';
}

/* ---------------------------------------------------------------- události */

async function handleWallClick(event) {
  const react = event.target.closest('[data-react]');
  if (react) {
    const post = wallState.posts.get(Number(react.dataset.post));
    const kind = post?.reactions?.mine === react.dataset.react ? null : react.dataset.react;
    try {
      updatePost(await api('react', { method: 'POST', body: { post_id: Number(react.dataset.post), kind } }));
    } catch (error) { toast(error.message, 'error'); }
    return;
  }
  const toggle = event.target.closest('[data-toggle-comments]');
  if (toggle) {
    const box = $(`[data-comments="${toggle.dataset.toggleComments}"]`);
    box.hidden = false;
    $('textarea', box)?.focus();
    return;
  }
  const all = event.target.closest('[data-all-comments]');
  if (all) {
    try { updatePost(await api('wall_post', { query: { id: all.dataset.allComments } }), true); } catch (error) { toast(error.message, 'error'); }
    return;
  }
  const removeComment = event.target.closest('[data-delete-comment]');
  if (removeComment) {
    if (!confirm('Smazat komentář?')) return;
    try { updatePost(await api('comment', { method: 'DELETE', query: { id: removeComment.dataset.deleteComment } }), true); } catch (error) { toast(error.message, 'error'); }
    return;
  }
  const removePost = event.target.closest('[data-delete-post]');
  if (removePost) {
    if (!confirm('Smazat příspěvek i s komentáři?')) return;
    try {
      await api('wall_post', { method: 'DELETE', query: { id: removePost.dataset.deletePost } });
      const post = wallState.posts.get(Number(removePost.dataset.deletePost));
      if (post?.source_id && post.mine) wallState.shares.delete(shareKey(post.kind, post.source_id));
      $(`[data-post-id="${removePost.dataset.deletePost}"]`)?.remove();
      toast('Příspěvek byl smazán.');
    } catch (error) { toast(error.message, 'error'); }
    return;
  }
  const figure = event.target.closest('[data-media-url]');
  if (figure) {
    const post = wallState.posts.get(Number(figure.closest('[data-post-id]').dataset.postId));
    openWallLightbox(figure.dataset.mediaUrl, figure.dataset.mediaCaption, post?.media || []);
  }
}

function openWallLightbox(url, caption) {
  $('#lightboxImage').src = url;
  $('#lightboxCaption').textContent = caption || '';
  $('#lightbox').showModal();
}

async function submitComment(form) {
  const textarea = $('textarea', form);
  const body = textarea.value.trim();
  if (!body) return;
  textarea.disabled = true;
  try {
    const post = await api('comment', { method: 'POST', body: { post_id: Number(form.dataset.commentForm), body } });
    updatePost(post, true);
    $(`[data-comments="${post.id}"] textarea`)?.focus();
  } catch (error) {
    toast(error.message, 'error');
    textarea.disabled = false;
  }
}

function bindWallEvents() {
  $('#wallPosts').addEventListener('click', handleWallClick);
  $('#wallPosts').addEventListener('submit', event => {
    const form = event.target.closest('[data-comment-form]');
    if (!form) return;
    event.preventDefault();
    submitComment(form);
  });
  $('#wallPosts').addEventListener('keydown', event => {
    const textarea = event.target.closest('.comment-form textarea');
    if (textarea && event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      submitComment(textarea.form);
    }
  });
  $('#wallPosts').addEventListener('input', event => {
    const textarea = event.target.closest('.comment-form textarea');
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.min(textarea.scrollHeight, 180)}px`;
  });

  $('#wallFilters').addEventListener('click', event => {
    const chip = event.target.closest('[data-wall-kind]');
    if (!chip) return;
    wallState.kind = chip.dataset.wallKind;
    $$('#wallFilters .chip').forEach(item => item.classList.toggle('is-on', item === chip));
    refreshWall();
  });
  $('#wallMore').addEventListener('click', () => refreshWall({ more: true }));
  $('#clearWallAuthor').addEventListener('click', () => filterWallByAuthor(0, ''));

  $('#composerForm').addEventListener('submit', submitComposer);
  $('#composerText').addEventListener('input', updateComposerCount);
  $('#composerText').addEventListener('keydown', event => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) $('#composerForm').requestSubmit();
  });
  $('#composerImages').addEventListener('change', event => {
    const files = [...event.target.files];
    if (wallState.composerFiles.length + files.length > 6) toast('K příspěvku jde přidat nejvýš 6 obrázků.', 'error');
    files.slice(0, Math.max(0, 6 - wallState.composerFiles.length)).forEach(file => wallState.composerFiles.push({ file, url: URL.createObjectURL(file) }));
    event.target.value = '';
    renderComposerPreviews();
  });

  $('#openSharePicker').addEventListener('click', () => {
    renderPicker();
    $('#sharePickerDialog').showModal();
  });
  $$('[data-picker-tab]').forEach(button => button.addEventListener('click', () => {
    wallState.pickerTab = button.dataset.pickerTab;
    renderPicker();
  }));

  $('#shareForm').addEventListener('submit', submitShare);
  $('#unshareButton').addEventListener('click', unshareCurrent);
  $('#sharePlan').addEventListener('click', shareCurrentPlan);
  $('#shareStrategy').addEventListener('click', () => {
    const id = state.currentStrategy?.id;
    $('#strategyDialog').close();
    openShareDialog('strategy', id);
  });

  // Tlačítka Sdílet v tabulkách deníku, historii a výběru.
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-share-kind]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    if ($('#sharePickerDialog').open) $('#sharePickerDialog').close();
    openShareDialog(button.dataset.shareKind, button.dataset.shareId);
  }, true);

  document.addEventListener('click', event => {
    const author = event.target.closest('[data-wall-author]');
    if (!author) return;
    filterWallByAuthor(author.dataset.wallAuthor, author.dataset.authorName);
  });
}
