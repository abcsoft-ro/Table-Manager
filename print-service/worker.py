"""Worker-ul serviciului de tiparire.

Ridica joburi din coada durabila (tblPrintQueue, prin api/print_queue.php),
construieste continutul ESC/POS si il livreaza catre tinta configurata.
La esec reincearca cu backoff, pana cand imprimanta revine.
"""

import json
import os
import ssl
import threading
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request

from cleanup import run_cleanup
from config import printer_target
from escpos import EscposBuilder, transliterate
from targets import TargetError, deliver


def log(msg, cfg=None):
    line = "[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg)
    print(line, flush=True)
    path = (cfg or {}).get("log_file")
    if not path:
        return
    try:
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except OSError:
        pass


# ---------------------------------------------------------------- HTTP helper
def _ssl_context(cfg, url):
    if str(url).lower().startswith("https") and not cfg.get("verify_ssl", True):
        return ssl._create_unverified_context()
    return None


def _http_json(url, payload=None, timeout=15, context=None):
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers,
                                 method=("POST" if payload is not None else "GET"))
    with urllib.request.urlopen(req, timeout=timeout, context=context) as resp:
        body = resp.read().decode("utf-8", "replace")
    return json.loads(body) if body.strip() else {}


def _api(cfg, endpoint, payload=None):
    base = cfg["api_base"].rstrip("/")
    url = base + "/" + endpoint.lstrip("/")
    return _http_json(url, payload, context=_ssl_context(cfg, url))


# --------------------------------------------------------------- formatare
def money(value):
    try:
        return "%.2f" % float(value or 0)
    except (TypeError, ValueError):
        return "0.00"


def qty(value):
    try:
        text = "%.3f" % float(value or 0)
    except (TypeError, ValueError):
        return "0"
    return text.rstrip("0").rstrip(".") or "0"


def build_kitchen(payload, cfg):
    b = EscposBuilder(cfg["receipt_width"], cfg.get("charset", "ascii"))
    title = payload.get("title") or cfg.get("kitchen_title", "BON COMANDA")

    b.align("center").size(1, 2).bold(True).line(title)
    b.size(1, 1).bold(False).align("left")
    b.sep()
    b.lr("Masa: %s" % payload.get("masa", ""),
         "Casier: %s" % payload.get("casier", ""))
    # Numarul bonului de sectie = contorul dedicat tblSet.NrBon.
    nr_bon = payload.get("nrBon")
    if nr_bon is None:
        nr_bon = payload.get("nrDoc", "")
    b.lr("NrBon: %s" % nr_bon, payload.get("dataOra", ""))
    if payload.get("printerName"):
        b.line("Printer: %s" % payload.get("printerName"))
    b.sep()

    for line in payload.get("lines", []) or []:
        name = line.get("denumire", "")
        if line.get("storno"):
            b.bold(True).line("ANULARE  %s x %s" % (qty(abs(line.get("cant", 0))), name)).bold(False)
        else:
            b.size(1, 2).line("%s x %s" % (qty(line.get("cant", 0)), name)).size(1, 1)
        for mod in line.get("mods", []) or []:
            b.line("      > %s" % mod)

    b.sep().feed(2).cut()
    return b.bytes()


def styled_line(b, line, align="center"):
    """Scrie o linie de antet/subsol, respectand font/size/bold (H1-H3, F1-F2).

    `line` poate fi un string simplu (joburi vechi) sau un obiect
    {"text", "font", "size", "bold"} trimis de PHP din tblAntet.
    """
    if not isinstance(line, dict):
        b.align(align).line(line)
        return b

    text = line.get("text", "")
    font = line.get("font") or ""
    size = line.get("size")

    b.align(align)
    if line.get("bold"):
        b.bold(True)
    if font:
        b.font(font)
    if size is not None:
        b.gs_size(size)
    b.line(text)

    # Revenim la stilul normal pentru liniile urmatoare
    b.gs_size(0).bold(False)
    if font:
        b.font("A")
    return b


