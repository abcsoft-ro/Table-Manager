"""Serviciul de tiparire TableManager.

Expune un mic server HTTP local (doar 127.0.0.1) si porneste worker-ul care
ridica joburi din coada si le trimite la imprimanta / emulator.

Rute:
  GET  /health          - stare serviciu + config rezumat
  GET  /wake            - trezeste worker-ul imediat (dupa enqueue)
  POST /wake            - idem
  GET  /preview/<fisier>- serveste un preview HTML din emulator
  GET  /preview         - ultimul preview generat
"""

import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

from config import load_config
from worker import log, run_loop

STOP = threading.Event()
WAKE = threading.Event()
CFG = load_config()


class Handler(BaseHTTPRequestHandler):
    server_version = "TableManagerPrint/1.0"

    def log_message(self, fmt, *args):  # linistim consola
        pass

    def _json(self, data, code=200):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _html(self, text, code=200):
        body = text.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = urlparse(self.path).path

        if path == "/health":
            self._json({
                "status": "ok",
                "service": "tablemanager-print",
                "http_port": CFG.get("http_port"),
                "api_base": CFG.get("api_base"),
                "preview_dir": CFG.get("preview_dir"),
                "fiscal_spool_dir": CFG.get("fiscal_spool_dir"),
                "printers": CFG.get("printers"),
            })
            return

        if path == "/wake":
            WAKE.set()
            self._json({"status": "ok", "message": "worker trezit"})
            return

        if path in ("/preview", "/preview/"):
            return self._serve_preview("ultimul.html")

        if path.startswith("/preview/"):
            name = os.path.basename(path[len("/preview/"):])
            return self._serve_preview(name)

        self._json({"status": "error", "message": "Ruta necunoscuta"}, 404)

    def do_POST(self):
        if urlparse(self.path).path in ("/wake", "/health"):
            WAKE.set()
            self._json({"status": "ok", "message": "worker trezit"})
            return
        self._json({"status": "error", "message": "Ruta necunoscuta"}, 404)

    def _serve_preview(self, name):
        path = os.path.join(CFG["preview_dir"], name)
        if not os.path.isfile(path):
            self._json({"status": "error", "message": "Preview inexistent"}, 404)
            return
        with open(path, "r", encoding="utf-8") as fh:
            self._html(fh.read())


def main():
    for folder in (CFG["preview_dir"], CFG["fiscal_spool_dir"]):
        try:
            os.makedirs(folder, exist_ok=True)
        except OSError:
            pass
    log_dir = os.path.dirname(CFG.get("log_file") or "")
    if log_dir:
        try:
            os.makedirs(log_dir, exist_ok=True)
        except OSError:
            pass

    log("Serviciu de tiparire pornit pe %s:%s" % (CFG["http_host"], CFG["http_port"]), CFG)
    thread = threading.Thread(target=run_loop, args=(CFG, STOP, WAKE), daemon=True)
    thread.start()

    server = ThreadingHTTPServer((CFG["http_host"], CFG["http_port"]), Handler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        STOP.set()
        WAKE.set()
        server.server_close()
        log("Serviciu de tiparire oprit.", CFG)


if __name__ == "__main__":
    main()
