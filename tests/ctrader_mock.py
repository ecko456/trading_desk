"""Simulace cTrader Open API pro testy.

WebSocket s JSON zprávami (jako port 5036), výměna kódu za přístupový klíč a přihlašovací
stránka, která přesměruje zpátky na ctrader.php jako skutečný cTrader. Data účtu jsou
v MockState; čísla zpráv a pole odpovídají proto souborům spotware/openapi-proto-messages.
Některá int64 a výčty schválně posílá jako text, protože JSON je tak může vrátit.
"""

import base64
import hashlib
import json
import socket
import socketserver
import struct
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

DAY = 86400 * 1000
GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def ms_days_ago(days, hours=0.0):
    return int(time.time() * 1000) - int(days * DAY) + int(hours * 3600 * 1000)


class MockState:
    """Účet 1001 (USD): pozice E otevřená před začátkem importu, A a B uzavřené, C otevřená
    a částečně uzavřená, výběr 500 USD. Zůstatek na začátku importu (před 10 dny) je 10 000."""

    def __init__(self):
        self.client_id = "test-client"
        self.client_secret = "test-secret"
        self.codes = {"good-code": ("access-1", "refresh-1")}
        self.valid_access = {"access-1"}
        self.refresh_tokens = {"refresh-1"}
        self.expires_in = 2628000
        self.refresh_calls = 0
        self.requests = []
        self.fail = {}
        self.lock = threading.Lock()
        self.accounts = [
            {"ctidTraderAccountId": "1001", "isLive": False, "traderLogin": 5550001, "brokerTitleShort": "Demo Broker"},
        ]
        self.balance = 971450
        e_open, e_close = ms_days_ago(12), ms_days_ago(9)
        a_open, a_close = ms_days_ago(5), ms_days_ago(5, 0.5)
        b_open, b_close1, b_close2 = ms_days_ago(3), ms_days_ago(3, 1), ms_days_ago(3, 2)
        c_open, c_close = ms_days_ago(1), ms_days_ago(1, 1)
        old_open, old_close = ms_days_ago(20), ms_days_ago(19)
        self.import_from_days = 10

        def deal(deal_id, position, side, volume, price, ts, symbol=1, detail=None, commission=-100):
            row = {
                "dealId": deal_id, "orderId": deal_id + 500, "positionId": position, "volume": volume,
                "filledVolume": volume, "symbolId": symbol, "createTimestamp": ts, "executionTimestamp": ts,
                "executionPrice": price, "tradeSide": side, "dealStatus": 2, "commission": commission, "moneyDigits": 2,
            }
            if detail:
                row["closePositionDetail"] = {"swap": 0, "moneyDigits": 2, "closedVolume": volume, **detail}
            return row

        self.deals = [
            deal(1, 9001, "BUY", 100, 4800.0, old_open),
            deal(2, 9001, 2, 100, 4810.0, old_close, detail={"entryPrice": 4800.0, "grossProfit": 1000, "commission": -100, "balance": 1000000, "balanceVersion": 3}),
            deal(8, 905, 1, 100, 4900.0, e_open),
            deal(9, 905, "SELL", 100, 4950.0, e_close, detail={"entryPrice": 4900.0, "grossProfit": 5000, "commission": -100, "balance": 1004900, "balanceVersion": 4}),
            # Komise v detailu je jen za uzavření; přesný výsledek dá rozdíl zůstatků (−2 USD).
            deal(11, 901, 1, 200, 5000.0, a_open),
            deal(12, 901, 2, 200, 5010.0, a_close, detail={"entryPrice": 5000.0, "grossProfit": 2000, "commission": -100, "balance": 1006700, "balanceVersion": 5}),
            deal(21, 902, 2, 10000000, 1.1, b_open, symbol=2),
            deal(22, 902, 1, 5000000, 1.099, b_close1, symbol=2, detail={"entryPrice": 1.1, "grossProfit": 5000, "commission": -350, "balance": 1011350, "balanceVersion": 6}),
            deal(23, 902, 1, 5000000, 1.098, b_close2, symbol=2, detail={"entryPrice": 1.1, "grossProfit": 10000, "commission": -350, "balance": 1021000, "balanceVersion": 7}),
            deal(31, 903, 2, 100, 5050.0, c_open),
            deal(32, 903, 1, 50, 5040.0, c_close, detail={"entryPrice": 5050.0, "grossProfit": 500, "commission": -50, "balance": 971450, "balanceVersion": 9}),
            {**deal(33, 904, 1, 100, 5000.0, a_open), "dealStatus": "REJECTED"},
        ]
        self.orders = [
            {"orderId": 511, "positionId": 901, "orderType": 1, "orderStatus": 2, "executionPrice": 5000.0, "stopLoss": 4995.0, "takeProfit": 5020.0, "utcLastUpdateTimestamp": a_open, "tradeData": {"symbolId": 1, "volume": 200, "tradeSide": "BUY"}},
            # Posunutý stop do zisku se pro risk nepoužije, první zůstává.
            {"orderId": 512, "positionId": 901, "orderType": "STOP_LOSS_TAKE_PROFIT", "orderStatus": 2, "stopPrice": 5005.0, "utcLastUpdateTimestamp": a_open + 1000, "tradeData": {"symbolId": 1, "volume": 200, "tradeSide": "SELL"}},
            {"orderId": 521, "positionId": 902, "orderType": "MARKET", "orderStatus": 2, "executionPrice": 1.1, "relativeStopLoss": 200, "utcLastUpdateTimestamp": b_open, "tradeData": {"symbolId": 2, "volume": 10000000, "tradeSide": 2}},
            {"orderId": 531, "positionId": 903, "closingOrder": False, "orderType": 1, "orderStatus": 2, "executionPrice": 5050.0, "stopLoss": 5060.0, "utcLastUpdateTimestamp": c_open, "tradeData": {"symbolId": 1, "volume": 100, "tradeSide": 2}},
        ]
        self.cash = [
            {"operationType": 1, "balanceHistoryId": 77, "balance": 971000, "delta": 50000, "changeBalanceTimestamp": ms_days_ago(2), "balanceVersion": 8, "moneyDigits": 2},
        ]
        self.positions = [
            {"positionId": 903, "tradeData": {"symbolId": 1, "volume": 50, "tradeSide": "SELL", "openTimestamp": c_open}, "positionStatus": 1, "swap": 0, "price": 5050.0, "stopLoss": 5060.0, "takeProfit": 5000.0, "moneyDigits": 2},
        ]
        self.unrealized = [{"positionId": 903, "grossUnrealizedPnL": 1050, "netUnrealizedPnL": 1000}]
        self.symbols = [
            {"symbolId": 1, "symbolName": "US500", "enabled": True},
            {"symbolId": 2, "symbolName": "EUR/USD", "enabled": True},
        ]
        self.symbol_details = [
            {"symbolId": 1, "digits": 2, "pipPosition": 1, "lotSize": 100},
            {"symbolId": 2, "digits": 5, "pipPosition": 4, "lotSize": 10000000},
        ]

    def log(self, payload_type, payload):
        with self.lock:
            self.requests.append((payload_type, payload))

    def count(self, payload_type):
        with self.lock:
            return sum(1 for kind, _ in self.requests if kind == payload_type)


