"""Worker-ul serviciului de sincronizare.

Ridica randurile din temp_Send_Sql cu Preluat = 0 (prin api/send_queue.php),
executa comanda SQL pe serverul din tblConectare si raporteaza rezultatul.
La esec reincearca cu backoff, pana cand serverul revine. Ruleaza in fundal
(thread daemon), cu asteptare pe eveniment, deci nu blocheaza calculatorul.
"""

import json
import ssl
import time
import traceback
import urllib.error
import urllib.request

from db import DbError, execute_statements


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


# --------------------------------------------------------------- procesare
def _retry_seconds(cfg, attempts):
    backoffs = cfg.get("backoff_seconds") or [5, 15, 30, 60, 300]
    idx = max(0, min(int(attempts) - 1, len(backoffs) - 1))
    return backoffs[idx]


def _report_failure(cfg, job, error):
    attempts = int(job.get("Attempts") or 1)
    # max_attempts <= 0 inseamna nelimitat: randul rămâne in coada si se
    # reincearca cu backoff pana cand serverul revine (nu se marcheaza 'failed').
    max_attempts = int(cfg.get("max_attempts") or 0)
    permanent = max_attempts > 0 and attempts >= max_attempts
    payload = {
        "action": "fail",
        "id": job.get("Id"),
        "error": str(error)[:500],
        "permanent": permanent,
    }
    if not permanent:
        payload["retryInSeconds"] = _retry_seconds(cfg, attempts)
    try:
        _api(cfg, "send_queue.php", payload)
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot raporta esecul randului %s: %s" % (job.get("Id"), exc), cfg)


def _report_success(cfg, job):
    try:
        _api(cfg, "send_queue.php", {"action": "done", "id": job.get("Id")})
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot raporta succesul randului %s: %s" % (job.get("Id"), exc), cfg)


def process_once(cfg):
    """Ridica si executa un singur rand. Intoarce True daca a fost unul."""
    try:
        res = _api(cfg, "send_queue.php?action=claim")
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log("Nu pot contacta API-ul (%s): %s" % (cfg["api_base"], exc), cfg)
        return False

    if not isinstance(res, dict):
        return False
    if res.get("status") == "empty":
        return False
    if res.get("status") != "success":
        log("Claim a raspuns: %s" % res, cfg)
        return False

    job = res.get("job") or {}
    info = res.get("db") or {}
    job_id = job.get("Id")

    try:
        message = execute_statements(info, [job.get("str_sql")], cfg)
        log("Rand %s (DocID %s) OK: %s" % (job_id, job.get("DocID"), message), cfg)
        _report_success(cfg, job)
    except (DbError, Exception) as exc:  # noqa: BLE001 - raportam orice esec
        log("Rand %s (DocID %s) esuat: %s" % (job_id, job.get("DocID"), exc), cfg)
        log(traceback.format_exc(), cfg)
        _report_failure(cfg, job, exc)
    return True


def run_loop(cfg_provider, stop_event, wake_event):
    """cfg_provider: callable care intoarce configul curent (hot-reload) sau un
    dict fix. Asteptarea se face pe eveniment, cu un poll de siguranta; nu se
    consuma CPU intre randuri."""
    get_cfg = cfg_provider if callable(cfg_provider) else (lambda: cfg_provider)
    log("Worker pornit. API: %s" % get_cfg()["api_base"], get_cfg())

    while not stop_event.is_set():
        cfg = get_cfg()
        try:
            worked = process_once(cfg)
        except Exception as exc:  # noqa: BLE001
            log("Eroare neasteptata in worker: %s" % exc, cfg)
            worked = False

        if worked:
            continue
        # Nimic de facut: asteptam fie un semnal /wake, fie poll_interval_sec.
        wake_event.wait(timeout=float(cfg.get("poll_interval_sec", 2.0)))
        wake_event.clear()
