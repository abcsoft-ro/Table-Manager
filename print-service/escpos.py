"""Generator minimal de comenzi ESC/POS pentru imprimante de bonuri."""

import unicodedata

ESC = b"\x1b"
GS = b"\x1d"

# Diacritice romanesti si alte caractere frecvente -> echivalent ASCII.
# Folosite cand charset = "ascii" (multe imprimante ieftine nu au code page RO).
_TRANSLITERARE = str.maketrans({
    "ă": "a", "Ă": "A", "â": "a", "Â": "A", "î": "i", "Î": "I",
    "ș": "s", "Ș": "S", "ş": "s", "Ş": "S", "ț": "t", "Ț": "T",
    "ţ": "t", "Ţ": "T",
    "„": '"', "”": '"', "“": '"', "’": "'", "‘": "'",
    "–": "-", "—": "-", "…": "...", "€": "EUR",
})


def transliterate(text):
    """Transforma textul in ASCII curat (fara diacritice / combinari)."""
    if text is None:
        return ""
    text = str(text).translate(_TRANSLITERARE)
    text = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in text if not unicodedata.combining(ch))


def width_of(text):
    """Largimea in caractere asa cum va fi tiparita (dupa transliterare)."""
    return len(transliterate(text))


class EscposBuilder:
    """Construieste un buffer ESC/POS octet cu octet."""

    def __init__(self, width=42, charset="ascii"):
        self.width = int(width)
        self.charset = charset or "ascii"
        self._buf = bytearray()
        self.reset()

    # ---- comenzi de baza -------------------------------------------------
    def reset(self):
        self._buf += ESC + b"@"
        return self

    def align(self, mode="left"):
        value = {"left": 0, "center": 1, "right": 2}.get(mode, 0)
        self._buf += ESC + b"a" + bytes([value])
        return self

    def bold(self, on=True):
        self._buf += ESC + b"E" + bytes([1 if on else 0])
        return self

    def underline(self, on=True):
        self._buf += ESC + b"-" + bytes([1 if on else 0])
        return self

    def size(self, width_mult=1, height_mult=1):
        # GS ! n : bitii 0-2 = inaltime-1, bitii 4-6 = latime-1
        n = (((width_mult - 1) & 0x07) << 4) | ((height_mult - 1) & 0x07)
        self._buf += GS + b"!" + bytes([n])
        return self

    def gs_size(self, n):
        # Seteaza direct parametrul GS ! n (asa cum e salvat in tblAntet.Size).
        self._buf += GS + b"!" + bytes([int(n) & 0xFF])
        return self

    def font(self, token):
        # ESC M n : selecteaza fontul A..E (n = 0..4)
        if not token:
            return self
        t = str(token).strip().upper()
        if t and "A" <= t[0] <= "E":
            self._buf += ESC + b"M" + bytes([ord(t[0]) - ord("A")])
        return self

    def cut(self, feed=3):
        self.feed(feed)
        self._buf += GS + b"V" + bytes([0])
        return self

    def feed(self, lines=1):
        self._buf += b"\n" * max(0, int(lines))
        return self

    def raw(self, data):
        if isinstance(data, str):
            data = self._encode(data)
        self._buf += bytes(data)
        return self

    # ---- text ------------------------------------------------------------
    def _encode(self, text):
        text = "" if text is None else str(text)
        if self.charset == "ascii":
            return transliterate(text).encode("ascii", "replace")
        try:
            return text.encode(self.charset, "replace")
        except LookupError:
            return transliterate(text).encode("ascii", "replace")

    def text(self, text=""):
        self._buf += self._encode(text)
        return self

    def line(self, text=""):
        self._buf += self._encode(text) + b"\n"
        return self

    def sep(self, char="-"):
        return self.line(char * self.width)

    def lr(self, left, right):
        """Linie cu coloana stanga si dreapta aliniate la latimea bonului."""
        left = transliterate(left)
        right = transliterate(right)
        space = self.width - len(left) - len(right)
        if space < 1:
            max_left = self.width - len(right) - 1
            left = left[:max_left] if max_left > 0 else ""
            space = max(1, self.width - len(left) - len(right))
        self._buf += self._encode(left) + b" " * space + self._encode(right) + b"\n"
        return self

    def bytes(self):
        return bytes(self._buf)
