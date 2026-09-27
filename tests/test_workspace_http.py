"""Přizpůsobené prostředí: nastavení, vlastní pole, DiNapoli a hodnota bodu vlastních trhů."""

from pathlib import Path
import json
import subprocess
import unittest
import uuid

from test_accounts_http import PHP, Client, Server

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class WorkspaceHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = Server()
        cls.client = Client(cls.server)
        cls.client.api("GET", "auth_state")
        token = (cls.server.data / "setup-token.txt").read_text().strip()
        status, result = cls.client.api("POST", "setup", {"token": token, "login": "desk", "display_name": "Desk", "secret_mode": "key"})
        assert status == 201, result

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def test_defaults_and_sanitizing(self):
        status, result = self.client.api("GET", "workspace")
        self.assertEqual(status, 200)
        self.assertEqual(result["prefs"]["method"], "both")
        self.assertFalse(result["prefs"]["onboarded"])
        self.assertIn("dn.swings", result["registry"]["presets"]["mp"])
        self.assertIn("profile.shape", result["registry"]["presets"]["dn"])
        status, saved = self.client.api("POST", "workspace", {
            "method": "dn", "onboarded": True,
            "hidden": {"plan": ["profile.shape", "neexistuje", "<script>"], "trade": ["trade.emotions"], "modules": ["psyche", "admin"]},
            "tokens_extra": ["Moje zóna", "Moje zóna", "x" * 40],
            "markets": [{"symbol": "fdax", "point_value": 25, "rth": "03:00"}, {"symbol": "es", "point_value": -1, "rth": "25:00"}],
        })
        self.assertEqual(status, 200)
        prefs = saved["prefs"]
        self.assertEqual(prefs["hidden"]["plan"], ["profile.shape"])
        self.assertEqual(prefs["hidden"]["modules"], ["psyche"])
        self.assertEqual(prefs["tokens_extra"], ["Moje zóna"])
        self.assertEqual(prefs["markets"][0], {"symbol": "FDAX", "point_value": 25.0, "rth": "03:00"})
        self.assertEqual(prefs["markets"][1], {"symbol": "ES", "point_value": None, "rth": "09:30"})
        # Hodnota bodu vlastního trhu se použije pro velikost pozice.
        status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-28", "market": "FDAX", "direction": "long", "entry_price": 100, "exit_price": 110, "stop_loss": 90, "risk_amount": 250})
        self.assertEqual(status, 200, trade)
        self.assertEqual(trade["point_value"], 25.0)
        self.assertEqual(trade["quantity"], 1.0)
        self.assertEqual(trade["result_r"], 1.0)

    def test_custom_fields_validate_and_analyze(self):
        status, result = self.client.api("POST", "custom_field", {"scope": "trade", "label": "Čekal jsem na potvrzení", "kind": "bool", "in_table": True})
        self.assertEqual(status, 200, result)
        confirm = result["field"]["id"]
        status, result = self.client.api("POST", "custom_field", {"scope": "trade", "label": "Kvalita setupu", "kind": "select", "options": "A+\nA\nB"})
        quality = result["field"]["id"]
        self.assertEqual(result["field"]["options"], ["A+", "A", "B"])
        self.assertEqual(self.client.api("POST", "custom_field", {"scope": "trade", "label": "Špatný", "kind": "select", "options": "jen jedna"})[0], 422)
        self.assertEqual(self.client.api("POST", "custom_field", {"scope": "trade", "label": "", "kind": "text"})[0], 422)
        self.assertEqual(self.client.api("POST", "custom_field", {"scope": "nic", "label": "X", "kind": "text"})[0], 422)

        for confirmed, grade, exit_price in ((True, "A+", 110), (True, "A", 105), (False, "B", 95), (False, "neplatná", 90)):
            status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-27", "market": "ES", "direction": "long", "entry_price": 100, "exit_price": exit_price, "stop_loss": 95, "risk_amount": 100, "custom": {str(confirm): confirmed, str(quality): grade, "999": "cizí"}})
            self.assertEqual(status, 200, trade)
        stored = json.loads(trade["custom"])
        self.assertEqual(stored, {str(confirm): False}, "neplatná volba a neznámé pole se neuloží")

        # Archivované pole se z formuláře neposílá, ale hodnota zůstane.
        self.client.api("POST", "custom_field", {"id": confirm, "label": "Čekal jsem na potvrzení", "archived": True})
        status, again = self.client.api("POST", "trade", {"id": trade["id"], "trade_date": "2026-09-27", "market": "ES", "direction": "long", "entry_price": 100, "exit_price": 90, "stop_loss": 95, "risk_amount": 100, "custom": {}})
        self.assertEqual(json.loads(again["custom"]), {str(confirm): False})
        self.client.api("POST", "custom_field", {"id": confirm, "label": "Čekal jsem na potvrzení", "archived": False})

        status, stats = self.client.api("GET", "custom_field_stats")
        self.assertEqual(status, 200)
        by_field = {item["field"]["id"]: item for item in stats["items"]}
        rows = {row["value"]: row for row in by_field[confirm]["rows"]}
        self.assertEqual(rows["Ano"]["trades"], 2)
        self.assertEqual(rows["Ano"]["avg_r"], 1.5)
        self.assertEqual(rows["Ne"]["avg_r"], -1.5)
        self.assertEqual([row["value"] for row in by_field[quality]["rows"]], ["A+", "A", "B"])

        status, moved = self.client.api("POST", "custom_field_move", {"id": quality, "delta": -1})
        self.assertEqual([f["id"] for f in moved["fields"] if f["scope"] == "trade" and not f["archived"]][:2], [quality, confirm])

    def test_plan_dinapoli_and_custom(self):
        status, result = self.client.api("POST", "custom_field", {"scope": "plan", "label": "COT report", "kind": "text"})
        field = result["field"]["id"]
        status, plan = self.client.api("POST", "plan", {
            "plan_type": "daily", "plan_date": "2026-09-29", "market": "ES", "session": "Intraday", "status": "draft",
            "dn_dma_3x3": "above", "dn_dma_25x5": "below", "dn_thrust": "up", "dn_patterns": "Double Repo, Neexistuje, Railroad Tracks",
            "dn_swings": [
                {"label": "Hlavní", "price_a": 6000, "price_b": 6100, "price_c": 6050},
                {"label": "", "price_a": 6020, "price_b": 6090},
            ],
            "custom": {str(field): "Commercials net long"},
        })
        self.assertEqual(status, 200, plan)
        self.assertEqual(plan["dn_patterns"], "Double Repo, Railroad Tracks")
        self.assertEqual(plan["dn_thrust"], "up")
        analysis = plan["dinapoli"]
        kinds = {(level["swing"], level["kind"]): level["price"] for level in analysis["levels"]}
        self.assertAlmostEqual(kinds[("Hlavní", "F3")], 6061.8)
        self.assertAlmostEqual(kinds[("Hlavní", "F5")], 6038.2)
        self.assertAlmostEqual(kinds[("Hlavní", "COP")], 6111.8)
        self.assertAlmostEqual(kinds[("Hlavní", "OP")], 6150)
        self.assertAlmostEqual(kinds[("S2", "F3")], 6063.26)
        self.assertEqual(len(analysis["clusters"]), 1)
        self.assertEqual(analysis["clusters"][0]["types"], ["confluence"])
        self.assertEqual(plan["custom_readable"], [{"label": "COT report", "value": "Commercials net long", "kind": "text"}])

        # Stejný výpočet v prohlížeči (static/dinapoli.js) jako na serveru.
        script = "const d=require('./static/dinapoli.js');process.stdout.write(JSON.stringify(d.analyze(JSON.parse(process.argv[1]),null)))"
        swings = [{"label": s["label"], "price_a": s["price_a"], "price_b": s["price_b"], "price_c": s["price_c"]} for s in plan["dn_swings"]]
        js = json.loads(subprocess.run(["node", "-e", script, json.dumps(swings)], cwd=ROOT, capture_output=True, text=True, check=True).stdout)
        self.assertEqual([(l["kind"], l["price"]) for l in js["levels"]], [(l["kind"], l["price"]) for l in analysis["levels"]])
        self.assertEqual(js["clusters"], analysis["clusters"])
        self.assertAlmostEqual(js["tolerance"], analysis["tolerance"])

        # Agreement: retracement jednoho swingu na cíli expanze druhého.
        status, plan = self.client.api("POST", "plan", {"id": plan["id"], "plan_type": "daily", "plan_date": "2026-09-29", "market": "ES", "session": "Intraday",
                                                        "dn_swings": [{"price_a": 6000, "price_b": 6100, "price_c": 6050}, {"price_a": 6231, "price_b": 6100}], "dn_tolerance": 3})
        clusters = plan["dinapoli"]["clusters"]
        self.assertTrue(any("agreement" in c["types"] for c in clusters), clusters)
        self.assertEqual(plan["dinapoli"]["tolerance"], 3)

    def test_pdf_with_dinapoli_and_custom_fields(self):
        probe = subprocess.run(["python3", "-c", "import reportlab, PIL"], capture_output=True)
        if probe.returncode != 0:
            self.skipTest("chybí reportlab nebo pillow")
        field = self.client.api("POST", "custom_field", {"scope": "plan", "label": "Téma dne " + uuid.uuid4().hex[:4], "kind": "bool"})[1]["field"]["id"]
        status, plan = self.client.api("POST", "plan", {"plan_type": "weekly", "plan_date": "2026-10-05", "market": "ES", "session": "Intraday",
                                                        "dn_dma_3x3": "above", "dn_patterns": "Double Repo", "dn_notes": "Fib node na 6038",
                                                        "dn_swings": [{"label": "W1", "price_a": 6000, "price_b": 6100, "price_c": 6050}, {"price_a": 6020, "price_b": 6090}],
                                                        "custom": {str(field): True}})
        self.assertEqual(status, 200, plan)
        status, pdf, content_type = self.client.request("GET", f"/pdf.php?id={plan['id']}", raw=True)
        self.assertEqual(status, 200, pdf[:300])
        self.assertTrue(pdf.startswith(b"%PDF"))

    def test_strategy_save_still_works(self):
        status, strategy = self.client.api("POST", "strategy", {"name": "Test " + uuid.uuid4().hex[:6], "timeframe": "M5"})
        self.assertEqual(status, 200, strategy)

    def test_share_includes_dinapoli_only_as_snapshot(self):
        status, plan = self.client.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-09-30", "market": "NQ", "session": "Intraday",
                                                        "dn_swings": [{"price_a": 100, "price_b": 200, "price_c": 150}]})
        status, post = self.client.api("POST", "share", {"kind": "plan", "id": plan["id"], "options": {}})
        self.assertEqual(status, 201, post)
        self.assertEqual(len(post["snapshot"]["dinapoli"]["levels"]), 5)
        self.assertNotIn("custom_readable", post["snapshot"])


if __name__ == "__main__":
    unittest.main()
