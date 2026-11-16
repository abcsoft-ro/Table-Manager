#!/usr/bin/env python3
"""SmartPay ECR Link v1.8.3 - client ECR (request) pentru POS bancar.

Ghid de implementare: Sunmi.md
Suporta transport serial (RS232/USB-COM) sau Ethernet (TCP), framing STX/ETX/TLV,
CRC16-IBM si secventa de control ENQ/ACK/NAK/EOT.

Comenzi:
    info            Get POS information
    sale            Vanzare pe card
    report-init     POS Report Initialization (numarul de tranzactii din jurnal)
    report-record   POS Report Record (detaliile unei tranzactii din jurnal)
    resync          Re-sincronizare dupa o conexiune pierduta (Appendix 3):
                    cauta UID-ul in jurnal si, optional, anuleaza tranzactia
    void            POS Void (anularea unei vanzari, dupa STAN + suma)
    remove-card     POS Remove Card (comanda locala)

Exemple:
    python3 smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 info
    python3 smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 sale 10.00 --uid 1001
    python3 smartpay_client.py --transport serial --serial-port COM3 sale 25.50
    python3 smartpay_client.py resync --uid TX20261028193759 --out rezultat_ecr.txt
"""

import argparse
import os
import socket
import struct
import sys
import time
from dataclasses import dataclass, field
from decimal import Decimal

from env_config import find_env, load_dotenv

STX = 0x02
ETX = 0x03
ENQ = 0x05
ACK = 0x06
NAK = 0x15
EOT = 0x04

CMD_GET_INFO = 0x01
CMD_SALE = 0x02
CMD_REPORT_INIT = 0x04
CMD_REPORT_RECORD = 0x05
CMD_VOID = 0x06
CMD_REMOVE_CARD = 0x09

# Tag-uri din protocol (vezi SmartPay_ECR_Link_v1.8, cap. 3 si 4).
TAG_COMMAND = 0xA000
TAG_AMOUNT = 0xA001
TAG_CURRENCY_NAME = 0xA002
TAG_CURRENCY_CODE = 0xA003
TAG_INDEX = 0xA004
TAG_STAN = 0xA006
TAG_UID = 0xA008
TAG_RESPONSE = 0xA100
TAG_TERMINAL_ID = 0xA103
TAG_MERCHANT_ID = 0xA104
TAG_PAYMENT_DATE = 0xA105
TAG_APPROVED_AMOUNT = 0xA106
TAG_HOST_CODE = 0xA107
TAG_RESPONSE_TEXT = 0xA108
TAG_RECEIPT = 0xA109
TAG_RRN = 0xA10A
TAG_AUTH_CODE = 0xA10B
TAG_PAN = 0xA10C
TAG_CARDHOLDER = 0xA10D
TAG_COUNT = 0xA10E
TAG_BATCH = 0xA10F
TAG_TOTAL_AMOUNT = 0xA110
TAG_PRINTER_STATUS = 0xA111
TAG_TX_TYPE = 0xA112
TAG_EMV_LABEL = 0xA113
TAG_EMV_ID = 0xA114
TAG_FLAGS = 0xA116
TAG_UID_RESPONSE = 0xA117

APPROVED_HOST_CODES = {b"00", b"Y1", b"Y3"}


class EcrError(Exception):
    """Eroare la nivel de protocol ECR."""


class EcrTimeout(EcrError):
    """Nu s-a primit raspuns in timpul alocat."""


class EcrFrameError(EcrError):
    """Frame invalid (ETX/CRC)."""


class EcrUncertain(EcrError):
    """Comanda a fost acceptata (ACK), dar raspunsul lipseste/invalid.

    In acest caz starea tranzactiei este necunoscuta: nu se reincerca orbește,
    ci se ruleaza re-sincronizarea din Appendix 3 (report init + report record).
    """


def crc16_ibm(data: bytes) -> int:
    crc = 0x0000
    for b in data:
        crc ^= (b << 8) & 0xFFFF
        for _ in range(8):
            if crc & 0x8000:
                crc = ((crc << 1) ^ 0x8005) & 0xFFFF
            else:
                crc = (crc << 1) & 0xFFFF
    return crc


def tlv(tag: int, value: bytes) -> bytes:
    return struct.pack(">HB", tag, len(value)) + value


def build_request(payload: bytes) -> bytes:
    crc = crc16_ibm(payload)
    return (
        bytes([STX])
        + struct.pack(">H", len(payload))
        + payload
        + bytes([ETX])
        + bytes([(crc >> 8) & 0xFF, crc & 0xFF])  # request: MSB, LSB
    )


def parse_tlvs(payload: bytes) -> dict:
    tags = {}
    i = 0
    while i + 3 <= len(payload):
        tag = struct.unpack(">H", payload[i:i + 2])[0]
        ln = payload[i + 2]
        tags[tag] = payload[i + 3:i + 3 + ln]
        i += 3 + ln
    return tags


