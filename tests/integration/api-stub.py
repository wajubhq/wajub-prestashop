#!/usr/bin/env python3
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# A stand-in for Wajub's API, for tests/integration/run.sh and
# run-payment.sh: the shop's key check (GET /) and the payments the checkout
# makes, whether api.wajub.com is up, down, or does not know the test keys.
# A shop reaches it only in debug mode, through _WAJUB_API_URL_
# (Services::apiUrl()), and its pages load the stand-in Wajub.js it serves
# (/wajub.js) only through _WAJUB_JS_URL_ (Wajub::wajubJsUrl()).
#
#     python3 api-stub.py <port file> <request log>
#
# Listens on 0.0.0.0 (a server on 127.0.0.1 cannot be reached from the
# shops' containers), on a port of its own choosing, written to <port file>.
# Each request is logged as one JSON line, with what the module must send
# (User-Agent, Idempotency-Key) and never more of the key than its prefix.
#
# Payments, as the API keeps them (in memory):
#   GET  /payments/options              the channels set by POST /__stub/options
#   POST /payments                      deferred (prepared) or hosted
#   PATCH /payments/{id}                a new amount, a new expiry
#   POST /payments/{id}/activate        the same body again answers as done, another 409
#   GET  /payments/{id}                 the payment
#   DELETE /payments/{id}               cancels one nobody paid yet, 409 otherwise
#   POST /payments/{id}/close           closes one between two instalments, keeping what it collected
#   GET  /payments/{id}/refunds         its refunds
#   POST /refunds                       a refund (the same Idempotency-Key answers the same refund)
#   GET  /webhooks                      the endpoints set by POST /__stub/webhooks
#   PUT  /webhooks/{id}                 an endpoint's events and state changed
# And for the tests:
#   POST /__stub/payments/{id}          sets fields of a payment (status, amount_paid…)
#   GET  /__stub/payments/{id}          the payment as kept, with what activated it
#   POST /__stub/options                the channels GET /payments/options lists
#   POST /__stub/fail                   {"path": "...", "status": 503, "times": 1}: the next calls fail
#   POST /__stub/slow                   {"path": "...", "seconds": 3, "times": 1}: the next calls answer late
#   GET  /__stub/refunds                every refund made, in order
#   POST /__stub/refunds                {"transaction", "amount", "status"}: a refund made out of the shop's sight
#   POST /__stub/webhooks               {"items": [...]}: the endpoints GET /webhooks lists
#   GET  /__stub/webhooks               the endpoints, as PUT left them

import hashlib
import json
import re
import sys
import threading
import time
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BUSINESS = "Stub Business"
LOCK = threading.Lock()
PAYMENTS = {}
REFUNDS = []
IDEMPOTENT = {}
COUNTER = [0]
OPTIONS = [
    {"id": "cm.mtn", "slug": "cm.mtn", "name": "MTN Mobile Money", "type": "mobile_money"},
    {"id": "cm.orange", "slug": "cm.orange", "name": "Orange Money", "type": "mobile"},
    {"id": "card", "slug": "card", "name": "Card", "type": "card"},
    {"id": "paypal", "slug": "paypal", "name": "PayPal", "type": "paypal"},
]
FAILURES = []
SLOW = []
WEBHOOKS = []


def iso(seconds_from_now):
    return (datetime.now(timezone.utc) + timedelta(seconds=seconds_from_now)).strftime("%Y-%m-%dT%H:%M:%SZ")


