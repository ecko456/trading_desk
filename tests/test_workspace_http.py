"""Přizpůsobené prostředí: nastavení, vlastní pole, DiNapoli a hodnota bodu vlastních trhů."""

from pathlib import Path
import json
import sqlite3
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
        levels = [
            {"timeframe": "H4", "kind": "F5", "status": "naked", "price": 6038, "note": "Swing z pondělí"},
            {"timeframe": "H4", "kind": "F5", "status": "revisited", "price": 6034},      # konfluence s prvním (4 b)
            {"timeframe": "H4", "kind": "F3", "status": "naked", "price": 6036},           # F3 konfluenci nedělá
            {"timeframe": "H1", "kind": "F5", "status": "naked", "price": 6037},           # jiný timeframe se neporovnává
            {"timeframe": "H4", "kind": "COP", "status": "naked", "price": 6120},
            {"timeframe": "H4", "kind": "F3", "status": "naked", "price": 6126},           # shoda s COP (6 b > 5), jen s větší tolerancí
            {"timeframe": "H4", "kind": "F7", "status": "naked", "price": ""},             # bez ceny se nepočítá
            {"timeframe": "H4", "kind": "XX", "status": "naked", "price": 6000},           # neznámý typ se neuloží
        ]
        status, plan = self.client.api("POST", "plan", {
            "plan_type": "daily", "plan_date": "2026-09-29", "market": "ES", "session": "Intraday", "status": "draft",
            "dn_dma_3x3": "above", "dn_dma_25x5": "below", "dn_thrust": "up", "dn_patterns": "Double Repo, Neexistuje, Railroad Tracks",
            "dn_levels": levels,
            "custom": {str(field): "Commercials net long"},
        })
        self.assertEqual(status, 200, plan)
        self.assertEqual(plan["dn_patterns"], "Double Repo, Railroad Tracks")
        self.assertEqual(plan["dn_thrust"], "up")
        self.assertEqual([level["kind"] for level in plan["dn_levels"]], ["F5", "F5", "F3", "F5", "COP", "F3"], "prázdný F7 a neznámý typ se neuloží")
        self.assertEqual(plan["dn_levels"][1]["status"], "revisited")
        analysis = plan["dinapoli"]
        self.assertEqual(len(analysis["levels"]), 6)
        self.assertEqual([(c["type"], c["timeframe"], c["low"], c["high"]) for c in analysis["clusters"]], [("confluence", "H4", 6034, 6038)])
        cluster = analysis["clusters"][0]
        self.assertTrue(cluster["revisited"])
        self.assertEqual(cluster["tolerance"], 5)
        self.assertEqual([m["status"] for m in cluster["members"]], ["revisited", "naked"])
        self.assertEqual(plan["custom_readable"], [{"label": "COT report", "value": "Commercials net long", "kind": "text"}])

        # Tolerance shody pro H4 zvýšená na 6 bodů: COP 6120 a F3 6126 se kryjí.
        prefs = self.client.api("GET", "workspace")[1]["prefs"]
        timeframes = [dict(row, agreement=6) if row["tf"] == "H4" else row for row in prefs["dn"]["timeframes"]]
        status, saved = self.client.api("POST", "workspace", {**prefs, "dn": {"timeframes": timeframes}})
        self.assertEqual(status, 200, saved)
        status, plan = self.client.api("GET", "plan", id=plan["id"])
        kinds = {(c["type"], c["low"], c["high"]) for c in plan["dinapoli"]["clusters"]}
        self.assertIn(("agreement", 6120, 6126), kinds)
        # F5 6034 a F3 6036 nejsou expanze, takže shodu netvoří ani s větší tolerancí.
        self.assertNotIn("agreement", {c["type"] for c in plan["dinapoli"]["clusters"] if c["low"] < 6100})

        # Stejný výpočet v prohlížeči (static/dinapoli.js) jako na serveru.
        script = "const d=require('./static/dinapoli.js');const a=JSON.parse(process.argv[1]);process.stdout.write(JSON.stringify(d.analyze(a.levels,a.timeframes)))"
        payload = json.dumps({"levels": plan["dn_levels"], "timeframes": timeframes})
        js = json.loads(subprocess.run(["node", "-e", script, payload], cwd=ROOT, capture_output=True, text=True, check=True).stdout)
        self.assertEqual(js["levels"], plan["dinapoli"]["levels"])
        self.assertEqual(js["clusters"], plan["dinapoli"]["clusters"])

        # Starší stránka bez dn_levels uložené levely nesmaže.
        status, again = self.client.api("POST", "plan", {"id": plan["id"], "plan_type": "daily", "plan_date": "2026-09-29", "market": "ES", "session": "Intraday"})
        self.assertEqual(len(again["dn_levels"]), 6)

    def test_confluence_chains_and_timeframe_settings(self):
        levels = [{"timeframe": "M15", "kind": "F5", "price": price} for price in (100, 104, 108, 120)]
        levels.append({"timeframe": "M15", "kind": "OP", "price": 110})
        status, plan = self.client.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-10-01", "market": "NQ", "session": "Intraday", "dn_levels": levels})
        self.assertEqual(status, 200, plan)
        clusters = plan["dinapoli"]["clusters"]
        # 100, 104 a 108 se řetězí přes sousedy do jednoho pásma; 120 je daleko.
        self.assertEqual([(c["type"], c["low"], c["high"], len(c["members"])) for c in clusters],
                         [("agreement", 108, 110, 2), ("confluence", 100, 108, 3)])
        # Neplatné hodnoty v nastavení dostanou výchozích 5 bodů, duplicitní timeframe se zahodí.
        prefs = self.client.api("GET", "workspace")[1]["prefs"]
        status, saved = self.client.api("POST", "workspace", {**prefs, "dn": {"timeframes": [
            {"tf": "M15", "confluence": -3, "agreement": "abc"}, {"tf": "m15", "confluence": 1, "agreement": 1}, {"tf": "<b>", "confluence": 1, "agreement": 1}, {"tf": "", "confluence": 1}]}})
        self.assertEqual(saved["prefs"]["dn"]["timeframes"], [{"tf": "M15", "confluence": 5.0, "agreement": 5.0}, {"tf": "b", "confluence": 1.0, "agreement": 1.0}])
        status, saved = self.client.api("POST", "workspace", {**prefs, "dn": {"timeframes": []}})
        self.assertEqual([row["tf"] for row in saved["prefs"]["dn"]["timeframes"]], ["M5", "M15", "M30", "H1", "H4", "D1", "W1", "MN", "Q"])
        # Seznam uložený starší verzí dostane měsíční a čtvrtletní timeframe; smazaný v nové verzi se už nevrací.
        status, saved = self.client.api("POST", "workspace", {**prefs, "version": 1, "dn": {"timeframes": [{"tf": "H4", "confluence": 3, "agreement": 4}, {"tf": "mn", "confluence": 20, "agreement": 20}]}})
        self.assertEqual([(row["tf"], row["confluence"]) for row in saved["prefs"]["dn"]["timeframes"]], [("H4", 3.0), ("mn", 20.0), ("Q", 5.0)])
        self.assertEqual(saved["prefs"]["version"], 2)
        status, saved = self.client.api("POST", "workspace", {**saved["prefs"], "dn": {"timeframes": [{"tf": "H4", "confluence": 3, "agreement": 4}]}})
        self.assertEqual([row["tf"] for row in saved["prefs"]["dn"]["timeframes"]], ["H4"])
        self.client.api("POST", "workspace", prefs)

    def test_pdf_with_dinapoli_and_custom_fields(self):
        probe = subprocess.run(["python3", "-c", "import reportlab, PIL"], capture_output=True)
        if probe.returncode != 0:
            self.skipTest("chybí reportlab nebo pillow")
        field = self.client.api("POST", "custom_field", {"scope": "plan", "label": "Téma dne " + uuid.uuid4().hex[:4], "kind": "bool"})[1]["field"]["id"]
        status, plan = self.client.api("POST", "plan", {"plan_type": "weekly", "plan_date": "2026-10-05", "market": "ES", "session": "Intraday",
                                                        "dn_dma_3x3": "above", "dn_patterns": "Double Repo", "dn_notes": "Fib node na 6038 <b>&",
                                                        "dn_levels": [{"timeframe": "D1", "kind": "F5", "price": 6038, "note": "Týdenní <swing>"}, {"timeframe": "D1", "kind": "F5", "status": "revisited", "price": 6040},
                                                                      {"timeframe": "D1", "kind": "XOP", "price": 6041}],
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
                                                        "dn_levels": [{"timeframe": "H1", "kind": "F5", "price": 100}, {"timeframe": "H1", "kind": "COP", "price": 103}]})
        status, post = self.client.api("POST", "share", {"kind": "plan", "id": plan["id"], "options": {}})
        self.assertEqual(status, 201, post)
        self.assertEqual(len(post["snapshot"]["dinapoli"]["levels"]), 2)
        self.assertEqual(post["snapshot"]["dinapoli"]["clusters"][0]["type"], "agreement")
        self.assertEqual(post["snapshot"]["dn_levels"][1]["kind"], "COP")
        self.assertNotIn("custom_readable", post["snapshot"])



