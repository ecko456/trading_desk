# Trading Desk: poznámky pro Clauda

Claude Code si tenhle soubor načte sám na začátku každé session. Je v něm, kde jsme
skončili, jak je projekt poskládaný a jak s ním pracovat. Podrobnosti k modulům:

- `odmeny_v2/CLAUDE.md`: Odměny (načte se sám při práci v `odmeny_v2/`)
- `static/hindsight/CLAUDE.md`: Hindsight

Dokumentace pro uživatele je v `README.md`, `INSTALL.md` a `odmeny_v2/README.md`.
Po každé větší práci **aktualizuj sekci „Kde jsme skončili“** a poznámky modulu.

## Kde jsme skončili

Stav k 30. 9. 2026:

- Pracovní větev je `claude/elegant-clarke-vl5n7d`. Uživatel z ní nasazuje a do `main` zatím
  sloučená není (přes 20 commitů navíc). Na `main` jsou navíc jen nahrané podklady:
  - `Chart.csv`, vzorek exportu svíček z ATAS;
  - `Hindsight – zadání modulu.docx`.
- Poslední práce (Hindsight):
  - import velkého exportu z ATAS bez pádu serveru (`f96ed9e`); uživatel potvrdil, že funguje;
  - klik na pás seancí přiblíží, další klik vrátí zobrazení (`6cb4b69`).
- **Odměny verze 2 jsou oddělené od ostré verze.** Uživatel chtěl, ať V2 nepoškodí
  fungující `/odmeny/`. Proto:
  - V2 je v repu v `odmeny_v2/` a běží na `/odmeny_v2/` s vlastním kódem, daty i Apache conf;
  - kopii ostrých dat jde udělat jen pro čtení;
  - zapamatovaná zařízení s PINem má V2 zvlášť.

  Ostrá verze v aktuální větvi není (jen v historii, `ffe47d2`). Podrobnosti jsou
  v `odmeny_v2/CLAUDE.md`. Další zadání k Odměnám se čeká.
- Od uživatele se čeká:
  - vzorek skutečného exportu evidence práce (Odměny);
  - vzorek CSV s obchody pro import do Hindsightu.

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
  (screenshot). **Při aktualizaci se nesmí ztratit data**, u Odměn to platí dvojnásob.
- Odměny musí zůstat šifrované a přístupné jen po přihlášení.
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
# Odměny verze 2 na /odmeny_v2/ (ostré /odmeny/ nemění; ODMENY_KOPIE=1 = s kopií ostrých dat)
cd /root/trading_desk && git pull origin claude/elegant-clarke-vl5n7d && sudo ODMENY_KOPIE=1 bash odmeny_v2/deploy/install.sh
# další aktualizace V2 (data V2 zůstanou, před aktualizací se zazálohují)
cd /root/trading_desk && git pull origin claude/elegant-clarke-vl5n7d && sudo bash odmeny_v2/deploy/install.sh
```

Když pracuješ v jiné větvi, dej uživateli příkaz s jejím názvem, nebo navrhni sloučení do `main`.

| | Trading Desk | Odměny ostré (nesahat) | Odměny verze 2 |
|---|---|---|---|
| URL | `/trading/` | `/odmeny/` | `/odmeny_v2/` |
| kód na serveru | `/var/www/trading-journal` | `/var/www/odmeny` | `/var/www/odmeny_v2` |
| data | `/var/lib/trading-journal` | `/var/lib/odmeny` | `/var/lib/odmeny_v2` (+ `backups/`) |
| Apache conf | `deploy/apache-trading.conf` | `odmeny.conf` (jen na serveru) | `odmeny_v2/deploy/apache-odmeny_v2.conf` |
| instalace | `deploy/install.sh` | žádná (historie `ffe47d2`) | `odmeny_v2/deploy/install.sh` |

- Instalace kopírují kód rsyncem s výjimkami. Dokumentace, testy, `deploy/`, `.git`,
  podklady (`*.csv`, `*.docx`), `odmeny/` a `odmeny_v2/` (u Trading Desku) a `CLAUDE.md` se na web
  nedostanou. Hlídají to testy a Apache i vestavěný router navíc odmítají `.md`, `.py`,
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
- `lib/hindsight.php`, `hindsight.php`, `static/hindsight/`: Hindsight (viz jeho CLAUDE.md).
- `static/app.js`: hlavní klient.
  - Vedle něj: `auth.js`, `members.js`, `settings.js`, `wall.js`, `dinapoli.js`,
    `tradingview-export.js`, `theme.js`, `styles.css`.
- `pdf.php` + `export_plan.py`: PDF náhledu přes Python reportlab (`/usr/bin/python3`).
- Ostatní PHP:
  - `backup.php`: ZIP záloha deníku;
  - `file.php`: obrázky a screenshoty;
  - `bin/setup-token.php`: kód prvního správce.
- `router.php`: router pro `php -S`, zavírá neveřejné cesty jako Apache.
  - Jiné způsoby spuštění: `start-*.bat/.command` a `.devcontainer/` (Codespaces).
- `tests/`: Python HTTP testy (spouští vlastní `php -S`) a Node testy (`*.js`).

**Odměny:** `odmeny_v2/`, samostatná šifrovaná aplikace, verze 2 na `/odmeny_v2/`.

- Ostrá verze `/odmeny/` v aktuální větvi není (viz `odmeny_v2/CLAUDE.md`).
- `hodnoceni-operatoru.html` v kořeni je původní aplikace. Neinstaluje se; slouží jako
  reference pro `odmeny_v2/tests/test_core.js`.

**Ostatní:** `trading-desk-v16.zip` je původní nahraná verze. Nic se z ní nenasazuje.

## Vývoj a testy v cloudové session

Kontejner má předinstalované PHP 8.4 CLI (sqlite3, mbstring), Node 22 a Playwright s Chromiem.
Apache předinstalovaný není. Nainstaluje ho `sudo bash deploy/install.sh`, ale jen kvůli testům
aktualizace na lokálním Apachi v kontejneru. **Nikdy ne na ostrém serveru.**

```bash
# Trading Desk: 92 testů, asi minuta (1 skip bez pypdf)
python3 -m unittest discover -s tests
for f in tests/*.js; do node "$f"; done
php -l api.php            # a další změněné PHP soubory

# Odměny: 19 testů, pár vteřin
python3 -m unittest discover -s odmeny_v2/tests
node odmeny_v2/tests/test_core.js
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
- Lokální Odměny: `ODMENY_DATA_DIR=$S/odm php -S 127.0.0.1:8490 -t odmeny_v2 odmeny_v2/dev-router.php`.
  Víc je v `odmeny_v2/CLAUDE.md`.
- Playwright:
  - `require('/opt/node22/lib/node_modules/playwright')`;
  - `chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' })`;
  - `playwright install` nespouštěj.
- `$S` = scratchpad session. Pomocné skripty a data drž tam. Kontejner je dočasný, takže co
  se má zachovat, patří do repa (commit a push).
- Hodiny kontejneru jsou reálné. Testy s daty „v budoucnosti“ můžou změnit autodetekci
  formátu data (Hindsight import).
