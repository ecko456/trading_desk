"""Odkaz pro ATAS: snímek zón denního náhledu přes tajný token, obnova při uložení, zrušení."""

from pathlib import Path
import io
import json
import sqlite3
import tempfile
import unittest
import zipfile
import urllib.error
import urllib.request

from test_accounts_http import PHP, Client, Server

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class AtasLinkTests(unittest.TestCase):
    def setUp(self):
        self.server = Server()
        self.admin = Client(self.server)
        self.admin.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        status, result = self.admin.api("POST", "setup", {"token": token, "login": "trader", "display_name": "Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)

    def tearDown(self):
        self.server.stop()

    def feed(self, token, query=""):
        """Čte odkaz bez přihlášení, jako indikátor v ATAS."""
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(f"{self.server.base}/atas.php?t={token}{query}", timeout=10) as response:
                return response.status, response.headers.get("Content-Type"), response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.headers.get("Content-Type"), error.read().decode()

    def zones(self, text):
        return [line.split(";") for line in text.splitlines() if line.startswith("zone;")]

    def plan(self, client=None, **plan):
        status, result = (client or self.admin).api("POST", "plan", {"plan_type": "daily", "market": "ES", "status": "draft", **plan})
        self.assertEqual(status, 200, result)
        return result

    def link(self, client=None, **body):
        status, result = (client or self.admin).api("POST", "atas_link", {"market": "ES", **body})
        self.assertEqual(status, 200, result)
        return result

    def test_link_serves_zones_of_latest_daily_plan(self):
        self.plan(plan_type="weekly", plan_date="2026-09-28", zones=[{"name": "Týdenní VAH", "price_low": 6740, "price_high": 6748, "direction": "short"}])
        self.plan(plan_date="2026-09-29", zones=[
            {"name": "Naked POC", "price_low": 6650, "price_high": 6652.5, "direction": "long", "valid_to": "open"},
            {"name": "Do pátku", "price_low": 6620, "price_high": 6624, "direction": "long", "valid_to": "2026-10-02"},
            {"name": "Jen ten den", "price_low": 6600, "price_high": 6605, "direction": "long"},
        ])
        plan = self.plan(plan_date="2026-09-30", bias="long", zones=[
            {"name": "VAL; týdne\nřádek", "price_low": 6671, "price_high": 6664, "direction": "long", "priority": "A", "long_entry": "Návrat nad 6671; potvrzení"},
            {"name": "Nad VAH", "price_low": 6730, "price_high": 6738, "direction": "both"},
            {"name": "Bez ceny", "direction": "short"},
        ])

        status, state = self.admin.api("GET", "atas_link", market="es")
        self.assertEqual((status, state["link"], state["preview"]), (200, None, {"date": "2026-09-30", "zones": 5}))
        state = self.link(include_weekly=True)
        token = state["link"]["token"]
        self.assertGreaterEqual(len(token), 32)

        status, content_type, text = self.feed(token)
        self.assertEqual((status, content_type), (200, "text/plain; charset=utf-8"))
        self.assertIn("market;ES\n", text)
        self.assertIn("date;2026-09-30\n", text)
        self.assertIn("bias;long\n", text)
        zones = {row[2]: row for row in self.zones(text)}
        self.assertEqual(set(zones), {"VAL týdne řádek", "Nad VAH", "Naked POC", "Do pátku", "Týdenní VAH"}, "středník a konec řádku nerozbijí formát")
        self.assertEqual(zones["VAL týdne řádek"][3:9], ["6664", "6671", "long", "A", "daily", "support"])
        self.assertEqual(zones["VAL týdne řádek"][10], "Návrat nad 6671 potvrzení")
        self.assertEqual(zones["Nad VAH"][5], "both")
        self.assertEqual((zones["Naked POC"][4], zones["Naked POC"][7], zones["Naked POC"][9]), ("6652.5", "carry", "open"))
        self.assertEqual(zones["Týdenní VAH"][7], "weekly")
        self.assertTrue(all(len(row) == 12 for row in self.zones(text)))

        status, content_type, body = self.feed(token, "&format=json")
        self.assertEqual((status, content_type), (200, "application/json; charset=utf-8"))
        snapshot = json.loads(body)
        self.assertEqual((snapshot["market"], snapshot["date"], len(snapshot["zones"])), ("ES", "2026-09-30", 5))

        # Uložení náhledu i změna zóny v Hindsightu snímek obnoví.
        self.plan(id=plan["id"], plan_date="2026-09-30", zones=[{"name": "Nová", "price_low": 6700, "price_high": 6705, "direction": "short"}])
        names = [row[2] for row in self.zones(self.feed(token)[2])]
        self.assertIn("Nová", names)
        self.assertNotIn("Nad VAH", names)
        status, zone = self.admin.api("POST", "hindsight_zone", {"date": "2026-09-30", "price_low": 6680, "price_high": 6684, "direction": "long", "name": "Z Hindsightu"})
        self.assertEqual(status, 200, zone)
        self.assertIn("Z Hindsightu", [row[2] for row in self.zones(self.feed(token)[2])])

        # Nový den náhledu: odkaz se sám přepne.
        self.plan(plan_date="2026-10-01", zones=[{"name": "Zítra", "price_low": 6690, "price_high": 6695, "direction": "long"}])
        text = self.feed(token)[2]
        self.assertIn("date;2026-10-01\n", text)
        self.assertNotIn("Nová", text)

        # Bez týdenních zón.
        self.link(include_weekly=False)
        self.assertNotIn("Týdenní VAH", self.feed(token)[2])

    def test_token_regenerate_and_remove(self):
        self.plan(plan_date="2026-09-30", zones=[{"name": "VAL", "price_low": 6664, "price_high": 6671, "direction": "long"}])
        first = self.link()["link"]["token"]
        self.assertEqual(self.link()["link"]["token"], first, "opakované uložení token nemění")
        self.assertIsNone(self.admin.api("GET", "atas_link", market="ES")[1]["link"]["read_at"])
        self.assertEqual(self.feed(first)[0], 200)
        self.assertIsNotNone(self.admin.api("GET", "atas_link", market="ES")[1]["link"]["read_at"], "vidět, kdy ATAS naposledy četl")

        second = self.link(regenerate=True)["link"]["token"]
        self.assertNotEqual(second, first)
        self.assertEqual(self.feed(first)[0], 404, "starý odkaz po vydání nového neplatí")
        self.assertEqual(self.feed(second)[0], 200)

        status, state = self.admin.api("DELETE", "atas_link", market="ES")
        self.assertEqual((status, state["link"]), (200, None))
        status, _, text = self.feed(second)
        self.assertEqual(status, 404)
        self.assertIn("Odkaz neplatí", text)
        for bad in ("", "abc", "x" * 40, "../../etc/passwd"):
            self.assertEqual(self.feed(bad)[0], 404, bad)
        status, result = self.admin.api("POST", "atas_link", {"market": "<script>"})
        self.assertEqual(status, 422, result)

    def test_link_is_private_and_stops_for_blocked_member(self):
        member = Client(self.server)
        status, result = member.api("POST", "register", {"login": "clen", "display_name": "Člen", "secret_mode": "password", "password": "member-password-1"})
        self.assertEqual(status, 201, result)
        member_id = result["user"]["id"]
        self.admin.api("POST", "admin_user", {"id": member_id, "op": "approve"})
        self.assertEqual(member.api("POST", "login", {"login": "clen", "secret": "member-password-1"})[0], 200)
        self.plan(client=member, plan_date="2026-09-30", zones=[{"name": "Členova", "price_low": 6600, "price_high": 6610, "direction": "short"}])
        self.plan(plan_date="2026-09-30", zones=[{"name": "Správcova", "price_low": 6700, "price_high": 6710, "direction": "long"}])
        member_token = self.link(client=member)["link"]["token"]
        admin_token = self.link()["link"]["token"]
        self.assertEqual([row[2] for row in self.zones(self.feed(member_token)[2])], ["Členova"])
        self.assertEqual([row[2] for row in self.zones(self.feed(admin_token)[2])], ["Správcova"])

        # Token je na disku jen jako otisk (deník správce je navíc šifrovaný).
        for path in self.server.data.rglob("*"):
            if path.is_file():
                self.assertNotIn(admin_token.encode(), path.read_bytes(), path.name)

        self.admin.api("POST", "admin_user", {"id": member_id, "op": "block"})
        self.assertEqual(self.feed(member_token)[0], 404, "zablokovaný účet odkaz nemá")
        self.admin.api("POST", "admin_user", {"id": member_id, "op": "unblock"})
        self.assertEqual(self.feed(member_token)[0], 200)
        self.admin.api("POST", "admin_user", {"id": member_id, "op": "delete"})
        self.assertEqual(self.feed(member_token)[0], 404, "smazaný účet odkaz nemá")

    def test_backup_does_not_carry_token(self):
        self.plan(plan_date="2026-09-30", zones=[{"name": "VAL", "price_low": 6664, "price_high": 6671, "direction": "long"}])
        token = self.link()["link"]["token"]
        status, payload, _ = self.admin.request("GET", "/backup.php", raw=True)
        self.assertEqual(status, 200)
        self.assertNotIn(token.encode(), payload)
        with zipfile.ZipFile(io.BytesIO(payload)) as archive, tempfile.NamedTemporaryFile(suffix=".sqlite3") as copy:
            copy.write(archive.read("trading.sqlite3"))
            copy.flush()
            self.assertEqual(sqlite3.connect(copy.name).execute("SELECT COUNT(*) FROM atas_links").fetchone()[0], 0)

    def test_only_get_and_post_are_allowed(self):
        self.plan(plan_date="2026-09-30", zones=[{"name": "VAL", "price_low": 6664, "price_high": 6671, "direction": "long"}])
        token = self.link()["link"]["token"]
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for method, code in (("PUT", 405), ("DELETE", 405), ("POST", 422)):
            request = urllib.request.Request(f"{self.server.base}/atas.php?t={token}", data=b"x", method=method)
            with self.assertRaises(urllib.error.HTTPError) as caught:
                opener.open(request, timeout=10)
            self.assertEqual(caught.exception.code, code, method)


def sample_draft(**changes):
    """Denní profil RTH pátku 20. 11. 2026 tak, jak ho posílá indikátor (vymyšlená čísla)."""
    draft = {
        "format": "td-atas-draft", "version": 1, "kind": "daily", "symbol": "ESZ6", "market": "ES", "tick": 0.25,
        "profile": "rth", "generated_at": "2026-11-20T21:05:00Z", "source": "ATAS TradingDeskDraft 1.0",
        "period": {"from": "2026-11-20", "to": "2026-11-20"}, "next": "2026-11-23",
        "open": 6012.25, "high": 6048.5, "low": 6001.0, "close": 6040.25, "volume": 1234567,
        "poc": 6031.25, "vah": 6042.0, "val": 6018.5, "vwap": 6027.75, "shape": "p",
        "ib": {"low": 6003.0, "high": 6021.5, "open": 6004.0, "close": 6020.75, "minutes": 60, "avg_range": 14.0},
        "ib_ext": "up",
        "prev": {"high": 6030.0, "low": 5995.0, "poc": 6015.0, "vah": 6025.0, "val": 6005.0},
        "single_prints": [{"low": 6009.0, "high": 6013.5}],
        "tails": {"buying": {"low": 6001.0, "high": 6003.25}, "selling": None},
        "poor_high": True, "poor_low": False,
        "naked_pocs": [{"price": 5990.25, "date": "2026-11-18"}],
        "lvn": [{"price": 6024.5}], "hvn": [{"price": 6031.25}],
        "overnight": None,
    }
    draft.update(changes)
    return draft


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class AtasDraftTests(unittest.TestCase):
    """Nástřel náhledu: indikátor pošle profil přes odkaz pro ATAS, aplikace z něj předvyplní náhled."""

    def setUp(self):
        self.server = Server()
        self.admin = Client(self.server)
        self.admin.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        status, result = self.admin.api("POST", "setup", {"token": token, "login": "trader", "display_name": "Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)
        status, result = self.admin.api("POST", "atas_link", {"market": "ES"})
        self.assertEqual(status, 200, result)
        self.token = result["link"]["token"]

    def tearDown(self):
        self.server.stop()

    def send(self, body, token=None):
        """Pošle nástřel bez přihlášení, jako indikátor v ATAS."""
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        request = urllib.request.Request(f"{self.server.base}/atas.php?t={token or self.token}", data=data, method="POST", headers={"Content-Type": "application/json"})
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(request, timeout=10) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            payload = error.read()
            return error.code, json.loads(payload) if payload else None

    def test_daily_draft_becomes_prefilled_plan(self):
        status, result = self.send(sample_draft())
        self.assertEqual(status, 200, result)
        self.assertEqual((result["ok"], result["market"], result["kind"], result["period"]), (True, "ES", "daily", {"from": "2026-11-20", "to": "2026-11-20"}))

        status, listed = self.admin.api("GET", "atas_drafts")
        self.assertEqual(status, 200, listed)
        self.assertEqual(len(listed["items"]), 1)
        item = listed["items"][0]
        self.assertEqual((item["market"], item["kind"], item["next"], item["symbol"], item["high"], item["poc"]), ("ES", "daily", "2026-11-23", "ESZ6", 6048.5, 6031.25))

        status, opened = self.admin.api("GET", "atas_draft", id=item["id"])
        self.assertEqual(status, 200, opened)
        plan = opened["plan"]
        self.assertIsNone(opened["existing_id"])
        self.assertEqual((plan["plan_type"], plan["plan_date"], plan["market"], plan["status"]), ("daily", "2026-11-23", "ES", "draft"))
        self.assertEqual([plan[k] for k in ("ref_high", "ref_vah", "ref_poc", "ref_val", "ref_low", "ref_close")], [6048.5, 6042.0, 6031.25, 6018.5, 6001.0, 6040.25])
        self.assertEqual({k: plan.get(k) for k in ("profile_shape", "previous_close", "value_area", "vpoc", "auction", "single_print", "tail")}, {
            "profile_shape": "p", "previous_close": "upper", "value_area": "rising", "vpoc": "rising", "auction": "imbalance",
            "single_print": "buy", "tail": "buy",
        })
        # IB minulé seance jen do poznámky, pole Initial Balance patří ke dni náhledu.
        self.assertNotIn("initial_balance", plan)
        self.assertIn("IB 6\u00a0003–6\u00a0021,5 (velká, drive proti průměru 14 b).", plan["general_notes"])
        self.assertEqual([(r["kind"], r["price_low"], r["price_high"]) for r in plan["refs"]], [
            ("single_print", 6009.0, 6013.5), ("poor_high", 6048.5, None), ("excess", 6001.0, 6003.25), ("naked_poc", 5990.25, None), ("lvn", 6024.5, None),
        ])
        self.assertIn("z 18. 11. 2026", plan["refs"][3]["note"])
        self.assertEqual([(l["name"], l["price"], l["kind"]) for l in plan["levels"]], [("PD VWAP", 6027.75, "pivot"), ("IBH", 6021.5, "resistance"), ("IBL", 6003.0, "support"), ("HVN", 6031.25, "pivot")])
        self.assertTrue(plan["general_notes"].startswith("Nástřel z ATAS: denní profil RTH 20. 11. 2026, ESZ6 (O 6\u00a0012,25, H 6\u00a0048,5"), plan["general_notes"])

        # Předvyplněný náhled jde uložit beze změny a druhé otevření ví, že už existuje.
        status, saved = self.admin.api("POST", "plan", plan)
        self.assertEqual(status, 200, saved)
        self.assertEqual(len(saved["refs"]), 5)
        self.assertEqual(len(saved["levels"]), 4)
        status, opened = self.admin.api("GET", "atas_draft", id=item["id"])
        self.assertEqual(opened["existing_id"], saved["id"])

        status, listed = self.admin.api("DELETE", "atas_draft", id=item["id"])
        self.assertEqual((status, listed["items"]), (200, []))
        self.assertEqual(self.admin.api("GET", "atas_draft", id=item["id"])[0], 404)

    def test_weekly_overnight_and_file_upload(self):
        weekly = sample_draft(kind="weekly", period={"from": "2026-11-16", "to": "2026-11-20"}, next=None, ib=None, shape="d",
                              prev={"poc": 6031.0, "vah": 6050.0, "val": 6010.0}, close=6035.0)
        status, result = self.send(weekly)
        self.assertEqual(status, 200, result)
        draft_id = self.admin.api("GET", "atas_drafts")[1]["items"][0]["id"]
        plan = self.admin.api("GET", "atas_draft", id=draft_id)[1]["plan"]
        self.assertEqual((plan["plan_type"], plan["plan_date"], plan["value_area"], plan["vpoc"], plan["auction"]),
                         ("weekly", "2026-11-23", "overlap", "stable", "balance"))
        self.assertNotIn("initial_balance", plan)
        self.assertNotIn("weekly_position", plan, "pole je jen v denním náhledu")
        self.assertEqual(plan["levels"][0]["name"], "PW VWAP")
        self.assertIn("týdenní profil RTH 16. 11. 2026 – 20. 11. 2026", plan["general_notes"])

        # Denní nástřel bez týdenního náhledu polohu vůči týdenní VA nezná; po uložení
        # týdenního náhledu ji spočítá z jeho value (Close 6040,25 je nad VAH 6038).
        status, daily = self.admin.api("POST", "atas_draft_file", {"draft": json.dumps(sample_draft())})
        self.assertNotIn("weekly_position", daily["plan"])
        self.assertEqual(self.admin.api("POST", "plan", {**plan, "ref_vah": 6038.0})[0], 200)
        status, daily = self.admin.api("POST", "atas_draft_file", {"draft": json.dumps(sample_draft())})
        self.assertEqual(daily["plan"]["weekly_position"], "above")

        # Ruční nahrání souboru: nic se neuloží, trh z formuláře má přednost.
        on = sample_draft(overnight={"low": 6035.0, "high": 6052.25, "poc": 6046.0}, market="")
        status, result = self.admin.api("POST", "atas_draft_file", {"draft": json.dumps(on), "market": "nq"})
        self.assertEqual(status, 200, result)
        self.assertEqual(result["plan"]["market"], "NQ")
        self.assertEqual([l["name"] for l in result["plan"]["levels"]][-4:], ["ONH", "ONL", "ON POC", "HVN"])
        self.assertEqual(len(self.admin.api("GET", "atas_drafts")[1]["items"]), 1, "soubor se neukládá")
        status, result = self.admin.api("POST", "atas_draft_file", {"draft": json.dumps(on)})
        self.assertEqual(status, 422, result)
        self.assertIn("chybí trh", result["error"])
        status, result = self.admin.api("POST", "atas_draft_file", {"draft": "{nesmysl"})
        self.assertEqual(status, 422, result)

    def test_bad_drafts_are_rejected_and_old_ones_pruned(self):
        cases = [
            (sample_draft(format="jiny"), 422, "td-atas-draft"),
            (sample_draft(version=2), 422, "jiné verze"),
            (sample_draft(high=None), 422, "High, Low a Close"),
            (sample_draft(kind="monthly"), 422, "typ"),
            (sample_draft(period={"from": "2026-02-30"}), 422, "datum"),
        ]
        for body, code, text in cases:
            status, result = self.send(body)
            self.assertEqual(status, code, body)
            self.assertIn(text, result["error"])
        self.assertEqual(self.send(b"{")[0], 422)
        self.assertEqual(self.send(sample_draft(), token="x" * 40)[0], 404)
        self.assertEqual(self.send(b"{" + b" " * 140000 + b"}")[0], 413)

        # Nebezpečné hodnoty: text bez řídicích znaků, čísla jen konečná, seznamy omezené.
        dirty = sample_draft(symbol="<b>ES</b>\n", source="x\u0000y", single_prints=[{"low": 1, "high": 2}] * 50, shape="hack", lvn=["abc", {"price": "6020,5"}], tails={"buying": {"low": "x"}})
        status, result = self.send(dirty)
        self.assertEqual(status, 200, result)
        stored = sqlite3.connect(self.server.data / "app.sqlite3").execute("SELECT payload FROM atas_drafts ORDER BY id DESC").fetchone()[0]
        clean = json.loads(stored)
        self.assertEqual((clean["symbol"], clean["source"], len(clean["single_prints"]), clean["shape"], clean["lvn"], clean["tails"]["buying"]),
                         ("bES/b", "x y", 20, "", [{"price": 6020.5}], None))

        for day in range(1, 13):
            self.assertEqual(self.send(sample_draft(period={"from": f"2026-10-{day:02d}"}))[0], 200)
        rows = sqlite3.connect(self.server.data / "app.sqlite3").execute("SELECT COUNT(*) FROM atas_drafts WHERE kind = 'daily'").fetchone()[0]
        self.assertEqual(rows, 10, "drží se posledních 10 nástřelů na trh a typ")

    def test_indicator_output_is_accepted(self):
        # Přesně to, co vyrobí TradingDeskProfile.cs (tests/atas, dotnet run --update-fixture).
        sample = json.loads((ROOT / "tests" / "atas" / "draft-sample.json").read_text(encoding="utf-8"))
        status, result = self.send(sample)
        self.assertEqual(status, 200, result)
        draft_id = self.admin.api("GET", "atas_drafts")[1]["items"][0]["id"]
        plan = self.admin.api("GET", "atas_draft", id=draft_id)[1]["plan"]
        self.assertEqual((plan["plan_date"], plan["ref_poc"], plan["ref_vah"], plan["ref_val"], plan["profile_shape"]), ("2026-11-23", 6025.0, 6030.0, 6022.75, "p"))
        self.assertEqual({k: plan.get(k) for k in ("previous_close", "value_area", "vpoc", "single_print", "tail")},
                         {"previous_close": "below", "value_area": "overlap", "vpoc": "stable", "single_print": "buy", "tail": "buy"})
        self.assertIn("IB 6\u00a0000–6\u00a0012 (běžná proti průměru 12 b).", plan["general_notes"])
        self.assertEqual([r["kind"] for r in plan["refs"]], ["single_print", "poor_high", "excess", "naked_poc"])
        self.assertEqual([l["name"] for l in plan["levels"]], ["PD VWAP", "IBH", "IBL"])

    def test_drafts_are_private(self):
        status, _ = self.send(sample_draft())
        self.assertEqual(status, 200)
        draft_id = self.admin.api("GET", "atas_drafts")[1]["items"][0]["id"]
        member = Client(self.server)
        status, result = member.api("POST", "register", {"login": "clen", "display_name": "Člen", "secret_mode": "password", "password": "member-password-1"})
        self.assertEqual(status, 201, result)
        self.admin.api("POST", "admin_user", {"id": result["user"]["id"], "op": "approve"})
        self.assertEqual(member.api("POST", "login", {"login": "clen", "secret": "member-password-1"})[0], 200)
        self.assertEqual(member.api("GET", "atas_drafts")[1]["items"], [])
        self.assertEqual(member.api("GET", "atas_draft", id=draft_id)[0], 404)
        member.api("DELETE", "atas_draft", id=draft_id)
        self.assertEqual(len(self.admin.api("GET", "atas_drafts")[1]["items"]), 1, "cizí člen nástřel nesmaže")
        self.assertEqual(Client(self.server).api("GET", "atas_drafts")[0], 401)


class AtasStaticTests(unittest.TestCase):
    def test_indicator_files_and_dialog(self):
        source = (ROOT / "static" / "atas" / "TradingDeskZones.cs").read_text(encoding="utf-8")
        project = (ROOT / "static" / "atas" / "TradingDeskZones.csproj").read_text(encoding="utf-8")
        self.assertIn("class TradingDeskZones : Indicator", source)
        # Indikátor čte stejné sloupce, jaké posílá server (zone;id;název;od;do;směr;priorita;rozsah).
        self.assertIn('case "zone" when parts.Length >= 8', source)
        self.assertIn("net8.0-windows;net10.0-windows", project)
        self.assertIn("<UseWPF>True</UseWPF>", project)
        html = (ROOT / "index.php").read_text(encoding="utf-8")
        for identifier in ("sharePlanMenu", "atasDialog", "atasUrl", "atasWeekly"):
            self.assertIn(f'id="{identifier}"', html)
        self.assertIn('href="static/atas/TradingDeskZones.cs" download', html)
        draft = (ROOT / "static" / "atas" / "TradingDeskDraft.cs").read_text(encoding="utf-8")
        self.assertIn("class TradingDeskDraft : Indicator", draft)
        self.assertIn('"td-atas-draft"', (ROOT / "static" / "atas" / "TradingDeskProfile.cs").read_text(encoding="utf-8"))
        for name in ("TradingDeskDraft.cs", "TradingDeskProfile.cs"):
            self.assertIn(f'href="static/atas/{name}" download', html)
        # ATAS X odmítne DLL s typy WPF; oba indikátory kreslí jen přes System.Drawing a RenderContext.
        for name in ("TradingDeskZones.cs", "TradingDeskDraft.cs", "TradingDeskProfile.cs"):
            text = (ROOT / "static" / "atas" / name).read_text(encoding="utf-8")
            for forbidden in ("System.Windows.Media", "SolidColorBrush", "System.Windows.Controls", "MessageBox", "Dispatcher"):
                self.assertNotIn(forbidden, text, name)

    def test_feed_is_not_blocked_by_apache(self):
        conf = (ROOT / "deploy" / "apache-trading.conf").read_text()
        for extension in ("cs", "csproj"):
            self.assertNotIn(f"|{extension}|", conf)
        self.assertTrue((ROOT / "atas.php").is_file())


if __name__ == "__main__":
    unittest.main()
