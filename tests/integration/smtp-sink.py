#!/usr/bin/env python3
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# A mail server that keeps what it is sent, for the integration tests of the
# e-mails the module sends (tests/integration/run-finish.sh): it answers
# SMTP as a server that accepts everything, and writes each message, as
# JSON (from, to, subject, text), one per line, to a file. Listens on
# 0.0.0.0 (the shops reach it at host.containers.internal), on a free port,
# written to a file.
#
#     python3 smtp-sink.py <port file> <messages file>

import email
import email.policy
import json
import socketserver
import sys
import threading

PORT_FILE, MESSAGES = sys.argv[1], sys.argv[2]
LOCK = threading.Lock()


def keep(sender, recipients, data):
    message = email.message_from_bytes(data, policy=email.policy.default)
    parts = []
    for part in message.walk():
        if part.get_content_maintype() == "text":
            try:
                parts.append({"type": part.get_content_type(), "body": part.get_content()})
            except (LookupError, ValueError):
                parts.append({"type": part.get_content_type(), "body": part.get_payload(decode=True).decode("utf-8", "replace")})
    entry = {"from": sender, "to": recipients, "subject": str(message.get("Subject", "")), "parts": parts}
    with LOCK, open(MESSAGES, "a", encoding="utf-8") as out:
        out.write(json.dumps(entry, ensure_ascii=False) + "\n")


class Session(socketserver.StreamRequestHandler):
    def say(self, line):
        self.wfile.write((line + "\r\n").encode())
        self.wfile.flush()

    def handle(self):
        self.say("220 wajub-sink ESMTP")
        sender, recipients = "", []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            line = raw.decode("utf-8", "replace").rstrip("\r\n")
            verb = line.split(" ", 1)[0].upper()
            if verb in ("EHLO", "HELO"):
                self.wfile.write(b"250-wajub-sink\r\n250 8BITMIME\r\n")
                self.wfile.flush()
            elif verb == "MAIL":
                sender, recipients = line.split(":", 1)[1].strip().strip("<>"), []
                self.say("250 OK")
            elif verb == "RCPT":
                recipients.append(line.split(":", 1)[1].strip().strip("<>"))
                self.say("250 OK")
            elif verb == "DATA":
                self.say("354 End data with <CR><LF>.<CR><LF>")
                chunks = []
                while True:
                    chunk = self.rfile.readline()
                    if not chunk or chunk in (b".\r\n", b".\n"):
                        break
                    chunks.append(chunk[1:] if chunk.startswith(b"..") else chunk)
                keep(sender, recipients, b"".join(chunks))
                self.say("250 OK: kept")
            elif verb == "QUIT":
                self.say("221 Bye")
                return
            else:
                # RSET, NOOP, AUTH and the rest: accepted.
                self.say("250 OK")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


with Server(("0.0.0.0", 0), Session) as server:
    with open(PORT_FILE, "w", encoding="utf-8") as out:
        out.write(str(server.server_address[1]))
    server.serve_forever()
