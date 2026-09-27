'use strict';

/*
 * Účet přihlášeného uživatele a správa členů.
 * Stejně jako wall.js se načítá před app.js a pomocníky používá až při volání.
 */

const membersState = {
  users: [],
  secretMode: null,
  issuedKey: '',
};

function isAdminViewer() {
  return document.body.dataset.userRole === 'admin';
}

function formatBytes(bytes) {
  const value = Number(bytes) || 0;
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} kB`;
  return `${(value / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

function shortDate(iso) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' }).format(new Date(iso));
}

/* ---------------------------------------------------------------- stav účtu */

async function refreshMe() {
  try {
    const result = await api('me');
    setWallBadge(result.wall_unseen || 0);
    const flag = $('#navAdminFlag');
    if (flag) {
      flag.hidden = !result.pending_users;
      flag.textContent = String(result.pending_users || '');
    }
  } catch (error) { /* odznaky nejsou kritické */ }
}

async function logout() {
  try { await api('logout', { method: 'POST' }); } catch (error) { /* odhlášení proběhne i tak */ }
  location.replace('./');
}

/* ---------------------------------------------------------------- profil */

async function saveProfile(event) {
  event.preventDefault();
  const data = formObject(event.currentTarget);
  try {
    const result = await api('profile', { method: 'POST', body: data });
    const user = result.user;
    $('#chipName').textContent = user.display_name;
    $('#profileName').textContent = user.display_name;
    document.body.dataset.userHue = String(user.avatar_hue);
    $$('.avatar[data-hue]').forEach(avatar => {
      if (avatar.closest('.post, .comment, .member-row, #memberTable, #pendingList')) return;
      avatar.dataset.hue = String(user.avatar_hue);
      avatar.textContent = initialsOf(user.display_name);
    });
    applyHues(document);
    toast('Profil je uložený.');
  } catch (error) { toast(error.message, 'error'); }
}

function previewHue(event) {
  const hue = event.target.value;
  ['#profileAvatar', '#chipAvatar'].forEach(selector => {
    const avatar = $(selector);
    if (avatar) avatar.style.setProperty('--hue', hue);
  });
}

async function changePassword(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const data = formObject(form);
  if (data.next !== data.again) { toast('Nová hesla se neshodují.', 'error'); return; }
  try {
    await api('password', { method: 'POST', body: { current: data.current, next: data.next } });
    form.reset();
    toast('Heslo je změněné. Ostatní zařízení jsou odhlášená.');
    if ($('#forcePasswordDialog').open) {
      $('#forcePasswordDialog').close();
      document.body.dataset.mustChange = '0';
    }
  } catch (error) { toast(error.message, 'error'); }
}

async function logoutOthers() {
  try {
    await api('sessions', { method: 'DELETE' });
    toast('Ostatní zařízení jsou odhlášená.');
  } catch (error) { toast(error.message, 'error'); }
}

/* ---------------------------------------------------------------- šifrování a klíč */

function openSecretDialog(mode) {
  membersState.secretMode = mode;
  membersState.issuedKey = '';
  const form = $('#secretForm');
  form.reset();
  const encrypt = mode === 'encrypt';
  $('#secretDialogEyebrow').textContent = encrypt ? 'Šifrování' : 'Přístupový klíč';
  $('#secretDialogTitle').textContent = encrypt ? 'Zapnout šifrování deníku' : 'Vydat nový přístupový klíč';
  $('#secretIntro').textContent = encrypt
    ? 'Deník i všechny screenshoty se zašifrují a heslo nahradí přístupový klíč. Klíč uvidíš jen jednou. Když ho ztratíš, data nikdo neobnoví, ani správce. Potvrď současným heslem.'
    : 'Starý klíč přestane platit a ostatní zařízení se odhlásí. Data zůstanou, jen se vymění zámek. Potvrď současným klíčem.';
  $('#secretLabel').firstChild.textContent = encrypt ? 'Současné heslo' : 'Současný přístupový klíč';
  $('#secretAsk').hidden = false;
  $('#appKeyCard').hidden = true;
  $('#secretSubmit').textContent = encrypt ? 'Zašifrovat deník' : 'Vydat nový klíč';
  $('#secretSubmit').disabled = false;
  $('#secretCancel').hidden = false;
  $('#secretDialogClose').hidden = false;
  $('#secretDialog').showModal();
  $('#secretForm input[name="current"]').focus();
}

async function submitSecret(event) {
  if (event.submitter?.value === 'cancel') return;
  event.preventDefault();
  if (membersState.issuedKey) {
    // Klíč je uložený; relace se změnila, proto se stránka načte znovu.
    location.reload();
    return;
  }
  const current = $('#secretForm input[name="current"]').value;
  if (!current) { toast('Vyplň současné heslo nebo klíč.', 'error'); return; }
  const button = $('#secretSubmit');
  button.disabled = true;
  button.classList.add('is-busy');
  try {
    const result = await api(membersState.secretMode === 'encrypt' ? 'encryption' : 'access_key', { method: 'POST', body: { current } });
    membersState.issuedKey = result.access_key;
    $('#secretAsk').hidden = true;
    $('#appKeyValue').textContent = result.access_key;
    $('#appKeyCard').hidden = false;
    $('#appKeySaved').checked = false;
    $('#secretSubmit').textContent = 'Hotovo';
    $('#secretCancel').hidden = true;
    $('#secretDialogClose').hidden = true;
    button.disabled = true;
  } catch (error) {
    toast(error.message, 'error');
    button.disabled = false;
  } finally {
    button.classList.remove('is-busy');
  }
}

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (error) {
    const area = document.createElement('textarea');
    area.value = text;
    area.className = 'visually-hidden';
    $('#secretDialog').append(area);
    area.select();
    const copied = document.execCommand('copy');
    area.remove();
    return copied;
  }
}

