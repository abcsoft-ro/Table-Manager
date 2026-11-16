#!/usr/bin/env python3
"""Simulator Viva.com ECR Protocol (TCP/IP) pentru testarea POS-ului bancar.

Implementeaza mesajele folosite de aplicatie:
    txReady (810)        trimis la deschiderea conexiunii
    txSaleRequest (200/00)   -> txSaleResponse (210/00)
    txCancelRequest (200/04) -> txCancelResponse (210/04)
    getLastReceiptRequest (700/00) -> getLastReceiptResponse (710)

Moduri (--mode):
    ok              aproba vanzarile
    decline         refuza vanzarile (respCode 51)
    cancel          utilizatorul anuleaza (respCode UC)
    silent          nu raspunde la vanzare (client -> UNKNOWN), apoi inchide
    accept-silent   inregistreaza vanzarea in jurnal dar nu raspunde (UNKNOWN,
                    iar last-receipt o gaseste -> resync aprobat)
    disconnect      inchide conexiunea imediat dupa accept (terminal mut)
    random          aprobat / refuzat / anulat, cu probabilitati
    test_general    ciclu: aprobat / refuzat / accept-silent / silent / disconnect

Exemple:
    mock-viva-terminal.bat --mode ok --port 8090
    mock-viva-terminal.bat --mode accept-silent --journal-file jurnal_viva.json
    mock-viva-terminal.bat --mode test_general --once

Nu face parte din productie.
"""

import argparse
import json
import os
import random
import socket
import sys
import time
from datetime import datetime

TYPE_READY = "810"


def build_message(fields) -> str:
    body = "|".join(
        str(value if value is not None else "")
        .replace("|", " ").replace("\r", " ").replace("\n", " ").strip()
        for value in fields
    )
    return f"{len(body) + 1:04d}|{body}"


def recv_exact(conn, count):
    chunks = []
    remaining = count
    while remaining > 0:
        chunk = conn.recv(remaining)
        if not chunk:
            raise ConnectionError("clientul a inchis conexiunea")
        chunks.append(chunk)
        remaining -= len(chunk)
    return b"".join(chunks)


def read_message(conn, timeout):
    conn.settimeout(timeout)
    head = recv_exact(conn, 4)
    if not head.isdigit():
        raise ValueError(f"msgLength invalid: {head!r}")
    body = recv_exact(conn, int(head))
    return (head + body).decode("utf-8", errors="replace")


def split_message(raw):
    return [part.strip() for part in str(raw).split("|")]


class Journal:
    def __init__(self, path=None):
        self.path = path
        self.items = []
        if path and os.path.isfile(path):
            try:
                with open(path, encoding="utf-8") as fh:
                    data = json.load(fh)
                if isinstance(data, list):
                    self.items = data
            except (OSError, ValueError):
                self.items = []

    def add(self, entry):
        self.items.append(entry)
        self.save()

    def last(self):
        return self.items[-1] if self.items else None

    def save(self):
        if not self.path:
            return
        try:
            tmp = self.path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as fh:
                json.dump(self.items, fh, ensure_ascii=False, indent=2)
            os.replace(tmp, self.path)
        except OSError as exc:
            log(f"[AVERTISMENT] Nu pot scrie jurnalul {self.path}: {exc}")


def log(message):
    try:
        print(message, flush=True)
    except (OSError, ValueError):
        pass


def make_rrn():
    return f"{(int(time.time()) * 7) % 10**9:09d}{random.randint(0, 999):03d}"


def make_stan():
    return f"{random.randint(0, 999999):06d}"


