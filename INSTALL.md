# Trading Desk — instalace

Deník obchodů a denní příprava pro Market Profile a Volume Profile.

**Aplikace běží celá u tebe v počítači.** Nic se neposílá na internet, nepotřebuješ účet, přihlášení ani předplatné. Data jsou soubor na tvém disku a jsou tvoje. Když počítač vypneš, nic nikam neodchází.

---

## Co si vybrat

| Máš | Postup | Čas |
|---|---|---|
| **Windows 10 nebo 11** | Varianta A — Ubuntu ve Windows | ~20 minut, jednou |
| **Mac** | Varianta B — PHP přes Homebrew | ~10 minut, jednou |
| **Ubuntu / Linux** | Varianta C | ~5 minut |
| **Jen prohlížeč, bez instalace** | Varianta D — GitHub Codespaces | ~3 minuty |
| **Vlastní server (VPS) na internetu** | Varianta E | ~15 minut |

Po prvním nastavení už appku spouštíš jedním kliknutím.

**První spuštění:** aplikace ještě nemá žádný účet, a tak se na přihlašovací stránce ukáže *Založení správce*. Chce jednorázový kód, který vypíše instalace (Windows, Linux), okno Terminálu (Mac) nebo terminál Codespace. Správce pak schvaluje registrace dalších členů. Pokud už aplikaci používáš, tvůj dosavadní deník převezme právě tenhle první účet.

---

## Varianta A — Windows

Windows umí spustit Ubuntu přímo v sobě (funkce se jmenuje WSL). Není to virtuál, který bys musel spravovat, a je zdarma. Aplikace pak běží jako malý server u tebe v PC.

### 1. Nainstaluj Ubuntu

Klikni pravým tlačítkem na tlačítko Start a vyber **Terminál (správce)** nebo **PowerShell (správce)**. Vlož příkaz a dej Enter:

```powershell
wsl --install
```

Až doběhne, **restartuj počítač**. Po restartu se sám otevře Ubuntu a zeptá se na uživatelské jméno a heslo. Vymysli si je a **heslo si zapamatuj**, budeš ho potřebovat v dalším kroku. Při psaní hesla se nic nezobrazuje, to je normální.

> Pokud `wsl --install` skončí chybou, máš nejspíš vypnutou virtualizaci v BIOSu, nebo starší Windows 10. Napiš mi a vyřešíme to.

### 2. Zkopíruj aplikaci a nainstaluj ji

Rozbal stažený ZIP třeba do `Dokumenty`. Otevři **Ubuntu** z nabídky Start a vlož tento příkaz — cestu uprav podle toho, kam jsi to rozbalil:

```bash
cp -a "/mnt/c/Users/TVOJE_JMENO/Documents/trading-desk/." ~/trading-web/
```

Pak spusť instalaci. Vyžádá si heslo, které sis zvolil v kroku 1:

```bash
cd ~/trading-web && sudo bash deploy/install.sh
```

Instalace stáhne webový server a doplňky pro export do PDF. Trvá pár minut. Na konci vypíše `Trading Desk je připravený`.

### 3. Restartuj Ubuntu

Zavři okno Ubuntu, otevři **PowerShell** a spusť:

```powershell
wsl --shutdown
```

Tím se aktivuje automatické spouštění serveru. Tenhle krok se dělá jen jednou.

### 4. Spouštění

Dvakrát klikni na **`start-windows.bat`** ve složce, kterou jsi rozbalil. Nastartuje Ubuntu, počká na server a otevře aplikaci v prohlížeči.

Můžeš si na něj udělat zástupce na plochu: klikni pravým tlačítkem → *Zobrazit další možnosti* → *Odeslat* → *Plocha (vytvořit zástupce)*.

Případně stačí mít otevřené Ubuntu a v prohlížeči zadat:

```text
http://localhost/trading/
```

---

## Varianta B — Mac

Na Macu není WSL, takže se použije přímo PHP. Aplikace i data zůstávají stejně jen u tebe v počítači.

### 1. Nainstaluj Homebrew