class Handler(BaseHTTPRequestHandler):
    server_version = "WajubStub/2"

    def do_GET(self):
        self.answer()

    def do_POST(self):
        self.answer()

    def do_PUT(self):
        self.answer()

    def do_PATCH(self):
        self.answer()

    def do_DELETE(self):
        self.answer()

    def answer(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        try:
            body = json.loads(raw.decode("utf-8")) if raw else {}
        except ValueError:
            body = {}
        key = self.headers.get("Authorization", "")
        prefix = key.split(".", 1)[0] + "." if "." in key else ""
        path = self.path.split("?", 1)[0]
        with open(self.server.request_log, "a", encoding="utf-8") as log:
            log.write(json.dumps({
                "method": self.command,
                "path": self.path,
                "key_prefix": prefix,
                "user_agent": self.headers.get("User-Agent", ""),
                "idempotency_key": self.headers.get("Idempotency-Key", ""),
                "body": body if not path.startswith("/__stub") else {},
            }) + "\n")

        if path == "/wajub.js":
            return self.script()
        if path.startswith("/__stub/"):
            return self.control(path, body)

        sandbox = key.startswith("sk_test.") or key.startswith("rk_test.")
        live = key.startswith("sk.") or key.startswith("rk.")
        if not sandbox and not live:
            return self.send(401, {"message": "Unauthenticated."})

        delay = 0.0
        with LOCK:
            for slow in SLOW:
                if slow["times"] > 0 and re.fullmatch(slow["path"], self.command + " " + path):
                    slow["times"] -= 1
                    delay = slow["seconds"]
                    break
        if delay > 0:
            time.sleep(delay)

        with LOCK:
            for failure in FAILURES:
                if failure["times"] > 0 and re.fullmatch(failure["path"], self.command + " " + path):
                    failure["times"] -= 1
                    return self.send(failure["status"], {"message": "Stub failure"})

        if self.command == "GET" and path == "/":
            # The API says `production` for a live key.
            return self.send(200, {"business": BUSINESS, "env": "sandbox" if sandbox else "production"})
        if self.command == "GET" and path == "/payments/options":
            return self.send(200, {"channels": OPTIONS})
        if self.command == "POST" and path == "/payments":
            return self.create(body, sandbox)
        if self.command == "POST" and path == "/refunds":
            return self.refund(body, sandbox, self.headers.get("Idempotency-Key", ""))

        if self.command == "GET" and path == "/webhooks":
            with LOCK:
                return self.send(200, {"items": list(WEBHOOKS)})
        match = re.fullmatch(r"/webhooks/([A-Za-z0-9_]+)", path)
        if match and self.command == "PUT":
            with LOCK:
                for endpoint in WEBHOOKS:
                    if endpoint.get("id") == match.group(1):
                        endpoint.update({k: v for k, v in body.items() if k in ("events", "active", "url", "description")})
                        return self.send(200, endpoint)
            return self.send(404, {"message": "Webhook endpoint not found."})

        match = re.fullmatch(r"/payments/([A-Za-z0-9_]+)(/activate|/refunds|/close)?", path)
        if match:
            with LOCK:
                payment = PAYMENTS.get(match.group(1))
            if payment is None or payment["sandbox"] != sandbox:
                return self.send(404, {"message": "Payment not found."})
            action = match.group(2) or ""
            if self.command == "GET" and action == "":
                return self.send(200, self.public(payment))
            if self.command == "GET" and action == "/refunds":
                with LOCK:
                    items = [r for r in REFUNDS if r["transaction"] == payment["id"]]
                return self.send(200, {"items": items})
            if self.command == "POST" and action == "/close":
                with LOCK:
                    if payment["status"] != "partial":
                        return self.send(409, {"message": "Not between two instalments.", "error_code": "payment_not_partial"})
                    payment["status"] = "succeeded"
                return self.send(200, {"transaction": self.public(payment)})
            if self.command == "PATCH" and action == "":
                return self.update(payment, body)
            if self.command == "POST" and action == "/activate":
                return self.activate(payment, body)
            if self.command == "DELETE" and action == "":
                with LOCK:
                    if payment["status"] != "pending":
                        return self.send(409, {"message": "Payment cannot be cancelled."})
                    payment["status"] = "cancelled"
                return self.send(200, self.public(payment))

        self.send(404, {"message": "Not found."})

    def create(self, body, sandbox):
        with LOCK:
            COUNTER[0] += 1
            n = COUNTER[0]
            payment_id = ("trx_test_stub%d_%d" if sandbox else "trx_stub%d_%d") % (int(time.time()) % 100000, n)
            expires_in = int((body.get("expires") or {}).get("in") or 1440)
            payment = {
                "id": payment_id,
                "reference": body.get("reference", ""),
                "description": body.get("description", ""),
                "amount": body.get("amount"),
                "amount_paid": 0,
                "currency": body.get("currency"),
                "status": "pending",
                "sandbox": sandbox,
                "deferred": bool(body.get("deferred")),
                "activated": not body.get("deferred"),
                "accept": body.get("accept"),
                "metadata": body.get("metadata") or {},
                "customer": body.get("customer"),
                "callback": body.get("callback"),
                "channel": "",
                "expires_at": iso(expires_in * 60),
                "authorization_token": "sess_stub_%d" % n,
                "authorization_url": "https://pay.wajub.com/" + payment_id,
                "activation": None,
                "activation_hash": "",
            }
            PAYMENTS[payment_id] = payment
            answer = dict(self.public(payment))
            answer["authorization_token"] = payment["authorization_token"]
        return self.send(201, answer)

    def refund(self, body, sandbox, key):
        with LOCK:
            if key and key in IDEMPOTENT:
                return self.send(201, {"refund": IDEMPOTENT[key]})
            payment = PAYMENTS.get(body.get("payment", ""))
            if payment is None or payment["sandbox"] != sandbox:
                return self.send(404, {"message": "Payment not found."})
            if payment["status"] not in ("succeeded", "partially_refunded"):
                return self.send(422, {"message": "Transaction must be completed to be refunded. Current status: " + payment["status"]})
            taken = sum(float(r["amount"]) for r in REFUNDS if r["transaction"] == payment["id"] and r["status"] in ("pending", "processing", "succeeded"))
            paid = float(payment.get("amount_paid") or payment.get("amount") or 0)
            amount = float(body.get("amount") if body.get("amount") is not None else paid - taken)
            if amount > paid - taken + 0.000001:
                return self.send(422, {"message": "Refund amount exceeds refundable amount (%s)" % (paid - taken), "errors": {"amount": ["too much"]}})
            refund = {
                "id": "rfd_stub%d_%d" % (int(time.time()) % 100000, len(REFUNDS) + 1),
                "transaction": payment["id"],
                "amount": amount,
                "currency": payment["currency"],
                "status": "processing" if sandbox else "pending",
                "reason": body.get("reason", ""),
                "metadata": body.get("metadata") or {},
                "sandbox": sandbox,
            }
            REFUNDS.append(refund)
            if key:
                IDEMPOTENT[key] = refund
        return self.send(201, {"refund": refund})

    def update(self, payment, body):
        with LOCK:
            if payment["activated"]:
                return self.send(409, {"message": "Payment already activated."})
            if "amount" in body:
                payment["amount"] = body["amount"]
            if body.get("expires"):
                payment["expires_at"] = iso(int(body["expires"]["in"]) * 60)
        return self.send(200, self.public(payment))

    def activate(self, payment, body):
        digest = hashlib.sha256(json.dumps(body, sort_keys=True).encode("utf-8")).hexdigest()
        with LOCK:
            if payment["activated"]:
                if payment["activation_hash"] == digest:
                    return self.send(200, {"activated": True})
                return self.send(409, {"message": "Payment already activated with another body."})
            if payment["status"] != "pending":
                return self.send(409, {"message": "Payment is not open."})
            if body.get("amount") != payment["amount"] or body.get("currency") != payment["currency"]:
                return self.send(422, {"message": "The amount does not match.", "errors": {"amount": ["mismatch"]}})
            payment["activated"] = True
            payment["activation"] = body
            payment["activation_hash"] = digest
            payment["metadata"] = dict(payment["metadata"], **(body.get("metadata") or {}))
            for field in ("reference", "description", "customer", "callback"):
                if field in body:
                    payment[field] = body[field]
        return self.send(200, {"activated": True})

    def control(self, path, body):
        global OPTIONS, WEBHOOKS
        match = re.fullmatch(r"/__stub/payments/([A-Za-z0-9_]+)", path)
        if match:
            with LOCK:
                payment = PAYMENTS.get(match.group(1))
                if payment is None:
                    return self.send(404, {"message": "No such payment."})
                if self.command == "POST":
                    payment.update(body)
                    if body.get("status") == "succeeded" and "amount_paid" not in body:
                        payment["amount_paid"] = payment["amount"]
                return self.send(200, payment)
        if path == "/__stub/options" and self.command == "POST":
            with LOCK:
                OPTIONS = body.get("channels", OPTIONS)
            return self.send(200, {"channels": OPTIONS})
        if path == "/__stub/refunds" and self.command == "GET":
            with LOCK:
                return self.send(200, {"items": list(REFUNDS)})
        if path == "/__stub/refunds" and self.command == "POST":
            # A refund made out of the shop's sight (in the Wajub Dashboard,
            # its webhook never sent): GET /payments/{id}/refunds lists it.
            with LOCK:
                payment = PAYMENTS.get(body.get("transaction", ""))
                if payment is None:
                    return self.send(404, {"message": "No such payment."})
                refund = {
                    "id": body.get("id") or "rfd_outside%d_%d" % (int(time.time()) % 100000, len(REFUNDS) + 1),
                    "transaction": payment["id"],
                    "amount": float(body.get("amount", 0)),
                    "currency": payment["currency"],
                    "status": body.get("status", "succeeded"),
                    "reason": "requested_by_customer",
                    "metadata": {},
                    "sandbox": payment["sandbox"],
                }
                REFUNDS.append(refund)
            return self.send(201, {"refund": refund})
        if path == "/__stub/webhooks":
            with LOCK:
                if self.command == "POST":
                    WEBHOOKS = list(body.get("items", []))
                return self.send(200, {"items": list(WEBHOOKS)})
        if path == "/__stub/slow" and self.command == "POST":
            with LOCK:
                SLOW.append({"path": body["path"], "seconds": float(body.get("seconds", 3)), "times": int(body.get("times", 1))})
            return self.send(200, {"ok": True})
        if path == "/__stub/fail" and self.command == "POST":
            with LOCK:
                FAILURES.append({"path": body["path"], "status": int(body.get("status", 503)), "times": int(body.get("times", 1))})
            return self.send(200, {"ok": True})
        return self.send(404, {"message": "Not found."})

    @staticmethod
    def public(payment):
        hidden = {"activation", "activation_hash", "deferred", "activated", "authorization_token"}
        return {k: v for k, v in payment.items() if k not in hidden}

    def script(self):
        with open(self.server.script, "rb") as source:
            payload = source.read()
        self.send_response(200)
        self.send_header("Content-Type", "application/javascript; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(payload)

    def send(self, status, body):
        payload = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format, *args):
        pass


def main():
    if len(sys.argv) != 3:
        print("usage: api-stub.py <port file> <request log>", file=sys.stderr)
        sys.exit(2)
    server = ThreadingHTTPServer(("0.0.0.0", 0), Handler)
    server.request_log = sys.argv[2]
    server.script = __file__.rsplit("/", 1)[0] + "/wajub-js-stub.js"
    open(server.request_log, "w").close()
    with open(sys.argv[1], "w", encoding="utf-8") as port_file:
        port_file.write(str(server.server_address[1]))
    server.serve_forever()


if __name__ == "__main__":
    main()
