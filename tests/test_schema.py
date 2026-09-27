from pathlib import Path
import re
import sqlite3
import unittest


ROOT = Path(__file__).resolve().parents[1]


class TradingSchemaTests(unittest.TestCase):
    def setUp(self):
        source = (ROOT / "bootstrap.php").read_text(encoding="utf-8")
        match = re.search(r"<<<'SQL'\n(.*?)\nSQL\);", source, re.DOTALL)
        self.assertIsNotNone(match, "SQL schema heredoc was not found")
        self.connection = sqlite3.connect(":memory:")
        self.connection.execute("PRAGMA foreign_keys = ON")
        self.connection.executescript(match.group(1))

    def tearDown(self):
        self.connection.close()

    def test_required_tables_exist(self):
        tables = {row[0] for row in self.connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        self.assertTrue({
            "plans", "zones", "ideas", "levels", "trades", "screenshots",
            "strategies", "accounts", "account_audits", "calendar_events", "psych_checks", "psych_profile",
        }.issubset(tables))

    def test_only_one_psych_profile_can_exist(self):
        now = "2026-09-12T00:00:00Z"
        self.connection.execute(
            "INSERT INTO psych_profile (id, answers, dimensions, rules, created_at, updated_at) VALUES (1, '{}', '{}', '{}', ?, ?)",
            (now, now),
        )
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                "INSERT INTO psych_profile (id, answers, dimensions, rules, created_at, updated_at) VALUES (2, '{}', '{}', '{}', ?, ?)",
                (now, now),
            )

    def test_levels_belong_to_plan_and_cascade(self):
        now = "2026-08-04T00:00:00Z"
        cursor = self.connection.execute(
            "INSERT INTO plans (plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            ("2026-08-04", "ES", "intraday", now, now),
        )
        plan_id = cursor.lastrowid
        self.connection.execute(
            "INSERT INTO levels (plan_id, sort_order, name, price, kind, line_style) VALUES (?, ?, ?, ?, ?, ?)",
            (plan_id, 0, "Weekly VAH", 6412.5, "resistance", "dashed"),
        )
        level = self.connection.execute("SELECT name, price, kind FROM levels").fetchone()
        self.assertEqual(level, ("Weekly VAH", 6412.5, "resistance"))
        self.connection.execute("DELETE FROM plans WHERE id = ?", (plan_id,))
        self.assertEqual(self.connection.execute("SELECT COUNT(*) FROM levels").fetchone()[0], 0)

    def test_screenshot_owner_is_required(self):
        now = "2026-08-04T00:00:00Z"
        self.connection.execute("INSERT INTO strategies (name, created_at, updated_at) VALUES (?, ?, ?)", ("30BOS", now, now))
        self.connection.execute(
            "INSERT INTO screenshots (id, strategy_id, file_name, original_name, mime_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ("a", 1, "a.png", "a.png", "image/png", 10, now),
        )
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                "INSERT INTO screenshots (id, file_name, original_name, mime_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                ("b", "b.png", "b.png", "image/png", 10, now),
            )

    def test_deleting_strategy_keeps_trade_but_clears_link(self):
        now = "2026-08-04T00:00:00Z"
        self.connection.execute("INSERT INTO strategies (name, created_at, updated_at) VALUES (?, ?, ?)", ("F7", now, now))
        self.connection.execute(
            "INSERT INTO trades (trade_date, market, strategy, strategy_id, direction, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ("2026-08-04", "ES", "F7", 1, "long", now, now),
        )
        self.connection.execute("DELETE FROM strategies WHERE id = 1")
        trade = self.connection.execute("SELECT strategy, strategy_id FROM trades").fetchone()
        self.assertEqual(trade[0], "F7")
        self.assertIsNone(trade[1])

    def test_deleting_account_removes_audits_but_keeps_trades(self):
        now = "2026-08-04T00:00:00Z"
        self.connection.execute(
            "INSERT INTO accounts (name, starting_balance, opened_at, created_at) VALUES (?, ?, ?, ?)",
            ("Apex", 50000, "2026-07-01", now),
        )
        self.connection.execute(
            "INSERT INTO account_audits (account_id, audit_date, reported_balance, expected_balance, difference, tolerance, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (1, "2026-08-01", 50500, 50400, 100, 200, now),
        )
        self.connection.execute(
            "INSERT INTO trades (trade_date, market, account_id, direction, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
            ("2026-08-04", "ES", 1, "long", now, now),
        )
        self.connection.execute("DELETE FROM accounts WHERE id = 1")
        self.assertEqual(self.connection.execute("SELECT COUNT(*) FROM account_audits").fetchone()[0], 0)
        self.assertEqual(self.connection.execute("SELECT COUNT(*) FROM trades").fetchone()[0], 1)
        self.assertIsNone(self.connection.execute("SELECT account_id FROM trades").fetchone()[0])

    def test_zone_bounds_are_validated(self):
        now = "2026-08-04T00:00:00Z"
        cursor = self.connection.execute(
            "INSERT INTO plans (plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            ("2026-08-04", "ES", "NY", now, now),
        )
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                "INSERT INTO zones (plan_id, price_low, price_high) VALUES (?, ?, ?)",
                (cursor.lastrowid, 6100, 6090),
            )

    def test_daily_and_weekly_plan_can_share_a_date(self):
        now = "2026-09-28T00:00:00Z"
        insert = "INSERT INTO plans (plan_type, plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)"
        self.connection.execute(insert, ("daily", "2026-09-28", "ES", "intraday", now, now))
        self.connection.execute(insert, ("weekly", "2026-09-28", "ES", "intraday", now, now))
        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(insert, ("weekly", "2026-09-28", "ES", "intraday", now, now))
        default = self.connection.execute(
            "INSERT INTO plans (plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            ("2026-09-29", "ES", "intraday", now, now),
        ).lastrowid
        self.assertEqual(self.connection.execute("SELECT plan_type FROM plans WHERE id = ?", (default,)).fetchone()[0], "daily")

    def test_plan_refs_and_zone_conditions_belong_to_plan(self):
        now = "2026-09-28T00:00:00Z"
        plan_id = self.connection.execute(
            "INSERT INTO plans (plan_type, plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
            ("weekly", "2026-09-28", "ES", "intraday", now, now),
        ).lastrowid
        self.connection.execute("INSERT INTO plan_refs (plan_id, kind, price_low, price_high) VALUES (?, ?, ?, ?)", (plan_id, "single_print", 6676, 6684))
        self.connection.execute(
            "INSERT INTO zones (plan_id, name, direction, long_entry, long_skip, short_entry, short_skip) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (plan_id, "B", "both", "vstup L", "neberu L", "vstup S", "neberu S"),
        )
        zone = self.connection.execute("SELECT long_entry, long_skip, short_entry, short_skip FROM zones").fetchone()
        self.assertEqual(zone, ("vstup L", "neberu L", "vstup S", "neberu S"))
        self.connection.execute("DELETE FROM plans WHERE id = ?", (plan_id,))
        self.assertEqual(self.connection.execute("SELECT COUNT(*) FROM plan_refs").fetchone()[0], 0)

    def test_plan_delete_keeps_trade_but_removes_children(self):
        now = "2026-08-04T00:00:00Z"
        cursor = self.connection.execute(
            "INSERT INTO plans (plan_date, market, session, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            ("2026-08-04", "ES", "NY", now, now),
        )
        plan_id = cursor.lastrowid
        self.connection.execute("INSERT INTO zones (plan_id, name) VALUES (?, ?)", (plan_id, "A"))
        self.connection.execute(
            "INSERT INTO trades (plan_id, trade_date, market, direction, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
            (plan_id, "2026-08-04", "ES", "long", now, now),
        )
        self.connection.execute("DELETE FROM plans WHERE id = ?", (plan_id,))
        self.assertEqual(self.connection.execute("SELECT COUNT(*) FROM zones").fetchone()[0], 0)
        trade = self.connection.execute("SELECT plan_id FROM trades").fetchone()
        self.assertIsNotNone(trade)
        self.assertIsNone(trade[0])


if __name__ == "__main__":
    unittest.main()
