"""cTrader: napojení přes OAuth, synchronizace, import obchodů a Money audit proti simulaci API."""

from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlparse
import io
import json
import sqlite3
import tempfile
import unittest
import urllib.error
import urllib.request
import zipfile

from ctrader_mock import CtraderMock
from test_accounts_http import PHP, Client, Server


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class CtraderTests(unittest.TestCase):
    def setUp(self):
        self.mock = CtraderMock()
        self.server = Server(extra_env=self.mock.env)
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        # Šifrovaný deník (přístupový klíč): klíče k cTraderu musí projít i přes zapečetění.
        status, result = self.client.api("POST", "setup", {"token": token, "login": "trader", "display_name": "Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)

    def tearDown(self):
        self.server.stop()
        self.mock.stop()

    def configure(self, secret="test-secret"):
        return self.client.api("POST", "admin_ctrader", {"client_id": "test-client", "client_secret": secret, "redirect_uri": self.server.base + "/ctrader.php"})

    def connect(self):
        """Celé přihlášení: ctrader.php → přihlášení u cTraderu → návrat → stránka deníku."""
        with self.client.opener.open(urllib.request.Request(self.server.base + "/ctrader.php?start=1"), timeout=60) as response:
            final = urlparse(response.geturl())
            # Stránku dočíst: ukončení serveru uprostřed požadavku by nechalo odemčenou kopii deníku.
            response.read()
        return {key: value[0] for key, value in parse_qs(final.query).items()}

    def state(self):
        status, state = self.client.api("GET", "broker_state")
        self.assertEqual(status, 200, state)
        return state

    def import_from(self):
        return (datetime.now(timezone.utc) - timedelta(days=self.mock.state.import_from_days)).strftime("%Y-%m-%d")

    def trades(self):
        status, result = self.client.api("GET", "trades")
        self.assertEqual(status, 200, result)
        return {trade["market"] + ":" + trade["direction"]: trade for trade in result["items"]}

    def link_new(self):
        broker = self.state()["accounts"][0]
        status, result = self.client.api("POST", "broker_account", {"id": broker["id"], "account_id": "new", "import_from": self.import_from()})
        self.assertEqual(status, 200, result)
        return result

    def test_admin_settings_are_validated_and_secret_stays_on_server(self):
        status, result = self.client.api("POST", "admin_ctrader", {"client_id": "x y", "client_secret": "s", "redirect_uri": "https://example.com/ctrader.php"})
        self.assertEqual(status, 422, result)
        status, result = self.client.api("POST", "admin_ctrader", {"client_id": "test-client", "client_secret": "test-secret", "redirect_uri": "http://example.com/ctrader.php"})
        self.assertEqual(status, 422, result)
        status, result = self.configure()
        self.assertEqual(status, 200, result)
        self.assertTrue(result["configured"])
        self.assertTrue(result["has_secret"])
        self.assertNotIn("test-secret", json.dumps(result))
        # Prázdný Secret při další úpravě ten uložený nechá.
        status, result = self.client.api("POST", "admin_ctrader", {"client_id": "test-client", "client_secret": "", "redirect_uri": self.server.base + "/ctrader.php"})
        self.assertEqual(status, 200, result)
        self.assertTrue(result["has_secret"])

    def test_connect_needs_configuration(self):
        self.assertEqual(self.connect()["ctrader"], "off")
        self.assertFalse(self.state()["configured"])

    def test_full_flow_imports_trades_and_audits_from_broker_balance(self):
        self.configure()
        self.assertEqual(self.connect(), {"ctrader": "connected", "n": "1"})
        state = self.state()
        self.assertNotIn("access-1", json.dumps(state))
        self.assertNotIn("refresh-1", json.dumps(state))
        broker = state["accounts"][0]
        self.assertEqual((broker["external_id"], broker["login"], broker["broker_name"], broker["is_live"]), ("1001", "5550001", "Demo Broker", False))
        self.assertIsNone(broker["account_id"])

        result = self.link_new()
        self.assertEqual(result["imported"], 3)
        broker = result["state"]["accounts"][0]
        self.assertEqual(broker["currency"], "USD")
        self.assertAlmostEqual(broker["balance"], 9714.5)
        self.assertAlmostEqual(broker["equity"], 9724.5)
        self.assertAlmostEqual(broker["cash_flow"], -500)
        self.assertAlmostEqual(broker["open_realized"], 4.5)
        self.assertEqual(len(broker["positions"]), 1)
        position = broker["positions"][0]
        self.assertEqual((position["symbol"], position["direction"], position["volume"], position["unit"]), ("US500", "short", 0.5, "lot"))
        self.assertAlmostEqual(position["pnl"], 10.0)

        status, accounts = self.client.api("GET", "accounts")
        account = next(item for item in accounts["items"] if item["id"] == broker["account_id"])
        self.assertAlmostEqual(account["starting_balance"], 10000.0, msg="zůstatek na začátku importu")
        self.assertAlmostEqual(account["expected_balance"], 9714.5, msg="deník + vklady/výběry + rozpracovaná pozice = cTrader")
        self.assertEqual(account["broker_link"]["login"], "5550001")
        self.assertEqual(account["broker"], "Demo Broker", "název brokera účtu zůstává")
        self.assertLessEqual(account["opened_at"], self.import_from())

        trades = self.trades()
        self.assertEqual(set(trades), {"US500:long", "EURUSD:short"})
        status, items = self.client.api("GET", "trades")
        self.assertEqual(len(items["items"]), 3)
        a = next(t for t in items["items"] if t["market"] == "US500" and abs(t["entry_price"] - 5000) < 1e-9)
        self.assertEqual((a["direction"], a["quantity"], a["exit_price"], a["stop_loss"], a["target_price"]), ("long", 2, 5010, 4995, 5020))
        self.assertAlmostEqual(a["result_usd"], 18.0, msg="přesně podle rozdílu zůstatků, ne podle komise v detailu")
        self.assertAlmostEqual(a["fees"], 2.0)
        self.assertAlmostEqual(a["risk_amount"], 10.0)
        self.assertAlmostEqual(a["result_r"], 1.8)
        self.assertEqual(a["account_id"], broker["account_id"])
        self.assertIn("cTraderu", a["notes"])
        b = trades["EURUSD:short"]
        self.assertEqual((b["quantity"], b["entry_price"], b["exit_price"], b["stop_loss"]), (1, 1.1, 1.0985, 1.102))
        self.assertAlmostEqual(b["result_usd"], 143.0)
        self.assertAlmostEqual(b["fees"], 7.0)
        self.assertAlmostEqual(b["risk_amount"], 200.0)
        self.assertAlmostEqual(b["result_r"], 0.72, delta=0.011)
        e = next(t for t in items["items"] if abs(t["entry_price"] - 4900) < 1e-9)
        self.assertAlmostEqual(e["result_usd"], 49.0)
        self.assertIsNone(e["risk_amount"], "bez stop lossu se R nepočítá")
        self.assertIsNone(e["result_r"])
        self.assertLess(e["trade_date"], self.import_from(), "pozice otevřená před importem má datum otevření")
        self.assertEqual(self.mock.state.count(2179), 1, "chybějící otevření se dohledá jen u pozice E")
        status, detail = self.client.api("GET", "trade", id=e["id"])
        self.assertRegex(detail["entry_time"], r"^\d\d:\d\d$")

        # Money audit podle zůstatku z cTraderu sedí na korunu.
        status, result = self.client.api("POST", "broker_audit", {"id": broker["id"]})
        self.assertEqual(status, 201, result)
        audit = result["audit"]
        self.assertEqual((audit["status"], audit["source"]), ("ok", "ctrader"))
        self.assertAlmostEqual(audit["reported_balance"], 9714.5)
        self.assertAlmostEqual(audit["difference"], 0.0)
        self.assertIn("vklady a výběry", audit["verdict"])

        # Další synchronizace nic nezdvojí; smazaný obchod se nevrátí.
        status, result = self.client.api("POST", "broker_sync", {})
        self.assertEqual(status, 200, result)
        self.assertEqual((result["imported"], result["errors"]), (0, []))
        status, _ = self.client.api("DELETE", "trade", id=a["id"])
        self.assertEqual(status, 200)
        status, result = self.client.api("POST", "broker_sync", {"id": broker["id"]})
        self.assertEqual(result["imported"], 0)
        self.assertEqual(len(self.client.api("GET", "trades")[1]["items"]), 2)

        # ZIP záloha je odemčená, přístupové klíče do ní nepatří.
        status, payload, _ = self.client.request("GET", "/backup.php", raw=True)
        self.assertEqual(status, 200)
        with zipfile.ZipFile(io.BytesIO(payload)) as archive, tempfile.NamedTemporaryFile(suffix=".sqlite3") as copy:
            copy.write(archive.read("trading.sqlite3"))
            copy.flush()
            rows = sqlite3.connect(copy.name).execute("SELECT access_token, refresh_token FROM broker_connections").fetchall()
        self.assertEqual(rows, [("", "")])

        # Na disku je deník zapečetěný, klíč k cTraderu v něm není čitelný.
        for path in self.server.data.glob("users/*/*"):
            if path.is_file():
                self.assertNotIn(b"refresh-1", path.read_bytes(), path.name)

    def test_return_without_own_start_is_rejected(self):
        self.configure()
        with self.client.opener.open(urllib.request.Request(self.server.base + "/ctrader.php?code=good-code"), timeout=30) as response:
            response.read()
            self.assertEqual(parse_qs(urlparse(response.geturl()).query)["ctrader"], ["state"])
        # Vlastní start, ale vrácený state nesedí.
        cookies = next(h for h in self.client.opener.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), cookies, NoRedirect)
        try:
            opener.open(self.server.base + "/ctrader.php?start=1", timeout=30)
        except urllib.error.HTTPError as redirect:
            location = redirect.headers["Location"]
        self.assertIn("scope=accounts", location)
        with self.client.opener.open(urllib.request.Request(self.server.base + "/ctrader.php?code=good-code&state=cizi"), timeout=30) as response:
            response.read()
            self.assertEqual(parse_qs(urlparse(response.geturl()).query)["ctrader"], ["state"])
        self.assertEqual(self.state()["accounts"], [])
        # cTrader, který state vrací: projde.
        self.mock.state.echo_state = True
        self.assertEqual(self.connect()["ctrader"], "connected")

    def test_expiring_access_is_refreshed(self):
        self.configure()
        self.mock.state.expires_in = 100
        self.assertEqual(self.connect()["ctrader"], "connected")
        self.link_new()
        self.assertEqual(self.mock.state.refresh_calls, 1)
        status, result = self.client.api("POST", "broker_sync", {})
        self.assertEqual(result["errors"], [])
        self.assertEqual(self.mock.state.refresh_calls, 1, "obnovený klíč platí 30 dní")

    def test_errors_are_readable(self):
        self.configure(secret="spatny-secret")
        self.assertEqual(self.connect(), {"ctrader": "error", "code": "ACCESS_DENIED"})
        self.configure()
        self.assertEqual(self.connect()["ctrader"], "connected")
        self.link_new()
        self.mock.state.fail[2121] = "CHANNEL_IS_BLOCKED"
        status, result = self.client.api("POST", "broker_sync", {})
        self.assertEqual(status, 200)
        self.assertEqual(len(result["errors"]), 1)
        self.assertIn("zablokoval", result["errors"][0])
        self.assertIn("zablokoval", result["state"]["accounts"][0]["last_error"])
        # Revokovaný přístup: obnova selže, hláška řekne, co dělat.
        del self.mock.state.fail[2121]
        self.mock.state.valid_access.clear()
        self.mock.state.refresh_tokens.clear()
        status, result = self.client.api("POST", "broker_sync", {})
        self.assertIn("Napoj účet znovu", result["errors"][0])

    def test_link_existing_account_and_unlink(self):
        self.configure()
        self.connect()
        status, account = self.client.api("POST", "account", {"name": "Prop účet", "starting_balance": 9714.5, "currency": "USD", "opened_at": datetime.now(timezone.utc).strftime("%Y-%m-%d")})
        self.assertEqual(status, 201, account)
        broker = self.state()["accounts"][0]
        status, result = self.client.api("POST", "broker_account", {"id": broker["id"], "account_id": account["id"]})
        self.assertEqual(status, 200, result)
        self.assertEqual(result["state"]["accounts"][0]["account_name"], "Prop účet")
        self.assertEqual(result["state"]["accounts"][0]["import_from"], datetime.now(timezone.utc).strftime("%Y-%m-%d"))
        status, result = self.client.api("POST", "broker_account", {"id": broker["id"], "account_id": account["id"], "import_from": "2001-01-01"})
        self.assertEqual(status, 422, result)
        status, result = self.client.api("POST", "broker_account", {"id": broker["id"], "account_id": None})
        self.assertIsNone(result["state"]["accounts"][0]["account_id"])
        status, state = self.client.api("DELETE", "broker_account", id=broker["id"])
        self.assertEqual((status, state["accounts"]), (200, []))


if __name__ == "__main__":
    unittest.main()
