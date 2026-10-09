"""Mock FiscalNet - simulator al casei de marcat pentru testare.

Urmareste folderul de comenzi (implicit logs/mock_fiscal_net/comenzi), iar pentru
fiecare fisier `pos_tm_*.txt` scris de POS genereaza in folderul de raspuns un
fisier cu ACELASI nume (ca driverul FiscalNet real), care contine:
  BONOK=1          (bon emis corect)
  NRBON=<nr>       (numarul bonului fiscal)
sau, la eroare:
  BONOK=0
  ERRCODE=<cod>
  ERRINFO=<info>

Moduri:
  ok       - bon emis corect (BONOK=1 + NRBON)
  err      - eroare (BONOK=0 + ERRCODE/ERRINFO)
  pos      - BONOK=-1 (nu s-a putut identifica raspunsul terminalului bancar)
  silent   - nu scrie nimic (simuleaza casa care nu raspunde)
  random   - alege aleator ok/err/pos dupa probabilitati

Modul este fix pentru toata rularea (in afara de `random`).

Nu face parte din aplicatia de productie: se porneste manual, din .venv, doar
pentru teste. Ex.:
  .venv\\Scripts\\python.exe tools\\mock_fiscalnet.py --mode ok --delay 2
  .venv\\Scripts\\python.exe tools\\mock_fiscalnet.py --mode random --err-prob 0.3
"""

import argparse
import glob
import json
import os
import random
import sys
import time

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(BASE_DIR)
DEFAULT_COMENZI = os.path.join(PROJECT_DIR, "logs", "mock_fiscal_net", "comenzi")
DEFAULT_RASPUNS = os.path.join(PROJECT_DIR, "logs", "mock_fiscal_net", "raspuns")
DEFAULT_STATE = os.path.join(PROJECT_DIR, "logs", "mock_fiscal_net", "state.json")
DEFAULT_LOG = os.path.join(PROJECT_DIR, "logs", "mock_fiscalnet.log")

# Fisierul de log (setat in main). Scriem si in el, nu doar in consola, ca sa
# putem diagnostica si daca fereastra de consola se inchide.
LOG_FILE = None


def log(msg):
    line = "[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg)
    # print() nu trebuie NICIODATA sa arunce (consola inchisa / codepage), altfel
    # un esec in handler-ul de erori ar opri tot mock-ul.
    try:
        print(line, flush=True)
    except Exception:  # noqa: BLE001
        pass
    if LOG_FILE:
        try:
            with open(LOG_FILE, "a", encoding="utf-8") as fh:
                fh.write(line + "\n")
        except OSError:
            pass


def load_state(path, nr0):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        if isinstance(data, dict):
            data.setdefault("processed", [])
            data.setdefault("nr", nr0)
            return data
    except (OSError, ValueError):
        pass
    return {"processed": [], "nr": nr0}


def save_state(path, state):
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(state, fh, ensure_ascii=False, indent=2)
    except OSError as exc:
        log("Nu pot salva starea: %s" % exc)


def _write_cp1250(path, text):
    # Scriere binara: pe Windows modul text ar transforma "\n" in "\r\n" si astfel
    # "\r\n" ar deveni "\r\r\n" (linii goale intre liniile fisierului).
    with open(path, "wb") as fh:
        fh.write(text.encode("cp1250", "replace"))


def build_response(mode, state):
    if mode == "ok":
        nr = int(state["nr"]) + 1
        state["nr"] = nr
        return "BONOK=1\r\nNRBON=%d\r\n" % nr
    if mode == "pos":
        return "BONOK=-1\r\n"
    # err
    return "BONOK=0\r\nERRCODE=1\r\nERRINFO=COTA_TVA_DEZACTIVATA\r\n"