def error(code, description=""):
    return 2142, {"errorCode": code, "description": description}


def respond(state, payload_type, payload, session):
    state.log(payload_type, payload)
    if payload_type in state.fail:
        return error(state.fail[payload_type])
    if payload_type == 2100:
        if payload.get("clientId") != state.client_id or payload.get("clientSecret") != state.client_secret:
            return error("CH_CLIENT_AUTH_FAILURE", "Wrong client credentials")
        session["app"] = True
        return 2101, {}
    if not session.get("app"):
        return error("CH_CLIENT_NOT_AUTHENTICATED")
    if payload_type == 2149:
        if payload.get("accessToken") not in state.valid_access:
            return error("CH_ACCESS_TOKEN_INVALID")
        return 2150, {"accessToken": payload["accessToken"], "permissionScope": "SCOPE_VIEW", "ctidTraderAccount": state.accounts}
    if payload_type == 2102:
        if payload.get("accessToken") not in state.valid_access:
            return error("CH_ACCESS_TOKEN_INVALID")
        session.setdefault("accounts", set()).add(int(payload["ctidTraderAccountId"]))
        return 2103, {"ctidTraderAccountId": payload["ctidTraderAccountId"]}
    account = int(payload.get("ctidTraderAccountId", 0))
    if account not in session.get("accounts", set()):
        return error("ACCOUNT_NOT_AUTHORIZED")
    base = {"ctidTraderAccountId": account}
    if payload_type == 2121:
        return 2122, {**base, "trader": {"ctidTraderAccountId": account, "balance": str(state.balance), "depositAssetId": 15, "moneyDigits": 2}}
    if payload_type == 2112:
        return 2113, {**base, "asset": [{"assetId": 14, "name": "EUR"}, {"assetId": 15, "name": "USD"}]}
    if payload_type == 2124:
        return 2125, {**base, "position": state.positions, "order": []}
    if payload_type == 2187:
        return 2188, {**base, "positionUnrealizedPnL": state.unrealized, "moneyDigits": 2}
    if payload_type in (2133, 2175, 2143):
        start, end = int(payload.get("fromTimestamp", 0)), int(payload.get("toTimestamp", 0))
        if payload_type == 2143 and end - start > 7 * DAY:
            return error("INCORRECT_BOUNDARIES", "Period too long")
        if payload_type == 2133:
            return 2134, {**base, "deal": [d for d in state.deals if start <= d["executionTimestamp"] <= end], "hasMore": False}
        if payload_type == 2175:
            return 2176, {**base, "order": [o for o in state.orders if start <= o["utcLastUpdateTimestamp"] <= end], "hasMore": False}
        return 2144, {**base, "depositWithdraw": [c for c in state.cash if start <= c["changeBalanceTimestamp"] <= end]}
    if payload_type == 2179:
        position = int(payload.get("positionId", 0))
        return 2180, {**base, "deal": [d for d in state.deals if d["positionId"] == position], "hasMore": False}
    if payload_type == 2114:
        return 2115, {**base, "symbol": state.symbols, "archivedSymbol": []}
    if payload_type == 2116:
        wanted = {int(i) for i in payload.get("symbolId", [])}
        return 2117, {**base, "symbol": [s for s in state.symbol_details if s["symbolId"] in wanted]}
    return error("UNSUPPORTED_MESSAGE", str(payload_type))


