# Odměny

Hodnocení operátorů (tabáky a Kafe) jako samostatná aplikace na serveru, na adrese
`/odmeny/`. S Trading Deskem sdílí jen server: má vlastní adresář, vlastní data
i vlastní přihlašování.

Vychází z `hodnoceni-operatoru.html`. Výpočty jsou převzaté beze změny
(`private/core.js`, opravy jsou v kódu označené „Oprava:“), rozhraní je nové.

## Zabezpečení

- **Přístup jen s kartičkou.** Při prvním spuštění aplikace vytvoří přístupovou
  kartičku s QR kódem. Klíč z kartičky se nikam neposílá: prohlížeč z něj odvodí
  otisk pro přihlášení a klíč, kterým odemkne data. Kartičku jde naskenovat
  kamerou, nahrát fotku, nebo klíč opsat (překlep v klíči aplikace pozná).
- **Data jsou šifrovaná v prohlížeči** (AES-256-GCM). Server dostane i uloží jen
  šifrovaný blok. Kdo získá databázi ze serveru, bez kartičky nic nepřečte.
- **Zapamatované zařízení s PINem.** Po přihlášení kartičkou si zařízení může
  pamatovat šestimístný PIN. Klíč k datům se skládá z tajemství v prohlížeči,
  tajemství na serveru a PINu; server vydá svou část jen po ověření PINu. Po pěti
  chybných PINech server zařízení smaže.
- **Relace** platí nejvýš 12 hodin a končí po hodině nečinnosti. Aplikace se sama
  zamkne po nastavené době (výchozí 15 minut) a po obnovení stránky chce znovu PIN
  nebo kartičku. Klíč k datům je jen v paměti otevřené stránky.
- **Kód aplikace** (`private/`) server vydá až po odemčení. Zamčená stránka
  neobsahuje nic z aplikace.
- Přísná bezpečnostní politika stránky (žádné cizí ani vložené skripty), ochrana
  proti požadavkům z cizích stránek, omezení počtu pokusů, HTTPS povinné.
- **Ztráta kartičky:** v Nastavení → Zabezpečení ji zruš. Přestane platit a odeberou
  se i zařízení, která se přes ni zapamatovala. Pozor: když ztratíš všechny
  kartičky a nemáš zapamatované zařízení, k datům se už nedostane nikdo, ani správce
  serveru. Vytiskni si proto dvě kartičky a jednu ulož bokem.

## Instalace na server (Ubuntu + Apache)

```bash
cd /root/trading_desk
git pull
sudo bash odmeny/deploy/install.sh
```

Instalace vypíše **kód pro první spuštění**. Otevři `https://<server>/odmeny/`,
zadej kód, vytvoř kartičku, vytiskni ji nebo ulož obrázek a dokonči nastavení. Na
telefonu pak otevři stejnou adresu a kartičku naskenuj.

Kód jde vypsat znovu (dokud neexistuje žádná kartička):

```bash
sudo runuser -u www-data -- env ODMENY_DATA_DIR=/var/lib/odmeny php /var/www/odmeny/bin/setup-token.php
```

- Aplikace: `/var/www/odmeny`, šifrovaná data: `/var/lib/odmeny` (SQLite).
- Apache: `/etc/apache2/conf-available/odmeny.conf` (z `deploy/apache-odmeny.conf`).
- Aplikace běží jen přes HTTPS (šifrování v prohlížeči bez něj nefunguje).

## Zálohy a historie

- Každé uložení je nová verze. Server drží posledních 30 uložení a k tomu stav
  z každé hodiny za poslední týden a z každého dne před tím (nejvýš 150 verzí).
  Vrátit jde v Nastavení → Data a zálohy → Historie verzí.
- **Šifrovaná záloha** (`.odmeny`) otevře kterákoli platná kartička, i na novém
  serveru (při prvním spuštění „Obnovit ze šifrované zálohy“).
- Záloha JSON z původní aplikace se načte přes „Načíst data z původní aplikace“.

## Rozdíly proti původní aplikaci

- Kódy nepřítomnosti se porovnávají bez ohledu na diakritiku (OČR = OCR).
- Každá sankce strhne aspoň jeden tabák (dřív při více sankcích v měsíci jen jeden
  za všechny dohromady).
- CSV v UTF-8 se čte správně (dřív rozbitá diakritika), Windows-1250 dál funguje.
- Když soubor nejde přečíst, aplikace to řekne (dřív se neukázalo nic).
- Před přepsáním už nahrané docházky se aplikace zeptá.
- Export do Excelu nespustí vzorce z buněk (=, +, −, @).
- Načítaná data (zálohy, import) se kontrolují a čistí.

## Vývoj a testy

```bash
# lokální server (bez HTTPS jen na 127.0.0.1)
ODMENY_DATA_DIR=/tmp/odmeny php -S 127.0.0.1:8490 -t odmeny odmeny/dev-router.php

# testy: server, šifrovaný tok, výpočty proti původní aplikaci
python3 -m unittest discover -s odmeny/tests
```

Knihovny: QR kódy (qrcode-generator, MIT), čtení QR (jsQR, Apache 2.0), Excel
(SheetJS, Apache 2.0), písma IBM Plex a Barlow (SIL OFL). Vše je přibalené,
aplikace nic nenačítá z cizích serverů.
