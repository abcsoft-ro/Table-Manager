#!/usr/bin/env python3
"""Simulator SmartPay ECR Link pentru testarea POS-ului bancar (TCP).

Implementeaza comenzile folosite de `tools/pos_banca/smartpay_client.py`:
Get Information, Sale, Report Initialization, Report Record, Void, Remove Card.

Moduri de vanzare (`--mode`):
    random                 70% aprobat / 20% refuzat / 10% anulat (ca simulatorul vechi)
    ok                     aprobat (adauga tranzactia in jurnal)
    decline                refuzat (nu intra in jurnal)
    cancel                 anulat de utilizator (nu intra in jurnal)
    silent                 accepta comanda (ACK), dar nu trimite raspuns (clientul
                           asteapta pana la ECR_TIMEOUT); implicit nu intra in jurnal
    silent-random          ca silent, dar 50/50 intra sau nu in jurnal
    accept-silent          ca silent, dar inregistreaza vanzarea APROBATA in jurnal
                           (simuleaza exact cazul "stare necunoscuta, dar incasata")
    accept-silent-random   ca accept-silent, dar 50/50 intra sau nu in jurnal
    disconnect             ca accept-silent, dar inchide conexiunea imediat dupa ACK
                           (clientul primeste repede UNKNOWN, fara sa astepte 180 s)
    disconnect-random      ca disconnect, dar 50/50 intra sau nu in jurnal
                           (alternativa rapida pentru testarea ambelor ramuri:
                           APPROVED gasit in jurnal / NOT FOUND)
    test_general           test cap-coada al platii pe card: fiecare incercare
                           primeste, cu probabilitate egala (1/5), unul dintre:
                           1. aprobat (bon fiscal emis);
                           2. neaprobat (PLATA REFUZATA);
                           3. stare necunoscuta, dar tranzactia este in jurnal
                              (resync-ul o gaseste -> nota se inchide automat);
                           4. stare necunoscuta, tranzactia nu este in jurnal si
                              nici verificarea (report init) nu primeste raspuns
                              (PLATA NECLARIFICATA -> solutionare manuala);
                           5. terminalul nu raspunde deloc - conexiunea este
                              inchisa inainte de login, deci driverul esueaza
                              inainte sa trimita comanda (TERMINALUL NU RASPUNDE).
                           Verificarea automata de dupa o vanzare incerta nu este
                           sabotata, ca sa se poata testa deblocarea automata.

Probabilitatea se poate fixa si cu `--journal-prob 0..1` (ex. 0.5). Pentru
modurile `disconnect`/`accept-silent` valoarea implicita este 1.0 (mereu in
jurnal), pentru `silent` este 0.0, iar pentru variantele `*-random` este 0.5.
Valoarea se aplica doar modurilor care nu trimit raspuns; `ok` adauga mereu.

Jurnalul este tinut in memorie; cu `--journal-file fisier.json` este incarcat
la pornire si salvat dupa fiecare vanzare/void, ca sa poata fi testata
re-sincronizarea si dupa restartul simulatorului.

Exemple:
    python mock_pos_server.py --mode ok --port 5070
    python mock_pos_server.py --mode disconnect --port 5070 --journal-file jurnal.json
    python mock_pos_server.py --mode disconnect-random --port 5070 --journal-file jurnal.json
    python mock_pos_server.py --mode disconnect --journal-prob 0.5 --port 5070 --journal-file jurnal.json
    python mock_pos_server.py --mode ok --port 5070 --journal-file jurnal.json --delay 0
    python mock_pos_server.py --mode test_general --port 5070 --journal-file jurnal.json

Din alt terminal (sau din POS, cu PoSbanca=1 si .env pe TCP 127.0.0.1:5070):
    python smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 sale 1.00 --uid TEST1
    python smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 resync --uid TEST1
    python smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 void --stan 000001 --amount 1.00
"""

import argparse
import json
import os
import random
import socket
import struct
import sys
import time

STX = 0x02
ETX = 0x03
ENQ = 0x05
ACK = 0x06
NAK = 0x15
EOT = 0x04

SOFTWARE_VERSION = b'1.03.081'
HARDWARE_VERSION = b'000018690000;20001455'.ljust(32)
PRINTER_STATUS = b'\x00\x00\x00\x00'

MODES = (
    'random', 'ok', 'decline', 'cancel',
    'silent', 'silent-random',
    'accept-silent', 'accept-silent-random',
    'disconnect', 'disconnect-random',
    'test_general',
)

