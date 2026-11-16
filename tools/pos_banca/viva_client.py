#!/usr/bin/env python3
"""Viva.com ECR Protocol (TCP/IP) - client ECR pentru POS bancar.

Documentatia protocolului:
    developer.viva.com -> In-person payments -> ECR Protocol
    (https://developer.viva.com/apis-for-point-of-sale/card-terminals-devices/vivawallet-api-cl/)

Mesajele sunt text, cu campurile separate prin '|'. Primele 4 caractere sunt
msgLength (lungimea mesajului fara primele 4 caractere, inclusiv separatorul
care urmeaza). Terminalul asculta implicit pe portul 8080 (se afiseaza pe ecran
cand este activat ERP/ECR; recomandat IP static).

Comenzi:
    sale            vanzare pe card (200/00)
    last-receipt    ultima tranzactie din terminal (700/00), pentru verificarea
                    unei plati incerte (protocolul nu permite cautarea dupa id)
    void            anulare/refund (200/04), dupa STAN + suma
    probe           verifica legatura si afiseaza txReady
    selftest        verifica framing/parsarea pe exemplele din documentatie

Conexiunea se citeste din .env-ul de langa script (acelasi fisier ca la
SmartPay), cu valorile implicite ale Viva:

    ECR_HOST=192.168.1.50
    ECR_PORT=8080
    ECR_TIMEOUT=120

Exemple:
    python viva_client.py --host 192.168.1.50 --port 8080 sale --amount 25.00 --tx TX1
    python viva_client.py last-receipt --out rezultat_ecr_resync.txt
    python viva_client.py void --stan 833121 --amount 25.00
    python viva_client.py selftest
"""

import argparse
import os
import random
import re
import socket
import sys
import time
from datetime import datetime
from decimal import Decimal, InvalidOperation

from env_config import find_env, load_dotenv

# Tipuri de mesaje (vezi tabelele din documentatie).
TYPE_READY = "810"
TYPE_TX_RESPONSE = "210"
TYPE_LAST_RECEIPT = "710"

MSG_TX_REQUEST = "200"
MSG_LAST_RECEIPT = "700"
CODE_SALE = "00"
CODE_CANCEL = "04"

DEFAULT_PORT = 8080
DEFAULT_TIMEOUT = 120.0
CONNECT_TIMEOUT = 5.0
READY_WAIT = 1.5
MAX_MESSAGE = 200000


class VivaError(Exception):
    """Eroare de protocol / configurare (comanda poate sa nu fi plecat)."""


def amount_str(value) -> str:
    """Suma cu exact 2 zecimale, cu punct zecimal (cerinta protocolului)."""
    try:
        return f"{Decimal(str(value).replace(',', '.')):.2f}"
    except (InvalidOperation, ValueError, AttributeError):
        raise VivaError(f"Suma invalida: {value}")


def new_txn_id() -> str:
    return "TX" + time.strftime("%Y%m%d%H%M%S") + f"{random.randint(0, 9999):04d}"


def new_session_id() -> str:
    """6 cifre, unice pentru secventa de sesiune (nu trebuie sa fie globale)."""
    return f"{random.randint(0, 999999):06d}"


def _field(value) -> str:
    # NU facem strip: uniqueTxnId se padding-uieste cu spatii si acele spatii
    # fac parte din mesaj (protocolul cere exact 32 de caractere).
    text = str(value if value is not None else "")
    return text.replace("|", " ").replace("\r", " ").replace("\n", " ")


def pad_txn_id(value) -> str:
    """uniqueTxnId trebuie sa aiba EXACT 32 caractere (padding cu spatii)."""
    text = _field(value).strip() or new_txn_id()
    return text[:32].ljust(32)


def build_message(fields) -> str:
    """Construieste un mesaj complet, cu msgLength corect.

    msgLength = lungimea totala - 4 (include separatorul de dupa cele 4 cifre).
    """
    body = "|".join(_field(f) for f in fields)
    return f"{len(body) + 1:04d}|{body}"


def split_message(raw):
    return [part.strip() for part in str(raw).split("|")]


def message_type(raw):
    """Tipul mesajului: index 1 la txReady (810), index 2 la raspunsuri."""
    parts = split_message(raw)
    if len(parts) < 2:
        return ""
    if parts[1] == TYPE_READY:
        return TYPE_READY
    return parts[2] if len(parts) > 2 else ""


