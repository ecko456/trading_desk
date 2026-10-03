"""Strategie: SVG obrázky (vyčištění, kam smí, jak se vydávají) a náhledový obrázek v seznamu."""

from pathlib import Path
import unittest
import urllib.request

from test_accounts_http import PHP, PNG, Client, Server
from test_audit_http import upload_body

ROOT = Path(__file__).resolve().parents[1]

CLEAN = (
    b'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60">'
    b'<defs><linearGradient id="g"><stop offset="0" stop-color="#00AC7C"/></linearGradient></defs>'
    b'<rect width="120" height="60" fill="url(#g)"/><text x="6" y="30">F5 retest</text></svg>'
)

EVIL = (
    b'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
    b'<script>alert(2)</script>'
    b'<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://example.com"/></foreignObject>'
    b'<a href="javascript:alert(3)"><rect width="5" height="5"/></a>'
    b'<image href="https://example.com/sled.png" width="5" height="5"/>'
    b'<use xlink:href="https://example.com/x.svg#a"/>'
    b'<rect width="9" height="9" style="fill:url(https://example.com/x)" onclick="alert(4)"/>'
    b'<style>@import url(https://example.com/a.css);</style>'
    b'<circle r="4" fill="red"/></svg>'
)

ILLUSTRATOR = (
    b'<?xml version="1.0" encoding="utf-8"?>\n'
    b'<!-- Generator: Adobe Illustrator 27.0.0, SVG Export Plug-In -->\n'
    b'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">\n'
    b'<svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'
)

ENTITIES = (
    b'<?xml version="1.0"?>\n<!DOCTYPE svg [ <!ENTITY a "aaaaaaaaaa"> <!ENTITY b "&a;&a;&a;&a;"> ]>\n'
    b'<svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>'
)

