"""Incarca setarile serviciului de sincronizare din config.json.

Serviciul ruleaza in fundal: un server HTTP local (doar 127.0.0.1) pentru
health/wake si un thread worker care ridica randurile din temp_Send_Sql si le
executa pe serverul extern. Worker-ul asteapta pe un eveniment (fara busy-wait),
deci nu incarca procesorul si nu blocheaza calculatorul.
"""

import json
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(BASE_DIR, "config.json")

DEFAULTS = {
    "http_host": "127.0.0.1",
    "http_port": 8757,
    "api_base": "https://127.0.0.1/rual/api",
    "verify_ssl": False,
    "poll_interval_sec": 2.0,
    # 0 = reincearca la infinit (recomandat pentru export: o locatie poate fi
    # offline cateva zile, iar bonurile trebuie sa plece cand revine legatura).
    "max_attempts": 0,
    "backoff_seconds": [5, 15, 30, 60, 300],
    "db_driver": "ODBC Driver 17 for SQL Server",
    "db_options": {"Encrypt": "no", "TrustServerCertificate": "yes"},
    "db_timeout": 10,
    "log_file": "logs/sync-service.log",
}


# Cheile care pot fi modificate la cald (fara repornirea serviciului).
EDITABLE_KEYS = (
    "max_attempts",
    "poll_interval_sec",
    "backoff_seconds",
    "db_timeout",
    "db_driver",
    "db_options",
)


def load_config(path=None):
    path = path or os.path.join(BASE_DIR, "config.json")
    cfg = dict(DEFAULTS)
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as fh:
            cfg.update(json.load(fh))

    log_file = cfg.get("log_file")
    if log_file and not os.path.isabs(log_file):
        cfg["log_file"] = os.path.join(BASE_DIR, log_file)

    return cfg


def editable_config_payload(cfg):
    """Subsetul de configurare care se poate schimba la cald."""
    return {key: cfg.get(key) for key in EDITABLE_KEYS}


def validate_config(cfg):
    """Valideaza cheile editabile. Intoarce mesaj de eroare sau None."""
    try:
        if int(cfg.get("max_attempts", 0)) < 0:
            return "max_attempts trebuie sa fie >= 0 (0 = nelimitat)"
    except (TypeError, ValueError):
        return "max_attempts invalid"

    try:
        if float(cfg.get("poll_interval_sec", 2.0)) <= 0:
            return "poll_interval_sec trebuie sa fie > 0"
    except (TypeError, ValueError):
        return "poll_interval_sec invalid"

    backoffs = cfg.get("backoff_seconds")
    if not isinstance(backoffs, list) or not backoffs:
        return "backoff_seconds trebuie sa fie o lista nevida"
    for value in backoffs:
        try:
            if int(value) <= 0:
                return "backoff_seconds trebuie sa contina numere pozitive"
        except (TypeError, ValueError):
            return "backoff_seconds invalid"

    try:
        if int(cfg.get("db_timeout", 10)) <= 0:
            return "db_timeout trebuie sa fie > 0"
    except (TypeError, ValueError):
        return "db_timeout invalid"

    if not str(cfg.get("db_driver") or "").strip():
        return "db_driver este obligatoriu"
    if cfg.get("db_options") is not None and not isinstance(cfg.get("db_options"), dict):
        return "db_options invalid"

    return None


def save_config(path, new_cfg):
    """Merge-uieste cheile editabile peste configul curent, valideaza si scrie atomic.

    Pastreaza cheile neatinse (http_host, http_port, api_base etc.). Intoarce
    (config_nou, None) sau (None, mesaj_eroare).
    """
    if not isinstance(new_cfg, dict):
        return None, "date de configurare invalide"

    cfg = load_config(path)
    for key in EDITABLE_KEYS:
        if key in new_cfg:
            cfg[key] = new_cfg[key]

    err = validate_config(cfg)
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