class VivaLink:
    """Conexiunea TCP cu terminalul, cu citire pe baza de msgLength."""

    def __init__(self, host, port, timeout):
        self.host = host
        self.port = int(port)
        self.timeout = float(timeout)
        self.sock = None
        # Comanda a plecat spre terminal? Doar atunci un timeout poate insemna
        # o plata posibil incasata (UNKNOWN), nu un terminal indisponibil.
        self.sent = False

    def open(self):
        if not self.host:
            raise VivaError("ECR_HOST lipseste din .env")
        try:
            self.sock = socket.create_connection(
                (self.host, self.port), timeout=CONNECT_TIMEOUT
            )
        except OSError as exc:
            raise VivaError(f"Conexiune esuata: {exc}") from exc
        try:
            self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        except OSError:
            pass
        return self

    def close(self):
        if self.sock is not None:
            try:
                self.sock.close()
            except OSError:
                pass
            self.sock = None

    def _recv_exact(self, count):
        chunks = []
        remaining = count
        while remaining > 0:
            try:
                chunk = self.sock.recv(remaining)
            except socket.timeout:
                raise
            if not chunk:
                raise VivaError("Terminalul a inchis conexiunea.")
            chunks.append(chunk)
            remaining -= len(chunk)
        return b"".join(chunks)

    def read_message(self):
        head = self._recv_exact(4)
        if not head.isdigit():
            raise VivaError(f"Raspuns invalid de la terminal (msgLength: {head!r}).")
        length = int(head)
        if length <= 0 or length > MAX_MESSAGE:
            raise VivaError(f"Lungime de mesaj invalida: {length}.")
        body = self._recv_exact(length)
        return (head + body).decode("utf-8", errors="replace")

    def read_optional(self, seconds):
        """Citeste un mesaj daca terminalul il trimite imediat (ex. txReady)."""
        if self.sock is None:
            return None
        self.sock.settimeout(seconds)
        try:
            return self.read_message()
        except socket.timeout:
            return None
        finally:
            if self.sock is not None:
                self.sock.settimeout(self.timeout)

    def send(self, message):
        if self.sock is None:
            raise VivaError("Conexiunea nu este deschisa.")
        try:
            self.sock.sendall(message.encode("utf-8"))
        except OSError as exc:
            raise VivaError(f"Nu pot trimite comanda: {exc}") from exc
        self.sent = True

    def exchange(self, fields, expected_type):
        """Trimite o cerere si asteapta raspunsul de tipul asteptat.

        Mesajele txReady primite dupa cerere sunt ignorate (unele terminale le
        trimit abia dupa request, altele la deschiderea socket-ului).
        """
        ready = self.read_optional(READY_WAIT)
        if ready:
            print(f"[READY] {ready}")
            if message_type(ready) != TYPE_READY:
                # Un mesaj neasteptat inainte de cerere: il ignoram, dar il
                # consemnam; nu il tratam ca raspuns la comanda.
                print(f"[READY] mesaj ignorat: {ready}")
        request = build_message(fields)
        print(f"[REQUEST] {request}")
        self.send(request)
        deadline = time.time() + float(self.timeout)
        while time.time() <= deadline:
            raw = self.read_message()
            kind = message_type(raw)
            print(f"[RESPONSE] {raw}")
            if kind == expected_type:
                return raw
            # txReady / alte mesaje intermediare: continuam sa asteptam.
        raise VivaError("Terminalul nu a trimis raspunsul asteptat.")


# --------------------------------------------------------------------------- #
# Parsare
# --------------------------------------------------------------------------- #
def extract_rrn(message):
    match = re.search(r"RRN[:\s]*([0-9]{6,12})", message or "", re.IGNORECASE)
    return match.group(1) if match else ""


def find_guid(raw):
    match = re.search(
        r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-"
        r"[0-9a-fA-F]{4}-[0-9a-fA-F]{12}",
        raw or "",
    )
    return match.group(0) if match else ""


def find_order_code(parts):
    # Modelurile difera prin campurile de la coada; orderCode-ul este singurul
    # token pur de 16 cifre din zona extinsa.
    for token in parts[12:]:
        if re.fullmatch(r"\d{16}", token or ""):
            return token
    return ""


