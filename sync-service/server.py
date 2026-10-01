"""Serviciul de sincronizare TableManager (export catre serverul extern).

Expune un mic server HTTP local (doar 127.0.0.1) si porneste, in fundal, un
thread worker care ridica randurile din temp_Send_Sql (Preluat = 0), executa
procedura pe serverul din tblConectare si marcheaza Preluat = 1.

Rute:
  GET  /health          - stare serviciu + config rezumat
  GET  /wake            - trezeste worker-ul imediat (dupa enqueue)
  POST /wake            - idem
  GET  /config          - configul editabil la cald
  POST /config          - valideaza + scrie atomic config.json + hot-reload
"""

import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

from config import CONFIG_PATH, editable_config_payload, load_config, save_config
from worker import log, run_loop

STOP = threading.Event()
WAKE = threading.Event()
CFG = load_config()


class Handler(BaseHTTPRequestHandler):
    server_version = "TableManagerSync/1.0"

    def log_message(self, fmt, *args):  # linistim consola
        pass

    def _json(self, data, code=200):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
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
            cfg = load_config()
            self._json({
                "status": "ok",
                "service": "tablemanager-sync",
                "pid": os.getpid(),
                "http_port": cfg.get("http_port"),
                "api_base": cfg.get("api_base"),
                "db_driver": cfg.get("db_driver"),
                "max_attempts": cfg.get("max_attempts"),
                "poll_interval_sec": cfg.get("poll_interval_sec"),
            })
            return

        if path == "/config":
            self._json({"status": "success", "config": editable_config_payload(load_config())})
            return

        if path == "/wake":
            WAKE.set()
            self._json({"status": "ok", "message": "worker trezit"})
            return

        self._json({"status": "error", "message": "Ruta necunoscuta"}, 404)

    def do_POST(self):
        path = urlparse(self.path).path

        if path in ("/wake", "/health"):
            WAKE.set()
            self._json({"status": "ok", "message": "worker trezit"})
            return

        if path == "/shutdown":
            # Oprire linistita, declansata din Setari > Servicii (api/services.php).
            self._json({"status": "ok", "message": "Oprire serviciu de sincronizare"})
            threading.Thread(target=self.server.shutdown, daemon=True).start()
            return

        if path == "/config":
            data = self._read_json()
            new_cfg = data.get("config", data)
            cfg, err = save_config(CONFIG_PATH, new_cfg)
            if err is not None:
                self._json({"status": "error", "message": err}, 400)
                return
            # Worker-ul re-citeste config.json la fiecare iteratie, deci
            # modificarile se aplica fara repornire (hot-reload).
            WAKE.set()
            log("Config de sincronizare actualizat (hot-reload).", cfg)
            self._json({"status": "success", "message": "Configurare salvata",
                        "config": editable_config_payload(cfg)})
            return

        self._json({"status": "error", "message": "Ruta necunoscuta"}, 404)


def main():
    log_dir = os.path.dirname(CFG.get("log_file") or "")
    if log_dir:
        try:
            os.makedirs(log_dir, exist_ok=True)
        except OSError:
            pass

    log("Serviciu de sincronizare pornit pe %s:%s" % (CFG["http_host"], CFG["http_port"]), CFG)
    # Worker-ul primeste load_config ca provider, deci re-citeste config.json la
    # fiecare iteratie: modificarile se aplica la cald, fara repornirea serviciului.
    thread = threading.Thread(target=run_loop, args=(load_config, STOP, WAKE), daemon=True)
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
        log("Serviciu de sincronizare oprit.", CFG)


if __name__ == "__main__":
    main()