class Simulator:
    def __init__(self, args):
        self.args = args
        self.seq = int(args.seq_start)
        self.journal = Journal(args.journal_file)
        self.cycle = 0
        self.modes = ["ok", "decline", "accept-silent", "silent", "disconnect"]

    def next_seq(self):
        self.seq += 1
        return f"{self.seq:06d}"

    def pick_mode(self):
        mode = self.args.mode
        if mode == "random":
            mode = random.choices(
                ["ok", "decline", "cancel"],
                weights=[1 - self.args.err_prob - self.args.cancel_prob,
                         self.args.err_prob, self.args.cancel_prob],
            )[0]
        elif mode == "test_general":
            mode = self.modes[self.cycle % len(self.modes)]
            self.cycle += 1
        return mode

    def sale_response(self, seq, amount, mode):
        if mode == "decline":
            return build_message([
                seq, "210", "00", "51", "Declined", "MASTERCARD",
                "537535******9228", "000000", "000000", "000001", amount,
                "1010",
            ])
        if mode == "cancel":
            return build_message([
                seq, "210", "00", "UC", "User Cancelled", "", "", "000000",
                "000000", "000001", amount, "1010",
            ])
        rrn = make_rrn()
        stan = make_stan()
        entry = {
            "rrn": rrn,
            "stan": stan,
            "amount": amount,
            "pan": "479275******9999",
            "card": "VISA",
            "txn_at": datetime.now().astimezone().isoformat(),
            "type": "SALE",
        }
        self.journal.add(entry)
        return build_message([
            seq, "210", "00", "00",
            f"CHIP/PIN~RRN:{rrn}", "VISA", entry["pan"], stan,
            f"{random.randint(0, 999999):06d}", "000001", amount, "1010",
            "00", "00", "00", "00", "00", "00", " 16016684",
            "", "", rrn,
        ])

    def cancel_response(self, seq, stan, amount, mode):
        if mode in ("decline", "silent"):
            return build_message([
                seq, "210", "04", "51", "Declined", "MASTERCARD",
                "537535******9228", "000000", "000000", "16016684",
            ])
        return build_message([
            seq, "210", "04", "00", "", "MASTERCARD",
            "537535******9228", stan, "000000", "16016684",
        ])

    def last_receipt_response(self, seq):
        entry = self.journal.last()
        if not entry:
            return build_message([seq, "710", "90", "", "0.0", "", "", ""])
        return build_message([
            seq, "710", "00", entry.get("amount", "0.00"), "0.0",
            entry.get("rrn", ""), entry.get("pan", ""),
            entry.get("txn_at", ""),
        ])

    def handle(self, conn, address):
        log(f"[CONNECT] {address[0]}:{address[1]}")
        mode = self.pick_mode()
        if mode == "disconnect":
            log("[MODE] disconnect - inchid imediat conexiunea")
            return
        time.sleep(max(0.0, self.args.delay))
        try:
            conn.sendall(build_message([TYPE_READY, "00", self.next_seq()]).encode())
        except OSError as exc:
            log(f"[EROARE] txReady: {exc}")
            return

        try:
            raw = read_message(conn, max(5.0, self.args.timeout))
        except (OSError, ValueError, ConnectionError) as exc:
            log(f"[EROARE] citire cerere: {exc}")
            return
        parts = split_message(raw)
        log(f"[REQUEST] {raw}")

        kind = parts[2] if len(parts) > 2 else ""
        code = parts[3] if len(parts) > 3 else ""
        seq = self.next_seq()

        if kind == "200" and code == "00":
            amount = parts[5] if len(parts) > 5 else "0.00"
            log(f"[SALE] amount={amount} mode={mode}")
            if mode in ("silent", "accept-silent"):
                if mode == "accept-silent":
                    # Jurnalul primeste vanzarea, dar clientul nu afla raspunsul.
                    self.journal.add({
                        "rrn": make_rrn(), "stan": make_stan(), "amount": amount,
                        "pan": "479275******9999", "card": "VISA",
                        "txn_at": datetime.now().astimezone().isoformat(),
                        "type": "SALE",
                    })
                time.sleep(max(0.0, self.args.silent_seconds))
                return
            response = self.sale_response(seq, amount, mode)
            try:
                conn.sendall(response.encode())
                log(f"[RESPONSE] {response}")
            except OSError as exc:
                log(f"[EROARE] raspuns vanzare: {exc}")
            return

        if kind == "200" and code == "04":
            stan = parts[6] if len(parts) > 6 else ""
            amount = parts[5] if len(parts) > 5 else ""
            log(f"[VOID] stan={stan} amount={amount} mode={mode}")
            if mode in ("silent", "accept-silent"):
                time.sleep(max(0.0, self.args.silent_seconds))
                return
            response = self.cancel_response(seq, stan, amount, mode)
            try:
                conn.sendall(response.encode())
                log(f"[RESPONSE] {response}")
            except OSError as exc:
                log(f"[EROARE] raspuns anulare: {exc}")
            return

        if kind == "700":
            response = self.last_receipt_response(seq)
            try:
                conn.sendall(response.encode())
                log(f"[RESPONSE] {response}")
            except OSError as exc:
                log(f"[EROARE] raspuns ultima tranzactie: {exc}")
            return

        log(f"[IGNORAT] tip={kind} cod={code}")


def main(argv=None):
    parser = argparse.ArgumentParser(description="Simulator Viva ECR (POS bancar)")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8090)
    parser.add_argument("--mode", default="ok",
                        choices=["ok", "decline", "cancel", "silent",
                                 "accept-silent", "disconnect", "random",
                                 "test_general"])
    parser.add_argument("--delay", type=float, default=0.0,
                        help="Intarziere (secunde) inainte de txReady")
    parser.add_argument("--silent-seconds", type=float, default=8.0,
                        help="Cat tine conexiunea deschisa in modul silent")
    parser.add_argument("--timeout", type=float, default=120.0,
                        help="Timeout de citire a cererii")
    parser.add_argument("--seq-start", type=int, default=31)
    parser.add_argument("--err-prob", type=float, default=0.25)
    parser.add_argument("--cancel-prob", type=float, default=0.10)
    parser.add_argument("--journal-file", default=None,
                        help="Jurnal persistent (JSON) pentru last-receipt")
    parser.add_argument("--once", action="store_true",
                        help="Accepta o singura conexiune si iese")
    args = parser.parse_args(argv)

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    except OSError:
        pass
    server.bind((args.host, int(args.port)))
    server.listen(8)
    log(f"[START] Viva ECR mock pe {args.host}:{args.port} mod={args.mode}"
        + (f" jurnal={args.journal_file}" if args.journal_file else ""))

    simulator = Simulator(args)
    try:
        while True:
            conn, address = server.accept()
            with conn:
                try:
                    simulator.handle(conn, address)
                except (OSError, ConnectionError, ValueError) as exc:
                    log(f"[EROARE] conexiune: {exc}")
            if args.once:
                break
    except KeyboardInterrupt:
        log("[STOP] intrerupt")
    finally:
        server.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