def parse_sale_response(raw, requested_amount=""):
    parts = split_message(raw)
    if len(parts) < 6:
        raise VivaError(f"Raspuns de vanzare incomplet: {raw}")
    code = parts[4] if len(parts) > 4 else ""
    message = parts[5] if len(parts) > 5 else ""
    card_type = parts[6] if len(parts) > 6 else ""
    pan = parts[7] if len(parts) > 7 else ""
    stan = parts[8] if len(parts) > 8 else ""
    auth = parts[9] if len(parts) > 9 else ""
    amount = parts[11] if len(parts) > 11 and parts[11] else requested_amount
    return {
        "code": code,
        "approved": code == "00",
        "message": message,
        "card_type": card_type,
        "pan": pan,
        "stan": stan,
        "auth": auth,
        "amount": amount,
        "rrn": extract_rrn(message),
        "transaction_id": find_guid(raw),
        "order_code": find_order_code(parts),
        "raw": raw,
    }


def parse_cancel_response(raw, requested_amount=""):
    parts = split_message(raw)
    if len(parts) < 6:
        raise VivaError(f"Raspuns de anulare incomplet: {raw}")
    code = parts[4] if len(parts) > 4 else ""
    message = parts[5] if len(parts) > 5 else ""
    card_type = parts[6] if len(parts) > 6 else ""
    pan = parts[7] if len(parts) > 7 else ""
    stan = parts[8] if len(parts) > 8 else ""
    auth = parts[9] if len(parts) > 9 else ""
    # In txCancelResponse campul de la pozitia 10 este terminalid-ul, nu suma;
    # suma anulata este cea ceruta (o folosim pentru audit si pentru retry).
    amount = requested_amount
    return {
        "code": code,
        "approved": code == "00",
        "message": message,
        "card_type": card_type,
        "pan": pan,
        "stan": stan,
        "auth": auth,
        "amount": amount,
        "rrn": extract_rrn(message),
        "transaction_id": find_guid(raw),
        "raw": raw,
    }


def iso_epoch(text):
    """Data ISO 8601 a terminalului -> epoch local (0 daca nu poate fi citita)."""
    text = (text or "").strip()
    if not text:
        return 0
    try:
        value = text[:-1] + "+00:00" if text.endswith("Z") else text
        moment = datetime.fromisoformat(value)
        if moment.tzinfo is None:
            moment = moment.astimezone()
        return int(moment.timestamp())
    except ValueError:
        return 0


def parse_last_receipt(raw):
    parts = split_message(raw)
    if len(parts) < 9:
        raise VivaError(f"Raspuns de ultima tranzactie incomplet: {raw}")
    return {
        "code": parts[3],
        "amount": parts[4],
        "rrn": parts[6],
        "pan": parts[7],
        "txn_at": parts[8],
        "txn_epoch": iso_epoch(parts[8]),
        "raw": raw,
    }


# --------------------------------------------------------------------------- #
# Fisierul de rezultat pentru aplicatia POS
# --------------------------------------------------------------------------- #
def _clean_result_value(value) -> str:
    text = str(value if value is not None else "")
    return text.replace("\r", " ").replace("\n", " ").strip()


def write_result_file(path, status, emite_bon, mesaj,
                      suma="", trx_id="", rrn="", stan="", extra=None):
    if not path:
        return
    lines = [
        f"STATUS={_clean_result_value(status)}",
        f"EMITE_BON={_clean_result_value(emite_bon)}",
        f"MESAJ={_clean_result_value(mesaj)}",
        f"SUMA={_clean_result_value(suma)}",
        f"TRX_ID={_clean_result_value(trx_id)}",
        f"RRN={_clean_result_value(rrn)}",
        f"STAN={_clean_result_value(stan)}",
    ]
    written = {"STATUS", "EMITE_BON", "MESAJ", "SUMA", "TRX_ID", "RRN", "STAN"}
    for key, value in (extra or {}).items():
        key = "".join(ch for ch in str(key) if ch.isalnum() or ch == "_").upper()
        if key and key not in written:
            lines.append(f"{key}={_clean_result_value(value)}")
    try:
        with open(path, "w", encoding="utf-8") as fh:
            fh.write("\n".join(lines) + "\n")
    except OSError as exc:
        print(f"[EROARE] Nu pot scrie fisierul de rezultat {path}: {exc}",
              file=sys.stderr)


def _sale_extra(parsed):
    parsed = parsed or {}
    return {
        "AUTH": parsed.get("auth", ""),
        "PAN": parsed.get("pan", ""),
        "CARD": parsed.get("card_type", ""),
        "CODE": parsed.get("code", ""),
        "ORDER_CODE": parsed.get("order_code", ""),
        "TXN_ID": parsed.get("transaction_id", ""),
        "RAW": parsed.get("raw", ""),
    }


