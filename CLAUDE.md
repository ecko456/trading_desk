# Trading Desk: poznámky pro Clauda

Claude Code si tenhle soubor načte sám na začátku každé session. Je v něm, kde jsme
skončili, jak je projekt poskládaný a jak s ním pracovat. Podrobnosti k Hindsightu jsou
ve `static/hindsight/CLAUDE.md`.

Dokumentace pro uživatele je v `README.md` a `INSTALL.md`.

## Tři oddělené projekty

Od 1. 10. 2026 je **tenhle repozitář jen Trading Desk**. Odměny mají vlastní repozitáře
s vlastním `CLAUDE.md` a pracuje se na nich v samostatných sessions.

| projekt | repozitář | adresa | klon na serveru |
|---|---|---|---|
| **Trading Desk (tady)** | `ecko456/trading_desk` | `/trading/` | `/root/trading_desk` |
| Odměny, ostrá verze 1.0 | `ecko456/odmeny` | `/odmeny/` | `/root/odmeny` |
| Odměny, verze 2 | `ecko456/odmeny_v2` | `/odmeny_v2/` | `/root/odmeny_v2` |

Když uživatel v téhle session chce něco v Odměnách, připomeň mu, ať otevře session
nad repozitářem `odmeny_v2` (nebo `odmeny`).

Po každé větší práci **aktualizuj sekci „Kde jsme skončili“** a poznámky modulu.

## Kde jsme skončili

Stav k 3. 10. 2026:

- Pracovní větev je `claude/elegant-clarke-vl5n7d`. Uživatel z ní nasazuje a do `main` zatím
  sloučená není (přes 20 commitů navíc). Na `main` jsou navíc jen nahrané podklady:
  - `Chart.csv`, vzorek exportu svíček z ATAS;
  - `Hindsight – zadání modulu.docx`.
- Poslední práce (Hindsight, 3. 10. 2026): svíčky nahrává jen **správce dat grafu**,
  ne každý správce (uživatel chtěl „ať mohu importovat pouze já“). Výchozí je první
  správce instalace, změna jen příkazem `bin/hindsight-keeper.php` na serveru. Info ve
  Správě (`#marketKeeperInfo`), testy `HindsightKeeperTests`.
- Předtím (osobnost a dýchání v Psychice, 3. 10. 2026):
  - `lib/personality.php`: Big Five z IPIP (50 výroků, veřejná doména, náš překlad, bez norem)
    a talenty CliftonStrengths (jen pořadí z reportu uživatele, 34 talentů s naším výkladem pro
    trading; Gallupovy texty v repu nejsou a být nesmí). Tabulka `psych_personality` (id = 1).
    Z obojího nejvýš 3 oblasti zaměření (8 dimenzí profilu + stimulation, conviction,
    anticipation, social, hesitation) a doporučení rytmu a kulisy;
  - rychlý test (`psych_questions`, `evaluate_psych`): oblasti z osobnosti mají váhu 1,25,
    až 2 vlastní otázky, cílených otázek celkem max. 3, profil má přednost; osobnost sama
    den na oranžovou neshodí. Otázky mají `source` a `reason`;
  - dýchání: `static/breathing.js` (časování je čistá funkce, `tests/test_breathing.js`),
    kruh, odpočet, pauza, nabídka po rychlém testu. Vzorce 4–8 a box 5–5–5–5 v pořadí
    výdech, zadržení, nádech, zadržení (tak to uživatel chtěl);
  - zvuk: `static/soundscapes.js`, pět kulis generovaných ve Web Audio (žádné soubory ani
    licence), synchronizované s dechem, hlasitosti srovnané offline na zhruba −27 dB RMS;
  - ukládá se jen to, co uživatel v nastavení dýchání sám změní, zbytek sleduje doporučení.
  - Uživatel poslal svůj report Gallup. Je to osobní dokument: do repa nepatří on ani jeho
    obsah (pořadí talentů), ani v testech a příkladech.
- Předtím (Obchodní plán a redesign PDF, 3. 10. 2026):
  - nový modul **Obchodní plán** (`lib/tradeplan.php`, `static/tradeplan.js`, pohled `#view-tradeplan`):
    jeden plán s verzemi v tabulce `trading_plans` (data jako JSON, čištění whitelistem
    `tradeplan_normalize`). Deset kroků: cíle, trhy a čas (okna v pražském čase), účty a risk,
    bias, zóny, strategie, den tradera, psychika, review, závazek. „Vložit návrh“ doplní jen
    prázdná pole (`tradeplan_template`, účty a strategie bere z deníku). Archivní verze jsou
    jen pro čtení, smazat jde jen archiv;
  - PDF plánu: `export_trading_plan.py` (A4 na výšku), `pdf.php?trading_plan=ID`;
  - PDF náhledu přepsané do vzhledu aplikace (`export_plan.py`, A4 na šířku): titulní pás,
    mapa ceny s očíslovanými zónami, karty zón. Sdílené prvky obou PDF jsou v `pdf_kit.py`,
    písma Fraunces a Manrope (OFL) v `lib/fonts`, záloha DejaVu;
  - ověřeno s reportlab 3.6.12, 4.1.0 a 5.0.1 (Ubuntu 22.04 má 3.6.8, ta umí ROUNDEDCORNERS
    i linearGradient), aktualizace starého deníku bez ztráty dat a PDF pod www-data na Apachi.