Otevři **Terminál** (Cmd+mezerník, napiš „Terminál") a vlož:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

Postupuj podle instrukcí na obrazovce. Na konci ti Homebrew může vypsat dva řádky začínající `echo` — ty je potřeba také spustit.

### 2. Nainstaluj PHP

```bash
brew install php
```

### 3. Spouštění

Rozbal ZIP třeba do složky Dokumenty a dvakrát klikni na **`start-macos.command`**.

Když macOS napíše, že soubor nelze otevřít, protože pochází od neznámého vývojáře, klikni na něj pravým tlačítkem, dej **Otevřít** a potvrď. Případně mu jednou povol spuštění v Terminálu:

```bash
chmod +x ~/Documents/trading-desk/start-macos.command
```

Aplikace se otevře na adrese `http://localhost:8420/`. Okno Terminálu nech otevřené, dokud ji používáš.

---

## Varianta C — Ubuntu nebo Linux

```bash
cd trading-desk && sudo bash deploy/install.sh
```

Aplikace poběží na `http://localhost/trading/`.

---

## Varianta D — GitHub Codespaces

Codespace je malý počítač v cloudu od GitHubu, který patří jen tobě. Nic se neinstaluje, stačí prohlížeč a přihlášení na GitHub.

### 1. Vytvoř Codespace

Otevři [codespaces.new/ecko456/trading_desk](https://codespaces.new/ecko456/trading_desk?quickstart=1) a klikni na **Create codespace**. Případně na stránce repozitáře zelené tlačítko **Code** → záložka **Codespaces** → **Create codespace on main**.

Poprvé to trvá zhruba dvě až tři minuty, protože se instaluje PHP a knihovny pro PDF.

### 2. Otevři aplikaci

Až se to dokončí, aplikace se sama otevře v nové záložce. Když se to nestane, otevři dole kartu **PORTS**, najdi řádek **Trading Desk (8420)** a klikni na ikonu zeměkoule.

Příště stačí stejný odkaz: `quickstart=1` otevře tvůj existující Codespace, nevytváří nový.

Při prvním spuštění vypíše terminál dole v Codespace **kód pro založení správce**. Zadej ho na přihlašovací stránce.

### Na co si dát pozor

- **Zálohuj se.** Data jsou uvnitř Codespace ve složce `data/`. Když Codespace zastavíš, zůstanou. GitHub ale ve výchozím nastavení **smaže Codespace, který 30 dní nepoužiješ**, a data s ním. Stahuj proto pravidelně ZIP v sekci **Záloha**.
- **Port nech soukromý.** Ve výchozím stavu na adresu aplikace vidíš jen ty po přihlášení na GitHub. Aplikace má vlastní přihlašování, ale Codespace není stavěný jako veřejný server.
- **Data nepatří do gitu.** Repozitář je veřejný. Soubor `.gitignore` databázi i screenshoty z commitů vynechává, i kdybys omylem kliknul na commit.
- **Čas zdarma je omezený.** Osobní účet má každý měsíc zdarma kvótu hodin Codespaces. Codespace se sám zastaví po 30 minutách nečinnosti, takže běží jen, když ho používáš.

---

## Varianta E — vlastní server (VPS) na internetu

Server na internetu najdou automatické skenery během pár minut, i když nemá doménu a znáš jen jeho IP adresu. Aplikace proto musí mít heslo a HTTPS dřív, než ji začneš používat.

### 1. Instalace

Na serveru s Ubuntu stejně jako ve variantě C:

```bash
git clone https://github.com/ecko456/trading_desk.git && cd trading_desk
sudo bash deploy/install.sh
```

Instalace na konci vypíše kód pro založení správce.

### 2. Správce a členové

Otevři aplikaci, zadej kód a založ účet správce. Další lidé se zaregistrují sami a ty je schválíš v sekci **Členové**. Každý má vlastní deník; na společné nástěnce je jen to, co kdo sdílí. Kdo chce, zvolí si šifrovaný deník s přístupovým klíčem místo hesla.

Když kód ztratíš, vypíšeš ho znovu:

```bash
sudo -u www-data TRADING_DATA_DIR=/var/lib/trading-journal php /var/www/trading-journal/bin/setup-token.php
```

### 3. Než ji začneš používat

- **HTTPS.** Bez něj jde heslo, přístupový klíč i přihlášení po síti čitelně. Bez domény se dá použít buď certifikát vystavený pro adresu typu `<IP s pomlčkami>.sslip.io`, nebo vlastní certifikát, u kterého prohlížeč jednou ukáže varování.
- **Firewall** jen pro SSH, HTTP a HTTPS a automatické bezpečnostní aktualizace.
- **Zálohy mimo server.** ZIP ze sekce **Záloha** stahuj i k sobě.

---

## Kde jsou moje data

| Varianta | Databáze | Screenshoty |
|---|---|---|
| Windows (WSL) a Linux | `/var/lib/trading-journal/users/<id>/` | `/var/lib/trading-journal/users/<id>/uploads/` |
| Mac a Codespace | `data/users/<id>/` ve složce aplikace | `data/users/<id>/uploads/` |

Každý člen má vlastní složku `users/<id>/`. Nešifrovaný deník je soubor `trading.sqlite3`, šifrovaný `trading.sqlite3.sealed`. Členové a nástěnka jsou v `app.sqlite3`.

Databáze je jeden soubor. Adresář s daty není z webu dostupný a obrázky se zobrazují jen přes kontrolovaný odkaz uvnitř aplikace.

**Zálohuj se.** V aplikaci je sekce **Záloha**, kde jedním kliknutím stáhneš ZIP s databází i všemi screenshoty. Dělej to pravidelně a jednu kopii měj mimo tenhle počítač — na flashce nebo v cloudu. Když se rozbije disk, aplikace ti data nezachrání, protože nikde jinde nejsou.

---

## PDF export (volitelné)

Export denního náhledu do PDF potřebuje Python s knihovnami `reportlab` a `pillow`.

Na **Windows (WSL) a Linuxu** je instalátor nainstaluje sám, není co řešit.

Na **Macu** je doinstaluj takto:

```bash
brew install python
pip3 install --break-system-packages reportlab pillow
```

Bez nich funguje všechno ostatní, jen tlačítko *Stáhnout PDF* nahlásí, že převodník chybí.

---

## Aktualizace na novou verzi

**Windows a Linux:** rozbal novou verzi a spusť stejné příkazy jako při instalaci — `cp -a ...` a `sudo bash deploy/install.sh`. Tvoje data zůstanou, instalace přepisuje jen aplikaci. Databáze se sama doplní o nové sloupce.

**Mac:** rozbal novou verzi vedle staré a **přenes si do ní složku `data/`** ze staré verze. V ní jsou všechny tvoje obchody.

**Server s Gitem (varianta E):** stáhni novou verzi a spusť instalaci znovu. Data v `/var/lib/trading-journal` zůstanou.

```bash
cd ~/trading_desk
git fetch origin main
git checkout -B main FETCH_HEAD
sudo bash deploy/install.sh
```

Když aplikace spadne na chybu hned po aktualizaci, instalace se nepovedla celá; spusť poslední příkaz znovu a přečti si, co vypíše.

Po aktualizaci dej v prohlížeči Ctrl+Shift+R (na Macu Cmd+Shift+R), ať se načte nová verze.

---

## Když něco nefunguje

**Screenshot se nenahraje, protože je prý moc velký**

Aplikace bere obrázky do 20 MB. Na Ubuntu to nastavuje konfigurace Apache z instalace; po aktualizaci na starší instalaci proto spusť znovu `sudo bash deploy/install.sh`. Velké PNG z 4K monitoru pomůže uložit jako JPEG.

**Prohlížeč hlásí, že se nelze připojit**

Windows: otevři Ubuntu a spusť `sudo service apache2 start`.
Mac: zkontroluj, že okno Terminálu se spuštěnou aplikací pořád běží.

**Chci ověřit, že server žije**

```bash
curl -fsS 'http://localhost/trading/api.php?action=health'
```

Na Macu použij `http://localhost:8420/api.php?action=health`. Odpověď musí obsahovat `"ok":true` a číslo verze.

**Něco vypadá rozbitě po aktualizaci**

Dej v prohlížeči tvrdé obnovení: Ctrl+Shift+R, na Macu Cmd+Shift+R.

**Port 8420 je obsazený (jen Mac)**

Otevři `start-macos.command` v textovém editoru a změň `PORT=8420` na jiné číslo, třeba `8421`.

---

## Bezpečnost — přečti si to

Aplikace má účty: každý člen se přihlašuje a vidí jen svůj deník, registrace schvaluje správce a přihlášení je chráněné proti hádání hesel.

**Na internet ji dávej jen s HTTPS** (varianta E). Bez něj jde heslo i přihlášení po síti čitelně. Na `localhost` a v Codespace to potřeba není.
