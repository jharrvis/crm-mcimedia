#!/usr/bin/env python3
"""Mock CRM untuk test rig wp-file-integrity.

Endpoint:
  GET  /api/security/status          -> {"status":"ok","service":"crm-security-api",...}
  POST /api/security/events          -> {"status":"ok","received":N,"created":N,"duplicates":0,"errors":[]}
  lainnya                            -> 404

Semua POST body dicatat ke file $MOCK_LOG (satu JSON per baris) supaya test
bisa memverifikasi payload persis. Variabel env:
  MOCK_LOG       path file log (wajib)
  MOCK_MODE      ok | http500 | badjson | reject422 (perilaku respons POST)
"""
import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

LOG = os.environ.get("MOCK_LOG", "/tmp/mock-crm.log")
MODE = os.environ.get("MOCK_MODE", "ok")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):  # sunyi
        pass

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.startswith("/api/security/status"):
            if MODE == "http500":
                self._send(500, {"error": "simulated failure"})
                return
            if MODE == "badjson":
                body = b"<html>PHP Fatal error</html>"
                self.send_response(200)
                self.send_header("Content-Type", "text/html")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return
            self._send(200, {"status": "ok", "service": "crm-security-api",
                             "time": "2026-10-05T00:00:00+08:00",
                             "counts": {"incidents_total": 0, "incidents_open": 0,
                                        "reports_total": 0}})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length)
        auth = self.headers.get("Authorization", "")
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps({"path": self.path, "auth": auth,
                                "body": json.loads(raw or b"{}")},
                               ensure_ascii=False) + "\n")
        if self.path.startswith("/api/security/events"):
            if MODE == "http500":
                self._send(500, {"error": "simulated failure"})
            elif MODE == "badjson":
                body = b"<html>PHP Fatal error</html>"
                self.send_response(200)
                self.send_header("Content-Type", "text/html")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            elif MODE == "reject422":
                self._send(422, {"message": "payload ditolak"})
            else:
                try:
                    n = len(json.loads(raw).get("events", []))
                except Exception:
                    n = 0
                self._send(200, {"status": "ok", "received": n, "created": n,
                                 "duplicates": 0, "errors": []})
        else:
            self._send(404, {"error": "not found"})


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8901
    HTTPServer(("127.0.0.1", port), Handler).serve_forever()