<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Hindsight je samostatná stránka přes celou obrazovku, přístup stejný jako do deníku.
$viewer = current_user();
if ($viewer === null || $viewer['must_change_secret']) {
    header('Location: ./', true, 302);
    exit;
}
if (is_hidden('modules', 'hindsight')) {
    header('Location: ./', true, 302);
    exit;
}
security_headers();
header('Cache-Control: no-store');
$isAdmin = is_admin($viewer);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#0E1117">
  <title>Hindsight · Trading Desk</title>
  <link rel="preload" href="static/hindsight/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="static/hindsight/fonts/jetbrains-mono-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= asset_url('static/hindsight/hindsight.css') ?>">
</head>
<body class="hs" data-admin="<?= $isAdmin ? '1' : '0' ?>">
  <header class="hs-bar">
    <a class="hs-back" href="./" title="Zpět do Trading Desku" aria-label="Zpět do Trading Desku">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg>
    </a>
    <div class="hs-title">
      <strong>Hindsight</strong>
      <span class="hs-instrument" id="hsInstrument">ES · 5m</span>
    </div>

    <div class="hs-nav" role="group" aria-label="Pohyb v čase">
      <button type="button" class="hs-btn hs-icon" data-go="first" title="První den (Home)" aria-label="První den"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 5.5v13M17 5.5 10.5 12l6.5 6.5"/></svg></button>
      <button type="button" class="hs-btn hs-icon" data-go="prev" title="Předchozí den (←)" aria-label="Předchozí den"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
      <label class="hs-date" title="Skok na datum">
        <span class="sr-only">Skok na datum</span>
        <input type="date" id="hsDate">
      </label>
      <button type="button" class="hs-btn hs-icon" data-go="next" title="Další den (→)" aria-label="Další den"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5 16 12l-6.5 6.5"/></svg></button>
      <button type="button" class="hs-btn hs-icon" data-go="last" title="Poslední den (End)" aria-label="Poslední den"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.5 5.5v13M7 5.5l6.5 6.5L7 18.5"/></svg></button>
      <button type="button" class="hs-btn hs-toggle" id="hsSnap" aria-pressed="false" title="Kolotoč: na obrazovce vždy přesně jeden den">Kolotoč</button>
    </div>

    <div class="hs-layers" role="group" aria-label="Vrstvy">
      <button type="button" class="hs-chip" data-layer="sessions" aria-pressed="true"><i class="hs-dot hs-dot-session"></i>Seance</button>
      <button type="button" class="hs-chip" data-layer="news" aria-pressed="true"><i class="hs-dot hs-dot-news"></i>News</button>
      <button type="button" class="hs-chip" data-layer="zones" aria-pressed="true"><i class="hs-dot hs-dot-zone"></i>Zóny</button>
      <button type="button" class="hs-chip" data-layer="bias" aria-pressed="true"><i class="hs-dot hs-dot-bias"></i>Bias</button>
      <button type="button" class="hs-chip" data-layer="volume" aria-pressed="true"><i class="hs-dot hs-dot-volume"></i>Objem</button>
    </div>

    <div class="hs-actions">
      <button type="button" class="hs-btn hs-toggle" id="hsZoneMode" aria-pressed="false" title="Nakreslit zónu: podrž Z a táhni v grafu, nebo zapni tady">+ Zóna <kbd>Z</kbd></button>
      <button type="button" class="hs-btn hs-icon" id="hsHelp" title="Ovládání" aria-label="Ovládání"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.3M12 16.6v.2"/></svg></button>
<?php if ($isAdmin): ?>
      <button type="button" class="hs-btn" id="hsDataButton" title="Svíčky: import z ATAS, kontrakty">Data</button>