function downloadIssuedKey() {
  const login = $('#view-profile p')?.textContent?.match(/@([^\s·]+)/)?.[1] || 'ucet';
  const lines = [
    'Trading Desk – přístupový klíč',
    '',
    `Účet: ${login}`,
    `Klíč: ${membersState.issuedKey}`,
    '',
    'Klíč zadávej při každém přihlášení místo hesla.',
    'Server ho neuchovává. Bez něj deník neotevře nikdo, ani správce.',
  ];
  const url = URL.createObjectURL(new Blob([lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `trading-desk-klic-${login}.txt`;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/* ---------------------------------------------------------------- správa členů */

async function refreshAdmin() {
  if (!isAdminViewer()) return;
  try {
    const result = await api('admin_users');
    membersState.users = result.items || [];
    $('#registrationOpen').checked = Boolean(result.registration_open);
    renderAdmin();
    refreshMe();
  } catch (error) { toast(error.message, 'error'); }
}

function renderAdmin() {
  const users = membersState.users;
  const pending = users.filter(user => user.status === 'pending');
  const active = users.filter(user => user.status === 'active');
  $('#adminPendingCount').textContent = String(pending.length);
  $('#adminActiveCount').textContent = String(active.length);
  $('#adminEncryptedCount').textContent = String(users.filter(user => user.encrypted && user.status !== 'pending').length);
  $('#adminBlockedCount').textContent = String(users.filter(user => user.status === 'blocked').length);
  $('#pendingCaption').textContent = pending.length ? `${pending.length} čeká` : '';

  $('#pendingList').innerHTML = pending.map(user => `<article class="pending-card">
      ${avatarHtml(user)}
      <div class="who"><strong>${escapeHtml(user.display_name)}</strong><small>@${escapeHtml(user.login)}${user.email ? ` · ${escapeHtml(user.email)}` : ''} · ${escapeHtml(timeAgo(user.created_at))}</small></div>
      ${user.encrypted ? `<span class="badge badge-gold">${svgIcon(ICONS.lock)}šifrovaný</span>` : ''}
      <div class="actions"><button class="button button-ghost button-small danger" type="button" data-admin-op="reject" data-user="${user.id}">Zamítnout</button><button class="button button-primary button-small" type="button" data-admin-op="approve" data-user="${user.id}">Schválit</button></div>
    </article>`).join('') || '<div class="empty-state compact">Žádná registrace nečeká na schválení.</div>';

  const me = Number(document.body.dataset.userId);
  $('#memberTable').innerHTML = users.map(user => {
    const self = user.id === me;
    const items = [];
    if (user.status === 'pending') items.push(['approve', 'Schválit registraci'], ['reject', 'Zamítnout a smazat', 'danger']);
    if (user.status === 'active' && !self) items.push(['block', 'Zablokovat přístup']);
    if (user.status === 'blocked') items.push(['unblock', 'Odblokovat']);
    if (user.status === 'active' && user.role !== 'admin') items.push(['promote', 'Udělat správcem']);
    if (user.role === 'admin' && !self) items.push(['demote', 'Odebrat práva správce']);
    if (!user.encrypted && user.status !== 'pending') items.push(['reset_password', 'Obnovit heslo']);
    if (!self && user.status !== 'pending') items.push(['delete', 'Smazat účet i s daty', 'danger']);
    const statusLabel = { active: 'Aktivní', pending: 'Čeká', blocked: 'Zablokovaný' }[user.status];
    return `<tr>
      <td><div class="member-cell">${avatarHtml(user, 'sm')}<span><strong>${escapeHtml(user.display_name)}${self ? ' <span class="muted">(ty)</span>' : ''}</strong><small>@${escapeHtml(user.login)}${user.email ? ` · ${escapeHtml(user.email)}` : ''}</small></span></div></td>
      <td><span class="status-badge is-${escapeHtml(user.status)}">${statusLabel}</span></td>
      <td>${user.role === 'admin' ? '<span class="badge badge-gold">Správce</span>' : 'Člen'}</td>
      <td>${user.encrypted ? `<span class="badge badge-gold">${svgIcon(ICONS.lock)}Šifrovaný</span>` : '<span class="muted">Bez šifrování</span>'}</td>
      <td>${escapeHtml(shortDate(user.created_at))}${user.approved_by ? `<br><small class="muted">schválil ${escapeHtml(user.approved_by)}</small>` : ''}</td>
      <td>${user.last_seen_at || user.last_login_at ? escapeHtml(timeAgo(user.last_seen_at || user.last_login_at)) : '<span class="muted">nikdy</span>'}</td>
      <td class="num">${user.posts}</td>
      <td class="num">${formatBytes(user.storage_bytes)}</td>
      <td class="num">${items.length ? `<details class="row-menu"><summary aria-label="Akce">⋯</summary><div class="menu">${items.map(([op, label, tone]) => `<button type="button" class="${tone || ''}" data-admin-op="${op}" data-user="${user.id}">${label}</button>`).join('')}</div></details>` : ''}</td>
    </tr>`;
  }).join('');
  applyHues($('#view-admin'));
}

const ADMIN_CONFIRM = {
  reject: user => `Zamítnout registraci ${user.display_name}? Účet se smaže.`,
  block: user => `Zablokovat ${user.display_name}? Hned se odhlásí a nepřihlásí se, dokud ho neodblokuješ.`,
  promote: user => `Udělat ${user.display_name} správcem? Uvidí seznam členů a bude schvalovat registrace.`,
  demote: user => `Odebrat ${user.display_name} práva správce?`,
  reset_password: user => `Obnovit heslo pro ${user.display_name}? Dostane dočasné heslo a jeho relace skončí.`,
};

async function runAdminOp(op, userId) {
  const user = membersState.users.find(item => item.id === Number(userId));
  if (!user) return;
  if (op === 'delete') {
    const typed = prompt(`Smazat účet ${user.display_name} i s celým deníkem, screenshoty a příspěvky? Nejde to vrátit.\n\nPro potvrzení napiš přihlašovací jméno: ${user.login}`);
    if (typed === null) return;
    if (typed.trim().toLowerCase() !== user.login.toLowerCase()) { toast('Jméno nesouhlasí, nic se nesmazalo.', 'error'); return; }
  } else if (ADMIN_CONFIRM[op] && !confirm(ADMIN_CONFIRM[op](user))) {
    return;
  }
  try {
    const result = await api('admin_user', { method: 'POST', body: { id: user.id, op } });
    if (result.temporary_password) {
      $('#adminResultText').textContent = `Dočasné heslo pro ${user.display_name} (@${user.login}):`;
      $('#adminResultSecret').textContent = result.temporary_password;
      $('#adminResultDialog').showModal();
    } else {
      const messages = { approve: 'Registrace je schválená.', reject: 'Registrace je zamítnutá.', block: 'Účet je zablokovaný.', unblock: 'Účet je odblokovaný.', promote: 'Člen je teď správce.', demote: 'Práva správce jsou odebraná.', delete: 'Účet je smazaný.' };
      toast(messages[op] || 'Hotovo.');
    }
    await refreshAdmin();
    refreshMembers();
  } catch (error) { toast(error.message, 'error'); }
}

async function toggleRegistration(event) {
  try {
    const result = await api('admin_settings', { method: 'POST', body: { registration_open: event.target.checked } });
    event.target.checked = Boolean(result.registration_open);
    toast(result.registration_open ? 'Registrace jsou otevřené.' : 'Registrace jsou uzavřené.');
  } catch (error) {
    event.target.checked = !event.target.checked;
    toast(error.message, 'error');
  }
}

/* ---------------------------------------------------------------- události */

function bindMemberEvents() {
  $('#logoutButton').addEventListener('click', logout);
  $('#accountProfileForm').addEventListener('submit', saveProfile);
  $('#hueRange').addEventListener('input', previewHue);
  $('#passwordForm')?.addEventListener('submit', changePassword);
  $('#forcePasswordForm').addEventListener('submit', event => {
    if (event.submitter?.value === 'cancel') return;
    changePassword(event);
  });
  $('#forcePasswordDialog').addEventListener('cancel', event => event.preventDefault());
  $('#forceLogout').addEventListener('click', logout);
  $('#logoutOthers').addEventListener('click', logoutOthers);
  $('#enableEncryption')?.addEventListener('click', () => openSecretDialog('encrypt'));
  $('#rotateKey')?.addEventListener('click', () => openSecretDialog('rotate'));
  $('#secretForm').addEventListener('submit', submitSecret);
  $('#secretDialog').addEventListener('cancel', event => { if (membersState.issuedKey) event.preventDefault(); });
  $('#appKeySaved').addEventListener('change', event => { $('#secretSubmit').disabled = !event.target.checked; });
  $('#appCopyKey').addEventListener('click', async event => {
    event.currentTarget.textContent = (await copyToClipboard(membersState.issuedKey)) ? 'Zkopírováno' : 'Označ a zkopíruj ručně';
  });
  $('#appDownloadKey').addEventListener('click', downloadIssuedKey);

  if (isAdminViewer()) {
    $('#registrationOpen').addEventListener('change', toggleRegistration);
    $('#view-admin').addEventListener('click', event => {
      const button = event.target.closest('[data-admin-op]');
      if (!button) return;
      button.closest('details')?.removeAttribute('open');
      runAdminOp(button.dataset.adminOp, button.dataset.user);
    });
  }
  // Otevřené menu u řádku se zavře kliknutím jinam.
  document.addEventListener('click', event => {
    $$('details.row-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.removeAttribute('open'); });
  });

  if (document.body.dataset.mustChange === '1') $('#forcePasswordDialog').showModal();
  setInterval(refreshMe, 120000);
}