- Předtím (napojení na cTrader, 3. 10. 2026):
  - cTrader Open API jen pro čtení (scope `accounts`). Správce vloží Client ID, Secret a adresu
    pro návrat ve Správě, každý člen si napojí svoje účty v Účtech a auditu (`ctrader.php`, OAuth).
  - Synchronizace stáhne zůstatek, pozice, deals, příkazy (kvůli SL) a pohyby na účtu a zapíše
    uzavřené pozice jako obchody. Money audit napojeného účtu bere zůstatek z cTraderu, při
    termínu sám, bez screenshotu.
  - **Proti skutečnému cTraderu to ještě nikdo nezkoušel.** Testy běží proti simulaci
    (`tests/ctrader_mock.py`). Uživatel musí zaregistrovat aplikaci na openapi.ctrader.com
    (Spotware ji schvaluje) a vyzkoušet demo účet. Nejisté body: jestli cTrader vrací `state`
    (ověřuje se i bez něj, cookie), výčty a int64 v JSON (text i číslo), sémantika komise
    (čistý výsledek se bere z rozdílu verzí zůstatku, vzorec je jen záloha).
- Předtím (Strategie, 2. 10. 2026):
  - SVG obrázky strategií (`lib/svg.php`: whitelist prvků a atributů, bez entit, `file.php`
    vydává SVG se sandbox CSP). SVG jde jen ke strategii, ne k náhledu, obchodu ani auditu;
  - pravý sloupec „Moje strategie“ v záložce Strategie: „+ Přidat strategii“, karty s náhledem
    (`cover_id` = první obrázek), rychlý náhled `#strategyPreview`;
  - oprava: skryté `id` přežilo `form.reset()`, takže „Přidat obchod/strategii“ po otevření
    jiného záznamu ukládalo přes něj. Nový náhled zase přebíral DiNapoli vzory. Pozor na to
    u každého formuláře se skrytým polem.
- Předtím (Hindsight):
  - import velkého exportu z ATAS bez pádu serveru (`f96ed9e`); uživatel potvrdil, že funguje;
  - klik na pás seancí přiblíží, další klik vrátí zobrazení (`6cb4b69`).
- **Projekty jsou rozdělené do tří repozitářů** (1. 10. 2026). Odměny odsud zmizely i s původní
  aplikací `hodnoceni-operatoru.html`, jejich historie je v nových repozitářích.
- Od uživatele se čeká:
  - vzorek exportu obchodů z ATAS (futures) pro import do deníku a Hindsightu;
  - registrace aplikace cTrader a první test s demo účtem;
  - zpětná vazba na obchodní plán (struktura a vzorový návrh) a na nový vzhled PDF;
  - zpětná vazba na kulisy (poslech) a výklad talentů pro trading.

## Začátek nové session

1. `git status`, `git log --oneline -5` a kontrola větve. Když aktuální větev nemá commity
   výše (např. vznikla z `main`), stáhni `origin/claude/elegant-clarke-vl5n7d` a navaž na ni.
   Když si nejsi jistý, na kterou větev navázat, zeptej se uživatele.
2. Přečti poznámky modulu, na kterém se bude pracovat.
3. Před změnami spusť testy modulu. Máš tak výchozí stav a víš, že prostředí funguje.

## Uživatel a pravidla spolupráce

- Komunikace **česky**, věcně. UI texty a komentáře v kódu jsou česky.
- Uživatel nasazuje sám podle přesných příkazů. Na konci práce vždy napiš:
  - co se změnilo a výsledek testů;
  - přesný příkaz pro server (viz Nasazení);
  - že je potřeba Ctrl+Shift+R.
- **Nikdy se nepřipojuj na server uživatele.** Do repa nedávej IP ani adresu serveru, ani
  žádná data. **Repozitář je veřejný.**
- Aplikace běží jen za HTTPS, Apache ho obsluhuje přímo.
- Opatrně, ať nic nerozbiješ. **Nepushuj neotestovaný kód** a v prohlížeči ověř i vzhled
  (screenshot). **Při aktualizaci se nesmí ztratit data.**
