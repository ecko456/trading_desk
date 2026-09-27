"""Úplné průchody přes HTTP proti skutečnému PHP serveru.

Ověřují účty, schvalování, oddělení deníků, šifrování na disku, nástěnku,
správu a ochrany. Bez PHP se přeskočí.
"""

from http.cookiejar import CookieJar
from pathlib import Path
import io
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
import uuid
import zipfile


ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
PNG = bytes.fromhex(
    "89504e470d0a1a0a0000000d4948445200000001000000010806000000"
    "1f15c4890000000d49444154789c6360f8cfc0f01f0005000201"
    "e2213fbc0000000049454e44ae426082"
)


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class Server:
    def __init__(self, extra_env=None):
        self.data = Path(tempfile.mkdtemp(prefix="td-http-"))
        self.port = free_port()
        self.base = f"http://127.0.0.1:{self.port}"
        env = dict(os.environ, TRADING_DATA_DIR=str(self.data), TRADING_REGISTER_PER_HOUR="1000", NO_PROXY="*")
        env.update(extra_env or {})
        self.process = subprocess.Popen(
            [PHP, "-S", f"127.0.0.1:{self.port}", "router.php"],
            cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=0.2):
                    return
            except OSError:
                time.sleep(0.05)
        raise RuntimeError("PHP server did not start")

    def stop(self):
        self.process.terminate()
        self.process.wait(timeout=5)
        shutil.rmtree(self.data, ignore_errors=True)


class Client:
    def __init__(self, server: Server):
        self.server = server
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(CookieJar()),
        )

    def request(self, method, path, body=None, headers=None, raw=False):
        data = None
        all_headers = {"Sec-Fetch-Site": "same-origin"}
        if isinstance(body, (dict, list)):
            data = json.dumps(body).encode()
            all_headers["Content-Type"] = "application/json"
        elif isinstance(body, tuple):
            data, content_type = body
            all_headers["Content-Type"] = content_type
        all_headers.update(headers or {})
        request = urllib.request.Request(self.server.base + path, data=data, method=method, headers=all_headers)
        try:
            with self.opener.open(request, timeout=30) as response:
                payload = response.read()
                status = response.status
                content_type = response.headers.get("Content-Type", "")
        except urllib.error.HTTPError as error:
            payload = error.read()
            status = error.code
            content_type = error.headers.get("Content-Type", "")
        if raw:
            return status, payload, content_type
        return status, (json.loads(payload) if "json" in content_type and payload else payload)

    def api(self, method, action, body=None, **query):
        path = "/api.php?action=" + action + "".join(f"&{key}={value}" for key, value in query.items())
        return self.request(method, path, body)


def multipart(fields, files):
    boundary = uuid.uuid4().hex
    out = io.BytesIO()
    for name, value in fields.items():
        out.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
    for name, filename, content in files:
        out.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"; filename=\"{filename}\"\r\nContent-Type: image/png\r\n\r\n".encode())
        out.write(content + b"\r\n")
    out.write(f"--{boundary}--\r\n".encode())
    return out.getvalue(), f"multipart/form-data; boundary={boundary}"