def build_nota(payload, cfg):
    b = EscposBuilder(cfg["receipt_width"], cfg.get("charset", "ascii"))

    # Antetul notei = strict liniile H1-H3 din tblAntet (cu font/size/bold).
    for extra in payload.get("headerLines", []) or []:
        styled_line(b, extra, "center")

    b.align("left").sep()
    b.align("center").bold(True).size(1, 2).line(
        payload.get("title") or cfg.get("nota_title", "NOTA DE PLATA"))
    b.size(1, 1).bold(False).align("left")
    b.lr("Masa: %s" % payload.get("masa", ""),
         "Casier: %s" % payload.get("casier", ""))
    b.lr("NrDoc: %s" % payload.get("nrDoc", ""), payload.get("dataOra", ""))
    b.sep()
    b.lr("Denumire", "Cant    Valoare")
    b.sep()

    for item in payload.get("items", []) or []:
        name = item.get("denumire", "")
        cant = qty(item.get("cant", 0)).rjust(6)
        # Afisam pretul de catalog pe linie; discountul pe subtotal se arata doar
        # ca total (Reducere) dupa subtotal, nu repartizat pe fiecare produs.
        orig = item.get("valoareOriginala")
        val = money(orig if orig is not None else item.get("valoare")).rjust(10)
        b.lr(name, "%s %s" % (cant, val))
        original = float(item.get("valoareOriginala") or 0)
        current = float(item.get("valoare") or 0)
        # Discountul pe produs (pe linie) se evidentiaza sub produs; cel pe
        # subtotal se arata doar ca total (Reducere) dupa subtotal.
        if item.get("discountLinie") and (original - current > 0.005):
            b.line("      discount: -%s" % money(original - current))

    b.sep()
    b.lr("Subtotal:", money(payload.get("subtotal")))
    if float(payload.get("reducere") or 0) > 0.0001:
        b.lr("Reducere:", "-" + money(payload.get("reducere")))
    for tva in payload.get("tva", []) or []:
        b.lr("TVA %s%%:" % tva.get("cota", ""), money(tva.get("suma")))
    b.bold(True).size(1, 2).lr("TOTAL:", money(payload.get("total")))
    b.size(1, 1).bold(False)
    b.sep()

    plati = payload.get("plati") or []
    if plati:
        b.line("Plati:")
        for plata in plati:
            b.lr("  %s" % plata.get("denumire", ""), money(plata.get("suma")))
        b.sep()

    for extra in payload.get("footerLines", []) or []:
        styled_line(b, extra, "center")

    b.align("center").feed(1).line("* * *").feed(2).cut()
    return b.bytes()


def build_raport(payload, cfg):
    b = EscposBuilder(cfg["receipt_width"], cfg.get("charset", "ascii"))

    # Antetul raportului = liniile H1-H2 din tblAntet (cu font/size/bold).
    for extra in payload.get("headerLines", []) or []:
        styled_line(b, extra, "center")

    b.align("center").size(1, 2).bold(True).line(payload.get("title") or "RAPORT")
    b.size(1, 1).bold(False).align("left")
    for line in payload.get("lines", []) or []:
        # O linie poate fi string simplu sau {"text", "bold", "size", "font"}.
        styled_line(b, line, "left")
    b.feed(2).cut()
    return b.bytes()


def build_test(cfg):
    """Bon scurt de test, folosit de butonul 'Test' din Setari > Imprimante."""
    b = EscposBuilder(cfg["receipt_width"], cfg.get("charset", "ascii"))
    b.align("center").size(1, 2).bold(True).line("TEST IMPRIMANTA")
    b.size(1, 1).bold(False).line("TableManager print-service")
    b.sep()
    b.line("Configurare OK")
    b.feed(2).cut()
    return b.bytes()


def build_fiscal_bytes(payload, cfg):
    text = payload.get("text")
    if text is None:
        text = "\r\n".join(payload.get("lines") or [])
    encoding = cfg.get("fiscal_encoding", "cp1250")
    try:
        return text.encode(encoding, "replace")
    except LookupError:
        return transliterate(text).encode("ascii", "replace")


def copy_fiscal_file(payload, data, dest_dir, cfg):
    """Copiaza fisierul de comenzi in folderul monitorizat de driver.

    Ridica TargetError la esec, ca jobul sa fie reincercat cu backoff - driverul
    casei de marcat trebuie sa primeasca fisierul.
    """
    name = payload.get("filename") or ("fiscal_%d.txt" % int(time.time()))
    try:
        os.makedirs(dest_dir, exist_ok=True)
        path = os.path.join(dest_dir, name)
        with open(path, "wb") as fh:
            fh.write(data)
    except OSError as exc:
        raise TargetError("Nu pot copia fisierul fiscal in '%s': %s" % (dest_dir, exc))
    return "copiat in %s" % path