# --------------------------------------------------------------------------- #
# Comenzi
# --------------------------------------------------------------------------- #
def run_sale(link, amount, tx_id):
    raw = link.exchange(
        [new_session_id(), MSG_TX_REQUEST, CODE_SALE, pad_txn_id(tx_id),
         amount_str(amount), "0000"],
        TYPE_TX_RESPONSE,
    )
    return parse_sale_response(raw, amount_str(amount))


def run_last_receipt(link):
    raw = link.exchange(
        [new_session_id(), MSG_LAST_RECEIPT, "00"],
        TYPE_LAST_RECEIPT,
    )
    return parse_last_receipt(raw)


def run_void(link, stan, amount):
    raw = link.exchange(
        [new_session_id(), MSG_TX_REQUEST, CODE_CANCEL, pad_txn_id(new_txn_id()),
         amount_str(amount), stan],
        TYPE_TX_RESPONSE,
    )
    return parse_cancel_response(raw, amount_str(amount))


def run_probe(link):
    ready = link.read_optional(READY_WAIT)
    return ready or ""


# --------------------------------------------------------------------------- #
# Autotest (fara retea) - exemplele din documentatia Viva ECR
# --------------------------------------------------------------------------- #
def run_selftest() -> int:
    failures = []

    def check(name, condition, detail=""):
        if condition:
            print(f"[PASS] {name}")
        else:
            print(f"[FAIL] {name}" + (f"  -> {detail}" if detail else ""))
            failures.append(name)

    check("txReady se construieste cu msgLength corect",
          build_message(["810", "00", "000032"]) == "0014|810|00|000032",
          build_message(["810", "00", "000032"]))

    isv_sale = build_message([
        "002690", "200", "00", "12345678901234567890123456789012", "123.50",
        "0000", "", "", "", "", "0.10", "xxxxx.apps.vivapayments.com",
        "qtFskX94T2f2Dkhc364pa1cgaFn3D", "Default",
    ])
    check("cererea de vanzare (exemplul ISV din documentatie)",
          isv_sale == "0134|002690|200|00|12345678901234567890123456789012|123.50|0000|||||0.10|xxxxx.apps.vivapayments.com|qtFskX94T2f2Dkhc364pa1cgaFn3D|Default",
          isv_sale)

    cancel = build_message([
        "002690", "200", "04", "12345678901234567890123456789012", "123.00", "826987",
    ])
    check("cererea de anulare (exemplul din documentatie)",
          cancel == "0061|002690|200|04|12345678901234567890123456789012|123.00|826987",
          cancel)

    check("uniqueTxnId este padded la 32 caractere", len(pad_txn_id("abc")) == 32)
    padded_req = build_message(["123456", "200", "00", pad_txn_id("abc"), "1.00", "0000"])
    check("cererea pastreaza padding-ul uniqueTxnId (32 caractere)",
          ("|abc" + " " * 29 + "|1.00|") in padded_req, padded_req)

    linux_sale = parse_sale_response(
        "0213|000032|210|00|00|CHIP/PIN~RRN:123456833121|VISA|479275******9999|"
        "833121|690882|000001|123.00|1010|00|00|00|00|00|00| 16016684||||"
        "629914XXXXXXXXX6770|629914XXXXXXXXX6770|qwerty123456|qwerty123456|"
        "my name|2212|1010"
    )
    check("vanzare aprobata (Linux Card Terminal)",
          linux_sale["approved"] and linux_sale["code"] == "00"
          and linux_sale["stan"] == "833121" and linux_sale["auth"] == "690882"
          and linux_sale["rrn"] == "123456833121"
          and linux_sale["pan"] == "479275******9999",
          str(linux_sale))

    android_sale = parse_sale_response(
        "0247|000024|210|00|00|Contactless/None~RRN:204814800749|MASTERCARD|"
        "537535******9228|800749|008120|000001|1.11|00|00|00|00|00|00|00|"
        "16000281|2048160108000202|2048160108||||||||"
        "0950f16c-1f52-4060-ac22-cb6b59c8990c|2022-02-17T16:15:15.1824947+02:00|1"
    )
    check("vanzare aprobata (Android Card Terminal)",
          android_sale["approved"] and android_sale["stan"] == "800749"
          and android_sale["auth"] == "008120"
          and android_sale["rrn"] == "204814800749"
          and android_sale["order_code"] == "2048160108000202"
          and android_sale["transaction_id"] == "0950f16c-1f52-4060-ac22-cb6b59c8990c",
          str(android_sale))

    declined = parse_sale_response(
        "0218|000026|210|00|51|Declined|MASTERCARD|537535******9228|800750||"
        "000001|1.01|1010|00|00|00|00|00|00| 16000281|2048160109000202|"
        "2048160109||||||||692676f4-7f9e-478c-b3fe-901dfa7b9bc8|"
        "2022-02-17T16:16:44.2677975+02:00|1"
    )
    check("vanzare refuzata (respCode 51)",
          (not declined["approved"]) and declined["code"] == "51",
          str(declined))

    receipt = parse_last_receipt(
        "0306|000014|710|00|1.23|0.0|134811422007|451545******0080|"
        "2021-12-14T13:59:57.673428+02:00|A0000000031010|EL096019265|"
        "kifisias 10, 12342 Athens|AGROTIKOS SYNETAIRISMOS|true|null|null|null|"
        "null|null|null|null|0000|false|false|1490355478|EUR|No text|"
        "v3.11.0(2451)|null|null|07|true"
    )
    check("ultima tranzactie (getLastReceipt)",
          receipt["code"] == "00" and receipt["amount"] == "1.23"
          and receipt["rrn"] == "134811422007" and receipt["txn_epoch"] > 0,
          str(receipt))

    cancel_resp = parse_cancel_response(
        "0069|000050|210|04|00||MASTERCARD|537535******1339|856749|000000|16016684"
    )
    check("anulare aprobata (txCancelResponse)",
          cancel_resp["approved"] and cancel_resp["stan"] == "856749",
          str(cancel_resp))

    print(f"\n--- selftest: {len(failures)} esec(uri) ---")
    return 1 if failures else 0


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Client ECR Viva.com (TCP/IP)")
    parser.add_argument("--env", default=None, help="Cale fisier .env (implicit: ./ .env)")
    parser.add_argument("--host", default=None)
    parser.add_argument("--port", type=int, default=None)
    parser.add_argument("--timeout", type=float, default=None)
    parser.add_argument("--retries", type=int, default=None,
                        help="Acceptat pentru compatibilitate cu apelurile POS")
    parser.add_argument("--out", default=None, help="Fisier text de rezultat pentru POS")

    sub = parser.add_subparsers(dest="command", required=True)
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--out", default=argparse.SUPPRESS,
                        help="Fisier text de rezultat pentru POS")

    sale = sub.add_parser("sale", parents=[common], help="Vanzare pe card")
    sale.add_argument("--amount", required=True, help="Suma, ex. 25.00")
    sale.add_argument("--tx", default=None, help="ID unic de tranzactie (uniqueTxnId)")

    sub.add_parser("last-receipt", parents=[common],
                   help="Ultima tranzactie din terminal (verificare plata incerta)")
    void = sub.add_parser("void", parents=[common], help="Anulare/refund dupa STAN")
    void.add_argument("--stan", required=True, help="STAN-ul vanzarii (refNum)")
    void.add_argument("--amount", required=True, help="Suma de anulat")
    sub.add_parser("probe", parents=[common], help="Verifica legatura (txReady)")
    sub.add_parser("selftest", parents=[common], help="Testeaza framing/parsarea (fara retea)")
    return parser