def make_legacy_journal(path: Path, marker: str):
    script = (
        "require 'lib/vault.php'; require 'lib/accounts.php';"
        "function utc_now(){return gmdate('Y-m-d\\TH:i:s\\Z');}"
        "$src = file_get_contents('bootstrap.php');"
        "preg_match('/function initialize_schema.*?\\n}\\n/s', $src, $m); eval(substr($m[0], 0));"
        "$pdo = new PDO('sqlite:' . $argv[1]); initialize_schema($pdo);"
        "$pdo->prepare(\"INSERT INTO trades (trade_date, market, direction, notes, created_at, updated_at) VALUES ('2026-09-01', 'ES', 'long', ?, 'x', 'x')\")->execute([$argv[2]]);"
    )
    subprocess.run([PHP, "-r", script, str(path), marker], cwd=ROOT, check=True, capture_output=True)


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class AccountsHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = Server()
        cls.legacy_marker = "LEGACY-" + uuid.uuid4().hex
        make_legacy_journal(cls.server.data / "trading.sqlite3", cls.legacy_marker)
        cls.admin = Client(cls.server)
        status, state = cls.admin.api("GET", "auth_state")
        assert status == 200 and state["setup_required"], state
        token = (cls.server.data / "setup-token.txt").read_text().strip()
        status, result = cls.admin.api("POST", "setup", {
            "token": token, "login": "boss", "display_name": "Boss", "secret_mode": "password", "password": "boss-password-123",
        })
        assert status == 201, result
        cls.adopted = result["adopted"]

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def register_and_approve(self, login, mode="password", password="member-password-1"):
        client = Client(self.server)
        body = {"login": login, "display_name": login.title(), "secret_mode": mode}
        if mode == "password":
            body["password"] = password
        status, result = client.api("POST", "register", body)
        self.assertEqual(status, 201, result)
        secret = result["access_key"] if mode == "key" else password
        status, _ = client.api("POST", "login", {"login": login, "secret": secret})
        self.assertEqual(status, 403, "čekající registrace se nesmí přihlásit")
        status, _ = self.admin.api("POST", "admin_user", {"id": result["user"]["id"], "op": "approve"})
        self.assertEqual(status, 200)
        status, me = client.api("POST", "login", {"login": login, "secret": secret})
        self.assertEqual(status, 200, me)
        return client, secret, result["user"]["id"]

    def save_trade(self, client, notes):
        status, trade = client.api("POST", "trade", {
            "trade_date": "2026-09-28", "market": "ES", "session": "Intraday", "direction": "long",
            "entry_price": 6355, "exit_price": 6365, "stop_loss": 6350, "risk_amount": 200, "fees": 5, "notes": notes,
        })
        self.assertEqual(status, 200, trade)
        return trade

    def test_setup_adopts_existing_journal(self):
        self.assertTrue(self.adopted)
        self.assertFalse((self.server.data / "trading.sqlite3").exists())
        self.assertFalse((self.server.data / "setup-token.txt").exists())
        status, trades = self.admin.api("GET", "trades")
        self.assertEqual(status, 200)
        self.assertIn(self.legacy_marker, [trade["notes"] for trade in trades["items"]])
        status, _ = Client(self.server).api("POST", "setup", {"token": "X", "login": "x2", "display_name": "X", "password": "abcdefghijk"})
        self.assertEqual(status, 409)

    def test_journal_requires_login(self):
        anonymous = Client(self.server)
        self.assertEqual(anonymous.api("GET", "trades")[0], 401)
        self.assertEqual(anonymous.request("GET", "/file.php?id=abc")[0], 401)
        self.assertEqual(anonymous.request("GET", "/backup.php")[0], 401)
        self.assertEqual(anonymous.request("GET", "/lib/accounts.php")[0], 404)

    def test_journals_are_isolated(self):
        alice, _, _ = self.register_and_approve("alice")
        bob, _, _ = self.register_and_approve("bob")
        marker = "alice-private-" + uuid.uuid4().hex
        trade = self.save_trade(alice, marker)
        status, upload = alice.request("POST", "/api.php?action=upload", multipart({"trade_id": trade["id"]}, [("file", "a.png", PNG)]))
        self.assertEqual(status, 201, upload)
        self.assertEqual(alice.request("GET", "/" + upload["url"], raw=True)[1], PNG)
        status, bob_trades = bob.api("GET", "trades")
        self.assertEqual(status, 200)
        self.assertNotIn(marker, json.dumps(bob_trades))
        self.assertEqual(bob.api("GET", "trade", id=trade["id"])[0], 404)
        self.assertEqual(bob.request("GET", "/" + upload["url"])[0], 404)

    def test_encrypted_journal_is_sealed_on_disk(self):
        carol, key, carol_id = self.register_and_approve("carol", mode="key")
        self.assertRegex(key, r"^([0-9A-Z]{4}-){7}[0-9A-Z]{4}$")
        marker = "carol-secret-" + uuid.uuid4().hex
        trade = self.save_trade(carol, marker)
        status, upload = carol.request("POST", "/api.php?action=upload", multipart({"trade_id": trade["id"]}, [("file", "c.png", PNG)]))
        self.assertEqual(status, 201, upload)

        directory = self.server.data / "users" / str(carol_id)
        self.assertTrue((directory / "trading.sqlite3.sealed").is_file())
        self.assertFalse((directory / "trading.sqlite3").exists())
        for file in directory.rglob("*"):
            if file.is_file():
                content = file.read_bytes()
                self.assertNotIn(marker.encode(), content, file)
                self.assertNotIn(b"SQLite format 3", content, file)
                self.assertNotIn(PNG, content, file)

        self.assertEqual(carol.request("GET", "/" + upload["url"], raw=True)[1], PNG)
        status, trades = carol.api("GET", "trades")
        self.assertIn(marker, json.dumps(trades))

        # Klíč jde zadat malými písmeny a s mezerami.
        again = Client(self.server)
        self.assertEqual(again.api("POST", "login", {"login": "carol", "secret": key.lower().replace("-", " ")})[0], 200)
        self.assertEqual(Client(self.server).api("POST", "login", {"login": "carol", "secret": key[:-1] + ("0" if key[-1] != "0" else "1")})[0], 401)

        status, raw, _ = carol.request("GET", "/backup.php", raw=True)
        self.assertEqual(status, 200)
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            database = archive.read("trading.sqlite3")
            self.assertIn(b"SQLite format 3", database)
            self.assertEqual(len([name for name in archive.namelist() if name.startswith("uploads/")]), 1)

        # Odemčené kopie nesmí po požadavcích zůstat v dočasném adresáři.
        leftovers = [p for base in (Path("/dev/shm"), Path(tempfile.gettempdir())) for p in base.glob(f"trading-desk-{os.geteuid()}/*.sqlite3")]
        self.assertEqual(leftovers, [])

    def test_enable_encryption_and_rotate_key(self):
        dave, password, dave_id = self.register_and_approve("dave")
        marker = "dave-" + uuid.uuid4().hex
        self.save_trade(dave, marker)
        self.assertEqual(dave.api("POST", "encryption", {"current": "wrong-password"})[0], 403)
        status, result = dave.api("POST", "encryption", {"current": password})
        self.assertEqual(status, 200, result)
        key = result["access_key"]
        self.assertTrue(result["user"]["encrypted"])
        self.assertIn(marker, json.dumps(dave.api("GET", "trades")[1]))
        self.assertFalse((self.server.data / "users" / str(dave_id) / "trading.sqlite3").exists())
        self.assertEqual(Client(self.server).api("POST", "login", {"login": "dave", "secret": password})[0], 401)

        status, rotated = dave.api("POST", "access_key", {"current": key})
        self.assertEqual(status, 200, rotated)
        self.assertEqual(Client(self.server).api("POST", "login", {"login": "dave", "secret": key})[0], 401)
        fresh = Client(self.server)
        self.assertEqual(fresh.api("POST", "login", {"login": "dave", "secret": rotated["access_key"]})[0], 200)
        self.assertIn(marker, json.dumps(fresh.api("GET", "trades")[1]))

    def test_wall_sharing_comments_and_moderation(self):
        erin, _, _ = self.register_and_approve("erin", mode="key")
        frank, _, _ = self.register_and_approve("frank")
        trade = self.save_trade(erin, "erin-private-note")
        status, upload = erin.request("POST", "/api.php?action=upload", multipart({"trade_id": trade["id"]}, [("file", "e.png", PNG)]))
        self.assertEqual(status, 201)

        status, post = erin.api("POST", "share", {"kind": "trade", "id": trade["id"], "options": {"note": "Čistý vstup z VAL", "charts": True}})
        self.assertEqual(status, 201, post)
        self.assertNotIn("notes", post["snapshot"])
        self.assertNotIn("result_usd", post["snapshot"])
        self.assertEqual(post["snapshot"]["result_r"], trade["result_r"])
        self.assertEqual(len(post["media"]), 1)
        self.assertEqual(frank.request("GET", "/" + post["media"][0]["url"], raw=True)[1], PNG)

        status, feed = frank.api("GET", "wall")
        self.assertIn(post["id"], [item["id"] for item in feed["items"]])
        status, commented = frank.api("POST", "comment", {"post_id": post["id"], "body": "Pěkné!"})
        self.assertEqual(status, 201)
        self.assertEqual(commented["comment_count"], 1)
        status, reacted = frank.api("POST", "react", {"post_id": post["id"], "kind": "fire"})
        self.assertEqual(reacted["reactions"]["counts"], {"fire": 1})
        self.assertEqual(reacted["reactions"]["mine"], "fire")

        # Znovu sdílet = aktualizovat, komentáře zůstanou.
        status, updated = erin.api("POST", "share", {"kind": "trade", "id": trade["id"], "options": {"money": True, "charts": False}})
        self.assertEqual(updated["id"], post["id"])
        self.assertIn("result_usd", updated["snapshot"])
        self.assertEqual(updated["comment_count"], 1)
        self.assertEqual(updated["media"], [])

        self.assertEqual(frank.api("DELETE", "wall_post", id=post["id"])[0], 403)
        comment_id = updated["comments"][0]["id"]
        self.assertEqual(erin.api("DELETE", "comment", id=comment_id)[0], 200, "autor příspěvku smí mazat komentáře pod ním")

        status, note = frank.request("POST", "/api.php?action=wall_post", multipart({"body": "Dnes RTH open drive"}, [("images[]", "n.png", PNG)]))
        self.assertEqual(status, 201, note)
        self.assertEqual(note["kind"], "note")
        self.assertEqual(self.admin.api("DELETE", "wall_post", id=note["id"])[0], 200, "správce moderuje")
        self.assertEqual(erin.api("DELETE", "share", kind="trade", id=trade["id"])[0], 200)
        self.assertNotIn(post["id"], [item["id"] for item in frank.api("GET", "wall")[1]["items"]])

    def test_admin_permissions_and_safeguards(self):
        gina, _, gina_id = self.register_and_approve("gina")
        self.assertEqual(gina.api("GET", "admin_users")[0], 403)
        self.assertEqual(gina.api("POST", "admin_user", {"id": gina_id, "op": "promote"})[0], 403)
        status, users = self.admin.api("GET", "admin_users")
        self.assertEqual(status, 200)
        self.assertNotIn("secret_hash", json.dumps(users))
        boss_id = next(user["id"] for user in users["items"] if user["login"] == "boss")
        self.assertEqual(self.admin.api("POST", "admin_user", {"id": boss_id, "op": "demote"})[0], 422)
        self.assertEqual(self.admin.api("POST", "admin_user", {"id": boss_id, "op": "block"})[0], 422)

        status, reset = self.admin.api("POST", "admin_user", {"id": gina_id, "op": "reset_password"})
        self.assertEqual(status, 200)
        self.assertEqual(gina.api("GET", "me")[0], 401, "reset ukončí relace")
        fresh = Client(self.server)
        status, me = fresh.api("POST", "login", {"login": "gina", "secret": reset["temporary_password"]})
        self.assertTrue(me["user"]["must_change_secret"])

        self.assertEqual(self.admin.api("POST", "admin_user", {"id": gina_id, "op": "block"})[0], 200)
        self.assertEqual(fresh.api("GET", "me")[0], 401)
        self.assertEqual(self.admin.api("POST", "admin_user", {"id": gina_id, "op": "delete"})[0], 200)
        self.assertFalse((self.server.data / "users" / str(gina_id)).exists())

        _, key, hank_id = self.register_and_approve("hank", mode="key")
        self.assertEqual(self.admin.api("POST", "admin_user", {"id": hank_id, "op": "reset_password"})[0], 422)

        self.assertEqual(self.admin.api("POST", "admin_settings", {"registration_open": False})[1]["registration_open"], False)
        self.assertEqual(Client(self.server).api("POST", "register", {"login": "late", "display_name": "Late", "password": "late-password-1"})[0], 403)
        self.admin.api("POST", "admin_settings", {"registration_open": True})

    def test_pdf_export_works_for_encrypted_journal(self):
        probe = subprocess.run(["python3", "-c", "import reportlab, PIL"], capture_output=True)
        if probe.returncode != 0:
            self.skipTest("chybí reportlab nebo pillow")
        jane, _, _ = self.register_and_approve("jane", mode="key")
        status, plan = jane.api("POST", "plan", {"plan_type": "daily", "plan_date": "2026-09-28", "market": "NQ", "session": "Intraday", "status": "draft",
                                                "zones": [{"name": "A", "direction": "long", "price_low": 100, "price_high": 110, "long_entry": "reakce"}]})
        self.assertEqual(status, 200, plan)
        status, _ = jane.request("POST", "/api.php?action=upload", multipart({"plan_id": plan["id"]}, [("file", "p.png", PNG)]))
        self.assertEqual(status, 201)
        status, pdf, content_type = jane.request("GET", f"/pdf.php?id={plan['id']}", raw=True)
        self.assertEqual(status, 200, pdf[:300])
        self.assertEqual(content_type, "application/pdf")
        self.assertTrue(pdf.startswith(b"%PDF"))

    def test_cross_site_writes_are_rejected(self):
        status, _ = self.admin.request("POST", "/api.php?action=trade", {"trade_date": "2026-09-28", "market": "ES", "direction": "long"}, headers={"Sec-Fetch-Site": "cross-site"})
        self.assertEqual(status, 403)

    def test_logout_ends_session(self):
        ivan, _, _ = self.register_and_approve("ivan")
        self.assertEqual(ivan.api("GET", "me")[0], 200)
        self.assertEqual(ivan.api("POST", "logout")[0], 200)
        self.assertEqual(ivan.api("GET", "me")[0], 401)


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class LoginThrottleTests(unittest.TestCase):
    def test_repeated_failures_are_throttled(self):
        server = Server()
        try:
            client = Client(server)
            client.api("GET", "auth_state")
            statuses = [client.api("POST", "login", {"login": "nobody", "secret": "wrong"})[0] for _ in range(10)]
            self.assertEqual(statuses[:8], [401] * 8)
            self.assertEqual(statuses[-1], 429)
        finally:
            server.stop()


if __name__ == "__main__":
    unittest.main()
