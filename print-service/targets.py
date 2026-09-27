"""Livrarea efectiva a unui flux ESC/POS catre o tinta configurabila.

Tinte suportate:
  preview  - randeaza bonul ca HTML (emulatorul, pana vine imprimanta)
  file     - scrie octetii intr-un fisier spool
  windows  - trimite RAW la o imprimanta Windows (necesita pywin32)
  network  - trimite la o imprimanta de retea (IP:9100)
"""

import os
import socket
import time

from emulator import save_preview


class TargetError(Exception):
    """Eroare de livrare care merita reincercata (imprimanta indisponibila)."""


def _status_bits():
    # PRINTER_STATUS_* din API-ul Windows
    return {
        "paused": 0x00000001,
        "error": 0x00000002,
        "offline": 0x00000080,
        "not_available": 0x00001000,
    }


def check_windows_printer(name):
    """Verifica starea imprimantei Windows. Ridica TargetError daca nu e ok."""
    try:
        import win32print  # type: ignore
    except ImportError as exc:
        raise TargetError("pywin32 nu este instalat (pip install pywin32)") from exc

    try:
        handle = win32print.OpenPrinter(name)
    except Exception as exc:  # pragma: no cover - depinde de Windows
        raise TargetError("Nu pot deschide imprimanta '%s': %s" % (name, exc)) from exc

    try:
        info = win32print.GetPrinter(handle, 2)
        status = int(info.get("Status", 0))
        bits = _status_bits()
        if status & (bits["offline"] | bits["error"] | bits["not_available"] | bits["paused"]):
            raise TargetError("Imprimanta '%s' este offline/indisponibila (status=%d)" % (name, status))
    finally:
        win32print.ClosePrinter(handle)


def _print_windows(name, data):
    check_windows_printer(name)
    import win32print  # type: ignore

    handle = win32print.OpenPrinter(name)
    try:
        job = win32print.StartDocPrinter(handle, 1, ("TableManager", None, "RAW"))
        try:
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, data)
            win32print.EndPagePrinter(handle)
        finally:
            win32print.EndDocPrinter(handle)
        return "trimis la imprimanta '%s' (job %s)" % (name, job)
    except Exception as exc:
        raise TargetError("Eroare trimitere la '%s': %s" % (name, exc)) from exc
    finally:
        win32print.ClosePrinter(handle)


def _print_network(host, port, data, timeout=5):
    try:
        sock = socket.create_connection((host, int(port)), timeout=timeout)
    except OSError as exc:
        raise TargetError("Nu pot conecta imprimanta %s:%s: %s" % (host, port, exc)) from exc
    try:
        sock.sendall(data)
    finally:
        sock.close()
    return "trimis la %s:%s" % (host, port)


def deliver(target, data, cfg, job_id=None):
    """Livreaza octetii catre tinta. Intoarce un mesaj descriptiv."""
    kind = (target or {}).get("target", "preview")

    if kind == "preview":
        path, url = save_preview(data, cfg["preview_dir"], job_id=job_id,
                                 charset=cfg.get("charset", "ascii"))
        return "previzualizare: %s" % url

    if kind == "file":
        folder = target.get("dir") or cfg["fiscal_spool_dir"]
        os.makedirs(folder, exist_ok=True)
        name = target.get("filename") or ("print_%s_%d.bin" % (job_id, int(time.time())))
        path = os.path.join(folder, name)
        with open(path, "wb") as fh:
            fh.write(data)
        return "scris in %s" % path

    if kind == "windows":
        name = target.get("name") or target.get("printer")
        if not name:
            raise TargetError("Tinta 'windows' nu are numele imprimantei")
        return _print_windows(name, data)

    if kind == "network":
        host = target.get("host")
        if not host:
            raise TargetError("Tinta 'network' nu are host")
        return _print_network(host, target.get("port", 9100), data)

    raise TargetError("Tinta necunoscuta: %s" % kind)
