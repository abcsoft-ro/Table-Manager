"""Incarca setarile serviciului de tiparire din config.json."""

import json
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

DEFAULTS = {
    "http_host": "127.0.0.1",
    "http_port": 8756,
    "api_base": "https://127.0.0.1/rual/api",
    "verify_ssl": False,
    "poll_interval_sec": 1.0,
    "max_attempts": 50,
    "backoff_seconds": [5, 15, 30, 60, 300],
    "charset": "ascii",
    "receipt_width": 42,
    "preview_dir": "preview",
    "fiscal_spool_dir": "spool/fiscal",
    "default_printer_nr": None,
    "printers": {},
    "nota_target": {"target": "preview"},
    "fiscal_target": {"target": "file"},
    "log_file": "logs/print-service.log",
    "kitchen_title": "BON COMANDA",
    "nota_title": "NOTA DE PLATA",
}


def load_config(path=None):
    path = path or os.path.join(BASE_DIR, "config.json")
    cfg = dict(DEFAULTS)
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as fh:
            cfg.update(json.load(fh))

    for key in ("preview_dir", "fiscal_spool_dir", "log_file"):
        value = cfg.get(key)
        if value and not os.path.isabs(value):
            cfg[key] = os.path.join(BASE_DIR, value)

    return cfg


def printer_target(cfg, printer_nr):
    """Intoarce configuratia tintei pentru un NrLogic din tblKP."""
    printers = cfg.get("printers") or {}
    if printer_nr is not None:
        target = printers.get(str(printer_nr))
        if target:
            return target
    default_nr = cfg.get("default_printer_nr")
    if default_nr is not None:
        target = printers.get(str(default_nr))
        if target:
            return target
    return {"target": "preview"}
