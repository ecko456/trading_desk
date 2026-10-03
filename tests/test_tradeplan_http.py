"""Obchodní plán: uložení s čištěním vstupu, vzorový návrh, verze a PDF."""

from pathlib import Path
import io
import tempfile
import unittest

from PIL import Image

from test_accounts_http import PHP, Client, Server
from test_audit_http import upload_body

try:
    from pypdf import PdfReader
except Exception:  # pypdf je volitelné; bez něj se text PDF neověřuje.
    PdfReader = None

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class TradePlanTests(unittest.TestCase):
    def setUp(self):
        self.server = Server()
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        # Šifrovaný deník: plán nesmí být na disku čitelný.
        status, result = self.client.api("POST", "setup", {"token": token, "login": "planovac", "display_name": "Jan Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)
        status, self.prop = self.client.api("POST", "account", {"name": "FTMO 100k", "broker": "FTMO", "starting_balance": 100000, "currency": "USD", "daily_risk": 1000})
        self.assertEqual(status, 201, self.prop)
        status, self.own = self.client.api("POST", "account", {"name": "Vlastní", "starting_balance": 20000, "currency": "EUR"})
        status, self.trend = self.client.api("POST", "strategy", {"name": "P do trendu", "timeframe": "M5", "style": "trend"})
        status, self.reversal = self.client.api("POST", "strategy", {"name": "Reversal F5/F7", "style": "reversal"})

    def tearDown(self):
        self.server.stop()

    def save(self, data, **extra):
        status, result = self.client.api("POST", "trading_plan", {"title": "Plán", "status": "active", "valid_from": "2026-10-01", "data": data, **extra})
        self.assertEqual(status, 200, result)
        return result

    def test_empty_state_has_options(self):
        status, state = self.client.api("GET", "trading_plan")
        self.assertEqual(status, 200, state)
        self.assertIsNone(state["plan"])
        self.assertEqual(state["versions"], [])
        self.assertIn("vah_val", state["options"]["zone_sources"])
        self.assertEqual(state["options"]["days"]["1"], "Po")

    def test_template_uses_journal_accounts_and_strategies(self):
        status, result = self.client.api("GET", "trading_plan_template")
        self.assertEqual(status, 200, result)
        data = result["data"]
        accounts = {item["account_id"]: item for item in data["accounts"]}
        self.assertEqual(accounts[self.prop["id"]]["role"], "eval", "FTMO je prop účet")
        self.assertEqual(accounts[self.prop["id"]]["max_daily_loss"], 1000)
        self.assertEqual(accounts[self.prop["id"]]["risk_per_trade"], 500)
        self.assertEqual(accounts[self.own["id"]]["role"], "personal")
        strategies = {item["strategy_id"]: item for item in data["strategies"]}
        self.assertEqual(strategies[self.trend["id"]]["context"], "trend")
        self.assertEqual(strategies[self.reversal["id"]]["bias_rule"], "any")
        self.assertTrue(any(window["kind"] == "trade" for window in data["windows"]))
        self.assertEqual(data["commitment"]["signature"], "Jan Trader")
        # Návrh jde rovnou uložit a projde čištěním beze ztrát.
        saved = self.save(data)["plan"]["data"]
        self.assertEqual(len(saved["strategies"]), 2)
        self.assertEqual(saved["zones"]["sources"], data["zones"]["sources"])
        self.assertEqual(saved["bias"]["process"], data["bias"]["process"])

    def test_input_is_cleaned(self):
        data = {
            "style": "scalping",
            "hacker": "<script>",
            "mission": "x" * 5000,
            "markets": ["es", "nq", "ES", "bad symbol", "<b>", 12],
            "windows": [
                {"name": "NY", "from": "15:30", "to": "17:30", "days": [1, 2, 9, "3"], "kind": "trade"},
                {"name": "Špatný čas", "from": "25:00", "to": "9:5", "days": [], "kind": "party"},
                {"name": "", "from": "", "to": ""},
            ],
            "accounts": [
                {"account_id": self.prop["id"], "role": "funded", "risk_per_trade": "450,5", "max_daily_loss": -5, "max_trades_day": "3", "drawdown_type": "nope"},
                {"account_id": 99999, "role": "eval"},
                {"account_id": self.prop["id"], "role": "eval"},
            ],
            "strategies": [
                {"strategy_id": self.trend["id"], "context": "trend", "accounts": [self.prop["id"], self.own["id"]], "min_rr": "2.5", "max_attempts": 500, "skip": "Red news"},
                {"strategy_id": 424242},
            ],
            "risk": {"daily_stop_r": "2", "weekly_stop_r": "abc", "sizing": "<b>vzorec</b>"},
            "bias": {"timeframes": ["weekly", "monthly", "hodinový"], "process": "a\r\nb"},
            "zones": {"sources": ["poc", "vah_val", "x"], "max_width": "8"},
            "review": {"next_on": "2026-02-30"},
            "commitment": {"signed_on": "2026-10-03", "signature": "Jan" * 50},
        }
        plan = self.save(data)["plan"]
        clean = plan["data"]
        self.assertNotIn("hacker", clean)
        self.assertEqual(clean["style"], "intraday")
        self.assertEqual(len(clean["mission"]), 3000)
        self.assertEqual(clean["markets"], ["ES", "NQ"])
        self.assertEqual(len(clean["windows"]), 2)
        self.assertEqual(clean["windows"][0]["days"], [1, 2, 3])
        self.assertEqual((clean["windows"][1]["from"], clean["windows"][1]["to"], clean["windows"][1]["kind"]), ("", "", "trade"))
        self.assertEqual(len(clean["accounts"]), 1, "neexistující a zdvojený účet se zahodí")
        account = clean["accounts"][0]
        self.assertEqual((account["role"], account["risk_per_trade"], account["max_daily_loss"], account["max_trades_day"], account["drawdown_type"]), ("funded", 450.5, None, 3, "static"))
        self.assertEqual(len(clean["strategies"]), 1)
        strategy = clean["strategies"][0]
        self.assertEqual(strategy["accounts"], [self.prop["id"]], "strategie smí jen na účty v plánu")
        self.assertEqual((strategy["min_rr"], strategy["max_attempts"], strategy["bias_rule"]), (2.5, None, "with"))
        self.assertEqual((clean["risk"]["daily_stop_r"], clean["risk"]["weekly_stop_r"], clean["risk"]["sizing"]), (2, None, "<b>vzorec</b>"))
        self.assertEqual(clean["bias"]["timeframes"], ["monthly", "weekly"])
        self.assertEqual(clean["bias"]["process"], "a\nb")
        self.assertEqual(clean["zones"]["sources"], ["vah_val", "poc"])
        self.assertIsNone(clean["review"]["next_on"])
        self.assertEqual(len(clean["commitment"]["signature"]), 80)
        self.assertEqual(plan["status"], "active")

    def test_versions_keep_history(self):
        first = self.save({"mission": "Verze jedna"})["plan"]
        self.assertEqual(first["version"], 1)
        status, state = self.client.api("POST", "trading_plan_version", {"id": first["id"]})
        self.assertEqual(status, 201, state)
        second = state["plan"]
        self.assertEqual((second["version"], second["status"], second["data"]["mission"]), (2, "draft", "Verze jedna"))
        self.assertEqual([(item["version"], item["status"]) for item in state["versions"]], [(2, "draft"), (1, "archived")])
        # Archivovaná verze se nemění, aktuální ano.
        status, result = self.client.api("POST", "trading_plan", {"id": first["id"], "data": {"mission": "přepis"}})
        self.assertEqual(status, 409, result)
        self.save({"mission": "Verze dvě"}, id=second["id"])
        status, state = self.client.api("GET", "trading_plan")
        self.assertEqual(state["plan"]["data"]["mission"], "Verze dvě")
        # Smazat jde jen archiv.
        self.assertEqual(self.client.api("DELETE", "trading_plan", id=second["id"])[0], 409)
        status, state = self.client.api("DELETE", "trading_plan", id=first["id"])
        self.assertEqual((status, [item["version"] for item in state["versions"]]), (200, [2]))

    def test_pdf_contains_plan(self):
        chart = io.BytesIO()
        Image.new("RGB", (800, 450), "#101820").save(chart, "PNG")
        status, result = self.client.request("POST", "/api.php?action=upload", upload_body({"strategy_id": self.trend["id"], "role": "strategy"}, content=chart.getvalue()))
        self.assertEqual(status, 201, result)
        template = self.client.api("GET", "trading_plan_template")[1]["data"]
        template["mission"] = "Obchoduji <b>jen</b> plán & nic jiného"
        plan = self.save(template, title="Plán na rok 2027")["plan"]
        status, pdf, content_type = self.client.request("GET", f"/pdf.php?trading_plan={plan['id']}", raw=True)
        self.assertEqual(status, 200, pdf[:300])
        self.assertEqual(content_type, "application/pdf")
        self.assertGreater(len(pdf), 20000)
        self.assertEqual(self.client.request("GET", "/pdf.php?trading_plan=999", raw=True)[0], 404)
        if PdfReader is None:
            return
        with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
            handle.write(pdf)
            handle.flush()
            text = " ".join(" ".join(page.extract_text() or "" for page in PdfReader(handle.name).pages).split())
        for part in ("Plán na rok 2027", "PLÁN V KOSTCE", "FTMO 100k", "P do trendu", "Reversal F5/F7", "Jak stavím zóny", "Obchoduji <b>jen</b> plán & nic jiného", "Jan Trader"):
            self.assertIn(part, text)

    def test_plan_is_sealed_on_disk(self):
        self.save({"mission": "TAJNA-MISE-123"})
        # JSON export deníku plán obsahuje, na disku ale čitelný není.
        status, export = self.client.api("GET", "export")
        self.assertEqual(status, 200, export)
        self.assertIn("TAJNA-MISE-123", export["trading_plans"][0]["data"])
        for path in self.server.data.rglob("*"):
            if path.is_file():
                self.assertNotIn(b"TAJNA-MISE-123", path.read_bytes(), path.name)

    def test_module_can_be_hidden(self):
        status, result = self.client.api("GET", "workspace")
        self.assertIn("tradeplan", [item["key"] for item in result["registry"]["modules"]])


class TradePlanDeployTests(unittest.TestCase):
    def test_pdf_files_are_deployed_but_not_public(self):
        for name in ("pdf_kit.py", "export_trading_plan.py", "lib/fonts/Manrope-Regular.ttf", "lib/fonts/Fraunces-SemiBold.ttf", "lib/fonts/OFL-Manrope.txt"):
            self.assertTrue((ROOT / name).is_file(), name)
        conf = (ROOT / "deploy" / "apache-trading.conf").read_text()
        self.assertIn("|py|", conf)
        self.assertIn(r"(\.[^/]*|data|lib|bin|tests|deploy)(/|$)", conf)


if __name__ == "__main__":
    unittest.main()
