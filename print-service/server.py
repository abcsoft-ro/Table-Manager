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

from config import CONFIG_PATH, load_config, print_config_payload, save_print_config
from targets import deliver
from worker import build_fiscal_bytes, build_test, log, run_loop

STOP = threading.Event()
WAKE = threading.Event()
CFG = load_config()


def reload_config():
    """Re-citeste config.json (hot-reload, fara repornire)."""
    global CFG
    CFG = load_config()
    return CFG


def _windows_printers():
    try:
        import win32print  # type: ignore
    except ImportError:
        return {"available": False, "printers": []}
    names = []
    try:
        flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        for p in win32print.EnumPrinters(flags, None, 2):
            name = p.get("pPrinterName")
            if name:
                names.append(name)
    except Exception:  # noqa: BLE001 - nu blocam ecranul daca lista esueaza
        return {"available": False, "printers": []}
    names.sort(key=lambda s: s.lower())
    return {"available": True, "printers": names}


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

    def _read_json(self):
        try:
            length = int(self.headers.get("Content-Length") or 0)
        except (TypeError, ValueError):
            length = 0
        if length <= 0:
            return {}
        raw = self.rfile.read(length)
        try:
            data = json.loads(raw.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            return {}
        return data if isinstance(data, dict) else {}

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

        if path == "/config":
            self._json({"status": "success", "config": print_config_payload(CFG)})
            return

        if path == "/printers":
            info = _windows_printers()
            self._json({"status": "success", "available": info["available"], "printers": info["printers"]})
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
        path = urlparse(self.path).path

        if path in ("/wake", "/health"):
            WAKE.set()
            self._json({"status": "ok", "message": "worker trezit"})
            return

        if path == "/config":
            data = self._read_json()
            new_print = data.get("config", data)
            cfg, err = save_print_config(CONFIG_PATH, new_print)
            if err is not None:
                self._json({"status": "error", "message": err}, 400)
                return
            reload_config()
            WAKE.set()
            log("Config de tiparire actualizat (hot-reload).", CFG)
            self._json({"status": "success", "message": "Configurare salvata", "config": print_config_payload(CFG)})
            return

        if path == "/test":
            data = self._read_json()
            which = str(data.get("which") or "").strip().lower()
            target = None
            if which in ("nota", "raport", "fiscal"):
                target = CFG.get(which + "_target")
            elif isinstance(data.get("target"), dict):
                target = data.get("target")
            if not target:
                self._json({"status": "error", "message": "Tinta de test invalida"}, 400)
                return
            try:
                if which == "fiscal":
                    payload = build_fiscal_bytes({"text": "TEST FISCAL\r\n"}, CFG)
                else:
                    payload = build_test(CFG)
                message = deliver(target, payload, CFG, job_id=0)
            except Exception as exc:  # noqa: BLE001 - raportam orice eroare de livrare
                self._json({"status": "error", "message": str(exc)}, 500)
                return
            self._json({"status": "success", "message": message})
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
    # Worker-ul primeste un provider de config, ca sa vada modificarile la cald.
    thread = threading.Thread(target=run_loop, args=(lambda: CFG, STOP, WAKE), daemon=True)
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