def amount_to_ascii(value) -> bytes:
    d = Decimal(str(value).replace(",", "."))
    cents = int((d * 100).to_integral_value())
    if cents < 0 or cents > 999_999_999_999:
        raise ValueError(f"Suma invalida: {value}")
    return f"{cents:012d}".encode("ascii")


def format_amount(raw: bytes) -> str:
    try:
        return f"{int(raw.decode('ascii')) / 100:.2f}"
    except (ValueError, AttributeError):
        return "?"


def _ascii(raw) -> str:
    if raw is None:
        return ""
    return raw.decode("ascii", "replace").rstrip()


def _code(tags: dict) -> int:
    """Codul de raspuns (TAG_A100, 1 byte HEX); 0xFF cand lipseste."""
    raw = tags.get(TAG_RESPONSE)
    return raw[0] if raw else 0xFF


def _record_extra(tags: dict) -> dict:
    """Campurile utile dintr-un report record / raspuns de vanzare."""
    raw_type = tags.get(TAG_TX_TYPE)
    tx_type = raw_type[0] if raw_type else -1
    amount_raw = tags.get(TAG_APPROVED_AMOUNT) or tags.get(TAG_AMOUNT) or b""
    return {
        "CODE": "00" if _code(tags) == 0 else f"{_code(tags):02X}",
        "UID": _ascii(tags.get(TAG_UID_RESPONSE)),
        "STAN": _ascii(tags.get(TAG_RECEIPT)) or _ascii(tags.get(TAG_STAN)),
        "AMOUNT": format_amount(amount_raw) if amount_raw else "",
        "TYPE": tx_type,
        "HOST": _ascii(tags.get(TAG_HOST_CODE)),
        "TEXT": _ascii(tags.get(TAG_RESPONSE_TEXT)),
        "DATE": _ascii(tags.get(TAG_PAYMENT_DATE)),
        "BATCH": _ascii(tags.get(TAG_BATCH)),
        "RRN": _ascii(tags.get(TAG_RRN)),
        "AUTH": _ascii(tags.get(TAG_AUTH_CODE)),
        "PAN": _ascii(tags.get(TAG_PAN)),
    }


# --------------------------------------------------------------------------- #
# Transport
# --------------------------------------------------------------------------- #
class Transport:
    def read(self, n: int) -> bytes:
        raise NotImplementedError

    def write(self, data: bytes) -> None:
        raise NotImplementedError

    def set_timeout(self, seconds: float) -> None:
        pass

    def close(self) -> None:
        pass

    def reset_input(self) -> None:
        pass

    def reopen(self):
        return self

    def read_exact(self, n: int) -> bytes:
        buf = b""
        while len(buf) < n:
            chunk = self.read(n - len(buf))
            if not chunk:
                raise EcrTimeout(f"Timeout la citirea a {n} octeti")
            buf += chunk
        return buf


class TcpTransport(Transport):
    def __init__(self, host: str, port: int, timeout: float = 180.0):
        self.host = host
        self.port = port
        self.timeout = timeout
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)

    def read(self, n: int) -> bytes:
        try:
            return self.sock.recv(n)
        except (socket.timeout, TimeoutError) as exc:
            raise EcrTimeout("Timeout TCP") from exc

    def write(self, data: bytes) -> None:
        self.sock.sendall(data)

    def set_timeout(self, seconds: float) -> None:
        self.timeout = seconds
        self.sock.settimeout(seconds)

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass

    def reopen(self):
        return TcpTransport(self.host, self.port, timeout=self.timeout)


class SerialTransport(Transport):
    def __init__(self, port: str, baud: int = 115200, timeout: float = 180.0):
        import serial

        self.port = port
        self.baud = baud
        self.timeout = timeout
        self.ser = serial.Serial(
            port,
            baud,
            bytesize=serial.EIGHTBITS,
            parity=serial.PARITY_NONE,
            stopbits=serial.STOPBITS_ONE,
            timeout=timeout,
        )

    def read(self, n: int) -> bytes:
        data = self.ser.read(n)
        if not data:
            raise EcrTimeout("Timeout serial")
        return data

    def write(self, data: bytes) -> None:
        self.ser.write(data)
        self.ser.flush()

    def set_timeout(self, seconds: float) -> None:
        self.timeout = seconds
        self.ser.timeout = seconds

    def close(self) -> None:
        try:
            self.ser.close()
        except Exception:
            pass

    def reset_input(self) -> None:
        try:
            self.ser.reset_input_buffer()
        except Exception:
            pass

    def reopen(self):
        self.close()
        time.sleep(0.2)
        return SerialTransport(self.port, self.baud, timeout=self.timeout)


