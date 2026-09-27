"""Emulator ESC/POS: decodeaza fluxul de octeti si il randeaza ca HTML.

Acopera subsetul de comenzi produs de escpos.EscposBuilder, suficient pentru
previzualizarea bonurilor cat timp nu exista imprimanta fizica.
"""

import html
import os
import time


def decode(data, charset="ascii"):
    """Decodeaza un buffer ESC/POS intr-o lista de linii cu atribute."""
    lines = []
    cur = bytearray()
    attr = {"align": "left", "bold": False, "underline": False,
            "width": 1, "height": 1}

    def flush():
        try:
            text = cur.decode(charset, "replace")
        except LookupError:
            text = cur.decode("ascii", "replace")
        lines.append({
            "text": text,
            "align": attr["align"],
            "bold": attr["bold"],
            "underline": attr["underline"],
            "width": attr["width"],
            "height": attr["height"],
        })
        cur.clear()

    i = 0
    n = len(data)
    while i < n:
        b = data[i]
        if b == 0x1b and i + 1 < n:  # ESC
            cmd = data[i + 1]
            if cmd == 0x40:  # @ reset
                attr.update(align="left", bold=False, underline=False,
                            width=1, height=1)
                i += 2
                continue
            if cmd == 0x61 and i + 2 < n:  # a n (align)
                attr["align"] = {0: "left", 1: "center", 2: "right"}.get(
                    data[i + 2], "left")
                i += 3
                continue
            if cmd == 0x45 and i + 2 < n:  # E n (bold)
                attr["bold"] = data[i + 2] != 0
                i += 3
                continue
            if cmd == 0x2d and i + 2 < n:  # - n (underline)
                attr["underline"] = data[i + 2] != 0
                i += 3
                continue
            if cmd == 0x4d and i + 2 < n:  # M n (selectie font A..E)
                i += 3
                continue
            i += 2
            continue
        if b == 0x1d and i + 1 < n:  # GS
            cmd = data[i + 1]
            if cmd == 0x21 and i + 2 < n:  # ! n (size)
                v = data[i + 2]
                attr["width"] = ((v >> 4) & 0x07) + 1
                attr["height"] = (v & 0x07) + 1
                i += 3
                continue
            if cmd == 0x56:  # V (cut)
                i += 3
                continue
            i += 2
            continue
        if b == 0x0a:  # LF
            flush()
            i += 1
            continue
        if b == 0x0d:  # CR ignorat
            i += 1
            continue
        cur.append(b)
        i += 1

    if cur:
        flush()
    return lines


def _line_html(ln):
    style = []
    if ln["bold"]:
        style.append("font-weight:700")
    if ln["underline"]:
        style.append("text-decoration:underline")

    # ESC/POS separa latimea de inaltime: width-ul largeste caracterele
    # (ocupa mai multe coloane), height-ul doar le inalta. De aceea latimea
    # se face cu font-size, iar inaltimea cu scaleY (nu ambele cu font-size,
    # altfel linia iese din hartie).
    w = ln.get("width", 1) or 1
    h = ln.get("height", 1) or 1
    if w > 1:
        style.append("font-size:%.2fem" % (w * 1.0))
    if h > 1:
        style.append("line-height:%.2f" % (h * 1.0))
        style.append("transform:scaleY(%.2f)" % (h * 1.0))
        style.append("transform-origin:left center")

    text = html.escape(ln["text"])
    if text == "":
        text = "&nbsp;"
    return '<div class="l-%s" style="%s">%s</div>' % (
        ln["align"], ";".join(style), text)


def render_html(lines, title="Bon"):
    body = "\n".join(_line_html(ln) for ln in lines)
    return """<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>%s</title>
<style>
  body { background:#3a3a3a; margin:0; padding:24px; font-family:monospace; }
  .paper { width:480px; margin:0 auto; background:#fff; padding:18px 16px;
           box-shadow:0 4px 18px rgba(0,0,0,.5); color:#000;
           font-family:'Courier New',Courier,monospace; font-size:15px;
           line-height:1.25; white-space:pre; }
  .l-center { text-align:center; }
  .l-right  { text-align:right; }
  .l-left   { text-align:left; }
  .cut { border-top:1px dashed #999; margin:14px 0 6px; }
</style>
</head>
<body>
<div class="paper">%s
<div class="cut"></div>
</div>
</body>
</html>""" % (html.escape(title), body)


def save_preview(data, preview_dir, job_id=None, charset="ascii", title="Bon"):
    """Scrie preview-ul HTML si intoarce (cale, url_relativ)."""
    os.makedirs(preview_dir, exist_ok=True)
    content = render_html(decode(data, charset), title)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    name = "bon_%s_%s.html" % (job_id if job_id is not None else "x", stamp)
    path = os.path.join(preview_dir, name)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(content)

    url = "/preview/" + name
    if job_id is not None:
        # Copie stabila per job, folosita de butonul Preview din POS.
        per_job = "job_%s.html" % job_id
        try:
            with open(os.path.join(preview_dir, per_job), "w", encoding="utf-8") as fh:
                fh.write(content)
            url = "/preview/" + per_job
        except OSError:
            pass

    latest = os.path.join(preview_dir, "ultimul.html")
    try:
        with open(latest, "w", encoding="utf-8") as fh:
            fh.write(content)
    except OSError:
        pass
    return path, url
