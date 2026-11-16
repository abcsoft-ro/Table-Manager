"""Incarcare minimala a unui fisier .env (fara dependinte externe)."""

import os


def find_env(explicit=None) -> str:
    if explicit:
        return explicit
    here = os.path.dirname(os.path.abspath(__file__))
    for candidate in (os.path.join(os.getcwd(), ".env"), os.path.join(here, ".env")):
        if os.path.isfile(candidate):
            return candidate
    return None


def load_dotenv(path) -> dict:
    data = {}
    if not path:
        return data
    try:
        with open(path, encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if line.startswith("export "):
                    line = line[len("export "):]
                if "=" not in line:
                    continue
                key, value = line.split("=", 1)
                key, value = key.strip(), value.strip()
                if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                    value = value[1:-1]
                data[key] = value
    except FileNotFoundError:
        pass
    return data