# --------------------------------------------------------------------------- #
# Link layer
# --------------------------------------------------------------------------- #
class EcrLink:
    def __init__(
        self,
        transport: Transport,
        retries: int = 3,
        inquiry_timeout: float = 2.0,
        ack_timeout: float = 5.0,
        response_timeout: float = 180.0,
    ):
        self.tr = transport
        self.retries = retries
        self.inquiry_timeout = inquiry_timeout
        self.ack_timeout = ack_timeout
        self.response_timeout = response_timeout
        self._logged_in = False
        # True din momentul in care comanda (nu login-ul) a fost scrisa spre
        # terminal. Diferenta conteaza la erori: inainte de trimitere cardul
        # nu poate fi debitat (eroare de conexiune), dupa trimitere poate
        # (stare necunoscuta).
        self.sent = False

    def login(self) -> None:
        for _ in range(self.retries):
            self.tr.write(bytes([ENQ]))
            self.tr.set_timeout(self.inquiry_timeout)
            try:
                if self.tr.read_exact(1) == bytes([ACK]):
                    self._logged_in = True
                    return
            except EcrTimeout:
                pass
        self.tr.write(bytes([EOT]))
        raise EcrError("Login esuat: POS nu a raspuns cu ACK")

    def logout(self) -> None:
        if not self._logged_in:
            return
        try:
            self.tr.write(bytes([EOT]))
            self.tr.set_timeout(self.inquiry_timeout)
            self.tr.read_exact(1)
        except (EcrError, OSError):
            pass
        finally:
            self._logged_in = False

    def _read_frame(self) -> bytes:
        stx = self.tr.read_exact(1)
        if stx != bytes([STX]):
            raise EcrFrameError(f"STX invalid: {stx.hex()}")
        length = struct.unpack(">H", self.tr.read_exact(2))[0]
        payload = self.tr.read_exact(length)
        etx = self.tr.read_exact(1)
        crc = self.tr.read_exact(2)
        if etx != bytes([ETX]):
            raise EcrFrameError(f"ETX invalid: {etx.hex()}")
        crc_rx = crc[0] | (crc[1] << 8)  # response: LSB, MSB
        if crc16_ibm(payload) != crc_rx:
            raise EcrFrameError("CRC invalid")
        return payload

    def command(self, payload: bytes) -> dict:
        frame = build_request(payload)

        accepted = False
        for _ in range(self.retries):
            self.sent = True
            self.tr.write(frame)
            self.tr.set_timeout(self.ack_timeout)
            try:
                if self.tr.read_exact(1) == bytes([ACK]):
                    accepted = True
                    break
            except EcrTimeout:
                pass
        if not accepted:
            self.tr.write(bytes([EOT]))
            raise EcrError("Comanda nu a fost acceptata (fara ACK)")

        self.tr.set_timeout(self.response_timeout)
        for _ in range(self.retries):
            try:
                payload_rx = self._read_frame()
            except EcrFrameError:
                self.tr.write(bytes([NAK]))  # cere retrimiterea raspunsului
                continue
            except EcrTimeout:
                break
            self.tr.write(bytes([ACK]))
            return parse_tlvs(payload_rx)

        self.tr.write(bytes([EOT]))
        raise EcrUncertain("Raspuns invalid sau lipsa de la POS")


# --------------------------------------------------------------------------- #
# Operatii
# --------------------------------------------------------------------------- #
@dataclass
class InfoResult:
    software_version: str = ""
    hardware_version: str = ""
    printer_status: str = ""
    tags: dict = field(default_factory=dict)

    @classmethod
    def from_tags(cls, tags: dict) -> "InfoResult":
        return cls(
            software_version=_ascii(tags.get(0xA101)),
            hardware_version=_ascii(tags.get(0xA102)),
            printer_status=tags.get(TAG_PRINTER_STATUS, b"").hex(),
            tags=tags,
        )