- Do commitů, PR, kódu ani dokumentace nepiš identifikátor modelu. Patičku commitu
  (Co-Authored-By, Claude-Session) ber z pokynů aktuální session.
- Commit message česky: `Modul: co se změnilo`, v těle proč a odrážky.
- PR otevírej jen na výslovné přání uživatele.

## Nasazení (dělá uživatel)

Server je Ubuntu s Apachem a mod_php. Klon repa je v `/root/trading_desk` a uživatel stahuje
přímo pracovní větev:

```bash
# Trading Desk (včetně Hindsightu)
cd /root/trading_desk && git pull origin claude/elegant-clarke-vl5n7d && sudo bash deploy/install.sh
```

Když pracuješ v jiné větvi, dej uživateli příkaz s jejím názvem, nebo navrhni sloučení do `main`.

- Kód na serveru: `/var/www/trading-journal`, data: `/var/lib/trading-journal`.
- Apache conf: `deploy/apache-trading.conf` → `/etc/apache2/conf-available/trading-journal.conf`.
- Odměny mají vlastní instalace ve svých repozitářích. Instalace Trading Desku se jich nedotýká.
- Instalace kopíruje kód rsyncem s výjimkami. Dokumentace, testy, `deploy/`, `.git`,
  podklady (`*.csv`, `*.docx`) a `CLAUDE.md` se na web nedostanou. Hlídají to testy a Apache i vestavěný router navíc odmítají `.md`, `.py`,
  `.sqlite3` a podobné soubory.
- PHP limity nastavuje Apache conf, protože mod_php nečte `.user.ini`. Trading Desk má
  upload 100M a post 128M (kvůli importu z ATAS), screenshoty drží aplikace na 20 MB.

## Mapa repozitáře

**Trading Desk:** PHP 8 + SQLite. Deník obchodů a denní příprava pro Market a Volume Profile.

- `index.php`: hlavní stránka deníku. Bez relace ukáže přihlášení (`lib/auth-page.php`).
- `bootstrap.php`: jádro. Schéma deníku, validace, náhledy, obchody, strategie, psychika,
  audit. `APP_VERSION`, `MAX_UPLOAD_BYTES`.
- `api.php`: JSON API (`?action=…`).
  - Obsahuje fatal handler: vrátí JSON 500 s českou hláškou.
  - Zápisy hlídá kontrola původu (Sec-Fetch-Site/Origin, `bootstrap.php`).
- `lib/accounts.php`: účty, relace a úložiště deníků.
  - `app.sqlite3` je sdílená: uživatelé, relace, nástěnka.
  - Každý uživatel má vlastní `users/<id>/` s vlastní SQLite a screenshoty.
- `lib/vault.php`: šifrované deníky (libsodium, XChaCha20-Poly1305). DEK je zabalený
  přístupovým klíčem a po přihlášení i tokenem relace.
- `lib/wall.php`: nástěnka. Sdílí se snímek, ne živý odkaz.
- `lib/workspace.php`: prostředí na míru (metodika, prvky, vlastní pole, trhy, moduly).
- `lib/svg.php`: čištění SVG obrázků strategií před uložením.
- cTrader:
  - `lib/ctrader.php`: nastavení aplikace, OAuth (token, obnova), klient WebSocketu s JSON
    zprávami (port 5036), čísla zpráv podle proto souborů Spotware;
  - `lib/broker.php`: tabulky `broker_*` v deníku, propojení s účtem, synchronizace, import
    pozic do `trades` (`external_ref`), automatický audit, oprava očekávaného zůstatku
    (`broker_balance_adjustment`);
  - `ctrader.php`: start přihlášení a návrat (cookie `td_ctrader` svázaná s členem);
  - `static/broker.js`: panel v Účtech a auditu a karta ve Správě;
  - `TRADING_CTRADER_SOCKET`, `_TOKEN_URL`, `_AUTH_URL` přesměrují spojení (jen testy).
- `lib/hindsight.php`, `hindsight.php`, `static/hindsight/`: Hindsight (viz jeho CLAUDE.md).
- `static/app.js`: hlavní klient.
  - Vedle něj: `auth.js`, `members.js`, `settings.js`, `wall.js`, `dinapoli.js`,
    `tradingview-export.js`, `theme.js`, `styles.css`.