def _pick(cli_value, env, key, default, cast=str):
    if cli_value is not None:
        return cli_value
    raw = env.get(key)
    if raw not in (None, ""):
        return cast(raw)
    return default


def resolve_config(args) -> dict:
    env_path = find_env(args.env)
    env = load_dotenv(env_path)
    out = getattr(args, "out", None)
    if not out:
        out = os.environ.get("POS_BANCA_RESULT") or None
    if not out:
        out = env.get("ECR_OUTPUT_FILE") or None
    return {
        "host": _pick(args.host, env, "ECR_HOST", ""),
        "port": _pick(args.port, env, "ECR_PORT", DEFAULT_PORT, int),
        "timeout": _pick(args.timeout, env, "ECR_TIMEOUT", DEFAULT_TIMEOUT, float),
        "out": out,
        "env_used": env_path,
    }


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    if args.command == "selftest":
        return run_selftest()

    try:
        cfg = resolve_config(args)
    except (ValueError, TypeError) as exc:
        print(f"[EROARE] Configuratie: {exc}", file=sys.stderr)
        out = getattr(args, "out", None) or os.environ.get("POS_BANCA_RESULT") or None
        write_result_file(out, "ERROR", "NU",
                          "Configuratia terminalului bancar (POS) este invalida.")
        return 2
    out = cfg["out"]
    trx_id = getattr(args, "tx", None) or new_txn_id()
    suma = ""
    link = None
    try:
        if args.command in ("sale", "void"):
            suma = amount_str(args.amount)
        link = VivaLink(cfg["host"], cfg["port"], cfg["timeout"])
        link.open()
        if cfg["env_used"]:
            print(f"[CONFIG] .env: {cfg['env_used']}")
        print(f"[CONFIG] TCP {cfg['host']}:{cfg['port']} (timeout {cfg['timeout']}s)")

        if args.command == "sale":
            parsed = run_sale(link, args.amount, trx_id)
            if parsed["approved"]:
                write_result_file(out, "OK", "DA",
                                  "Tranzactie aprobata.",
                                  suma=parsed["amount"] or suma, trx_id=trx_id,
                                  rrn=parsed["rrn"], stan=parsed["stan"],
                                  extra=_sale_extra(parsed))
                return 0
            mesaj = ("Anulat de utilizator." if parsed["code"] == "UC"
                     else "Tranzactie respinsa.")
            write_result_file(out, "ERROR", "NU", mesaj,
                              suma=parsed["amount"] or suma, trx_id=trx_id,
                              rrn=parsed["rrn"], stan=parsed["stan"],
                              extra=_sale_extra(parsed))
            return 1

        if args.command == "last-receipt":
            parsed = run_last_receipt(link)
            ok = parsed["code"] == "00" and parsed["rrn"] != ""
            write_result_file(
                out, "OK" if ok else "ERROR", "NU",
                "Ultima tranzactie a fost citita." if ok
                else "Ultima tranzactie din terminal nu a putut fi citita.",
                rrn=parsed["rrn"], suma=parsed["amount"],
                extra={
                    "AMOUNT": parsed["amount"],
                    "RRN": parsed["rrn"],
                    "PAN": parsed["pan"],
                    "TXN_AT": parsed["txn_at"],
                    "TXN_EPOCH": parsed["txn_epoch"],
                    "CODE": parsed["code"],
                    "RAW": parsed["raw"],
                },
            )
            return 0 if ok else 2

        if args.command == "void":
            parsed = run_void(link, args.stan, args.amount)
            if parsed["approved"]:
                write_result_file(
                    out, "OK", "NU", "Tranzactie anulata.",
                    suma=parsed["amount"] or suma, trx_id=trx_id,
                    rrn=parsed["rrn"], stan=parsed["stan"],
                    extra={"VOIDED": "1", "CODE": parsed["code"],
                           "RAW": parsed["raw"]},
                )
                return 0
            write_result_file(
                out, "ERROR", "NU",
                "Anularea a esuat (cod " + (parsed["code"] or "?") + ").",
                suma=parsed["amount"] or suma, trx_id=trx_id,
                rrn=parsed["rrn"], stan=parsed["stan"],
                extra={"VOIDED": "0", "CODE": parsed["code"],
                       "RAW": parsed["raw"]},
            )
            return 1

        if args.command == "probe":
            ready = run_probe(link)
            write_result_file(out, "OK", "NU",
                              "Terminalul raspunde." if ready else "Terminalul nu a trimis txReady.",
                              extra={"READY": ready})
            return 0

        raise VivaError(f"Comanda necunoscuta: {args.command}")
    except (VivaError, OSError) as exc:
        print(f"[EROARE] Comunicatie: {exc}", file=sys.stderr)
        # O eroare dupa ce comanda a plecat poate insemna o plata incasata:
        # scriem UNKNOWN, ca operatorul sa verifice terminalul inainte de retry.
        if args.command == "sale":
            if link is not None and link.sent:
                write_result_file(
                    out, "UNKNOWN", "NU",
                    "Stare necunoscuta: terminalul a acceptat comanda, dar nu a "
                    "trimis raspuns. Verificati chitanta terminalului inainte de "
                    "reincercare.",
                    suma=suma, trx_id=trx_id,
                )
                return 3
            write_result_file(out, "ERROR", "NU",
                              "Nu s-a putut conecta la terminalul bancar (POS).",
                              suma=suma, trx_id=trx_id)
            return 2
        if args.command == "last-receipt":
            write_result_file(out, "ERROR", "NU",
                              "Ultima tranzactie din terminal nu a putut fi citita.",
                              extra={"RAW": str(exc)})
            return 2
        write_result_file(out, "ERROR", "NU",
                          "Eroare de comunicatie cu terminalul bancar (POS).",
                          suma=suma, trx_id=trx_id)
        return 2
    finally:
        if link is not None:
            link.close()


if __name__ == "__main__":
    sys.exit(main())
