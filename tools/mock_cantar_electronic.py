"""Mock cantar electronic - simulator al unui cantar pentru testare.

La fiecare rulare simuleaza o cantarire: dupa o scurta intarziere (implicit
0.5 s, cat "cantareste"/se stabilizeaza) scrie in fisierul de raspuns un numar
aleator in intervalul [min, max] (implicit 0.5 - 1.5, in kg), apoi se inchide.
Fisierul este SUPRASCRIS la fiecare apel.

Fisierul implicit este `logs/mock_cantar/raspuns_cantar.txt` si corespunde
setarii `tblSet.CaleFisierRaspunsCantar` din Setari -> Device-uri -> Cantar.

Nu face parte din aplicatia de productie: se porneste manual, din .venv, doar
pentru teste. Ex.:
  .venv\\Scripts\\python.exe tools\\mock_cantar_electronic.py
  .venv\\Scripts\\python.exe tools\\mock_cantar_electronic.py --min 0.2 --max 2.0 --delay 1
"""

import argparse
import os
import random
import sys
import time

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(BASE_DIR)
DEFAULT_FILE = os.path.join(PROJECT_DIR, "logs", "mock_cantar", "raspuns_cantar.txt")
DEFAULT_LOG = os.path.join(PROJECT_DIR, "logs", "mock_cantar.log")

# Fisierul de log (setat in main). Scriem si in el, nu doar in consola, ca sa
# putem diagnostica si daca fereastra de consola se inchide.
LOG_FILE = None


def log(msg):
    line = "[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg)
    # print() nu trebuie NICIODATA sa arunce (consola inchisa / codepage), altfel
    # un esec ar opri mock-ul.
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


def write_value(path, text):
    """Scrie valoarea atomic (tmp + replace), ca sa nu se citeasca partial."""
    folder = os.path.dirname(path)
    if folder:
        os.makedirs(folder, exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="ascii", newline="") as fh:
        fh.write(text)
    os.replace(tmp, path)


def format_value(value, decimals):
    return ("%." + str(decimals) + "f") % value


def main():
    ap = argparse.ArgumentParser(description="Mock cantar electronic pentru testare.")
    ap.add_argument("--file", default=DEFAULT_FILE, help="fisierul de raspuns scris (suprascris)")
    ap.add_argument("--delay", type=float, default=0.5,
                    help="intarzierea pana la scriere (secunde, implicit 0.5)")
    ap.add_argument("--min", type=float, default=0.5, help="valoarea minima generata (implicit 0.5)")
    ap.add_argument("--max", type=float, default=1.5, help="valoarea maxima generata (implicit 1.5)")
    ap.add_argument("--decimals", type=int, default=3, help="numarul de zecimale (implicit 3)")
    args = ap.parse_args()

    if args.max < args.min:
        args.min, args.max = args.max, args.min
    args.delay = max(0.0, args.delay)
    args.decimals = max(0, args.decimals)

    global LOG_FILE
    LOG_FILE = DEFAULT_LOG

    log("Mock cantar electronic (fisier=%s, interval=[%s, %s], delay=%ss)."
        % (args.file, args.min, args.max, args.delay))
    log("Setati tblSet.CaleFisierRaspunsCantar din Setari -> Device-uri -> Cantar "
        "la acest fisier.")

    # O singura cantarire per rulare: asteapta, scrie valoarea si iese.
    if args.delay > 0:
        time.sleep(args.delay)
    value = random.uniform(args.min, args.max)
    text = format_value(value, args.decimals)
    write_value(args.file, text + "\n")
    log("Cantarit %s kg -> %s" % (text, args.file))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
