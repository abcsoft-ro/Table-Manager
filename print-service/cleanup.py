"""Curatare periodica a folderelor spool si a fisierului de log.

- folderele (spool/fiscal, preview) pastreaza doar fisierele mai noi de N zile;
- fisierul de log este scurtat la ultimele K MB cand depaseste M MB;
- coada de tiparire (tblPrintQueue) este curatata de joburile vechi: cele
  finalizate (done) mai vechi de N zile si cele esuate (failed) mai vechi de M
  zile sunt sterse, prin api/print_queue.php (serviciul nu are driver de baza).
"""

import os
import time

DAY_SECONDS = 86400


def _as_int(value, default):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _as_float(value, default):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def clean_dir(path, days, log_fn=None):
    """Sterge fisierele (nu subfolderele) mai vechi de `days` zile.

    `days <= 0` dezactiveaza curatirea. Intoarce numarul de fisiere sterse.
    """
    if not path or days <= 0 or not os.path.isdir(path):
        return 0
    cutoff = time.time() - days * DAY_SECONDS
    removed = 0
    try:
        names = os.listdir(path)
    except OSError:
        return 0
    for name in names:
        full = os.path.join(path, name)
        try:
            if not os.path.isfile(full):
                continue
            if os.path.getmtime(full) < cutoff:
                os.remove(full)
                removed += 1
        except OSError:
            continue
    if removed and log_fn:
        log_fn("Curatire '%s': %d fisiere mai vechi de %d zile sterse." % (path, removed, days))
    return removed


def clean_log(path, max_bytes, keep_bytes, log_fn=None):
    """Scurteaza logul la ultimele `keep_bytes` cand depaseste `max_bytes`.

    Scrie inapoi doar de la inceputul urmatoarei linii complete, ca sa nu taie
    un mesaj la jumatate. Intoarce numarul de bytes pastrati (0 daca nu s-a
    facut nimic).
    """
    if not path or max_bytes <= 0 or not os.path.isfile(path):
        return 0
    try:
        size = os.path.getsize(path)
    except OSError:
        return 0
    if size <= max_bytes:
        return 0

    keep_bytes = max(0, min(keep_bytes, size))
    try:
        with open(path, "rb") as fh:
            fh.seek(size - keep_bytes)
            tail = fh.read()
        nl = tail.find(b"\n")
        if nl != -1:
            tail = tail[nl + 1:]
        with open(path, "wb") as fh:
            fh.write(tail)
    except OSError:
        return 0

    if log_fn:
        log_fn("Curatire log '%s': scurtat de la %d la %d bytes." % (path, size, len(tail)))
    return len(tail)


def clean_queue(cfg, api_fn, log_fn=None):
    """Sterge joburile vechi din coada de tiparire (tblPrintQueue).

    `done` mai vechi de queue_cleanup_done_days zile (implicit 10) si `failed`
    mai vechi de queue_cleanup_failed_days zile (implicit 1). 0 dezactiveaza
    partea respectiva. Apelul se face prin api/print_queue.php (api_fn), fiindca
    serviciul de tiparire nu are driver de baza de date.
    """
    if api_fn is None:
        return
    done_days = max(0, _as_int(cfg.get("queue_cleanup_done_days"), 10))
    failed_days = max(0, _as_int(cfg.get("queue_cleanup_failed_days"), 1))
    if done_days <= 0 and failed_days <= 0:
        return
    try:
        res = api_fn(cfg, "print_queue.php", {
            "action": "cleanup",
            "doneDays": done_days,
            "failedDays": failed_days,
        })
    except Exception as exc:  # retea / HTTP / JSON
        if log_fn:
            log_fn("Curatire coada tiparire: eroare %s" % exc)
        return
    if isinstance(res, dict) and res.get("status") == "success":
        removed_done = _as_int(res.get("removedDone"), 0)
        removed_failed = _as_int(res.get("removedFailed"), 0)
        if (removed_done or removed_failed) and log_fn:
            log_fn("Curatire coada tiparire: %d joburi done si %d failed sterse."
                   % (removed_done, removed_failed))


def run_cleanup(cfg, log_fn=None, api_fn=None):
    """Curata folderele spool, logul si coada de tiparire, dupa setarile din config."""
    days = _as_int(cfg.get("cleanup_days"), 30)
    preview_days = _as_int(cfg.get("preview_cleanup_days"), days)

    clean_dir(cfg.get("fiscal_spool_dir"), days, log_fn)
    clean_dir(cfg.get("preview_dir"), preview_days, log_fn)

    max_mb = _as_float(cfg.get("log_max_mb"), 5)
    keep_mb = _as_float(cfg.get("log_keep_mb"), 1)
    if max_mb > 0:
        clean_log(cfg.get("log_file"),
                  int(max_mb * 1024 * 1024),
                  int(max(keep_mb, 0) * 1024 * 1024),
                  log_fn)

    clean_queue(cfg, api_fn, log_fn)