@dataclass
class SaleResult:
    approved: bool = False
    ecr_code: int = -1
    host_code: str = ""
    response_text: str = ""
    terminal_id: str = ""
    merchant_id: str = ""
    payment_date: str = ""
    approved_amount: str = ""
    stan: str = ""
    rrn: str = ""
    auth_code: str = ""
    pan: str = ""
    cardholder: str = ""
    batch: str = ""
    emv_label: str = ""
    emv_id: str = ""
    unique_id: str = ""
    flags: int = 0
    tags: dict = field(default_factory=dict)

    @classmethod
    def from_tags(cls, tags: dict) -> "SaleResult":
        ecr = tags.get(TAG_RESPONSE, b"")
        ecr_code = ecr[0] if ecr else -1
        host = tags.get(TAG_HOST_CODE, b"")
        flags = tags.get(TAG_FLAGS, b"\x00")
        return cls(
            approved=(ecr_code == 0x00 and host in APPROVED_HOST_CODES),
            ecr_code=ecr_code,
            host_code=_ascii(host),
            response_text=_ascii(tags.get(TAG_RESPONSE_TEXT)),
            terminal_id=_ascii(tags.get(TAG_TERMINAL_ID)),
            merchant_id=_ascii(tags.get(TAG_MERCHANT_ID)),
            payment_date=_ascii(tags.get(TAG_PAYMENT_DATE)),
            approved_amount=_ascii(tags.get(TAG_APPROVED_AMOUNT)),
            stan=_ascii(tags.get(TAG_RECEIPT)),
            rrn=_ascii(tags.get(TAG_RRN)),
            auth_code=_ascii(tags.get(TAG_AUTH_CODE)),
            pan=_ascii(tags.get(TAG_PAN)),
            cardholder=_ascii(tags.get(TAG_CARDHOLDER)),
            batch=_ascii(tags.get(TAG_BATCH)),
            emv_label=_ascii(tags.get(TAG_EMV_LABEL)),
            emv_id=_ascii(tags.get(TAG_EMV_ID)),
            unique_id=_ascii(tags.get(TAG_UID_RESPONSE)),
            flags=flags[0] if flags else 0,
            tags=tags,
        )


def get_information(link: EcrLink, printer_status: bool = True) -> InfoResult:
    payload = tlv(TAG_COMMAND, bytes([CMD_GET_INFO]))
    if printer_status:
        payload += tlv(0xA009, b"\x01")
    return InfoResult.from_tags(link.command(payload))


def sale(
    link: EcrLink,
    amount,
    currency_name: str = "RON",
    currency_code: str = "946",
    unique_id: str = None,
    cash_back=None,
) -> SaleResult:
    payload = tlv(TAG_COMMAND, bytes([CMD_SALE]))
    payload += tlv(TAG_AMOUNT, amount_to_ascii(amount))
    payload += tlv(TAG_CURRENCY_NAME, currency_name.encode("ascii"))
    payload += tlv(TAG_CURRENCY_CODE, currency_code.encode("ascii"))
    if unique_id is not None:
        payload += tlv(TAG_UID, str(unique_id).encode("ascii"))
    payload += tlv(0xA007, amount_to_ascii(cash_back) if cash_back else b"000000000000")
    return SaleResult.from_tags(link.command(payload))


def report_initialization(
    link: EcrLink,
    currency_name: str = "RON",
    currency_code: str = "946",
) -> dict:
    """POS Report Initialization (4.7): numarul de tranzactii din jurnal."""
    payload = tlv(TAG_COMMAND, bytes([CMD_REPORT_INIT]))
    payload += tlv(TAG_CURRENCY_NAME, currency_name.encode("ascii"))
    payload += tlv(TAG_CURRENCY_CODE, currency_code.encode("ascii"))
    return link.command(payload)


def report_record(link: EcrLink, index: int) -> dict:
    """POS Report Record (4.8): detaliile tranzactiei de la indexul dat (0-based)."""
    payload = tlv(TAG_COMMAND, bytes([CMD_REPORT_RECORD]))
    payload += tlv(TAG_INDEX, f"{int(index):03d}".encode("ascii"))
    return link.command(payload)


def perform_void(link: EcrLink, stan: str, amount) -> dict:
    """POS Void (4.9): anuleaza o vanzare identificata prin STAN + suma."""
    payload = tlv(TAG_COMMAND, bytes([CMD_VOID]))
    payload += tlv(TAG_AMOUNT, amount_to_ascii(amount))
    payload += tlv(TAG_STAN, str(stan).strip().zfill(6).encode("ascii"))
    return link.command(payload)


def remove_card(link: EcrLink) -> dict:
    """POS Remove Card (4.12), comanda locala (nu merge la banca)."""
    return link.command(tlv(TAG_COMMAND, bytes([CMD_REMOVE_CARD])))


# --------------------------------------------------------------------------- #
# Afisare
# --------------------------------------------------------------------------- #
def print_info(result: InfoResult) -> None:
    print("-" * 78)
    print(f"POS Software Version : {result.software_version}")
    print(f"POS Hardware Version : {result.hardware_version}")
    print(f"POS Printer Status   : {result.printer_status}")
    print("-" * 78)