# Scenariile modului test_general (probabilitate egala). "unreachable" se
# decide la conectare, inainte de login (vezi handle_connection), celelalte
# patru la vanzare.
TEST_GENERAL_SALE_SCENARIOS = ('ok', 'decline', 'unknown-found', 'unknown-mute')


def out(msg):
    """Print tolerant la codificarea consolei Windows."""
    try:
        print(msg, flush=True)
    except (UnicodeEncodeError, OSError):
        try:
            print(str(msg).encode('ascii', 'replace').decode('ascii'), flush=True)
        except Exception:
            pass


def crc16_ibm(data):
    crc = 0x0000
    for b in data:
        crc ^= (b << 8) & 0xFFFF
        for _ in range(8):
            if crc & 0x8000:
                crc = ((crc << 1) ^ 0x8005) & 0xFFFF
            else:
                crc = (crc << 1) & 0xFFFF
    return crc


def tlv(tag, value):
    return struct.pack('>HB', tag, len(value)) + value


def build_frame(payload):
    length = len(payload)
    crc = crc16_ibm(payload)
    return (
        bytes([STX])
        + struct.pack('>H', length)
        + payload
        + bytes([ETX])
        + bytes([crc & 0xFF, (crc >> 8) & 0xFF])  # response: LSB, MSB
    )


def parse_tlvs(payload):
    tags = {}
    i = 0
    while i + 3 <= len(payload):
        tag = struct.unpack('>H', payload[i:i + 2])[0]
        ln = payload[i + 2]
        tags[tag] = payload[i + 3:i + 3 + ln]
        i += 3 + ln
    return tags


def ascii_value(raw, default=''):
    if raw is None:
        return default
    return raw.decode('ascii', 'replace').rstrip()


def amount_cents(raw):
    try:
        return int((raw or b'0').decode('ascii'))
    except (ValueError, AttributeError):
        return 0


def cents_ascii(cents):
    return f'{int(cents):012d}'.encode('ascii')


def hex_text(raw):
    return raw.hex() if isinstance(raw, (bytes, bytearray)) else str(raw)