# Dlouhý úvodní komentář: fileinfo pak soubor pozná jen jako text/xml.
LONG_COMMENT = b'<?xml version="1.0"?>\n<!-- ' + b"x" * 6000 + b' -->\n<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class StrategySvgTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = Server()
        cls.client = Client(cls.server)
        cls.client.api("GET", "auth_state")
        token = (cls.server.data / "setup-token.txt").read_text().strip()
        status, result = cls.client.api("POST", "setup", {"token": token, "login": "svg", "display_name": "SVG", "password": "svg-heslo-2026"})
        assert status == 201, result

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def strategy(self, name):
        status, strategy = self.client.api("POST", "strategy", {"name": name, "notes": "Popis setupu"})
        self.assertEqual(status, 200, strategy)
        return strategy

    def upload(self, fields, content, filename=b"schema.svg"):
        return self.client.request("POST", "/api.php?action=upload", upload_body(fields, filename=filename, content=content))

    def fetch(self, shot_id):
        request = urllib.request.Request(self.server.base + "/file.php?id=" + shot_id)
        with self.client.opener.open(request, timeout=30) as response:
            return response.headers, response.read()

    def uploads_on_disk(self):
        return len(list((self.server.data / "users").glob("*/uploads/*")))

    def test_clean_svg_is_stored_and_served_safely(self):
        strategy = self.strategy("Reversal F5")
        status, result = self.upload({"strategy_id": strategy["id"], "role": "strategy"}, CLEAN)
        self.assertEqual(status, 201, result)
        headers, body = self.fetch(result["id"])
        self.assertEqual(headers["Content-Type"], "image/svg+xml")
        self.assertIn("sandbox", headers["Content-Security-Policy"])
        self.assertIn("default-src 'none'", headers["Content-Security-Policy"])
        self.assertEqual(headers["X-Content-Type-Options"], "nosniff")
        for part in (b"<linearGradient", b'fill="url(#g)"', b"F5 retest"):
            self.assertIn(part, body)

    def test_scripts_and_external_links_are_removed(self):
        strategy = self.strategy("Škodlivé SVG")
        status, result = self.upload({"strategy_id": strategy["id"], "role": "strategy"}, EVIL)
        self.assertEqual(status, 201, result)
        _, body = self.fetch(result["id"])
        lowered = body.lower()
        for part in (b"<script", b"alert", b"onload", b"onclick", b"foreignobject", b"iframe", b"javascript:", b"example.com", b"@import"):
            self.assertNotIn(part, lowered)
        self.assertIn(b"<circle", body)

    def test_svg_only_for_strategies(self):
        status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-01", "market": "ES", "direction": "long"})
        self.assertEqual(status, 200, trade)
        status, plan = self.client.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-09-02", "market": "ES", "session": "Intraday"})
        self.assertEqual(status, 200, plan)
        strategy = self.strategy("Jen strategie")
        before = self.uploads_on_disk()
        for fields in ({"trade_id": trade["id"]}, {"plan_id": plan["id"]}, {"trade_id": trade["id"], "strategy_id": strategy["id"]}):
            status, result = self.upload(fields, CLEAN)
            self.assertEqual(status, 422, result)
            self.assertIn("jen jako obrázek strategie", result["error"])
        self.assertEqual(self.uploads_on_disk(), before, "odmítnuté SVG nezůstane na disku")

    def test_entities_are_rejected_but_plain_doctype_passes(self):
        strategy = self.strategy("Exporty z editorů")
        status, result = self.upload({"strategy_id": strategy["id"]}, ENTITIES)
        self.assertEqual(status, 422, result)
        self.assertIn("entitami", result["error"])
        status, result = self.upload({"strategy_id": strategy["id"]}, ILLUSTRATOR)
        self.assertEqual(status, 201, result)
        _, body = self.fetch(result["id"])
        self.assertNotIn(b"DOCTYPE", body)
        self.assertIn(b"<rect", body)
        status, result = self.upload({"strategy_id": strategy["id"]}, LONG_COMMENT)
        self.assertEqual(status, 201, result)

    def test_text_that_is_not_svg_is_rejected(self):
        strategy = self.strategy("Text místo obrázku")
        for filename, content in ((b"schema.svg", b"<html><body>ahoj</body></html>"), (b"schema.svg", b"obycejny text"), (b"graf.png", LONG_COMMENT)):
            status, result = self.upload({"strategy_id": strategy["id"]}, content, filename=filename)
            self.assertEqual(status, 422, (filename, result))

    def test_strategy_list_has_cover_image(self):
        strategy = self.strategy("S náhledem")
        empty = self.strategy("Bez obrázku")
        status, first = self.upload({"strategy_id": strategy["id"], "role": "strategy"}, CLEAN)
        self.assertEqual(status, 201, first)
        status, second = self.client.request("POST", "/api.php?action=upload", upload_body({"strategy_id": strategy["id"], "role": "strategy"}))
        self.assertEqual(status, 201, second)
        status, listing = self.client.api("GET", "strategies")
        self.assertEqual(status, 200, listing)
        items = {item["id"]: item for item in listing["items"]}
        self.assertEqual(items[strategy["id"]]["cover_id"], first["id"], "náhled je první nahraný obrázek")
        self.assertIsNone(items[empty["id"]]["cover_id"])
        status, _ = self.client.api("DELETE", "upload", id=first["id"])
        self.assertEqual(status, 200)
        status, listing = self.client.api("GET", "strategies")
        items = {item["id"]: item for item in listing["items"]}
        self.assertEqual(items[strategy["id"]]["cover_id"], second["id"])

    def test_png_still_works_for_trades(self):
        status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-03", "market": "ES", "direction": "short"})
        self.assertEqual(status, 200, trade)
        status, result = self.client.request("POST", "/api.php?action=upload", upload_body({"trade_id": trade["id"]}))
        self.assertEqual(status, 201, result)


class SvgDeployTests(unittest.TestCase):
    def test_installer_adds_php_xml(self):
        # Čištění SVG potřebuje DOM z balíku php-xml; na čistém Ubuntu s mod_php není.
        install = (ROOT / "deploy" / "install.sh").read_text()
        self.assertIn(" php-xml ", install)


if __name__ == "__main__":
    unittest.main()