def print_sale(result: SaleResult) -> None:
    print("-" * 78)
    print(f"Terminal ID             : {result.terminal_id}")
    print(f"Merchant ID             : {result.merchant_id}")
    print(f"Payment Date            : {result.payment_date}")
    print(f"Approved Amount         : {result.approved_amount}")
    print(f"Host Response Code      : {result.host_code}")
    print(f"Host Response Text      : {result.response_text}")
    print(f"Batch Number            : {result.batch}")
    print(f"Receipt Number          : {result.stan}")
    print(f"Return Reference Number : {result.rrn}")
    print(f"Authorization Code      : {result.auth_code}")
    print(f"PAN                     : {result.pan}")
    print(f"EMV Application Label   : {result.emv_label}")
    print(f"EMV Application ID      : {result.emv_id}")
    print(f"Transaction Unique ID   : {result.unique_id}")
    print(f"Transaction Flags       : 0x{result.flags:02X}")
    if result.flags & 0x01:
        print("\t 0x01 - CHIP card")
    if result.flags & 0x02:
        print("\t 0x02 - Contactless card")
    if result.flags & 0x04:
        print("\t 0x04 - Magnetic Stripe card")
    if result.flags & 0x08:
        print("\t 0x08 - Signature request")
    if result.flags & 0x10:
        print("\t 0x10 - PIN was introduced")
    if result.flags & 0x20:
        print("\t 0x20 - Offline transaction")
    print("-" * 78)
    print(f"REZULTAT: {'APROBAT' if result.approved else 'NEAPROBAT'} "
          f"(ECR={result.ecr_code:#04x}, host={result.host_code or '-'})")


# --------------------------------------------------------------------------- #
# Fisier de rezultat pentru aplicatia POS-ECR
# --------------------------------------------------------------------------- #
def amount_str(value) -> str:
    return f"{Decimal(str(value).replace(',', '.')):.2f}"


def new_trx_id() -> str:
    return "TX" + time.strftime("%Y%m%d%H%M%S")


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


def clear_stale_session(transport: Transport) -> None:
    """Trimite un EOT inainte de login, ca sa inchida o sesiune ramasa
    deschisa de o rulare anterioara (ex. driver crapat fara EOT).

    Dupa EOT citim si aruncam raspunsul (ACK/NAK) pana la timeout: altfel un ACK
    ramas in buffer ar fi citit de `login()` ca raspuns la ENQ, iar ACK-ul
    urmator ar desincroniza citirea frame-ului de raspuns.
    """
    try:
        transport.reset_input()
        transport.write(bytes([EOT]))
        transport.set_timeout(0.3)
        deadline = time.time() + 0.8
        while time.time() < deadline:
            try:
                transport.read(64)
            except EcrTimeout:
                break
        transport.reset_input()
    except (OSError, EcrError):
        pass


def close_session(link: EcrLink, transport):
    """Inchide sesiunea cu EOT; daca scrierea esueaza (USB suspendat, port
    devalizat), redeschide portul si reincearca. Nu arunca niciodata."""
    if transport is None:
        return None
    sent = False
    for attempt in range(2):
        try:
            transport.write(bytes([EOT]))
            sent = True
            break
        except (OSError, EcrError):
            if attempt == 0:
                try:
                    transport.close()
                except Exception:
                    pass
                time.sleep(0.2)
                try:
                    transport = transport.reopen()
                    link.tr = transport
                except (OSError, EcrError):
                    break
    if sent:
        try:
            transport.set_timeout(1.0)
            try:
                transport.read_exact(1)
            except (EcrError, OSError):
                pass
        except (EcrError, OSError):
            pass
    link._logged_in = False
    return transport


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(description="Client ECR SmartPay (GetInfo + Sale)")
    p.add_argument("--env", default=None, help="Cale fisier .env (implicit: ./ .env)")
    p.add_argument("--transport", choices=["tcp", "serial"], default=None)
    p.add_argument("--host", default=None)
    p.add_argument("--port", type=int, default=None)
    p.add_argument("--serial-port", dest="serial_port", default=None)
    p.add_argument("--baud", type=int, default=None)
    p.add_argument("--currency-name", default=None)
    p.add_argument("--currency-code", default=None)
    p.add_argument("--timeout", type=float, default=None)
    p.add_argument("--retries", type=int, default=None)
    p.add_argument("--out", default=None, help="Fisier text de rezultat pentru POS-ECR")

    sub = p.add_subparsers(dest="command", required=True)
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--out", default=argparse.SUPPRESS,
                        help="Fisier text de rezultat pentru POS-ECR")

    sub.add_parser("info", parents=[common], help="POS Get Information")
    s = sub.add_parser("sale", parents=[common], help="POS Sale by Card")
    s.add_argument("amount", help="Suma, ex. 10.00")
    s.add_argument("--uid", default=None, help="ID unic tranzactie (A008)")
    s.add_argument("--cashback", default=None, help="Suma cash back (A007)")

    sub.add_parser("report-init", parents=[common],
                   help="POS Report Initialization (jurnalul terminalului)")
    r = sub.add_parser("report-record", parents=[common],
                       help="POS Report Record (o tranzactie din jurnal)")
    r.add_argument("--index", type=int, required=True,
                   help="Indexul tranzactiei (0-based)")

    rs = sub.add_parser("resync", parents=[common],
                        help="Re-sincronizare dupa conexiune pierduta (Appendix 3)")
    rs.add_argument("--uid", required=True, help="UID-ul tranzactiei de cautat")
    rs.add_argument("--void", action="store_true",
                    help="Anuleaza tranzactia daca este gasita in jurnal")
    rs.add_argument("--max-records", type=int, default=10,
                    help="Cate inregistrari sa verifice, de la ultima inapoi")

    v = sub.add_parser("void", parents=[common], help="POS Void")
    v.add_argument("--stan", required=True, help="STAN / numarul bonului (6 caractere)")
    v.add_argument("--amount", required=True, help="Suma de anulat")

    sub.add_parser("remove-card", parents=[common], help="POS Remove Card")
    return p