class SocketHandler(socketserver.BaseRequestHandler):
    def setup(self):
        self.buffer = b""

    def read(self, size):
        while len(self.buffer) < size:
            chunk = self.request.recv(65536)
            if not chunk:
                raise ConnectionError("closed")
            self.buffer += chunk
        data, self.buffer = self.buffer[:size], self.buffer[size:]
        return data

    def send(self, message):
        data = json.dumps(message).encode()
        head = bytes([0x81])
        if len(data) < 126:
            head += bytes([len(data)])
        elif len(data) < 65536:
            head += bytes([126]) + struct.pack(">H", len(data))
        else:
            head += bytes([127]) + struct.pack(">Q", len(data))
        self.request.sendall(head + data)

    def handle(self):
        state = self.server.state
        while b"\r\n\r\n" not in self.buffer:
            chunk = self.request.recv(4096)
            if not chunk:
                return
            self.buffer += chunk
        head, self.buffer = self.buffer.split(b"\r\n\r\n", 1)
        key = ""
        for line in head.decode().split("\r\n"):
            if line.lower().startswith("sec-websocket-key:"):
                key = line.split(":", 1)[1].strip()
        accept = base64.b64encode(hashlib.sha1((key + GUID).encode()).digest()).decode()
        self.request.sendall(f"HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {accept}\r\n\r\n".encode())
        session = {}
        try:
            while True:
                first, second = self.read(2)
                opcode = first & 0x0F
                length = second & 0x7F
                if length == 126:
                    length = struct.unpack(">H", self.read(2))[0]
                elif length == 127:
                    length = struct.unpack(">Q", self.read(8))[0]
                mask = self.read(4) if second & 0x80 else b"\0\0\0\0"
                data = bytes(b ^ mask[i % 4] for i, b in enumerate(self.read(length)))
                if opcode == 0x8:
                    return
                if opcode != 0x1:
                    continue
                message = json.loads(data)
                # Události mimo odpovědi klient musí přeskočit.
                self.send({"payloadType": 51, "payload": {}})
                self.send({"payloadType": 2147, "payload": {"ctidTraderAccountIds": [42], "reason": "jiný účet"}})
                kind, payload = respond(state, int(message["payloadType"]), message.get("payload") or {}, session)
                self.send({"clientMsgId": message.get("clientMsgId"), "payloadType": kind, "payload": payload})
        except (ConnectionError, OSError):
            return