class Simulator:
    def __init__(self, args):
        self.args = args
        self.batch = (args.batch or '000001')[:6].rjust(6, '0')
        self.terminal_id = (args.terminal_id or 'TETRA001')[:8]
        self.merchant_id = (args.merchant_id or 'TETRA001')[:15]
        self.stan_seq = max(0, int(args.start_stan or 1)) - 1
        self.records = self.load_journal()
        # test_general: urmeaza o verificare automata (resync) dupa o vanzare
        # incerta -> nu sabotam conexiunea cu "terminalul oprit", ca sa se poata
        # testa si deblocarea automata. mute_journal: urmatoarea comanda de
        # jurnal (report init) nu primeste raspuns.
        self.expect_resync = False
        self.mute_journal = False

    # ------------------------------------------------------------------ jurnal
    def load_journal(self):
        path = self.args.journal_file
        if not path:
            return []
        try:
            with open(path, encoding='utf-8') as fh:
                data = json.load(fh)
            records = data if isinstance(data, list) else []
        except (OSError, ValueError):
            records = []
        # Continuam numerotarea STAN dupa jurnalul existent, ca un restart sa
        # nu produca STAN duplicat (altfel void-ul ar putea tinti o vanzare veche).
        max_stan = 0
        for record in records:
            try:
                max_stan = max(max_stan, int(str(record.get('STAN', '0')).strip() or 0))
            except (TypeError, ValueError):
                pass
        if max_stan > self.stan_seq:
            self.stan_seq = max_stan
        return records

    def save_journal(self):
        path = self.args.journal_file
        if not path:
            return
        try:
            with open(path, 'w', encoding='utf-8') as fh:
                json.dump(self.records, fh, indent=1, ensure_ascii=False)
        except OSError as exc:
            out(f'[SIMULATOR] Nu pot scrie jurnalul {path}: {exc}')

    def next_stan(self):
        self.stan_seq += 1
        return f'{self.stan_seq:06d}'

    def journal_probability(self, mode):
        """Probabilitatea ca o vanzare fara raspuns sa fie pusa in jurnal."""
        if self.args.journal_prob is not None:
            return max(0.0, min(1.0, float(self.args.journal_prob)))
        if mode.endswith('-random'):
            return 0.5
        if mode in ('accept-silent', 'disconnect'):
            return 1.0
        return 0.0

    def should_journal(self, mode):
        return random.random() < self.journal_probability(mode)

    def add_sale(self, tags, approved_text='TRANZ. ACCEPTATA'):
        amount = tags.get(0xA001, b'000000000000')
        uid = ascii_value(tags.get(0xA008))
        if not uid:
            uid = self.args.uid or ('SIM' + time.strftime('%Y%m%d%H%M%S'))
        stan = self.next_stan()
        rrn = f'{random.randint(10**11, 10**12 - 1)}'
        record = {
            'TYPE': 0,
            'UID': uid,
            'STAN': stan,
            'AMOUNT': ascii_value(amount, '000000000000'),
            'DATE': time.strftime('%Y%m%d%H%M%S'),
            'RRN': rrn,
            'AUTH': f'{random.randint(100000, 999999)}',
            'PAN': '**3337',
            'HOLDER': 'CARD VISA TEST',
            'BATCH': self.batch,
            'HOST': '00',
            'TEXT': approved_text,
            'FLAGS': '02',
            'EMV_LABEL': 'VISA CREDIT',
            'EMV_ID': 'A0000000031010',
        }
        self.records.append(record)
        self.save_journal()
        out(f'[SIMULATOR] Vanzare adaugata in jurnal: UID={uid} STAN={stan} '
            f'suma={record["AMOUNT"]} tip=0')
        return record

    def find_void(self, stan, amount):
        for record in reversed(self.records):
            if record.get('TYPE') == 0 and record.get('STAN') == stan:
                if ascii_value(amount, '') and ascii_value(amount, '') != record.get('AMOUNT'):
                    continue
                record['TYPE'] = 1
                record['TEXT'] = 'TRANZACTIE ANULATA'
                self.save_journal()
                out(f'[SIMULATOR] Void: STAN={stan} marcat ca anulat (tip=1)')
                return record
        return None

    # -------------------------------------------------------------- raspunsuri
    def info_response(self):
        payload = tlv(0xA100, b'\x00')
        payload += tlv(0xA101, SOFTWARE_VERSION)
        payload += tlv(0xA102, HARDWARE_VERSION)
        payload += tlv(0xA111, PRINTER_STATUS)
        return build_frame(payload)

    def sale_response(self, record):
        payload = tlv(0xA100, b'\x00')
        payload += tlv(0xA103, self.terminal_id.encode('ascii'))
        payload += tlv(0xA104, self.merchant_id.encode('ascii'))
        payload += tlv(0xA105, record['DATE'].encode('ascii'))
        payload += tlv(0xA106, record['AMOUNT'].encode('ascii'))
        payload += tlv(0xA107, b'00')
        payload += tlv(0xA108, b'TRANZ. ACCEPTATA')
        payload += tlv(0xA109, record['STAN'].encode('ascii'))
        payload += tlv(0xA10F, self.batch.encode('ascii'))
        payload += tlv(0xA10A, record['RRN'].encode('ascii'))
        payload += tlv(0xA10B, record['AUTH'].encode('ascii'))
        payload += tlv(0xA10C, record['PAN'].encode('ascii'))
        payload += tlv(0xA10D, record['HOLDER'].encode('ascii'))
        payload += tlv(0xA113, record['EMV_LABEL'].encode('ascii'))
        payload += tlv(0xA114, record['EMV_ID'].encode('ascii'))
        payload += tlv(0xA116, bytes([int(record['FLAGS'], 16)]))
        payload += tlv(0xA117, record['UID'].encode('ascii'))
        return build_frame(payload)

    def declined_response(self, code, text, host=None):
        payload = tlv(0xA100, code)
        payload += tlv(0xA103, self.terminal_id.encode('ascii'))
        payload += tlv(0xA104, self.merchant_id.encode('ascii'))
        payload += tlv(0xA105, time.strftime('%Y%m%d%H%M%S').encode('ascii'))
        payload += tlv(0xA108, text.encode('ascii'))
        if host:
            payload += tlv(0xA107, host.encode('ascii'))
        payload += tlv(0xA10F, self.batch.encode('ascii'))
        return build_frame(payload)

    def recovery_init_response(self):
        total = 0
        for record in self.records:
            if record.get('TYPE') == 0:
                total += amount_cents(record.get('AMOUNT', '0').encode('ascii'))
        payload = tlv(0xA100, b'\x00')
        payload += tlv(0xA10E, f'{len(self.records):03d}'.encode('ascii'))
        payload += tlv(0xA10F, self.batch.encode('ascii'))
        payload += tlv(0xA110, cents_ascii(total))
        return build_frame(payload)

    def recovery_record_response(self, index):
        if index < 0 or index >= len(self.records):
            return build_frame(tlv(0xA100, b'\x03'))  # out of range
        record = self.records[index]
        payload = tlv(0xA100, b'\x00')
        payload += tlv(0xA103, self.terminal_id.encode('ascii'))
        payload += tlv(0xA104, self.merchant_id.encode('ascii'))
        payload += tlv(0xA105, record.get('DATE', '').encode('ascii'))
        payload += tlv(0xA106, record.get('AMOUNT', '000000000000').encode('ascii'))
        payload += tlv(0xA107, record.get('HOST', '00').encode('ascii'))
        payload += tlv(0xA108, record.get('TEXT', '').encode('ascii'))
        payload += tlv(0xA109, record.get('STAN', '').encode('ascii'))
        payload += tlv(0xA10A, record.get('RRN', '').encode('ascii'))
        payload += tlv(0xA10B, record.get('AUTH', '').encode('ascii'))
        payload += tlv(0xA10C, record.get('PAN', '').encode('ascii'))
        payload += tlv(0xA10D, record.get('HOLDER', '').encode('ascii'))
        payload += tlv(0xA10F, record.get('BATCH', self.batch).encode('ascii'))
        payload += tlv(0xA112, bytes([int(record.get('TYPE', 0))]))
        payload += tlv(0xA113, record.get('EMV_LABEL', '').encode('ascii'))
        payload += tlv(0xA114, record.get('EMV_ID', '').encode('ascii'))
        payload += tlv(0xA116, bytes([int(record.get('FLAGS', '00'), 16)]))
        payload += tlv(0xA117, record.get('UID', '').encode('ascii'))
        return build_frame(payload)

    def void_response(self, record):
        payload = tlv(0xA100, b'\x00')
        payload += tlv(0xA103, self.terminal_id.encode('ascii'))
        payload += tlv(0xA104, self.merchant_id.encode('ascii'))
        payload += tlv(0xA105, time.strftime('%Y%m%d%H%M%S').encode('ascii'))
        payload += tlv(0xA106, record.get('AMOUNT', '').encode('ascii'))
        payload += tlv(0xA107, b'00')
        payload += tlv(0xA108, b'TRANZACTIE ANULATA')
        payload += tlv(0xA109, record.get('STAN', '').encode('ascii'))
        payload += tlv(0xA10A, record.get('RRN', '').encode('ascii'))
        payload += tlv(0xA10F, record.get('BATCH', self.batch).encode('ascii'))
        payload += tlv(0xA112, b'\x01')
        payload += tlv(0xA117, record.get('UID', '').encode('ascii'))
        return build_frame(payload)

    # ------------------------------------------------------------ procesare
    def process(self, conn, frame, length):
        payload = frame[3:3 + length]
        etx = frame[3 + length]
        crc_rx = (frame[3 + length + 1] << 8) | frame[3 + length + 2]
        crc_calc = crc16_ibm(payload)

        if etx != ETX or crc_calc != crc_rx:
            out(f'[SIMULATOR] Pachet INVALID (ETX={etx:#04x}, '
                f'CRC={crc_calc:#06x}/{crc_rx:#06x}) -> NAK')
            conn.sendall(bytes([NAK]))
            return True

        tags = parse_tlvs(payload)
        cmd_raw = tags.get(0xA000, b'')
        cmd = cmd_raw[0] if cmd_raw else 0xFF
        out(f'[SIMULATOR] Comanda {cmd:#04x}')

        # POS accepta intotdeauna comanda (ca terminalul real).
        conn.sendall(bytes([ACK]))

        if cmd == 0x01:
            conn.sendall(self.info_response())
            return True

        if cmd == 0x02:
            return self.handle_sale(conn, tags)

        if cmd == 0x04:
            # test_general: o vanzare incerta fara urma in jurnal lasa si
            # verificarea fara raspuns -> POS-ul cere solutionarea manuala.
            self.expect_resync = False
            if self.mute_journal:
                self.mute_journal = False
                out('[SIMULATOR] test_general: jurnalul nu raspunde -> resync fara raspuns')
                try:
                    conn.close()
                except OSError:
                    pass
                return False
            time.sleep(0.2)
            conn.sendall(self.recovery_init_response())
            return True

        if cmd == 0x05:
            index_raw = ascii_value(tags.get(0xA004), '000')
            try:
                index = int(index_raw)
            except ValueError:
                index = -1
            time.sleep(0.2)
            conn.sendall(self.recovery_record_response(index))
            return True

        if cmd == 0x06:
            stan = ascii_value(tags.get(0xA006))
            amount = tags.get(0xA001, b'')
            record = self.find_void(stan, amount)
            time.sleep(0.2)
            if record is None:
                out(f'[SIMULATOR] Void: STAN={stan} negasit -> eroare 03')
                conn.sendall(build_frame(tlv(0xA100, b'\x03')))
            else:
                conn.sendall(self.void_response(record))
            return True

        if cmd == 0x09:
            conn.sendall(build_frame(tlv(0xA100, b'\x00')))
            return True

        conn.sendall(build_frame(tlv(0xA100, b'\x04')))  # invalid input parameters
        return True

    def handle_sale(self, conn, tags):
        raw_mode = self.args.mode
        mode = raw_mode
        if mode == 'random':
            chance = random.randint(1, 100)
            mode = 'ok' if chance <= 70 else ('decline' if chance <= 90 else 'cancel')
        # O vanzare noua este o incercare noua: orice asteptare de verificare
        # ramasa de la o incercare anterioara este anulata.
        self.expect_resync = False
        if mode == 'test_general':
            mode = random.choice(TEST_GENERAL_SALE_SCENARIOS)
            out(f'[SIMULATOR] test_general: scenariu "{mode}" '
                f'(probabilitate 1/5 fiecare, al 5-lea = terminal oprit)')
        if mode.endswith('-random'):
            mode = mode[:-len('-random')]

        if mode == 'decline':
            out('[SIMULATOR] Vanzare REFUZATA')
            time.sleep(self.args.delay)
            conn.sendall(self.declined_response(b'\x01', 'TRANZACTIE RESPINSA', 'Z3'))
            return True

        if mode == 'cancel':
            out('[SIMULATOR] Vanzare ANULATA de utilizator')
            time.sleep(self.args.delay)
            conn.sendall(self.declined_response(b'\x09', 'Anulat de utilizator.'))
            return True

        if mode == 'unknown-found':
            # Stare necunoscuta, dar tranzactia intra in jurnal: verificarea
            # automata o gaseste si nota se inchide fara niciun click.
            self.add_sale(tags)
            self.expect_resync = True
            out('[SIMULATOR] test_general: APROBATA in jurnal, fara raspuns '
                '(resync-ul o va gasi -> deblocare automata)')
            try:
                conn.close()
            except OSError:
                pass
            return False

        if mode == 'unknown-mute':
            # Stare necunoscuta fara urma in jurnal + verificarea fara raspuns:
            # POS-ul ajunge la "PLATA NECLARIFICATA" (solutionare manuala).
            self.expect_resync = True
            self.mute_journal = True
            out('[SIMULATOR] test_general: NEINREGISTRATA, fara raspuns + '
                'resync fara raspuns (deblocare manuala)')
            try:
                conn.close()
            except OSError:
                pass
            return False

        if mode == 'silent':
            if self.should_journal(raw_mode):
                self.add_sale(tags)
                out(f'[SIMULATOR] Vanzare IN JURNAL (aprobata), fara raspuns ({raw_mode})')
            else:
                out(f'[SIMULATOR] Vanzare NEINREGISTRATA, fara raspuns ({raw_mode})')
            return True

        if mode in ('accept-silent', 'disconnect'):
            if self.should_journal(raw_mode):
                self.add_sale(tags)
                out(f'[SIMULATOR] Vanzare APROBATA in jurnal, fara raspuns ({raw_mode})')
            else:
                out(f'[SIMULATOR] Vanzare NEINREGISTRATA (nu apare in jurnal), fara raspuns ({raw_mode})')
            if mode == 'disconnect':
                try:
                    conn.close()
                except OSError:
                    pass
                return False
            return True

        record = self.add_sale(tags)
        out('[SIMULATOR] Vanzare APROBATA')
        time.sleep(self.args.delay)
        conn.sendall(self.sale_response(record))
        return True


