"""Executia comenzilor SQL pe serverul extern, prin pyodbc.

Conectarea primeste datele din tblConectare (trimise de api/send_queue.php la
claim: server, database, user, password, tc) si le combina cu optiunile din
config.json (driver ODBC, Encrypt etc.).
"""

try:
    import pyodbc
except ImportError:  # pragma: no cover - raportat clar la pornire
    pyodbc = None


class DbError(Exception):
    pass


def build_connection_string(info, cfg):
    if pyodbc is None:
        raise DbError("pyodbc nu este instalat (pip install pyodbc)")

    driver = cfg.get("db_driver") or "ODBC Driver 17 for SQL Server"
    server = info.get("server") or ""
    database = info.get("database") or ""

    parts = [
        "DRIVER={%s}" % driver,
        "SERVER=%s" % server,
    ]
    if database:
        parts.append("DATABASE=%s" % database)

    if info.get("tc"):
        parts.append("Trusted_Connection=yes")
    else:
        parts.append("UID=%s" % (info.get("user") or ""))
        parts.append("PWD=%s" % (info.get("password") or ""))

    for key, value in (cfg.get("db_options") or {}).items():
        parts.append("%s=%s" % (key, value))

    return ";".join(parts) + ";"


def describe_target(info):
    """Descriere lizibila a tintei conexiunii, fara parola.

    Folosita in mesajele de eroare: altfel, in temp_Send_Sql.LastError apare doar
    textul driverului ODBC („Error Locating Server/Instance Specified"), fara sa
    se vada ce server/baza a incercat serviciul. Nu include niciodata parola.
    """
    server = (info.get("server") or "").strip() or "(server lipsa)"
    database = (info.get("database") or "").strip()
    if info.get("tc"):
        auth = "Windows auth"
    else:
        auth = "user %s" % ((info.get("user") or "").strip() or "(gol)")
    target = server + (" / " + database if database else "")
    return "%s, %s" % (target, auth)


def execute_statements(info, statements, cfg):
    """Executa comenzile SQL primite pe serverul extern.

    `statements` este lista comenzilor ne-goale (comanda din str_sql).
    Se foloseste autocommit, ca fiecare procedura sa isi confirme singura
    tranzactia si sa nu ramana tranzactii deschise pe conexiune.

    Intoarce (mesaj, rezultat), unde `rezultat` este ultimul rand de rezultat
    returnat de comenzi (dict coloana->valoare) sau None. Este folosit pentru
    a prelua DocID/Serie/NrDocFisc de la procedura GenFactPoS.
    """
    statements = [s for s in (statements or []) if s and s.strip()]
    if not statements:
        raise DbError("Nicio comanda SQL de executat")

    conn_str = build_connection_string(info, cfg)
    # Timeout-ul de conectare vine din tblSet.ODBC_TimeOut (api/send_queue.php il
    # trimite la claim); db_timeout din config.json este doar rezerva, pentru
    # cazul in care setarea nu este disponibila.
    try:
        timeout = int(info.get("timeout") or 0) or int(cfg.get("db_timeout", 10) or 10)
    except (TypeError, ValueError):
        timeout = 10
    try:
        cn = pyodbc.connect(
            conn_str,
            timeout=timeout,
            autocommit=True,
        )
    except pyodbc.Error as exc:
        # Tinta este pusa inaintea textului driverului: LastError din
        # temp_Send_Sql este trunchiat la 500 de caractere (worker._report_failure),
        # iar inceputul mesajului ODBC (cauza) ramane astfel vizibil.
        raise DbError("Conexiunea la server a esuat [%s]: %s" % (describe_target(info), exc))

    result = None
    try:
        cur = cn.cursor()
        for sql in statements:
            cur.execute(sql)
            # Consumam toate seturile de rezultate (procedura poate returna
            # resultset-uri / mesaje) si pastram ultimul rand de rezultat.
            while True:
                try:
                    if cur.description:
                        cols = [c[0] for c in cur.description]
                        rows = cur.fetchall()
                        if rows:
                            row = {}
                            for c, v in zip(cols, rows[-1]):
                                row[c] = v if (v is None or isinstance(v, (int, float, str, bool))) else str(v)
                            result = row
                    else:
                        cur.fetchall()
                except pyodbc.ProgrammingError:
                    pass
                if not cur.nextset():
                    break
        return "executat (%d comenzi)" % len(statements), result
    except pyodbc.Error as exc:
        raise DbError("Executia a esuat: %s" % exc)
    finally:
        try:
            cn.close()
        except Exception:  # noqa: BLE001
            pass