# --------------------------------------------------------------- procesare
def process_job(cfg, job):
    """Construieste si livreaza un job. Ridica la esec."""
    tip = (job.get("Tip") or "").lower()
    payload = job.get("Payload")
    if isinstance(payload, str):
        try:
            payload = json.loads(payload)
        except ValueError:
            payload = {}
    payload = payload or {}

    if tip == "fiscal":
        data = build_fiscal_bytes(payload, cfg)
        target = dict(cfg.get("fiscal_target") or {"target": "file"})
        target.setdefault("dir", cfg["fiscal_spool_dir"])
        if payload.get("filename") and not target.get("filename"):
            target["filename"] = payload["filename"]
        msg = deliver(target, data, cfg, job.get("JobID"))
        # Copiem fisierul de comenzi si in folderul monitorizat de driverul casei
        # de marcat (tblSet.CaleFisierComenziECR), de unde este procesat.
        copy_dir = payload.get("copy_dir")
        if copy_dir:
            msg = "%s; %s" % (msg, copy_fiscal_file(payload, data, copy_dir, cfg))
        return msg

    if tip == "kitchen":
        data = build_kitchen(payload, cfg)
        printer_nr = job.get("PrinterNr")
        if printer_nr is None:
            printer_nr = payload.get("printerNr")
        target = printer_target(cfg, printer_nr)
        return deliver(target, data, cfg, job.get("JobID"))

    if tip in ("nota", "proforma"):
        data = build_nota(payload, cfg)
        target = cfg.get("nota_target") or {"target": "preview"}
        return deliver(target, data, cfg, job.get("JobID"))

    if tip == "raport":
        data = build_raport(payload, cfg)
        target = cfg.get("raport_target") or cfg.get("nota_target") or {"target": "preview"}
        return deliver(target, data, cfg, job.get("JobID"))

    raise ValueError("Tip de job necunoscut: %s" % tip)


def _claim(cfg):
    try:
        res = _api(cfg, "print_queue.php?action=claim")
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot contacta API-ul (%s): %s" % (cfg["api_base"], exc), cfg)
        return None
    if not isinstance(res, dict) or res.get("status") != "success":
        return None
    return res.get("job")


def _retry_seconds(cfg, attempts):
    backoffs = cfg.get("backoff_seconds") or [5, 15, 30, 60, 300]
    idx = max(0, min(int(attempts) - 1, len(backoffs) - 1))
    return backoffs[idx]


def _report_failure(cfg, job, error):
    attempts = int(job.get("Attempts") or 1)
    permanent = attempts >= int(cfg.get("max_attempts", 50))
    payload = {
        "action": "fail",
        "jobId": job.get("JobID"),
        "error": str(error)[:250],
        "permanent": permanent,
    }
    if not permanent:
        payload["retryInSeconds"] = _retry_seconds(cfg, attempts)
    try:
        _api(cfg, "print_queue.php", payload)
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot raporta esecul jobului %s: %s" % (job.get("JobID"), exc), cfg)


def _report_success(cfg, job, message):
    try:
        _api(cfg, "print_queue.php", {
            "action": "done",
            "jobId": job.get("JobID"),
            "message": str(message)[:250],
        })
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot raporta succesul jobului %s: %s" % (job.get("JobID"), exc), cfg)


def process_once(cfg):
    """Ridica si proceseaza un singur job. Intoarce True daca a fost unul."""
    job = _claim(cfg)
    if not job:
        return False

    job_id = job.get("JobID")
    tip = job.get("Tip")
    try:
        message = process_job(cfg, job)
        log("Job %s (%s) OK: %s" % (job_id, tip, message), cfg)
        _report_success(cfg, job, message)
    except (TargetError, Exception) as exc:  # noqa: BLE001 - raportam orice esec
        log("Job %s (%s) esuat: %s" % (job_id, tip, exc), cfg)
        log(traceback.format_exc(), cfg)
        _report_failure(cfg, job, exc)
    return True


def run_loop(cfg_provider, stop_event, wake_event):
    """cfg_provider: callable care intoarce configul curent (hot-reload) sau un
    dict fix. Re-citim configul la fiecare iteratie, ca modificarile din ecranul
    Setari > Imprimante sa se aplice fara repornirea serviciului."""
    get_cfg = cfg_provider if callable(cfg_provider) else (lambda: cfg_provider)
    log("Worker pornit. API: %s" % get_cfg()["api_base"], get_cfg())
    next_cleanup = 0.0
    while not stop_event.is_set():
        cfg = get_cfg()

        if time.time() >= next_cleanup:
            try:
                run_cleanup(cfg, lambda msg, c=cfg: log(msg, c))
            except Exception as exc:  # noqa: BLE001 - curatarea nu opreste tiparirea
                log("Eroare la curatare: %s" % exc, cfg)
            hours = float(cfg.get("cleanup_interval_hours", 24) or 24)
            next_cleanup = time.time() + max(1.0, hours) * 3600

        try:
            worked = process_once(cfg)
        except Exception as exc:  # noqa: BLE001
            log("Eroare neasteptata in worker: %s" % exc, cfg)
            worked = False

        if worked:
            continue
        # Niciun job: asteptam fie un semnal /wake, fie intervalul de poll.
        wake_event.wait(timeout=float(cfg.get("poll_interval_sec", 1.0)))
        wake_event.clear()
