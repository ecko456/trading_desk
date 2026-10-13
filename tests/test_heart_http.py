"""Tep během obchodování: seance, živé vzorky, Zkratka (zapečetěná schránka), import a model po 15 obchodech."""

from datetime import datetime, timedelta
from pathlib import Path
import base64
from zoneinfo import ZoneInfo
import json
import sqlite3
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

from test_accounts_http import PHP, Client, Server, multipart

PRAGUE = ZoneInfo("Europe/Prague")


def day_ago(days=2):
    """Pracovní den před pár dny (pražský čas), ať testy nezávisí na kalendáři."""
    day = datetime.now(PRAGUE).date() - timedelta(days=days)
    while day.weekday() >= 5:
        day -= timedelta(days=1)
    return day


def at(day, hhmm, seconds=0):
    hour, minute = map(int, hhmm.split(":"))
    return int(datetime(day.year, day.month, day.day, hour, minute, tzinfo=PRAGUE).timestamp()) + seconds


@unittest.skipIf(PHP is None, "PHP není nainstalované")
class HeartTests(unittest.TestCase):
    def setUp(self):
        self.server = Server()
        self.client = Client(self.server)
        self.client.api("GET", "auth_state")
        token = (self.server.data / "setup-token.txt").read_text().strip()
        status, result = self.client.api("POST", "setup", {"token": token, "login": "trader", "display_name": "Trader", "secret_mode": "key"})
        self.assertEqual(status, 201, result)

    def tearDown(self):
        self.server.stop()

    def shortcut(self, token, body, content_type="text/plain", method="POST"):
        """Odeslání ze Zkratky: bez přihlášení, bez cookies."""
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        data = body.encode() if isinstance(body, str) else body
        request = urllib.request.Request(f"{self.server.base}/hr.php?t={token}", data=data if method == "POST" else None, method=method, headers={"Content-Type": content_type})
        try:
            with opener.open(request, timeout=10) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode()

    def state(self):
        status, state = self.client.api("GET", "heart_state")
        self.assertEqual(status, 200, state)
        return state

    def trade(self, day, entry, error, result_r=1.0, labeled=True, exit_minutes=6):
        body = {"trade_date": day.isoformat(), "market": "ES", "direction": "long", "entry_time": entry, "result_r": result_r,
                "exit_time": (datetime.strptime(entry, "%H:%M") + timedelta(minutes=exit_minutes)).strftime("%H:%M")}
        if labeled:
            body.update({"followed_plan": 0 if error else 1, "execution_rating": 5 if error else 1})
        status, result = self.client.api("POST", "trade", body)
        self.assertIn(status, (200, 201), result)
        return result

    def test_shortcut_link_seals_samples_until_journal_opens_them(self):
        state = self.state()
        self.assertEqual((state["link"]["link"], state["active"], state["model"]["ready"]), (None, None, False))
        self.assertTrue(state["link"]["available"])

        status, link = self.client.api("POST", "heart_link")
        self.assertEqual(status, 200, link)
        token = link["link"]["token"]
        self.assertGreaterEqual(len(token), 32)

        day = day_ago()
        lines = ["Datum;Tep"] + [f"{datetime.fromtimestamp(at(day, '14:00', 5 * i), PRAGUE).isoformat()};{70 + i % 5}" for i in range(120)]
        status, text = self.shortcut(token, "\n".join(lines))
        self.assertEqual(status, 200, text)
        self.assertIn("Přijato 120 hodnot tepu", text)

        # Na serveru leží jen zapečetěný blok, bez časů i hodnot.
        with sqlite3.connect(self.server.data / "app.sqlite3") as connection:
            rows = connection.execute("SELECT sealed, samples FROM hr_inbox").fetchall()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0][1], 120)
        sealed = base64.b64decode(rows[0][0])
        self.assertGreater(len(sealed), 500)
        self.assertNotIn(day.isoformat().encode(), sealed)
        self.assertNotIn(b"samples", sealed)

        state = self.state()
        self.assertEqual(state["imported"], {"stored": 120, "items": 1})
        self.assertEqual(len(state["sessions"]), 1)
        session = state["sessions"][0]
        self.assertEqual((session["source"], session["device"], session["samples"], session["date"]), ("watch", "Apple Watch", 120, day.isoformat()))
        self.assertEqual(session["baseline_kind"], "auto")
        self.assertAlmostEqual(session["baseline_bpm"], 70.0, delta=0.5)
        self.assertIsNotNone(state["link"]["link"]["received_at"])
        with sqlite3.connect(self.server.data / "app.sqlite3") as connection:
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM hr_inbox").fetchone()[0], 0, "schránka se po načtení vyprázdní")

        # Stejná data podruhé se jen přepíšou, nová seance nevznikne.
        self.assertEqual(self.shortcut(token, "\n".join(lines))[0], 200)
        state = self.state()
        self.assertEqual((len(state["sessions"]), state["sessions"][0]["samples"]), (1, 120))

        # Formulář s polem data (jiné nastavení Zkratky) a JSON ze slovníku.
        later = [f"{datetime.fromtimestamp(at(day, '14:10', 5 * i), PRAGUE).isoformat()};80" for i in range(10)]
        status, text = self.shortcut(token, "data=" + urllib.parse.quote("\n".join(later)), "application/x-www-form-urlencoded")
        self.assertEqual(status, 200, text)
        payload = json.dumps({"vzorky": [{"date": at(day, "14:11", 5 * i), "value": "81"} for i in range(5)]})
        self.assertEqual(self.shortcut(token, payload, "application/json")[0], 200)
        state = self.state()
        self.assertEqual(state["sessions"][0]["samples"], 120 + 10 + 5)

        # Chyby a neplatné odkazy.
        self.assertEqual(self.shortcut(token, "nic tu není")[0], 422)
        self.assertEqual(self.shortcut("x" * 32, "\n".join(lines))[0], 404)
        self.assertEqual(self.shortcut(token, "", method="GET")[0], 405)
        status, renewed = self.client.api("POST", "heart_link")
        self.assertNotEqual(renewed["link"]["token"], token)
        self.assertEqual(self.shortcut(token, "\n".join(lines))[0], 404, "starý odkaz po obnovení neplatí")
        status, removed = self.client.api("DELETE", "heart_link")
        self.assertEqual((status, removed["link"]), (200, None))
        self.assertEqual(self.shortcut(renewed["link"]["token"], "\n".join(lines))[0], 404)

    def test_live_session_with_baseline_samples_and_alerts(self):
        status, result = self.client.api("POST", "heart_session", {"op": "start", "source": "ble", "device": "Polar H10 1A2B"})
        self.assertEqual(status, 201, result)
        session = result["session"]
        self.assertTrue(session["active"])
        start = session["started_ts"]

        # Klidové měření: 12 vzorků po 5 s, pak seance s jedním skokem.
        now = int(time.time())
        calm = [[now - 55 + 5 * i, 64 + (i % 3), 42.5] for i in range(12)]
        status, stored = self.client.api("POST", "heart_samples", {"session_id": session["id"], "samples": calm + [[now, 300], [now - 99999, 70], ["x", 70]]})
        self.assertEqual((status, stored["stored"]), (200, 12), "nesmyslný tep a čas mimo seanci se zahodí")
        status, result = self.client.api("POST", "heart_session", {"op": "baseline", "id": session["id"], "from": now - 60, "to": now})
        self.assertEqual(status, 200, result)
        self.assertEqual((result["session"]["baseline_kind"], result["session"]["baseline_bpm"], result["session"]["baseline_rmssd"]), ("measured", 65.0, 42.5))

        status, alert = self.client.api("POST", "heart_alert", {"session_id": session["id"], "ts": now, "kind": "high", "delta": 18.4, "bpm": 83.4})
        self.assertEqual(status, 200, alert)
        self.assertEqual(self.client.api("POST", "heart_alert", {"id": alert["id"], "action": "breath"})[0], 200)
        self.assertEqual(self.client.api("POST", "heart_alert", {"session_id": session["id"], "kind": "nevim"})[0], 422)
        self.assertEqual(self.client.api("POST", "heart_samples", {"session_id": session["id"], "samples": [[now, 70]] * 2001})[0], 422)
        self.assertEqual(self.client.api("POST", "heart_session", {"op": "nevim"})[0], 422)
        self.assertEqual(self.client.api("POST", "heart_session", {"op": "baseline", "id": session["id"], "from": now, "to": now - 10})[0], 422)

        state = self.state()
        self.assertEqual((state["active"]["id"], state["active"]["samples"]), (session["id"], 12))

        # Nová seance ukončí tu běžící.
        status, second = self.client.api("POST", "heart_session", {"op": "start", "source": "none"})
        self.assertEqual(status, 201)
        status, detail = self.client.api("GET", "heart_session", id=session["id"])
        self.assertEqual(status, 200, detail)
        self.assertFalse(detail["session"]["active"])
        self.assertEqual((len(detail["samples"]), detail["session"]["alerts"], detail["alerts"][0]["action"]), (12, 1, "breath"))
        self.assertEqual(detail["samples"][0][2], 42.5)
        self.assertGreaterEqual(detail["session"]["ended_ts"], start)

        status, ended = self.client.api("POST", "heart_session", {"op": "end", "id": second["session"]["id"]})
        self.assertEqual((status, ended["session"]["active"]), (200, False))
        self.assertIsNone(self.state()["active"])
        self.assertEqual(self.client.api("DELETE", "heart_session", id=session["id"])[0], 200)
        self.assertEqual(self.client.api("GET", "heart_session", id=session["id"])[0], 422)

        # Export dat obsahuje i tep.
        status, export = self.client.api("GET", "export")
        self.assertEqual(status, 200)
        self.assertEqual(len(export["hr_sessions"]), 1)
        self.assertNotIn("hr_link", export)

    def test_upload_formats(self):
        day = day_ago(3)
        iso = datetime.fromtimestamp(at(day, "10:00"), PRAGUE).isoformat()
        czech = f"{day.day}. {day.month}. {day.year} v 10:01:05"
        text = "\n".join([
            "# komentář", "Datum,Tep", f"{iso};72", f"{czech};73 tepů/min", f"{at(day, '10:02') * 1000},74",
            f"{day.isoformat()} 10:03:00\t75,5", "nesmysl;72", f"{iso};999",
        ])
        status, result = self.client.api("POST", "heart_upload", {"text": text})
        self.assertEqual(status, 200, result)
        self.assertEqual((result["stored"], result["skipped"]), (4, 3))
        body, content_type = multipart({}, [("file", "tep.csv", f"{datetime.fromtimestamp(at(day, '10:04'), PRAGUE).isoformat()};76\n".encode())])
        status, result = self.client.request("POST", "/api.php?action=heart_upload", (body, content_type))
        self.assertEqual((status, result["stored"]), (200, 1))
        status, detail = self.client.api("GET", "heart_session", id=result["sessions"][0])
        self.assertEqual([round(sample[1], 1) for sample in detail["samples"]], [72.0, 73.0, 74.0, 75.5, 76.0])
        self.assertEqual(detail["samples"][1][0], at(day, "10:01", 5))
        self.assertEqual(self.client.api("POST", "heart_upload", {"text": "nic"})[0], 422)

    def test_model_learns_after_fifteen_trades_and_finds_threshold(self):
        day = day_ago(2)
        # Klid kolem 65; před chybovými vstupy tep +20, před čistými +2.
        errors = ["14:10", "14:40", "15:10", "15:40", "16:10", "16:40"]
        clean = ["14:25", "14:55", "15:25", "15:55", "16:25", "16:55", "17:10", "17:25", "17:40"]
        high = {at(day, hhmm) for hhmm in errors}
        mild = {at(day, hhmm) for hhmm in clean}
        lines = []
        for second in range(at(day, "14:00"), at(day, "17:50"), 5):
            bpm = 65
            if any(0 <= entry - second <= 150 for entry in high):
                bpm = 85
            elif any(0 <= entry - second <= 150 for entry in mild):
                bpm = 67
            lines.append(f"{datetime.fromtimestamp(second, PRAGUE).isoformat()};{bpm}")
        status, result = self.client.api("POST", "heart_upload", {"text": "\n".join(lines)})
        self.assertEqual(status, 200, result)

        for hhmm in errors[:5]:
            self.trade(day, hhmm, True, result_r=-1.0)
        for hhmm in clean[:9]:
            self.trade(day, hhmm, False)
        self.trade(day, "17:45", False, labeled=False)
        model = self.state()["model"]
        self.assertEqual((model["trades"], model["unlabeled"], model["ready"], model["threshold"]), (14, 1, False, None))
        self.assertIn("Učím se: 14 z 15", model["text"])

        self.trade(day, errors[5], True, result_r=-1.0)
        state = self.state()
        model = state["model"]
        self.assertTrue(model["ready"])
        self.assertEqual((model["trades"], model["errors"], model["basis"]), (15, 6, "errors"))
        self.assertGreaterEqual(model["threshold"], 6)
        self.assertLessEqual(model["threshold"], 20)
        self.assertEqual((model["above"], model["below"]), ({"n": 6, "errors": 6, "rate": 1.0}, {"n": 9, "errors": 0, "rate": 0.0}))
        self.assertEqual(model["strength"], "strong")
        self.assertLess(model["p"], 0.01)
        self.assertIn("chyboval v 6 z 6", model["text"])
        buckets = {bucket["label"]: bucket for bucket in model["buckets"]}
        self.assertEqual((buckets["nad +20"]["errors"], buckets["0 až +5"]["n"]), (6, 9))
        self.assertEqual(len(state["points"]), 16)

        session_id = state["sessions"][0]["id"]
        status, detail = self.client.api("GET", "heart_session", id=session_id)
        self.assertEqual(status, 200, detail)
        self.assertEqual(len(detail["trades"]), 16)
        first = detail["trades"][0]
        self.assertEqual((first["error"], round(first["delta"])), (True, 20))
        self.assertEqual(detail["threshold_basis"], "errors")
        self.assertTrue(detail["segments"], "úseky s vysokým tepem nad dvě minuty")

    def test_heart_tables_added_to_existing_journal(self):
        # Starý deník bez tabulek tepu se při otevření doplní a data zůstanou.
        status, trade = self.client.api("POST", "trade", {"trade_date": "2026-09-01", "market": "ES", "direction": "long", "notes": "starý obchod"})
        self.assertIn(status, (200, 201))
        status, state = self.client.api("GET", "heart_state")
        self.assertEqual(status, 200, state)
        status, trades = self.client.api("GET", "trades")
        self.assertTrue(any(item.get("notes") == "starý obchod" for item in trades["items"]))


if __name__ == "__main__":
    unittest.main()
