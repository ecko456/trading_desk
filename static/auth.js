'use strict';

// Přihlášení, registrace a založení prvního správce.

(function authPage() {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const card = $('#authCard');
  let issuedKey = '';
  let issuedLogin = '';
  let continueTo = null;

  async function post(action, body) {
    const response = await fetch(`api.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    });
    const payload = (response.headers.get('content-type') || '').includes('json') ? await response.json() : {};
    if (!response.ok) throw Object.assign(new Error(payload.error || 'Server požadavek nedokončil.'), { payload });
    return payload;
  }

  function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  function showError(form, message) {
    const box = $('.auth-error', form);
    if (!box) return;
    box.textContent = message;
    box.hidden = !message;
  }

  function setBusy(form, busy) {
    $$('button[type="submit"]', form).forEach(button => {
      button.disabled = busy;
      button.classList.toggle('is-busy', busy);
    });
  }

  function syncSecretMode(form) {
    const mode = $('input[name="secret_mode"]:checked', form)?.value || 'password';
    const fields = $('[data-password-fields]', form);
    if (fields) fields.hidden = mode !== 'password';
  }

  function checkPasswords(data) {
    if ((data.secret_mode || 'password') !== 'password') return '';
    if ((data.password || '').length < 10) return 'Heslo musí mít aspoň 10 znaků.';
    if (data.password !== data.password_again) return 'Hesla se neshodují.';
    return '';
  }

  function showResult({ eyebrow, title, text, key, login, next }) {
    $$('.auth-form, .auth-tabs', card).forEach(element => { element.hidden = true; });
    $('#authResultEyebrow').textContent = eyebrow;
    $('#authResultTitle').textContent = title;
    $('#authResultText').textContent = text;
    issuedKey = key || '';
    issuedLogin = login || '';
    continueTo = next;
    $('#keyCard').hidden = !issuedKey;
    $('#keyValue').textContent = issuedKey;
    $('#keySaved').checked = false;
    $('#authContinue').disabled = Boolean(issuedKey);
    $('#authContinue').hidden = !next;
    $('#authResult').hidden = false;
    $('#authResult h2').focus?.();
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (error) {
      // Na serveru bez HTTPS schránka nefunguje; náhradou je výběr a kopírování.
      const area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.className = 'visually-hidden';
      document.body.append(area);
      area.select();
      const copied = document.execCommand('copy');
      area.remove();
      return copied;
    }
  }

  function downloadKey() {
    const lines = [
      'Trading Desk – přístupový klíč',
      '',
      `Účet: ${issuedLogin}`,
      `Klíč: ${issuedKey}`,
      '',
      'Klíč zadávej při každém přihlášení místo hesla.',
      'Server ho neuchovává. Bez něj deník neotevře nikdo, ani správce.',
      'Ulož ho do správce hesel a tento soubor pak smaž.',
    ];
    const url = URL.createObjectURL(new Blob([lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `trading-desk-klic-${issuedLogin || 'ucet'}.txt`;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  // Přepínač vzhledu stejný jako v aplikaci.
  $('#themeToggle')?.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('td-theme', next); } catch (error) { /* bez úložiště jen na tuto návštěvu */ }
  });

  $$('[data-auth-tab]').forEach(tab => tab.addEventListener('click', () => {
    $$('[data-auth-tab]').forEach(item => item.setAttribute('aria-selected', String(item === tab)));
    $$('[data-auth-pane]').forEach(pane => { pane.hidden = pane.dataset.authPane !== tab.dataset.authTab; });
    $(`[data-auth-pane="${tab.dataset.authTab}"] input:not([type="radio"])`)?.focus();
  }));

  $$('.auth-form').forEach(form => {
    $$('input[name="secret_mode"]', form).forEach(input => input.addEventListener('change', () => syncSecretMode(form)));
    syncSecretMode(form);
  });

  $$('.secret-toggle').forEach(button => button.addEventListener('click', () => {
    const input = button.previousElementSibling;
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    button.textContent = visible ? 'Zobrazit' : 'Skrýt';
  }));

  $('#loginForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = formData(form);
    showError(form, '');
    if (!data.login || !data.secret) { showError(form, 'Vyplň jméno a heslo nebo klíč.'); return; }
    setBusy(form, true);
    try {
      await post('login', { login: data.login.trim(), secret: data.secret });
      location.replace('./');
    } catch (error) {
      showError(form, error.message);
      setBusy(form, false);
    }
  });

  $('#registerForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = formData(form);
    showError(form, '');
    const problem = checkPasswords(data);
    if (problem) { showError(form, problem); return; }
    setBusy(form, true);
    try {
      const result = await post('register', data);
      showResult({
        eyebrow: 'Registrace odeslána',
        title: 'Čeká na schválení',
        text: result.access_key
          ? 'Správce tvou registraci brzy schválí. Mezitím si ulož přístupový klíč níže, ukáže se jen teď.'
          : 'Správce tvou registraci brzy schválí. Potom se přihlas jménem a heslem.',
        key: result.access_key,
        login: result.user.login,
        next: () => location.replace('./'),
      });
      $('#authContinue').textContent = result.access_key ? 'Klíč mám, zpět na přihlášení' : 'Zpět na přihlášení';
    } catch (error) {
      showError(form, error.message);
    } finally {
      setBusy(form, false);
    }
  });

  $('#setupForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = formData(form);
    showError(form, '');
    const problem = checkPasswords(data);
    if (problem) { showError(form, problem); return; }
    setBusy(form, true);
    try {
      const result = await post('setup', data);
      showResult({
        eyebrow: 'Správce založen',
        title: `Vítej, ${result.user.display_name}`,
        text: result.adopted
          ? 'Stávající deník z tohoto serveru je teď ve tvém účtu.' + (result.access_key ? ' Byl zašifrovaný tvým klíčem.' : '')
          : 'Aplikace je připravená. Nové členy schválíš v sekci Správa.',
        key: result.access_key,
        login: result.user.login,
        next: () => location.replace('./'),
      });
      if (!result.access_key) location.replace('./');
    } catch (error) {
      showError(form, error.message);
      setBusy(form, false);
    }
  });

  $('#keySaved')?.addEventListener('change', event => { $('#authContinue').disabled = !event.target.checked; });
  $('#copyKey')?.addEventListener('click', async event => {
    const copied = await copyText(issuedKey);
    event.currentTarget.textContent = copied ? 'Zkopírováno' : 'Označ a zkopíruj ručně';
  });
  $('#downloadKey')?.addEventListener('click', downloadKey);
  $('#authContinue')?.addEventListener('click', () => continueTo?.());

  // Na telefonu by automatické zaměření posunulo stránku a otevřelo klávesnici.
  if (window.matchMedia('(min-width: 981px)').matches) {
    (card.dataset.setup === '1' ? $('#setupForm input[name="token"]') : $('#loginForm input[name="login"]'))?.focus();
  }
})();