def handle_connection(conn, addr, sim):
    out(f'[SIMULATOR] Conexiune de la {addr}')
    # test_general, scenariul "terminalul nu raspunde deloc": inchidem conexiunea
    # INAINTE de login, deci driverul esueaza inainte sa trimita comanda (sent
    # ramane False) si POS-ul afiseaza TERMINALUL NU RASPUNDE, nu PLATA
    # NECLARIFICATA. Nu sabotam insa verificarea automata de dupa o vanzare
    # incerta (expect_resync), ca sa putem testa si deblocarea automata.
    if (sim.args.mode == 'test_general' and not sim.expect_resync
            and random.random() < 0.2):
        out('[SIMULATOR] test_general: terminalul nu raspunde deloc '
            '(conexiune inchisa inainte de login)')
        try:
            conn.close()
        except OSError:
            pass
        return
    conn.settimeout(180.0)
    buffer = b''
    try:
        while True:
            try:
                data = conn.recv(4096)
            except socket.timeout:
                out('[SIMULATOR] Timeout de citire, inchid conexiunea.')
                break
            except OSError:
                break
            if not data:
                break
            buffer += data

            keep_open = True
            while buffer:
                first = buffer[0]

                if first == ENQ:
                    conn.sendall(bytes([ACK]))
                    buffer = buffer[1:]
                elif first == EOT:
                    # EOT = logout: ACK si continuam (clientul poate trimite
                    # apoi un ENQ nou pe aceeasi conexiune; ex. clear_stale_session).
                    conn.sendall(bytes([ACK]))
                    buffer = buffer[1:]
                elif first in (ACK, NAK):
                    buffer = buffer[1:]
                elif first == STX:
                    if len(buffer) < 3:
                        break
                    length = struct.unpack('>H', buffer[1:3])[0]
                    total = 3 + length + 1 + 2
                    if len(buffer) < total:
                        break
                    frame = buffer[:total]
                    buffer = buffer[total:]
                    keep_open = sim.process(conn, frame, length)
                    if keep_open is False:
                        return
                else:
                    out(f'[SIMULATOR] Octet necunoscut {first:#04x}, resincronizare.')
                    buffer = buffer[1:]
    except Exception as exc:
        out(f'[SIMULATOR EROARE]: {exc}')
    finally:
        try:
            conn.close()
        except OSError:
            pass