def _pick(cli_value, env, key, default, cast=str):
    if cli_value is not None:
        return cli_value
    raw = env.get(key)
    if raw not in (None, ""):
        return cast(raw)
    return default


def resolve_config(args) -> dict:
    env = load_dotenv(find_env(args.env))
    return {
        "transport": _pick(args.transport, env, "ECR_TRANSPORT", "tcp"),
        "host": _pick(args.host, env, "ECR_HOST", "127.0.0.1"),
        "port": _pick(args.port, env, "ECR_PORT", 5070, int),
        "serial_port": _pick(args.serial_port, env, "ECR_SERIAL_PORT", None),
        "baud": _pick(args.baud, env, "ECR_BAUD", 115200, int),
        "currency_name": _pick(args.currency_name, env, "ECR_CURRENCY_NAME", "RON"),
        "currency_code": _pick(args.currency_code, env, "ECR_CURRENCY_CODE", "946"),
        "timeout": _pick(args.timeout, env, "ECR_TIMEOUT", 180.0, float),
        "retries": _pick(args.retries, env, "ECR_RETRIES", 3, int),
        "out": _pick(getattr(args, "out", None), env, "ECR_OUTPUT_FILE", None),
        "conn_label": _pick(None, env, "ECR_CONN_LABEL", None),
        "env_used": find_env(args.env),
    }


def make_transport(cfg) -> Transport:
    if cfg["transport"] == "tcp":
        return TcpTransport(cfg["host"], cfg["port"], timeout=cfg["timeout"])
    if not cfg["serial_port"]:
        raise EcrError("ECR_SERIAL_PORT lipseste (sau --serial-port)")
    return SerialTransport(cfg["serial_port"], cfg["baud"], timeout=cfg["timeout"])


def _find_record(link: EcrLink, uid: str, max_records: int,
                 currency_name: str = "RON", currency_code: str = "946"):
    """Cauta UID-ul in jurnalul terminalului, de la ultima tranzactie inapoi."""
    init = report_initialization(link, currency_name, currency_code)
    code = _code(init)
    if code != 0:
        raise EcrError(f"Report initialization a esuat (cod {code:02X})")
    count_raw = _ascii(init.get(TAG_COUNT))
    try:
        count = int(count_raw)
    except ValueError:
        count = 0
    if count <= 0:
        return None, count
    last = count - 1
    first = max(0, last - max(1, int(max_records)) + 1)
    for index in range(last, first - 1, -1):
        tags = report_record(link, index)
        if _code(tags) != 0:
            continue
        if _ascii(tags.get(TAG_UID_RESPONSE)) == uid:
            return tags, count
    return None, count