def process_command(path, state, args):
    cmd_name = os.path.basename(path)
    time.sleep(max(0.0, args.delay))

    mode = args.mode
    if mode == "random":
        r = random.random()
        if r < args.err_prob:
            mode = "err"
        elif r < args.err_prob + args.pos_prob:
            mode = "pos"
        else:
            mode = "ok"
        log("Comanda %s: mod random -> %s" % (cmd_name, mode))

    if mode == "silent":
        log("Comanda %s: mod silent (nu scriu niciun raspuns)." % cmd_name)
        return

    text = build_response(mode, state)
    # Raspunsul FiscalNet are acelasi nume ca fisierul de comanda.
    _write_cp1250(os.path.join(args.raspuns, cmd_name), text)
    log("Comanda %s -> raspuns %s (%s)" % (cmd_name, cmd_name, mode))


def main():
    ap = argparse.ArgumentParser(description="Mock FiscalNet pentru testare.")
    ap.add_argument("--comenzi", default=DEFAULT_COMENZI, help="folderul de comenzi urmarit")
    ap.add_argument("--raspuns", default=DEFAULT_RASPUNS, help="folderul de raspuns")
    ap.add_argument("--state", default=DEFAULT_STATE, help="fisierul de stare (NRBON)")
    ap.add_argument("--mode", default="ok",
                    choices=["ok", "err", "pos", "silent", "random"],
                    help="comportamentul simularii (random = aleator dupa probabilitati)")
    ap.add_argument("--err-prob", type=float, default=0.25,
                    help="probabilitatea de eroare in modul random (implicit 0.25)")
    ap.add_argument("--pos-prob", type=float, default=0.10,
                    help="probabilitatea de BONOK=-1 in modul random (implicit 0.10)")
    ap.add_argument("--delay", type=float, default=2.0, help="intarzierea raspunsului (secunde)")
    ap.add_argument("--nr", type=int, default=4100, help="numarul bonului initial")
    ap.add_argument("--poll", type=float, default=0.5, help="intervalul de verificare (secunde)")
    ap.add_argument("--once", action="store_true", help="proceseaza o singura comanda si iese")
    ap.add_argument("--keep", action="store_true",
                    help="nu sterge fisierul de comanda dupa procesare (implicit il sterge)")
    args = ap.parse_args()

    # Limiteaza probabilitatile, ca sa rămâna mereu o sansa si pentru "ok".
    args.err_prob = max(0.0, min(1.0, args.err_prob))
    args.pos_prob = max(0.0, min(1.0 - args.err_prob, args.pos_prob))

    os.makedirs(args.comenzi, exist_ok=True)
    os.makedirs(args.raspuns, exist_ok=True)

    global LOG_FILE
    LOG_FILE = os.path.join(PROJECT_DIR, "logs", "mock_fiscalnet.log")

    state = load_state(args.state, args.nr)
    processed = set(state.get("processed", []))

    log("Mock FiscalNet pornit (mod=%s, comenzi=%s, raspuns=%s)."
        % (args.mode, args.comenzi, args.raspuns))
    log("Comanda noua -> raspuns in folderul de raspuns (trebuie sa fie acelasi "
        "cu tblSet.CaleFisierRaspunsECR din Setari).")

    while True:
        # Bucla nu trebuie sa se opreasca din nicio eroare neprevazuta.
        try:
            found = False
            for path in sorted(glob.glob(os.path.join(args.comenzi, "pos_tm_*.txt"))):
                name = os.path.basename(path)
                if name in processed:
                    continue
                try:
                    process_command(path, state, args)
                except Exception as exc:  # noqa: BLE001 - o comanda defecta nu opreste mock-ul
                    log("Eroare la procesarea %s: %s" % (name, exc))
                    continue  # NU marcam procesat -> se reincearca
                # Marcam procesat (si salvam starea) doar dupa ce raspunsul a fost scris.
                processed.add(name)
                state["processed"] = sorted(processed)
                save_state(args.state, state)
                if not args.keep:
                    try:
                        os.remove(path)
                    except OSError as exc:
                        log("Nu pot sterge comanda %s: %s" % (name, exc))
                found = True
                if args.once:
                    return
            if args.once and not found:
                time.sleep(args.poll)
                continue
            time.sleep(args.poll)
        except Exception as exc:  # noqa: BLE001 - mock-ul nu trebuie sa moara
            log("Eroare neasteptata in bucla: %s" % exc)
            time.sleep(args.poll)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