def build_parser():
    p = argparse.ArgumentParser(description='Simulator SmartPay ECR Link (POS bancar, TCP)')
    p.add_argument('--host', default=os.environ.get('POS_HOST', '0.0.0.0'))
    p.add_argument('--port', type=int, default=int(os.environ.get('POS_PORT', '5070')))
    p.add_argument('--mode', choices=MODES, default='random')
    p.add_argument('--journal-prob', type=float, default=None,
                   help='Probabilitatea ca o vanzare fara raspuns (silent/accept-silent/'
                        'disconnect) sa intre in jurnal, 0..1. Implicit: 1.0 pentru '
                        'accept-silent/disconnect, 0.0 pentru silent, 0.5 pentru *-random')
    p.add_argument('--journal-file', default=None,
                   help='Fisier JSON pentru persistenta jurnalului intre restarturi')
    p.add_argument('--batch', default='000001')
    p.add_argument('--terminal-id', default='TETRA001')
    p.add_argument('--merchant-id', default='TETRA001')
    p.add_argument('--start-stan', type=int, default=1)
    p.add_argument('--uid', default=None,
                   help='UID folosit daca cererea de vanzare nu contine A008')
    p.add_argument('--delay', type=float, default=1.5,
                   help='Intarzierea raspunsului la vanzare (secunde)')
    return p