def _write_unreachable_or_unknown(out: str, link: "EcrLink", suma: str,
                                 trx_id: str) -> int:
    """Scrie rezultatul unei erori de comunicatie la vanzare.

    Daca comanda nu a ajuns la terminal (conexiune/login esuat), cardul NU a
    putut fi debitat: scriem ERROR, iar POS-ul arata "terminalul nu raspunde"
    si lasa nota deblocata. Daca a plecat (fara ACK sau fara raspuns), poate fi
    o incasare: scriem UNKNOWN, ca operatorul sa verifice jurnalul inainte de
    reincercare (altfel s-ar putea taxa de doua ori).
    """
    if getattr(link, "sent", False):
        write_result_file(
            out, "UNKNOWN", "NU",
            "Stare necunoscuta: terminalul a acceptat comanda, dar nu a "
            "trimis raspuns. Verificati jurnalul terminalului inainte de "
            "reincercare.",
            suma=suma, trx_id=trx_id,
        )
        return 3
    write_result_file(
        out, "ERROR", "NU",
        "Nu s-a putut conecta la terminalul bancar (POS).",
        suma=suma, trx_id=trx_id,
    )
    return 2


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    cfg = resolve_config(args)
    out = cfg["out"]
    label = cfg["conn_label"] or ("retea" if cfg["transport"] == "tcp" else "serial")
    trx_id = getattr(args, "uid", None) or new_trx_id()
    suma = ""
    if args.command in ("sale", "void"):
        try:
            suma = amount_str(args.amount)
        except (ValueError, ArithmeticError):
            suma = str(getattr(args, "amount", ""))

    transport = None
    try:
        transport = make_transport(cfg)
    except (EcrError, OSError, ImportError) as exc:
        # ImportError = lipseste pyserial din interpretorul folosit.
        print(f"[EROARE] Conexiune ({label}): {exc}", file=sys.stderr)
        write_result_file(out, "ERROR", "NU",
                          "Nu s-a putut conecta la terminalul bancar (POS).",
                          suma=suma, trx_id=trx_id)
        return 2

    link = EcrLink(
        transport,
        retries=cfg["retries"],
        response_timeout=cfg["timeout"],
    )
    try:
        clear_stale_session(transport)
        link.login()
        if cfg["env_used"]:
            print(f"[CONFIG] .env: {cfg['env_used']}")
        if cfg["transport"] == "tcp":
            print(f"[CONFIG] TCP {cfg['host']}:{cfg['port']}")
        else:
            print(f"[CONFIG] Serial {cfg['serial_port']} @ {cfg['baud']}")

        if args.command == "info":
            result = get_information(link)
            write_result_file(
                out, "OK", "NU", "OK",
                extra={
                    "SOFTWARE": result.software_version,
                    "HARDWARE": result.hardware_version,
                    "PRINTER": result.printer_status,
                },
            )
            print_info(result)
            return 0

        if args.command == "sale":
            result = sale(
                link,
                args.amount,
                currency_name=cfg["currency_name"],
                currency_code=cfg["currency_code"],
                unique_id=trx_id,
                cash_back=args.cashback,
            )
            if result.approved:
                # Scriem rezultatul INAINTE de afisare: daca afisarea ar esua
                # (ex. consola inchisa), rezultatul ajunge tot la POS.
                write_result_file(out, "OK", "DA",
                                  result.response_text or "Tranzactie aprobata.",
                                  suma=suma, trx_id=result.unique_id or trx_id,
                                  rrn=result.rrn, stan=result.stan,
                                  extra={"AUTH": result.auth_code,
                                         "PAN": result.pan,
                                         "TERMINAL": result.terminal_id,
                                         "MERCHANT": result.merchant_id,
                                         "CARD": result.emv_label})
                print_sale(result)
                return 0

            if result.ecr_code == 0x09:
                mesaj = result.response_text or "Anulat de utilizator."
            else:
                mesaj = result.response_text or "Tranzactie respinsa."
            write_result_file(out, "ERROR", "NU", mesaj,
                              suma=suma, trx_id=result.unique_id or trx_id,
                              rrn=result.rrn, stan=result.stan,
                              extra={"AUTH": result.auth_code,
                                     "PAN": result.pan,
                                     "TERMINAL": result.terminal_id,
                                     "MERCHANT": result.merchant_id,
                                     "CARD": result.emv_label})
            print_sale(result)
            return 1

        if args.command == "report-init":
            tags = report_initialization(link, cfg["currency_name"], cfg["currency_code"])
            code = _code(tags)
            extra = {
                "CODE": "00" if code == 0 else f"{code:02X}",
                "COUNT": _ascii(tags.get(TAG_COUNT)),
                "BATCH": _ascii(tags.get(TAG_BATCH)),
                "TOTAL": format_amount(tags.get(TAG_TOTAL_AMOUNT, b"")),
            }
            write_result_file(out, "OK" if code == 0 else "ERROR", "NU",
                              "OK" if code == 0 else f"Cod {code:02X}", extra=extra)
            print(f"[REPORT INIT] code={code:02X} count={extra['COUNT']} "
                  f"batch={extra['BATCH']} total={extra['TOTAL']}")
            return 0 if code == 0 else 2

        if args.command == "report-record":
            tags = report_record(link, args.index)
            code = _code(tags)
            extra = _record_extra(tags)
            write_result_file(out, "OK" if code == 0 else "ERROR", "NU",
                              "OK" if code == 0 else f"Cod {code:02X}", extra=extra)
            print(f"[REPORT RECORD] index={args.index} code={code:02X} "
                  f"uid={extra['UID']} stan={extra['STAN']} "
                  f"amount={extra['AMOUNT']} type={extra['TYPE']}")
            return 0 if code == 0 else 2

        if args.command == "resync":
            uid = str(args.uid).strip()
            tags, count = _find_record(link, uid, args.max_records,
                                       cfg["currency_name"], cfg["currency_code"])
            if tags is None:
                write_result_file(
                    out, "NOTFOUND", "NU",
                    "Tranzactia nu apare in jurnalul terminalului (nu a fost aprobata).",
                    trx_id=uid,
                    extra={"FOUND": "0", "COUNT": count},
                )
                print(f"[RESYNC] uid={uid} nu a fost gasit in jurnal "
                      f"(count={count})")
                return 4

            extra = _record_extra(tags)
            extra["FOUND"] = "1"
            extra["COUNT"] = count
            # A112: 0 = vanzare, 1 = vanzare anulata, 2 = void. Doar o vanzare
            # normala (sau un tip lipsa) inseamna ca suma a fost incasata.
            approved = extra.get("TYPE") in (0, -1)
            extra["APPROVED"] = "1" if approved else "0"
            write_result_file(
                out, "OK", "NU",
                "Tranzactia a fost gasita in jurnalul terminalului."
                if approved else
                "Tranzactia apare in jurnal, dar a fost deja anulata.",
                trx_id=uid, suma=extra.get("AMOUNT", ""),
                rrn=extra.get("RRN", ""),
                stan=extra.get("STAN", ""), extra=extra,
            )
            print(f"[RESYNC] uid={uid} gasit: stan={extra.get('STAN')} "
                  f"amount={extra.get('AMOUNT')} type={extra.get('TYPE')} "
                  f"approved={extra['APPROVED']}")

            if args.void and approved:
                vtags = perform_void(link, extra.get("STAN", ""),
                                     extra.get("AMOUNT") or suma)
                vcode = _code(vtags)
                vhost = _ascii(vtags.get(TAG_HOST_CODE))
                voided = (vcode == 0 and vhost == "00")
                void_extra = _record_extra(vtags)
                void_extra["FOUND"] = "1"
                void_extra["APPROVED"] = "1" if approved else "0"
                void_extra["VOIDED"] = "1" if voided else "0"
                write_result_file(
                    out, "OK" if voided else "ERROR", "NU",
                    "Tranzactie anulata." if voided
                    else "Anularea a esuat; reincercati mai tarziu.",
                    trx_id=uid, suma=extra.get("AMOUNT", ""),
                    rrn=void_extra.get("RRN", extra.get("RRN", "")),
                    stan=extra.get("STAN", ""), extra=void_extra,
                )
                print(f"[RESYNC] void uid={uid} code={vcode:02X} host={vhost or '-'} "
                      f"-> {'OK' if voided else 'ESEC'}")
                return 0 if voided else 2
            return 0

        if args.command == "void":
            tags = perform_void(link, args.stan, args.amount)
            code = _code(tags)
            host = _ascii(tags.get(TAG_HOST_CODE))
            voided = (code == 0 and host == "00")
            extra = _record_extra(tags)
            extra["VOIDED"] = "1" if voided else "0"
            write_result_file(
                out, "OK" if voided else "ERROR", "NU",
                "Tranzactie anulata." if voided
                else "Anularea a esuat; reincercati mai tarziu.",
                suma=suma, rrn=extra.get("RRN", ""), stan=args.stan,
                extra=extra,
            )
            print(f"[VOID] stan={args.stan} amount={suma} code={code:02X} "
                  f"host={host or '-'} -> {'OK' if voided else 'ESEC'}")
            return 0 if voided else 2

        if args.command == "remove-card":
            tags = remove_card(link)
            code = _code(tags)
            write_result_file(out, "OK" if code == 0 else "ERROR", "NU",
                              "OK" if code == 0 else f"Cod {code:02X}")
            print(f"[REMOVE CARD] code={code:02X}")
            return 0 if code == 0 else 2

        raise EcrError(f"Comanda necunoscuta: {args.command}")
    except EcrUncertain as exc:
        print(f"[EROARE] Comunicatie ({label}): {exc}", file=sys.stderr)
        if args.command == "sale":
            write_result_file(
                out, "UNKNOWN", "NU",
                "Stare necunoscuta: terminalul a acceptat comanda, dar nu a "
                "trimis raspuns. Verificati jurnalul terminalului inainte de "
                "reincercare.",
                suma=suma, trx_id=trx_id,
            )
            return 3
        write_result_file(out, "ERROR", "NU",
                          "Eroare de comunicatie cu terminalul bancar (POS).",
                          suma=suma, trx_id=trx_id)
        return 2
    except EcrError as exc:
        print(f"[EROARE] Comunicatie ({label}): {exc}", file=sys.stderr)
        if args.command == "sale":
            return _write_unreachable_or_unknown(out, link, suma, trx_id)
        write_result_file(out, "ERROR", "NU",
                          "Eroare de comunicatie cu terminalul bancar (POS).",
                          suma=suma, trx_id=trx_id)
        return 2
    except OSError as exc:
        print(f"[EROARE] Comunicatie ({label}): {exc}", file=sys.stderr)
        if args.command == "sale":
            return _write_unreachable_or_unknown(out, link, suma, trx_id)
        write_result_file(out, "ERROR", "NU",
                          "Eroare de comunicatie cu terminalul bancar (POS).",
                          suma=suma, trx_id=trx_id)
        return 2
    finally:
        if transport is not None:
            try:
                transport = close_session(link, transport)
            except Exception:
                pass
            try:
                transport.close()
            except Exception:
                pass


if __name__ == "__main__":
    sys.exit(main())