- PDF přes Python reportlab (`/usr/bin/python3`, jinak `TRADING_PYTHON`):
  - `pdf.php`: `?id=` náhled, `?trading_plan=` obchodní plán; obrázky dešifruje do dočasných souborů;
  - `export_plan.py` (náhled), `export_trading_plan.py` (plán), `pdf_kit.py` (písma, paleta,
    karty, tabulky, titulní pás, záhlaví a zápatí s „Strana X / Y“);
  - `lib/fonts/`: statické řezy Fraunces a Manrope z variabilních písem (fontTools instancer).
    Znaky mimo písmo (☐ ● ✓ →) v PDF nepoužívej, kreslí se jako prvky.
- `lib/tradeplan.php` + `static/tradeplan.js`: Obchodní plán (číselníky `TP_*`, verze, návrh).
- Psychika:
  - vstupní profil, rychlý test a kalibrace jsou v `bootstrap.php` (`psych_*`);
  - `lib/personality.php` + `static/personality.js`: osobnost (Big Five, talenty), oblasti zaměření;
  - `static/breathing.js` (dechové cvičení) a `static/soundscapes.js` (kulisy, `TDSound`).
- Ostatní PHP:
  - `backup.php`: ZIP záloha deníku;
  - `file.php`: obrázky a screenshoty;
  - `bin/setup-token.php`: kód prvního správce;
  - `bin/hindsight-keeper.php`: kdo smí nahrávat svíčky do Hindsightu.
- `router.php`: router pro `php -S`, zavírá neveřejné cesty jako Apache.
  - Jiné způsoby spuštění: `start-*.bat/.command` a `.devcontainer/` (Codespaces).
- `tests/`: Python HTTP testy (spouští vlastní `php -S`) a Node testy (`*.js`).

**Ostatní:** `trading-desk-v16.zip` je původní nahraná verze. Nic se z ní nenasazuje.

## Vývoj a testy v cloudové session

Kontejner má předinstalované PHP 8.4 CLI (sqlite3, mbstring), Node 22 a Playwright s Chromiem.
Apache předinstalovaný není. Nainstaluje ho `sudo bash deploy/install.sh`, ale jen kvůli testům
aktualizace na lokálním Apachi v kontejneru. **Nikdy ne na ostrém serveru.**

```bash
# Trading Desk: 129 testů, asi minuta a půl (1 skip bez pypdf)
python3 -m unittest discover -s tests
for f in tests/*.js; do node "$f"; done
php -l api.php            # a další změněné PHP soubory
```

- Testy spouštěj přes `discover` z kořene repa. `python3 -m unittest tests.x` selže na importu.
- `tests/test_pdf_export.py` potřebuje Python reportlab a Pillow. Když chybí, nainstaluj je přes
  `pip install reportlab pillow`; `pypdf` je volitelné (bez něj se jeden test přeskočí).
- Lokální Trading Desk:
  - Spusť `TRADING_DATA_DIR=$S/data php -S 127.0.0.1:8475 router.php` a pak
    `curl --noproxy '*' http://127.0.0.1:8475/api.php?action=auth_state`. Tím vznikne
    `$S/data/setup-token.txt`.
  - Správce založíš přes `POST api.php?action=setup` s údaji
    `{token, login, display_name, secret_mode:'key'}`.
  - Větší uploady potřebují `php -d upload_max_filesize=100M -d post_max_size=128M -S …`.
- Playwright:
  - `require('/opt/node22/lib/node_modules/playwright')`;
  - `chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' })`;
  - `playwright install` nespouštěj.
- `$S` = scratchpad session. Pomocné skripty a data drž tam. Kontejner je dočasný, takže co
  se má zachovat, patří do repa (commit a push).
- cTrader v prohlížeči: spusť simulaci (`CtraderMock()` z `tests/ctrader_mock.py`, vypíše
  `env`) a PHP server s těmi proměnnými. Client ID `test-client`, Secret `test-secret`,
  adresa pro návrat `http://127.0.0.1:<port>/ctrader.php`.
- Testy, které ukončují server, musí dočíst odpověď. Jinak zabitý požadavek nechá v `/dev/shm`
  odemčenou kopii šifrovaného deníku a `test_encrypted_journal_is_sealed_on_disk` selže.
- PDF s jinou verzí reportlabu: venv v `$S` a `TRADING_PYTHON=$S/venv/bin/python` pro testy.
  Text z PDF čte `pypdf` (potřebuje i `cffi`); nadpisy jsou v PDF velkými písmeny.
- Zvuk se nedá poslechnout, ale dá se změřit: kulisu vyrenderuj v Chromiu přes
  `OfflineAudioContext` (soundscapes.js funguje i offline) a změř RMS, špičky a skoky mezi
  vzorky. Ukázky pro uživatele: offline render přehrát do `MediaRecorder` (webm/opus).
- Hodiny kontejneru jsou reálné. Testy s daty „v budoucnosti“ můžou změnit autodetekci
  formátu data (Hindsight import).
