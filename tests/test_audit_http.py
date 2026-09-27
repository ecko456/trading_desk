"""Regrese z bezpečnostního auditu: nahrávání, JSON, hádání hesla, hlavičky a čísla."""

from pathlib import Path
import io
import json
import unittest
import uuid

from test_accounts_http import PHP, PNG, Client, Server

ROOT = Path(__file__).resolve().parents[1]


def upload_body(fields, filename=b"graf.png", content=PNG):
    boundary = uuid.uuid4().hex
    out = io.BytesIO()
    for name, value in fields.items():
        out.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
    out.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"".encode() + filename + b"\"\r\nContent-Type: image/png\r\n\r\n" + content + b"\r\n")
    out.write(f"--{boundary}--\r\n".encode())
    return out.getvalue(), f"multipart/form-data; boundary={boundary}"


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class AuditHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = Server()
        cls.client = Client(cls.server)
        cls.client.api("GET", "auth_state")
        token = (cls.server.data / "setup-token.txt").read_text().strip()
        status, result = cls.client.api("POST", "setup", {"token": token, "login": "audit", "display_name": "Audit", "password": "audit-heslo-2026"})
        assert status == 201, result

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def trade(self, **extra):
        status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-01", "market": "ES", "direction": "long", **extra})
        self.assertEqual(status, 200, trade)
        return trade

    def test_broken_filename_does_not_break_json(self):
        trade = self.trade()
        status, result = self.client.request("POST", "/api.php?action=upload", upload_body({"trade_id": trade["id"], "role": "after<script>", "caption": "x" * 900}, filename=b"graf\xff\xfe.png"))
        self.assertEqual(status, 201, result)
        status, detail = self.client.api("GET", "trade", id=trade["id"])
        self.assertEqual(status, 200)
        shot = detail["screenshots"][0]
        self.assertTrue(shot["original_name"].startswith("graf"))
        self.assertEqual(shot["role"], "afterscript")
        self.assertEqual(len(shot["caption"]), 300)
        status, export = self.client.api("GET", "export")
        self.assertEqual(status, 200)
        self.assertIsInstance(export, dict)

    def test_upload_to_missing_item_is_a_clear_error(self):
        before = len(list((self.server.data / "users").glob("*/uploads/*")))
        status, result = self.client.request("POST", "/api.php?action=upload", upload_body({"plan_id": 987654}))
        self.assertEqual(status, 422, result)
        self.assertIn("neexistuje", result["error"])
        self.assertEqual(len(list((self.server.data / "users").glob("*/uploads/*"))), before, "odmítnutý soubor nezůstane na disku")

    def test_non_finite_numbers_are_not_stored(self):
        trade = self.trade(entry_price="1e400", exit_price=10, stop_loss=5, risk_amount=100)
        self.assertIsNone(trade["entry_price"])
        self.assertIsNone(trade["result_r"])
        status, items = self.client.api("GET", "trades")
        self.assertEqual(status, 200)
        self.assertIsInstance(items, dict)

    def test_account_currency_is_a_code(self):
        status, account = self.client.api("POST", "account", {"name": "Měna " + uuid.uuid4().hex[:4], "starting_balance": 1000, "currency": "<img>"})
        self.assertEqual(status, 201, account)
        self.assertEqual(account["currency"], "USD")
        status, account = self.client.api("POST", "account", {"name": "Měna " + uuid.uuid4().hex[:4], "starting_balance": 1000, "currency": "eur"})
        self.assertEqual(account["currency"], "EUR")

    def test_hsts_only_over_https(self):
        status, _, _ = self.client.request("GET", "/api.php?action=health", raw=True)
        self.assertEqual(status, 200)
        import urllib.request
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with opener.open(urllib.request.Request(self.server.base + "/api.php?action=health")) as response:
            self.assertIsNone(response.headers.get("Strict-Transport-Security"))
        with opener.open(urllib.request.Request(self.server.base + "/api.php?action=health", headers={"X-Forwarded-Proto": "https"})) as response:
            self.assertIn("max-age=", response.headers.get("Strict-Transport-Security", ""))

    def test_dinapoli_swings_are_capped(self):
        swings = [{"price_a": 100 + i, "price_b": 200 + i} for i in range(60)]
        status, plan = self.client.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-09-04", "market": "ES", "session": "Intraday", "dn_swings": swings})
        self.assertEqual(status, 200, plan)
        self.assertEqual(len(plan["dn_swings"]), 40)


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class SecretGuessingTests(unittest.TestCase):
    """Změna hesla ověřuje současné heslo; hádat ho musí jít stejně pomalu jako při přihlášení."""

    def setUp(self):
        self.server = Server()
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        self.client.api("POST", "setup", {"token": token, "login": "hadac", "display_name": "Hádač", "password": "spravne-heslo-1"})

    def tearDown(self):
        self.server.stop()

    def test_password_change_is_throttled(self):
        codes = [self.client.api("POST", "password", {"current": f"spatne-{i}", "next": "nove-heslo-12345"})[0] for i in range(10)]
        self.assertEqual(codes[:8], [403] * 8)
        self.assertEqual(codes[8:], [429, 429])
        # Ani správné heslo teď neprojde; brzda platí pro účet jako celek.
        self.assertEqual(self.client.api("POST", "password", {"current": "spravne-heslo-1", "next": "nove-heslo-12345"})[0], 429)


class DeployConfigTests(unittest.TestCase):
    def test_web_root_hides_internal_files(self):
        conf = (ROOT / "deploy" / "apache-trading.conf").read_text()
        self.assertIn(r"(\.[^/]*|data|lib|bin|tests|deploy)(/|$)", conf)
        self.assertIn("md|txt|zip|py", conf)
        self.assertIn("php_value upload_max_filesize 20M", conf)
        install = (ROOT / "deploy" / "install.sh").read_text()
        for pattern in ("--exclude '/.git/'", "--exclude '*.zip'", "--exclude '/README.md'", 'rm -rf "${TARGET_DIR}/.git"'):
            self.assertIn(pattern, install)

    def test_builtin_server_hides_the_same_files(self):
        router = (ROOT / "router.php").read_text()
        self.assertIn("md|txt|zip|py|sh|bat|command|ini|json|sqlite3", router)

    def test_upload_limits_match_everywhere(self):
        self.assertIn("post_max_size = 64M", (ROOT / ".user.ini").read_text())
        for script in (ROOT / "start-macos.command", ROOT / ".devcontainer" / "start.sh"):
            self.assertIn("-d post_max_size=64M", script.read_text())


if __name__ == "__main__":
    unittest.main()
