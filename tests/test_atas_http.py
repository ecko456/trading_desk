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

    def test_only_get_is_allowed(self):
        self.plan(plan_date="2026-09-30", zones=[{"name": "VAL", "price_low": 6664, "price_high": 6671, "direction": "long"}])
        token = self.link()["link"]["token"]
        request = urllib.request.Request(f"{self.server.base}/atas.php?t={token}", data=b"x", method="POST")
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with self.assertRaises(urllib.error.HTTPError) as caught:
            opener.open(request, timeout=10)
        self.assertEqual(caught.exception.code, 405)


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

    def test_feed_is_not_blocked_by_apache(self):
        conf = (ROOT / "deploy" / "apache-trading.conf").read_text()
        for extension in ("cs", "csproj"):
            self.assertNotIn(f"|{extension}|", conf)
        self.assertTrue((ROOT / "atas.php").is_file())


if __name__ == "__main__":
    unittest.main()
