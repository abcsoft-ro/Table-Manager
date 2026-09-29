"""Incarca setarile serviciului de tiparire din config.json."""

import json
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(BASE_DIR, "config.json")

# Cheile de configurare a tiparirii editabile din ecranul Setari > Imprimante.
PRINT_KEYS = ("printers", "nota_target", "raport_target", "fiscal_target", "default_printer_nr")
TARGET_KINDS = ("preview", "file", "windows", "network")

DEFAULTS = {
    "http_host": "127.0.0.1",
    "http_port": 8756,
    # Gol = se deduce automat (http://127.0.0.1/<folder-proiect>/api), ca sa
    # functioneze indiferent cum se numeste folderul de instalare. O valoare
    # ne-goala in config.json este folosita ca atare (override).
    "api_base": "",
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


def default_api_base():
    """URL-ul API-ului PHP, dedus din numele folderului proiectului.

    Serviciul sta in <proiect>/print-service, iar proiectul e direct sub
    DocumentRoot, deci calea web este /<nume-folder>/api. Ex.: daca folderul
    este "myproject", rezulta http://127.0.0.1/myproject/api.
    """
    project = os.path.basename(os.path.dirname(BASE_DIR))
    return "http://127.0.0.1/%s/api" % project


def load_config(path=None):
    path = path or os.path.join(BASE_DIR, "config.json")
    cfg = dict(DEFAULTS)
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as fh:
            cfg.update(json.load(fh))

    if not str(cfg.get("api_base") or "").strip():
        cfg["api_base"] = default_api_base()

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


# --------------------------------------------------------------- config print

def print_config_payload(cfg):
    """Subsetul de configurare expus/editat din ecranul de imprimante."""
    return {
        "printers": cfg.get("printers") or {},
        "nota_target": cfg.get("nota_target"),
        "raport_target": cfg.get("raport_target"),
        "fiscal_target": cfg.get("fiscal_target"),
        "default_printer_nr": cfg.get("default_printer_nr"),
    }


def _validate_target(t):
    if not isinstance(t, dict):
        return "configuratie de tinta invalida"
    kind = str(t.get("target") or "preview").strip()
    if kind not in TARGET_KINDS:
        return "tip de tinta necunoscut: %s" % kind
    if kind == "network":
        host = str(t.get("host") or "").strip()
        if not host:
            return "tinta de retea fara host/IP"
        try:
            port = int(t.get("port", 9100))
        except (TypeError, ValueError):
            return "port invalid"
        if port < 1 or port > 65535:
            return "port in afara intervalului (1-65535)"
    if kind == "windows":
        name = str(t.get("name") or t.get("printer") or "").strip()
        if not name:
            return "tinta Windows fara numele imprimantei"
    return None


def validate_print_config(cfg):
    """Valideaza cheile de tiparire. Intoarce mesaj de eroare sau None."""
    printers = cfg.get("printers")
    if printers is not None and not isinstance(printers, dict):
        return "lista imprimantelor invalida"
    for key, target in (printers or {}).items():
        err = _validate_target(target)
        if err:
            return "imprimanta %s: %s" % (key, err)
    for key in ("nota_target", "raport_target", "fiscal_target"):
        target = cfg.get(key)
        if target is not None:
            err = _validate_target(target)
            if err:
                return "%s: %s" % (key, err)
    return None


def save_print_config(path, new_print):
    """Merge-uieste noile chei peste configul curent, valideaza si scrie atomic.

    Pastreaza cheile neatinse (http_port, api_base, etc.). Intoarce
    (config_nou, None) sau (None, mesaj_eroare).
    """
    if not isinstance(new_print, dict):
        return None, "date de configurare invalide"

    cfg = load_config(path)
    for key in PRINT_KEYS:
        if key in new_print:
            cfg[key] = new_print[key]

    err = validate_print_config(cfg)
    if err:
        return None, err

    tmp = path + ".tmp"
    try:
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(cfg, fh, indent=2, ensure_ascii=False)
            fh.write("\n")
        os.replace(tmp, path)
    except OSError as exc:
        return None, "nu pot scrie config.json: %s" % exc

    return cfg, None
