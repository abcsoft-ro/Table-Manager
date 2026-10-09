"""Mock FiscalWire - simulator al casei de marcat pentru testare.

Urmareste folderul de comenzi (implicit logs/mock_fiscal/comenzi), iar pentru
fiecare fisier `pos_tm_*.inp` scris de POS genereaza fisierele de raspuns pe
care le-ar genera driverul FiscalWire real: `.txt` (copie a bonului cu status
pe fiecare linie) si `.nrb` (confirmarea bonului, cu Z/NR/BF/UNIQUE_ID).

Moduri:
  ok       - bon emis corect (`.txt` toate liniile Ok + `.nrb` OK;Z=..|NR=..)
  err      - o linie de comanda respinsa (`.txt` cu Er, fara `.nrb`)
  canceled - bon anulat (`.nrb` cu CANCELED)
  silent   - nu scrie nimic (simuleaza casa care nu raspunde)
  random   - alege aleator ok/err/canceled dupa --err-prob si --canceled-prob

Modul este fix pentru toata rularea (in afara de `random`). Daca pornesti
mock-ul cu `--mode err`, va raspunde cu eroare la fiecare comanda; pentru succes
foloseste `--mode ok` (implicit) sau `--mode random`.

Nu face parte din aplicatia de productie: se porneste manual, din .venv, doar
pentru teste. Ex.:
  .venv\\Scripts\\python.exe tools\\mock_fiscalwire.py --mode ok --delay 2
  .venv\\Scripts\\python.exe tools\\mock_fiscalwire.py --mode silent
  .venv\\Scripts\\python.exe tools\\mock_fiscalwire.py --mode err
  .venv\\Scripts\\python.exe tools\\mock_fiscalwire.py --mode random --err-prob 0.3
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
DEFAULT_COMENZI = os.path.join(PROJECT_DIR, "logs", "mock_fiscal", "comenzi")
DEFAULT_RASPUNS = os.path.join(PROJECT_DIR, "logs", "mock_fiscal", "raspuns")
DEFAULT_STATE = os.path.join(PROJECT_DIR, "logs", "mock_fiscal", "state.json")


def log(msg):
    print("[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg), flush=True)


def load_state(path, z0, nr0):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        if isinstance(data, dict):
            data.setdefault("processed", [])
            data.setdefault("z", z0)
            data.setdefault("nr", nr0)
            data.setdefault("bf", 1)
            return data
    except (OSError, ValueError):
        pass
    return {"processed": [], "z": z0, "nr": nr0, "bf": 1}


def save_state(path, state):
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(state, fh, ensure_ascii=False, indent=2)
    except OSError as exc:
        log("Nu pot salva starea: %s" % exc)


def read_command(path):
    with open(path, "r", encoding="cp1250", errors="replace") as fh:
        return fh.read()


def split_command(lines):
    """Intoarce listele (prefix_fields, rest_after_semicolon, tip) pentru fiecare linie."""
    out = []
    for ln in lines:
        if ln == "":
            continue
        if ";" in ln:
            head, rest = ln.split(";", 1)
        else:
            head, rest = ln, ""
        parts = head.split(",")
        tip = (parts[0] if parts else "").strip().upper()
        out.append((parts, rest, tip))
    return out


def build_txt(lines, serie, mode):
    result = []
    line_no = 0
    errored = False
    for parts, rest, tip in split_command(lines):
        line_no += 1
        if len(parts) >= 5:
            parts[2] = serie
            parts[3] = str(line_no)
            parts[4] = "Ok"
        status = "Ok"
        if mode == "err" and not errored and tip == "S":
            status = "Er"
            errored = True
            if len(parts) >= 5:
                parts[4] = status
            rest = rest.rstrip() + "COTA_TVA_DEZACTIVATA,"
        result.append(",".join(parts) + ";" + rest)
    return "\r\n".join(result) + "\r\n"


def build_nrb(cmd_name, serie, z, nr, bf, lines):
    ts = time.strftime("%Y_%m_%d_%H_%M_%S") + "_000"
    header = "OK;Z=%d|NR=%d|UNIQUE_ID=%s|%s|BF=%d" % (z, nr, cmd_name, ts, bf)

    # Liniile de plata din comenzile T,<..>;COD;SUMA;...
    nrb_lines = [header]
    for parts, rest, tip in split_command(lines):
        if tip != "T":
            continue
        fields = rest.split(";")
        if len(fields) < 2:
            continue
        cod = fields[0].strip()
        suma = fields[1].strip()
        if cod == "" or suma == "":
            continue
        nume = {"0": "CASH", "1": "CARD", "2": "CEC", "3": "TICKET"}.get(cod, "CASH")
        nrb_lines.append(
            "NumeTipPlata=%s;CodTipPlata=%s;Suma=%s;ReceiptNo=|AuthCode=|RRN=|"
            "CardNo=|BatchNo=|CardHolder=|CardCompany=|PaymentDate=" % (nume, cod, suma)
        )
    return "\r\n".join(nrb_lines) + "\r\n"


def _write_cp1250(path, text):
    # Scriere binara: pe Windows modul text ar transforma "\n" in "\r\n" si astfel
    # "\r\n" ar deveni "\r\r\n" (linii goale intre liniile fisierului).
    with open(path, "wb") as fh:
        fh.write(text.encode("cp1250", "replace"))


def write_response(raspuns_dir, cmd_name, txt, nrb):
    base = os.path.splitext(cmd_name)[0]
    stamp = time.strftime("%Y-%m-%d__%H-%M-%S")
    out_base = "%s__%s" % (base, stamp)
    if txt is not None:
        _write_cp1250(os.path.join(raspuns_dir, out_base + ".txt"), txt)
    if nrb is not None:
        _write_cp1250(os.path.join(raspuns_dir, out_base + ".nrb"), nrb)
    return out_base


def process_command(path, state, args):
    cmd_name = os.path.basename(path)
    try:
        lines = read_command(path).replace("\r\n", "\n").replace("\r", "\n").split("\n")
    except OSError as exc:
        log("Nu pot citi comanda %s: %s" % (cmd_name, exc))
        return

    time.sleep(max(0.0, args.delay))

    mode = args.mode
    if mode == "random":
        # Alege aleator un raspuns: ok / err / canceled dupa probabilitati.
        r = random.random()
        if r < args.err_prob:
            mode = "err"
        elif r < args.err_prob + args.canceled_prob:
            mode = "canceled"
        else:
            mode = "ok"
        log("Comanda %s: mod random -> %s" % (cmd_name, mode))

    if mode == "silent":
        log("Comanda %s: mod silent (nu scriu niciun raspuns)." % cmd_name)
        return

    txt = build_txt(lines, args.serie, mode)
    nrb = None
    if mode == "canceled":
        ts = time.strftime("%Y_%m_%d_%H_%M_%S") + "_000"
        nrb = "CANCELED;||UNIQUE_ID=%s|%s|\r\n" % (cmd_name, ts)
    elif mode == "err":
        txt = build_txt(lines, args.serie, "err")
        nrb = None
    else:  # ok
        nr = int(state["nr"]) + 1
        bf = int(state["bf"]) + 1
        state["nr"] = nr
        state["bf"] = bf
        nrb = build_nrb(cmd_name, args.serie, int(state["z"]), nr, bf, lines)

    out_base = write_response(args.raspuns, cmd_name, txt, nrb)
    log("Comanda %s -> %s (.txt%s)" % (cmd_name, out_base, ", .nrb" if nrb else ""))
    save_state(args.state, state)


def main():
    ap = argparse.ArgumentParser(description="Mock FiscalWire pentru testare.")
    ap.add_argument("--comenzi", default=DEFAULT_COMENZI, help="folderul de comenzi urmarit")
    ap.add_argument("--raspuns", default=DEFAULT_RASPUNS, help="folderul de raspuns")
    ap.add_argument("--state", default=DEFAULT_STATE, help="fisierul de stare (Z/NR/BF)")
    ap.add_argument("--mode", default="ok",
                    choices=["ok", "err", "canceled", "silent", "random"],
                    help="comportamentul simularii (random = aleator dupa probabilitati)")
    ap.add_argument("--err-prob", type=float, default=0.25,
                    help="probabilitatea de eroare in modul random (implicit 0.25)")
    ap.add_argument("--canceled-prob", type=float, default=0.10,
                    help="probabilitatea de anulare in modul random (implicit 0.10)")
    ap.add_argument("--delay", type=float, default=2.0, help="intarzierea raspunsului (secunde)")
    ap.add_argument("--serie", default="DB4090037802", help="seria echipamentului fiscal")
    ap.add_argument("--z", type=int, default=28, help="numarul Z initial")
    ap.add_argument("--nr", type=int, default=4100, help="numarul bonului initial")
    ap.add_argument("--poll", type=float, default=0.5, help="intervalul de verificare (secunde)")
    ap.add_argument("--once", action="store_true", help="proceseaza o singura comanda si iese")
    ap.add_argument("--keep", action="store_true",
                    help="nu sterge fisierul de comanda dupa procesare (implicit il sterge, ca FiscalWire)")
    args = ap.parse_args()

    # Limiteaza probabilitatile, ca sa rămâna mereu o sansa si pentru "ok".
    args.err_prob = max(0.0, min(1.0, args.err_prob))
    args.canceled_prob = max(0.0, min(1.0 - args.err_prob, args.canceled_prob))

    os.makedirs(args.comenzi, exist_ok=True)
    os.makedirs(args.raspuns, exist_ok=True)

    state = load_state(args.state, args.z, args.nr)
    processed = set(state.get("processed", []))

    log("Mock FiscalWire pornit (mod=%s, comenzi=%s, raspuns=%s)."
        % (args.mode, args.comenzi, args.raspuns))
    log("Comanda noua -> raspuns in folderul de raspuns (trebuie sa fie acelasi "
        "cu tblSet.CaleFisierRaspunsECR din Setari).")

    while True:
        found = False
        for path in sorted(glob.glob(os.path.join(args.comenzi, "pos_tm_*.inp"))):
            name = os.path.basename(path)
            if name in processed:
                continue
            processed.add(name)
            state["processed"] = sorted(processed)
            try:
                process_command(path, state, args)
            except Exception as exc:  # noqa: BLE001 - un comanda defecta nu opreste mock-ul
                log("Eroare la procesarea %s: %s" % (name, exc))
                import traceback
                traceback.print_exc()
            else:
                # FiscalWire muta/sterge fisierul de comanda dupa procesare.
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


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