class HttpHandler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        state = self.server.state
        url = urlparse(self.path)
        query = {k: v[0] for k, v in parse_qs(url.query).items()}
        if url.path == "/auth":
            # Přihlášení cTrader ID: hned vrátí kód na adresu pro návrat (bez state, jako to cTrader nedokumentuje).
            if query.get("client_id") != state.client_id or query.get("scope") != "accounts":
                return self.reply(400, {"error": "bad request"})
            target = query["redirect_uri"] + "?" + urlencode({"code": state.next_code} if getattr(state, "next_code", None) else {"code": "good-code"})
            if getattr(state, "echo_state", False):
                target += "&" + urlencode({"state": query.get("state", "")})
            self.send_response(302)
            self.send_header("Location", target)
            self.end_headers()
            return
        if url.path == "/apps/token":
            if query.get("client_id") != state.client_id or query.get("client_secret") != state.client_secret:
                return self.reply(200, {"errorCode": "ACCESS_DENIED", "description": "Wrong credentials"})
            if query.get("grant_type") == "authorization_code":
                tokens = state.codes.get(query.get("code", ""))
                if tokens is None:
                    return self.reply(200, {"errorCode": "ACCESS_DENIED", "description": "Unknown code"})
                return self.reply(200, {"accessToken": tokens[0], "tokenType": "bearer", "expiresIn": state.expires_in, "refreshToken": tokens[1], "errorCode": None, "description": None})
            if query.get("grant_type") == "refresh_token" and query.get("refresh_token") in state.refresh_tokens:
                with state.lock:
                    state.refresh_calls += 1
                    access = f"access-r{state.refresh_calls}"
                    refresh = f"refresh-r{state.refresh_calls}"
                    state.valid_access.add(access)
                    state.refresh_tokens.add(refresh)
                return self.reply(200, {"accessToken": access, "tokenType": "bearer", "expiresIn": 2628000, "refreshToken": refresh})
            return self.reply(200, {"errorCode": "ACCESS_DENIED", "description": "Bad refresh"})
        self.reply(404, {"error": "not found"})


class CtraderMock:
    def __init__(self):
        self.state = MockState()
        self.ws = socketserver.ThreadingTCPServer(("127.0.0.1", 0), SocketHandler)
        self.ws.daemon_threads = True
        self.ws.state = self.state
        self.http = ThreadingHTTPServer(("127.0.0.1", 0), HttpHandler)
        self.http.daemon_threads = True
        self.http.state = self.state
        for server in (self.ws, self.http):
            threading.Thread(target=server.serve_forever, daemon=True).start()

    @property
    def env(self):
        http = f"http://127.0.0.1:{self.http.server_address[1]}"
        return {
            "TRADING_CTRADER_SOCKET": f"tcp://127.0.0.1:{self.ws.server_address[1]}",
            "TRADING_CTRADER_TOKEN_URL": http + "/apps/token",
            "TRADING_CTRADER_AUTH_URL": http + "/auth",
        }

    def stop(self):
        for server in (self.ws, self.http):
            server.shutdown()
            server.server_close()
