<?php
declare(strict_types=1);

// Přihlašovací stránka. Vkládá ji index.php, když návštěvník nemá platnou relaci.
$state = auth_state();

/** Dekorativní TPO profil: řádky s různou šířkou, jak je trader zná z grafu. */
function hero_profile(): string
{
    $widths = [8, 14, 22, 30, 44, 58, 70, 84, 92, 86, 74, 66, 52, 60, 72, 64, 48, 36, 26, 18, 12, 8];
    $rows = '';
    foreach ($widths as $index => $width) {
        $rows .= sprintf('<rect x="0" y="%d" width="%d" height="9" rx="2"/>', $index * 14, $width * 2);
    }
    return '<svg class="hero-profile" viewBox="0 0 200 308" aria-hidden="true">' . $rows . '</svg>';
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="theme-color" content="#07080b">
  <title>Trading Desk · Přihlášení</title>
  <script src="<?= asset_url('static/theme.js') ?>"></script>
  <link rel="preload" href="static/fonts/manrope-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="static/fonts/fraunces-latin-opsz-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= asset_url('static/styles.css') ?>">
</head>
<body class="auth-body">
  <main class="auth-shell">
    <section class="auth-hero" aria-label="Trading Desk">
      <div class="auth-hero-glow" aria-hidden="true"></div>
      <?= hero_profile() ?>
      <div class="auth-brand">
        <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
        <span><strong>Trading Desk</strong><small>Market Profile journal</small></span>
      </div>
      <div class="auth-pitch">
        <p class="eyebrow">Příprava · Exekuce · Disciplína</p>
        <h1>Klid před otevřením.<br><em>Jistota</em> v exekuci.</h1>
        <p>Denní a týdenní náhled trhu, deník obchodů a psychika na jednom místě. Tvůj deník je jen tvůj a když chceš, zašifrovaný klíčem, který znáš jen ty. Co se rozhodneš sdílet, uvidí ostatní na společné nástěnce.</p>
      </div>
      <ul class="auth-features">
        <li><?= icon('lock') ?><span><strong>Soukromý deník</strong><small>Každý člen má vlastní, oddělenou databázi.</small></span></li>
        <li><?= icon('key') ?><span><strong>Šifrování na přání</strong><small>Místo hesla přístupový klíč. Bez něj deník neotevře nikdo, ani správce.</small></span></li>
        <li><?= icon('wall') ?><span><strong>Společná nástěnka</strong><small>Sdílej náhledy, obchody a strategie. Komentuj a reaguj.</small></span></li>
      </ul>
    </section>

    <section class="auth-panel">
      <button class="icon-button theme-toggle auth-theme" type="button" id="themeToggle" aria-label="Přepnout světlý a tmavý vzhled" title="Světlý / tmavý vzhled"><?= icon('moon') ?><?= icon('sun') ?></button>
      <div class="auth-card" id="authCard" data-setup="<?= $state['setup_required'] ? '1' : '0' ?>" data-registration="<?= $state['registration_open'] ? '1' : '0' ?>" data-encryption="<?= $state['encryption_available'] ? '1' : '0' ?>">

        <?php if ($state['setup_required']): ?>
        <form class="auth-form" id="setupForm" autocomplete="off" novalidate>
          <header><p class="eyebrow">První spuštění</p><h2>Založení správce</h2><p>Správce schvaluje registrace a spravuje členy. Kód najdeš na serveru, do prohlížeče se nikdy neposílá.</p></header>
          <details class="auth-help">
            <summary>Kde najdu kód?</summary>
            <p>Na serveru spusť <code>sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php /var/www/trading-journal/bin/setup-token.php</code>. Při lokálním spuštění je kód v souboru <code>data/setup-token.txt</code>, v Codespace ho vypíše terminál.</p>
          </details>
          <label>Kód pro založení správce<input name="token" required autocomplete="one-time-code" placeholder="XXXX-XXXX-XXXX" spellcheck="false"></label>
          <?php require __DIR__ . '/auth-identity.php'; ?>
          <button class="button button-primary button-block" type="submit">Založit správce a vstoupit</button>
          <p class="auth-error" role="alert" hidden></p>
        </form>
        <?php else: ?>
        <div class="auth-tabs" role="tablist">
          <button type="button" role="tab" aria-selected="true" data-auth-tab="login">Přihlášení</button>
          <button type="button" role="tab" aria-selected="false" data-auth-tab="register">Registrace</button>
        </div>

        <form class="auth-form" id="loginForm" data-auth-pane="login" novalidate>
          <header><p class="eyebrow">Vítej zpět</p><h2>Přihlášení</h2><p>Šifrovaný účet se přihlašuje přístupovým klíčem místo hesla.</p></header>
          <label>Přihlašovací jméno<input name="login" required autocomplete="username" autocapitalize="none" spellcheck="false"></label>
          <label>Heslo nebo přístupový klíč
            <span class="secret-field"><input name="secret" type="password" required autocomplete="current-password" spellcheck="false"><button class="secret-toggle" type="button" aria-label="Zobrazit">Zobrazit</button></span>
          </label>
          <button class="button button-primary button-block" type="submit">Vstoupit</button>
          <p class="auth-error" role="alert" hidden></p>
        </form>

        <form class="auth-form" id="registerForm" data-auth-pane="register" hidden novalidate>
          <header><p class="eyebrow">Nový člen</p><h2>Registrace</h2><p>Každou registraci schvaluje správce. Jakmile ji schválí, můžeš se přihlásit.</p></header>
          <?php if (!$state['registration_open']): ?>
          <p class="auth-note">Registrace jsou teď uzavřené. Požádej správce o pozvánku.</p>
          <?php else: ?>
          <?php require __DIR__ . '/auth-identity.php'; ?>
          <button class="button button-primary button-block" type="submit">Odeslat registraci</button>
          <p class="auth-error" role="alert" hidden></p>
          <?php endif; ?>
        </form>
        <?php endif; ?>

        <section class="auth-result" id="authResult" hidden>
          <header><p class="eyebrow" id="authResultEyebrow">Hotovo</p><h2 id="authResultTitle">Registrace odeslána</h2><p id="authResultText"></p></header>
          <div class="key-card" id="keyCard" hidden>
            <span class="key-card-label"><?= icon('key') ?>Tvůj přístupový klíč</span>
            <output class="key-value" id="keyValue"></output>
            <div class="key-actions">
              <button class="button button-ghost" type="button" id="copyKey">Kopírovat</button>
              <button class="button button-ghost" type="button" id="downloadKey"><?= icon('download') ?>Stáhnout</button>
            </div>
            <p>Ulož si ho do správce hesel nebo vytiskni. <strong>Server ho nikde neuchovává</strong> a bez něj deník neotevře nikdo, ani správce. Ztracený klíč znamená ztracená data.</p>
            <label class="key-confirm"><input type="checkbox" id="keySaved"><span>Klíč mám bezpečně uložený</span></label>
          </div>
          <button class="button button-primary button-block" type="button" id="authContinue">Pokračovat</button>
        </section>
      </div>
      <p class="auth-foot">Trading Desk <?= htmlspecialchars(APP_VERSION) ?> · data zůstávají na tomto serveru</p>
    </section>
  </main>
  <script src="<?= asset_url('static/auth.js') ?>" defer></script>
</body>
</html>
