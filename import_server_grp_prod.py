#!/usr/bin/env python3
"""Import produse/grupe de pe server (procedura stocata dbo.ImportProd).

Rulat de Pornire-POS-Kiosk.bat la pornirea aplicatiei. Ruleaza doar cand
tblSet.Server = 1.

Comportamentul cand exista linii in tblNoteD este controlat de parametrul
--if-notes (se poate schimba usor in bat, fara a modifica scriptul):
    --if-notes=run   (implicit) executa ImportProd indiferent de tblNoteD
    --if-notes=skip  sare peste import daca tblNoteD are linii (se ruleaza
                     dupa raportul Z)

Se conecteaza direct la baza locala Rual cu pyodbc. Credentialele locale sunt
citite din api/db.local.php (aceeasi sursa ca PHP); configuratia sursei externe
se citeste din tblConectare (ID = 1).

Exemple:
    python import_server_grp_prod.py
    python import_server_grp_prod.py --if-notes=skip
"""

import argparse
import datetime
import os
import re
import sys

try:
    import pyodbc
except ImportError:  # pragma: no cover
    print("pyodbc nu este instalat (pip install pyodbc)", file=sys.stderr)
    sys.exit(3)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
LOG_DIR = os.path.join(BASE_DIR, "logs")
LOG_FILE = os.path.join(LOG_DIR, "import_server_grp_prod.log")


def log(message):
    line = "%s  %s" % (datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), message)
    print(line)
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except OSError:
        pass


def read_db_config():
    """Citeste Server/Database/UID/PWD din api/db.local.php."""
    path = os.path.join(BASE_DIR, "api", "db.local.php")
    if not os.path.isfile(path):
        return None
    with open(path, "r", encoding="utf-8") as fh:
        text = fh.read()

    def field(key):
        match = re.search(r"['\"]%s['\"]\s*=>\s*['\"]([^'\"]*)['\"]" % key, text)
        return match.group(1) if match else ""

    cfg = {
        "server": field("Server"),
        "database": field("Database"),
        "uid": field("UID"),
        "pwd": field("PWD"),
    }
    if not cfg["server"] or not cfg["database"]:
        return None
    return cfg


def pick_driver():
    preferred = [
        "ODBC Driver 18 for SQL Server",
        "ODBC Driver 17 for SQL Server",
        "ODBC Driver 13 for SQL Server",
        "SQL Server Native Client 11.0",
        "SQL Server",
    ]
    available = list(pyodbc.drivers())
    for name in preferred:
        if name in available:
            return name
    return "SQL Server"


def build_local_connection_string(cfg):
    parts = [
        "DRIVER={%s}" % pick_driver(),
        "SERVER=%s" % cfg["server"],
        "DATABASE=%s" % cfg["database"],
        "UID=%s" % cfg["uid"],
        "PWD=%s" % cfg["pwd"],
        "Encrypt=no",
        "TrustServerCertificate=yes",
    ]
    return ";".join(parts) + ";"


def consume(cur):
    """Consuma toate seturile de rezultate ale procedurii."""
    while True:
        try:
            cur.fetchall()
        except pyodbc.ProgrammingError:
            pass
        if not cur.nextset():
            break


def main(note_mode="run"):
    cfg = read_db_config()
    if not cfg:
        log("EROARE: nu pot citi api/db.local.php (Server/Database).")
        return 3

    try:
        cn = pyodbc.connect(build_local_connection_string(cfg), autocommit=True, timeout=10)
    except pyodbc.Error as exc:
        log("EROARE conexiune la baza locala: %s" % exc)
        return 3

    try:
        cur = cn.cursor()

        cur.execute("SELECT TOP 1 Value FROM tblSet WHERE Setting = 'Server'")
        row = cur.fetchone()
        server_flag = str(row[0]).strip() if row and row[0] is not None else "0"
        if server_flag != "1":
            log("Server=0 -> import sarit.")
            return 0

        cur.execute("SELECT COUNT(*) FROM tblNoteD")
        row = cur.fetchone()
        note = int(row[0]) if row else 0
        if note > 0 and note_mode == "skip":
            log("tblNoteD are %d linii -> import sarit (--if-notes=skip; se ruleaza dupa raportul Z)." % note)
            return 0
        if note > 0:
            log("tblNoteD are %d linii -> importul continua (--if-notes=run)." % note)

        cur.execute(
            "SELECT TOP 1 ServerIP, ServerName, UserName, Password FROM tblConectare WHERE ID = 1"
        )
        row = cur.fetchone()
        if not row:
            log("EROARE: nu exista configurarea tblConectare ID=1.")
            return 3
        server = (row[0] or "").strip()
        instance = (row[1] or "").strip()
        user = (row[2] or "sa").strip()
        password = row[3] or ""
        ip = server if (not instance or instance.lower() == server.lower()) else server + "\\" + instance
        if not ip:
            log("EROARE: ServerIP lipsa in tblConectare.")
            return 3

        log("Import produse/grupe de pe %s ..." % ip)
        cur.execute("EXEC dbo.ImportProd @IP = ?, @user = ?, @PassWord = ?", ip, user, password)
        consume(cur)
        log("Import finalizat cu succes (server %s)." % ip)
        return 0
    except pyodbc.Error as exc:
        log("EROARE la import: %s" % exc)
        return 1
    finally:
        try:
            cn.close()
        except Exception:  # noqa: BLE001
            pass


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Import produse/grupe (dbo.ImportProd).")
    parser.add_argument(
        "--if-notes",
        choices=["run", "skip"],
        default="run",
        help="run (implicit) = executa ImportProd chiar daca tblNoteD are linii; "
             "skip = sari daca are linii (se ruleaza dupa raportul Z).",
    )
    args = parser.parse_args()
    sys.exit(main(args.if_notes))