@unittest.skipIf(PHP is None, "PHP není nainstalované")
class DinapoliMigrationTests(unittest.TestCase):
    """Swingy z předchozí verze se po aktualizaci převedou na levely."""

    def setUp(self):
        self.server = Server()
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        self.client.api("POST", "setup", {"token": token, "login": "starsi", "display_name": "Starší", "password": "starsi-heslo-1"})

    def tearDown(self):
        self.server.stop()

    def test_old_swings_become_levels(self):
        status, plan = self.client.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-09-28", "market": "ES", "session": "Intraday"})
        self.assertEqual(status, 200, plan)
        journal = next((self.server.data / "users").glob("*/trading.sqlite3"))
        with sqlite3.connect(journal) as db:
            db.executescript("""
                DROP TABLE plan_dn_levels;
                CREATE TABLE plan_dn_swings (id INTEGER PRIMARY KEY, plan_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
                    label TEXT, price_a REAL, price_b REAL, price_c REAL, note TEXT);
            """)
            db.execute("INSERT INTO plan_dn_swings (plan_id, sort_order, label, price_a, price_b, price_c) VALUES (?, 0, 'Hlavní', 6000, 6100, 6050)", (plan["id"],))
            db.execute("INSERT INTO plan_dn_swings (plan_id, sort_order, label, price_a, price_b) VALUES (?, 1, '', 6231, 6100)", (plan["id"],))
        status, plan = self.client.api("GET", "plan", id=plan["id"])
        self.assertEqual(status, 200, plan)
        migrated = [(level["kind"], round(level["price"], 2)) for level in plan["dn_levels"]]
        self.assertEqual(migrated, [("F3", 6061.8), ("F5", 6038.2), ("COP", 6111.8), ("OP", 6150.0), ("XOP", 6211.8), ("F3", 6150.04), ("F5", 6180.96)])
        self.assertIn("Hlavní", plan["dn_levels"][0]["note"])
        self.assertEqual({level["timeframe"] for level in plan["dn_levels"]}, {""})
        # Druhé otevření už nic nezdvojí.
        status, again = self.client.api("GET", "plan", id=plan["id"])
        self.assertEqual(len(again["dn_levels"]), 7)


if __name__ == "__main__":
    unittest.main()