<?php endif; ?>
    </div>
  </header>

  <main class="hs-main">
    <div class="hs-days" id="hsDays" aria-label="Hlavičky dnů"></div>
    <div class="hs-chart" id="hsChart">
      <div class="hs-legend" id="hsLegend" aria-live="off"></div>
      <div class="hs-tooltip" id="hsTooltip" hidden></div>
      <div class="hs-empty" id="hsEmpty" hidden></div>
      <div class="hs-loading" id="hsLoading" hidden>Načítám svíčky…</div>
    </div>
    <div class="hs-foot">
      <div class="hs-minimap" id="hsMinimapWrap" title="Rok na jedné čáře: klikni nebo táhni okno">
        <canvas id="hsMinimap" aria-label="Přehled celého období"></canvas>
      </div>
      <a class="hs-attr" href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer" title="TradingView Lightweight Charts™, Copyright (c) 2026 TradingView, Inc.">Lightweight Charts™<br>TradingView</a>
    </div>
  </main>

  <div class="hs-pop" id="hsPop" role="dialog" aria-modal="false" hidden></div>

  <dialog class="hs-dialog" id="hsHelpDialog">
    <form method="dialog">
      <h2>Ovládání</h2>
      <dl class="hs-keys">
        <dt>Kolečko</dt><dd>posun v čase (se setrvačností)</dd>
        <dt><kbd>Ctrl</kbd> + kolečko</dt><dd>přiblížení a oddálení kolem kurzoru</dd>
        <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>předchozí a další den</dd>
        <dt><kbd>Home</kbd> <kbd>End</kbd></dt><dd>první a poslední den</dd>
        <dt><kbd>Z</kbd> + táhnout</dt><dd>nová zóna (pak typ, popis, platnost)</dd>
        <dt>Klik na zónu</dt><dd>upravit, ukončit, smazat</dd>
        <dt>Klik na hlavičku dne</dt><dd>bias dne a poznámka</dd>
        <dt>Minimapa dole</dt><dd>klik nebo tažení okna = skok v roce</dd>
        <dt>Kolotoč</dt><dd>na obrazovce vždy přesně jeden obchodní den</dd>
      </dl>
      <p class="hs-note">Časy jsou v pražském čase. Seance se počítají v newyorském čase (Asie 18:00–03:00, Evropa 03:00–09:30, New York 09:30–16:00 ET), letní čas se posouvá sám. Zóny a bias jsou stejné jako v denním náhledu.</p>
      <p class="hs-credit">Graf: TradingView Lightweight Charts™, Copyright (c) 2026 TradingView, Inc., <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">tradingview.com</a> (Apache 2.0). Písma Inter a JetBrains Mono (SIL OFL).</p>
      <div class="hs-dialog-actions"><button class="hs-btn hs-primary" value="close">Zavřít</button></div>
    </form>
  </dialog>

<?php if ($isAdmin): ?>
  <dialog class="hs-dialog hs-data" id="hsDataDialog">
    <form id="hsImportForm" method="dialog">
      <h2>Svíčky ES</h2>
      <p class="hs-note">Export z ATAS (CSV nebo TXT, 1m až 5m). Svíčky jsou společné pro všechny tradery. Uloží se jen svíčky z období vybraného kontraktu (od rollu předchozího kontraktu do vlastního rollu), ostatní se přeskočí.</p>
      <div class="hs-form-grid">
        <label>Kontrakt
          <select name="contract" id="hsImportContract" required></select>
        </label>
        <label>Časy v souboru
          <select name="tz">
            <option value="Europe/Prague" selected>Pražský čas (ATAS)</option>
            <option value="America/New_York">New York (ET)</option>
            <option value="America/Chicago">Chicago (CT)</option>
            <option value="UTC">UTC</option>
          </select>
        </label>
        <label>Formát data
          <select name="date_order">
            <option value="auto" selected>Poznat automaticky</option>
            <option value="ydm">rok-den-měsíc (ATAS: 2026-28-09)</option>
            <option value="ymd">rok-měsíc-den (2026-09-28)</option>
            <option value="dmy">den/měsíc/rok (28/09/2026)</option>
            <option value="mdy">měsíc/den/rok (09/28/2026)</option>
          </select>
        </label>
        <label>Soubor
          <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
        </label>
      </div>
      <p class="hs-period" id="hsImportPeriod"></p>
      <p class="hs-result" id="hsImportResult" role="status"></p>
      <div class="hs-dialog-actions">
        <button type="button" class="hs-btn" id="hsDemoButton" title="Vymyšlená data na vyzkoušení, jdou smazat">Ukázková data</button>
        <span class="hs-grow"></span>
        <button type="button" class="hs-btn" value="close" data-close>Zavřít</button>
        <button type="submit" class="hs-btn hs-primary" id="hsImportSubmit">Nahrát</button>
      </div>
      <h3>Nahrané kontrakty</h3>
      <div class="hs-contracts" id="hsContracts"></div>
    </form>
  </dialog>
<?php endif; ?>

  <script src="<?= asset_url('static/hindsight/vendor/lightweight-charts.js') ?>"></script>
  <script src="<?= asset_url('static/hindsight/time.js') ?>"></script>
  <script src="<?= asset_url('static/hindsight/hindsight.js') ?>"></script>
</body>
</html>