def main():
    try:
        sys.stdout.reconfigure(errors='replace')
    except Exception:
        pass
    parser = build_parser()
    args = parser.parse_args()
    if args.journal_prob is not None and not (0.0 <= args.journal_prob <= 1.0):
        parser.error('--journal-prob trebuie sa fie intre 0 si 1')
    sim = Simulator(args)
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((args.host, args.port))
    server.listen(1)
    server.settimeout(1.0)

    silent_mode = (
        args.mode in ('silent', 'accept-silent', 'disconnect')
        or args.mode.endswith('-silent') or args.mode.endswith('-random')
    )
    jp_text = f'{sim.journal_probability(args.mode):.2f}' if silent_mode else 'n/a'
    out(f'[SIMULATOR] Pornit pe {args.host}:{args.port}, mod={args.mode}, '
        f'jurnal_prob={jp_text}, jurnal={len(sim.records)} tranzactii'
        + (f' ({args.journal_file})' if args.journal_file else ''))
    try:
        while True:
            try:
                conn, addr = server.accept()
            except socket.timeout:
                continue
            handle_connection(conn, addr, sim)
    except KeyboardInterrupt:
        out('\n[SIMULATOR] Oprit cu Ctrl+C.')
    finally:
        server.close()


if __name__ == '__main__':
    main()
