"""Hindsight: svíčky ES (import z ATAS po kontraktech), zóny a bias sdílené s denním náhledem."""

from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo
import http.client
import subprocess
import unittest

from test_accounts_http import PHP, Client, Server, multipart

ROOT = Path(__file__).resolve().parents[1]
PRAGUE = ZoneInfo("Europe/Prague")
NEW_YORK = ZoneInfo("America/New_York")
# Roll ESU6 -> ESZ6: čtvrtek 10. 9. 2026, obchodní den začíná 9. 9. v 18:00 New York (22:00 UTC).
ROLL_TS = int(datetime(2026, 9, 9, 22, 0, tzinfo=timezone.utc).timestamp())


def atas_bars(start, end):
    """5m svíčky jako z ATAS: pražský čas, bez denní přestávky CME a víkendu."""
    bars = []
    moment = start
    price = 6600.0
    while moment < end:
        ny = moment.astimezone(NEW_YORK)
        closed = ny.hour == 17 or ny.weekday() == 5 or (ny.weekday() == 4 and ny.hour >= 17) or (ny.weekday() == 6 and ny.hour < 18)
        if not closed:
            step = 1.0 if (moment.minute // 5) % 2 else -0.75
            bars.append((int(moment.timestamp()), price, max(price, price + step) + 0.5, min(price, price + step) - 0.5, price + step))
            price += step
        moment += timedelta(minutes=5)
    return bars


def atas_csv(bars):
    """Export ATAS: rok-den-měsíc, středníky, bez hlavičky a objemu, konce řádků CRLF."""
    lines = []
    for ts, o, h, l, c in bars:
        local = datetime.fromtimestamp(ts, timezone.utc).astimezone(PRAGUE)
        lines.append(f"{local:%Y-%d-%m %H:%M:%S};{o:.2f};{h:.2f};{l:.2f};{c:.2f}")
    return ("\r\n".join(lines) + "\r\n").encode()


BARS = atas_bars(datetime(2026, 9, 6, 22, 0, tzinfo=timezone.utc), datetime(2026, 9, 12, 0, 0, tzinfo=timezone.utc))
CSV = atas_csv(BARS)


def upload(client, contract, content=CSV, tz="Europe/Prague", date_order="auto"):
    body = multipart({"contract": contract, "tz": tz, "date_order": date_order}, [("file", "Chart.csv", content)])
    return client.request("POST", "/api.php?action=hindsight_import", body)


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class HindsightHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = Server()
        cls.admin = Client(cls.server)
        cls.admin.api("GET", "auth_state")
        token = (cls.server.data / "setup-token.txt").read_text().strip()
        status, result = cls.admin.api("POST", "setup", {"token": token, "login": "desk", "display_name": "Desk", "secret_mode": "key"})
        assert status == 201, result

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def member(self, login):
        client = Client(self.server)
        status, result = client.api("POST", "register", {"login": login, "display_name": login.title(), "secret_mode": "password", "password": "member-password-1"})
        self.assertEqual(status, 201, result)
        self.admin.api("POST", "admin_user", {"id": result["user"]["id"], "op": "approve"})
        status, _ = client.api("POST", "login", {"login": login, "secret": "member-password-1"})
        self.assertEqual(status, 200)
        return client

    def test_1_import_keeps_only_the_contract_period(self):
        before = [bar for bar in BARS if bar[0] < ROLL_TS]
        after = [bar for bar in BARS if bar[0] >= ROLL_TS]

        # Ukázková data se použijí, jen dokud nejsou skutečná.
        status, demo = self.admin.api("POST", "hindsight_demo")
        self.assertEqual(status, 201, demo)
        self.assertTrue(self.admin.api("GET", "hindsight_range")[1]["demo"])

        status, result = upload(self.admin, "esz6")
        self.assertEqual(status, 201, result)
        self.assertEqual(result["contract"], "ESZ6")
        self.assertEqual(result["period"], {"from_date": "2026-09-10", "last_date": "2026-12-09"})
        self.assertEqual(result["bars"], len(after))
        self.assertEqual(result["new"], len(after))
        self.assertEqual(result["from_ts"], ROLL_TS)
        self.assertEqual(result["outside"], [{"contract": "ESU6", "bars": len(before), "from_date": "2026-06-11", "last_date": "2026-09-09"}])

        # Opakovaný import nic nezdvojí, jen přepíše.
        status, again = upload(self.admin, "ESZ26")
        self.assertEqual(status, 201, again)
        self.assertEqual((again["contract"], again["new"], again["updated"]), ("ESZ6", 0, len(after)))

        status, older = upload(self.admin, "ES 09-26")
        self.assertEqual(status, 201, older)
        self.assertEqual((older["contract"], older["bars"]), ("ESU6", len(before)))
        self.assertEqual(older["to_ts"], before[-1][0])
        self.assertEqual(before[-1][0], ROLL_TS - 3900, "poslední svíčka ESU6 je 16:55 New York, pak přestávka CME")

        # Soubor bez svíček kontraktu a neplatné názvy.
        status, wrong = upload(self.admin, "ESH7")
        self.assertEqual(status, 422, wrong)
        self.assertEqual([item["contract"] for item in wrong["outside"]], ["ESU6", "ESZ6"])
        for name in ("", "XYZ", "MESZ6", "ESX6", "ES" + "Z" * 30):
            self.assertEqual(upload(self.admin, name)[0], 422, name)
        self.assertEqual(upload(self.admin, "ESZ6", tz="Mars/Base")[0], 422)

        status, bars = self.admin.api("GET", "hindsight_bars", **{"from": "2026-09-07", "to": "2026-09-11"})
        self.assertEqual(status, 200, bars)
        times = [bar[0] for bar in bars["bars"]]
        self.assertEqual(times, [bar[0] for bar in BARS], "souvislá řada bez mezer a bez ukázkových svíček")
        self.assertEqual(bars["rolls"], [{"ts": ROLL_TS, "from": "ESU6", "to": "ESZ6"}])
        # 15:30 v Praze (letní čas) = 13:30 UTC, ceny beze změny.
        moment = int(datetime(2026, 9, 8, 13, 30, tzinfo=timezone.utc).timestamp())
        expected = next(bar for bar in BARS if bar[0] == moment)
        found = next(bar for bar in bars["bars"] if bar[0] == moment)
        self.assertEqual(found[1:5], list(expected[1:5]))

        status, span = self.admin.api("GET", "hindsight_range")
        self.assertFalse(span["demo"])
        self.assertEqual((span["first_ts"], span["last_ts"]), (BARS[0][0], BARS[-1][0]))
        contracts = {item["contract"]: item for item in span["contracts"]}
        self.assertEqual((contracts["ESU6"]["from_date"], contracts["ESU6"]["last_date"]), ("2026-06-11", "2026-09-09"))
        self.assertEqual(contracts["ESZ6"]["expiry"], "2026-12-18")
        self.assertIn("UKAZKA", contracts)
        options = span["contract_options"]
        self.assertEqual(len(options), 6)
        self.assertEqual(sum(1 for option in options if option["current"]), 1)

        # Jeden den, kde den i měsíc můžou být obojí (3. 4. nebo 4. 3.): import se zeptá na formát.
        one_day = atas_csv(atas_bars(datetime(2026, 4, 2, 22, 0, tzinfo=timezone.utc), datetime(2026, 4, 3, 20, 0, tzinfo=timezone.utc)))
        self.assertTrue(one_day.startswith(b"2026-03-04 00:00:00;"))
        status, unclear = upload(self.admin, "ESM6", one_day)
        self.assertEqual((status, unclear.get("date_order")), (422, True), unclear)
        status, clear = upload(self.admin, "ESM6", one_day, date_order="ydm")
        self.assertEqual(status, 201, clear)
        self.assertEqual(clear["from_ts"], int(datetime(2026, 4, 2, 22, 0, tzinfo=timezone.utc).timestamp()))
        self.assertEqual(upload(self.admin, "ESH6", one_day, date_order="ymd")[0], 201, "4. 3. patří kontraktu ESH6")
        self.assertEqual(self.admin.request("DELETE", "/api.php?action=hindsight_contract&contract=ESM6")[0], 200)
        self.assertEqual(self.admin.request("DELETE", "/api.php?action=hindsight_contract&contract=ESH6")[0], 200)

        # 1m svíčky se sloučí do 5m (open první, close poslední minuty, high/low z celé pětiminuty).
        minutes = []
        for ts, o, h, l, c in BARS[:600]:
            for k in range(5):
                minutes.append((ts + 60 * k, o + k * 0.25, h + (1 if k == 2 else 0), l - (1 if k == 3 else 0), o + (k + 1) * 0.25 if k < 4 else c))
        comma = lambda value: f"{value:.2f}".replace(".", ",")  # české desetinné čárky
        lines = ["Date;Time;Open;High;Low;Close;Volume"] + [f"{datetime.fromtimestamp(ts, timezone.utc).astimezone(PRAGUE):%d.%m.%Y;%H:%M:%S};{comma(o)};{comma(h)};{comma(l)};{comma(c)};10" for ts, o, h, l, c in minutes]
        status, merged = upload(self.admin, "ESU6", ("\ufeff" + "\n".join(lines)).encode())
        self.assertEqual(status, 201, merged)
        self.assertEqual((merged["rows"], merged["bars"]), (3000, 600))
        bars = self.admin.api("GET", "hindsight_bars", **{"from": "2026-09-07", "to": "2026-09-09"})[1]["bars"]
        first = next(bar for bar in bars if bar[0] == BARS[0][0])
        self.assertEqual(first[1:6], [BARS[0][1], BARS[0][2] + 1, BARS[0][3] - 1, BARS[0][4], 50.0])

        # Smazání kontraktu (jen správce).
        status, removed = self.admin.request("DELETE", "/api.php?action=hindsight_contract&contract=UKAZKA")
        self.assertEqual((status, removed["removed"] > 0), (200, True))

    def test_2_market_data_is_admin_only(self):
        member = self.member("marta")
        self.assertEqual(member.api("GET", "hindsight_range")[0], 200)
        self.assertEqual(upload(member, "ESZ6")[0], 403)
        self.assertEqual(member.api("POST", "hindsight_demo")[0], 403)
        self.assertEqual(member.request("DELETE", "/api.php?action=hindsight_contract&contract=ESZ6")[0], 403)
        self.assertEqual(Client(self.server).api("GET", "hindsight_bars", **{"from": "2026-09-07", "to": "2026-09-08"})[0], 401)
        status, _ = member.api("GET", "hindsight_bars", **{"from": "2025-01-01", "to": "2026-09-08"})
        self.assertEqual(status, 422, "rozsah nad 400 dní")
        # Zóny a bias jsou v osobním deníku: člen nevidí zóny správce.
        self.admin.api("POST", "hindsight_zone", {"date": "2026-09-08", "type": "support", "price_low": 6590, "price_high": 6595, "valid_to": "open"})
        status, notes = member.api("GET", "hindsight_annotations", **{"from": "2026-09-07", "to": "2026-09-11"})
        self.assertEqual((status, notes["zones"]), (200, []))

    def test_3_zones_and_bias_are_the_daily_plan(self):
        client = self.member("petr")
        status, zone = client.api("POST", "hindsight_zone", {
            "date": "2026-09-08", "type": "support", "name": "Týdenní VAL", "note": "drží",
            "price_low": 6610, "price_high": 6600, "valid_to": "open",
        })
        self.assertEqual(status, 200, zone)
        status, notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-20", "to": "2026-09-25"})
        self.assertEqual([(z["id"], z["price_low"], z["price_high"], z["type"], z["valid_to"]) for z in notes["zones"]], [(zone["id"], 6600.0, 6610.0, "support", "open")])

        # Stejná zóna je v denním náhledu a uložení náhledu ji nezmění.
        status, plan = client.api("GET", "plan", id=zone["plan_id"])
        self.assertEqual(status, 200, plan)
        self.assertEqual((plan["plan_date"], plan["market"]), ("2026-09-08", "ES"))
        stored = plan["zones"][0]
        self.assertEqual((stored["valid_to"], stored["zone_type"], stored["note"], stored["direction"]), ("open", "support", "drží", "long"))
        editor_zone = {key: stored[key] for key in ("name", "direction", "price_low", "price_high", "valid_to", "zone_type", "note")}
        status, saved = client.api("POST", "plan", {"id": plan["id"], "plan_type": "daily", "plan_date": "2026-09-08", "market": "ES", "session": "Intraday", "zones": [editor_zone, {"name": "Starší zóna", "direction": "short", "price_low": 6700, "price_high": 6705}]})
        self.assertEqual(status, 200, saved)
        status, notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-07", "to": "2026-09-11"})
        by_name = {z["name"]: z for z in notes["zones"]}
        self.assertEqual(by_name["Týdenní VAL"]["valid_to"], "open")
        self.assertEqual((by_name["Starší zóna"]["valid_to"], by_name["Starší zóna"]["type"]), ("", "resistance"))
        # Zóna ze starého náhledu platí jen svůj den.
        status, later = client.api("GET", "hindsight_annotations", **{"from": "2026-09-09", "to": "2026-09-11"})
        self.assertEqual([z["name"] for z in later["zones"]], ["Týdenní VAL"])

        # Ukončení zóny a změna typu; směr obchodu z náhledu zůstane.
        zone_id = by_name["Týdenní VAL"]["id"]
        status, _ = client.request("PUT", "/api.php?action=hindsight_zone", {"id": zone_id, "type": "resistance", "name": "Týdenní VAL", "price_low": 6600, "price_high": 6610, "valid_to": "2026-09-10"})
        self.assertEqual(status, 200)
        self.assertEqual(client.api("GET", "hindsight_annotations", **{"from": "2026-09-11", "to": "2026-09-30"})[1]["zones"], [])
        status, notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-10", "to": "2026-09-10"})
        self.assertEqual((notes["zones"][0]["type"], notes["zones"][0]["direction"]), ("resistance", "long"))
        self.assertEqual(client.request("PUT", "/api.php?action=hindsight_zone", {"id": zone_id, "price_low": 6600, "price_high": 6610, "valid_to": "2026-09-01"})[0], 422)
        self.assertEqual(client.api("POST", "hindsight_zone", {"date": "2026-09-08", "price_low": "", "price_high": 6610})[0], 422)
        self.assertEqual(client.api("POST", "hindsight_zone", {"date": "8. 9. 2026", "price_low": 1, "price_high": 2})[0], 422)

        # Bias dne = bias denního náhledu.
        status, bias = client.api("POST", "hindsight_bias", {"date": "2026-09-08", "bias": "short", "note": "pod VAL"})
        self.assertEqual((status, bias["plan_id"]), (200, zone["plan_id"]))
        self.assertEqual(client.api("GET", "plan", id=zone["plan_id"])[1]["bias"], "short")
        status, bias = client.api("POST", "hindsight_bias", {"date": "2026-09-10", "bias": "long"})
        self.assertEqual(status, 200)
        self.assertNotEqual(bias["plan_id"], zone["plan_id"], "pro den bez náhledu vznikne koncept")
        self.assertEqual(client.api("POST", "hindsight_bias", {"date": "2026-09-10", "bias": "up"})[0], 422)
        notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-07", "to": "2026-09-11"})[1]
        self.assertEqual({day["date"]: (day["bias"], day["bias_note"]) for day in notes["days"]}, {"2026-09-08": ("short", "pod VAL"), "2026-09-10": ("long", "")})

        # Zóny z týdenního náhledu nebo jiného trhu Hindsight nemění ani nemaže.
        status, weekly = client.api("POST", "plan", {"plan_type": "weekly", "plan_date": "2026-09-07", "market": "ES", "session": "Intraday", "zones": [{"name": "Týdenní", "direction": "long", "price_low": 6500, "price_high": 6510}]})
        self.assertEqual(status, 200, weekly)
        weekly_zone = client.api("GET", "plan", id=weekly["id"])[1]["zones"][0]["id"]
        self.assertEqual(client.request("PUT", "/api.php?action=hindsight_zone", {"id": weekly_zone, "price_low": 1, "price_high": 2})[0], 404)
        self.assertEqual(client.request("DELETE", f"/api.php?action=hindsight_zone&id={weekly_zone}")[0], 404)
        self.assertEqual(len(client.api("GET", "plan", id=weekly["id"])[1]["zones"]), 1)

        self.assertEqual(client.request("DELETE", f"/api.php?action=hindsight_zone&id={zone_id}")[0], 200)
        notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-07", "to": "2026-09-11"})[1]
        self.assertNotIn(zone_id, [z["id"] for z in notes["zones"]])

    def test_4_news_trades_and_prefs(self):
        client = self.member("olga")
        client.api("POST", "calendar_event", {"event_date": "2026-09-10", "time_label": "14:30", "title": "CPI", "kind": "news", "impact": "high"})
        client.api("POST", "calendar_event", {"event_date": "2026-09-10", "time_label": "16:00", "title": "Nízký dopad", "kind": "news", "impact": "low"})
        client.api("POST", "trade", {"trade_date": "2026-09-10", "market": "ES", "direction": "long", "entry_price": 6600, "exit_price": 6610, "stop_loss": 6595, "risk_amount": 250})
        notes = client.api("GET", "hindsight_annotations", **{"from": "2026-09-07", "to": "2026-09-11"})[1]
        self.assertEqual([(n["title"], n["ts"]) for n in notes["news"]], [("CPI", int(datetime(2026, 9, 10, 12, 30, tzinfo=timezone.utc).timestamp()))])
        day = next(d for d in notes["days"] if d["date"] == "2026-09-10")
        self.assertEqual((day["trades"], day["pnl"]), (1, 500.0))

        status, saved = client.api("POST", "hindsight_prefs", {"layers": {"news": False, "zones": 0, "cizí": True}, "snap": 1})
        self.assertEqual(status, 200, saved)
        prefs = client.api("GET", "hindsight_range")[1]["prefs"]
        self.assertEqual(prefs, saved["prefs"])
        self.assertEqual((prefs["layers"]["news"], prefs["layers"]["zones"], prefs["layers"]["sessions"], prefs["snap"]), (False, False, True, True))
        self.assertNotIn("cizí", prefs["layers"])

    def test_5_page_requires_login(self):
        connection = http.client.HTTPConnection("127.0.0.1", self.server.port, timeout=10)
        connection.request("GET", "/hindsight.php")
        response = connection.getresponse()
        response.read()
        self.assertEqual((response.status, response.getheader("Location")), (302, "./"))
        connection.close()
        status, body, _ = self.admin.request("GET", "/hindsight.php", raw=True)
        self.assertEqual(status, 200)
        self.assertIn(b"hindsight.js", body)
        self.assertIn(b'data-admin="1"', body)
        self.assertNotIn(b"<script>", body, "žádný vložený skript (CSP)")
        status, body, _ = self.member("rita").request("GET", "/hindsight.php", raw=True)
        self.assertEqual(status, 200)
        self.assertIn(b'data-admin="0"', body)
        self.assertNotIn(b"hsImportForm", body, "import vidí jen správce")


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class HindsightTimeTests(unittest.TestCase):
    def test_sessions_and_contracts(self):
        result = subprocess.run(["node", "tests/test_hindsight_time.js"], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)

    def test_contract_periods(self):
        script = (
            "require 'bootstrap.php';"
            "foreach (['ESZ6','ESZ26','ES 12-26','ESZ2026','ESU6','ESH7'] as $n) { $i = hs_contract_info($n); echo $i['contract'], ' ', $i['from_date'], ' ', $i['last_date'], ' ', gmdate('c', $i['from_ts']), PHP_EOL; }"
            "var_dump(hs_contract_info('MESZ6'), hs_contract_info('ESX6'));"
            "echo hs_contract_at(1788991199)['contract'], ' ', hs_contract_at(1788991200)['contract'], PHP_EOL;"
        )
        output = subprocess.run([PHP, "-r", script], cwd=ROOT, capture_output=True, text=True, env={"TRADING_DATA_DIR": "/nonexistent-hindsight"})
        lines = output.stdout.strip().splitlines()
        self.assertEqual(lines[:6], [
            "ESZ6 2026-09-10 2026-12-09 2026-09-09T22:00:00+00:00",
            "ESZ6 2026-09-10 2026-12-09 2026-09-09T22:00:00+00:00",
            "ESZ6 2026-09-10 2026-12-09 2026-09-09T22:00:00+00:00",
            "ESZ6 2026-09-10 2026-12-09 2026-09-09T22:00:00+00:00",
            "ESU6 2026-06-11 2026-09-09 2026-06-10T22:00:00+00:00",
            "ESH7 2026-12-10 2027-03-10 2026-12-09T23:00:00+00:00",
        ], output.stderr)
        self.assertEqual(lines[6:8], ["NULL", "NULL"])
        self.assertEqual(lines[8], "ESU6 ESZ6")


if __name__ == "__main__":
    unittest.main()
