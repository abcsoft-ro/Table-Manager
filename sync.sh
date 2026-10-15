#!/usr/bin/env bash
#
# Sincronizare TableManager cu GitHub (abcsoft-ro/Table-Manager).
#
# Face commit doar daca exista modificari, apoi push pe branch-ul main.
# Tokenul se citeste din github_api.txt (ignorat de git).
#
# Dupa sincronizarea aplicatiei, actualizeaza si snapshot-ul de instalare din
# repo-ul separat TableManager-Setup (abcsoft-ro/TableManager-Setup):
#   - scrie payload/app-version.json (versiune + SHA + build + data);
#   - regenereaza payload/app.tgz din aplicatia curenta;
#   - commit + push pe main.
# Folderul repo-ului de setup se poate schimba cu variabila SETUP_DIR.
#
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(pwd)"

# Garda de branch: sync.sh publica pe canalul de productie (branch-ul main si
# snapshot-ul de instalare). Rulat de pe alt branch ar publica cod nedorit.
# Pentru branch-uri foloseste 'git push origin <branch>'. Forteaza cu SYNC_ALLOW_BRANCH=1.
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$CURRENT_BRANCH" != "main" ] && [ "${SYNC_ALLOW_BRANCH:-0}" != "1" ]; then
  echo "EROARE: sync.sh ruleaza doar pe branch-ul 'main' (acum: '$CURRENT_BRANCH')." >&2
  echo "Pentru branch-uri foloseste 'git commit'/'git push origin $CURRENT_BRANCH'." >&2
  echo "Forteaza cu SYNC_ALLOW_BRANCH=1 daca stii ce faci." >&2
  exit 1
fi

SETUP_DIR="${SETUP_DIR:-/mnt/c/Users/Florian/source/repos/TableManager-Setup}"

if [ ! -f github_api.txt ]; then
  echo "EROARE: lipseste github_api.txt cu tokenul GitHub." >&2
  exit 1
fi
TOKEN=$(sed -n 's/^token://p' github_api.txt | tr -d '\r\n')
if [ -z "$TOKEN" ]; then
  echo "EROARE: nu am gasit tokenul in github_api.txt." >&2
  exit 1
fi

# Versiunea human (din VERSION): folosita la titlul din index.html si la metadate.
HUMAN=""
if [ -f VERSION ]; then
  HUMAN="$(tr -d ' \t\r\n' < VERSION)"
fi

# Titlul tabului din pagina principala reflecta intotdeauna versiunea (fallback
# static; la runtime JS il seteaza oricum din api/version.php). Pagina a fost
# convertita in index.php (randare server-side pentru i18n); pastram si
# fallback-ul pe index.html pentru instalatiile vechi.
for PAGE in index.php index.html; do
  if [ -n "$HUMAN" ] && [ -f "$PAGE" ]; then
    sed -i -E "s#(<title>TableManager )[^<]*( - Modern Web POS</title>)#\1${HUMAN}\2#" "$PAGE"
  fi
done

git add -A

if git diff --cached --quiet; then
  echo "Nicio modificare de sincronizat ($(date '+%Y-%m-%d %H:%M:%S'))."
else
  git commit -q -m "Sync $(date '+%Y-%m-%d %H:%M:%S')"
  git push -q "https://x-access-token:${TOKEN}@github.com/abcsoft-ro/Table-Manager.git" main
  echo "Sincronizat: $(git rev-parse --short HEAD) ($(date '+%Y-%m-%d %H:%M:%S'))"
fi

# ------------------------------------------------------- variabile versiune
SHA="$(git rev-parse HEAD)"
SHORT="$(git rev-parse --short HEAD)"
BUILD="$(git rev-list --count HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
BUILT_AT="$(date '+%Y-%m-%d %H:%M:%S')"

# ------------------------------------------------- metadate repo (About + topics)
# Descrierea GitHub ("About") se construieste din VERSION + subtitlul din README,
# ca sa rămână consistenta cu README-ul la fiecare release. Best-effort: daca
# API-ul GitHub nu raspunde, sincronizarea continua.
REPO_SLUG="abcsoft-ro/Table-Manager"
SUBTITLE="$(grep -m1 '^### ' README.md 2>/dev/null | sed 's/^### //' || true)"
DESC="TableManager ${HUMAN} — ${SUBTITLE}"
DESC_JSON="$(printf '%s' "$DESC" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
if curl -s -X PATCH \
     -H "Authorization: token ${TOKEN}" \
     -H "Accept: application/vnd.github+json" \
     -d "{\"description\":\"${DESC_JSON}\"}" \
     "https://api.github.com/repos/${REPO_SLUG}" >/dev/null; then
  echo "GitHub: descriere actualizata (v${HUMAN})."
else
  echo "GitHub: nu am putut actualiza descrierea (best-effort)." >&2
fi

if curl -s -X PUT \
     -H "Authorization: token ${TOKEN}" \
     -H "Accept: application/vnd.github+json" \
     -d '{"names":["pos","point-of-sale","restaurant","fast-food","php","mssql","tablemanager"]}' \
     "https://api.github.com/repos/${REPO_SLUG}/topics" >/dev/null; then
  echo "GitHub: topics actualizate."
else
  echo "GitHub: nu am putut actualiza topics (best-effort)." >&2
fi

# ------------------------------------------------------- snapshot de instalare
if [ ! -d "$SETUP_DIR/.git" ]; then
  echo "Setup: '$SETUP_DIR' nu este un repo git (sar peste). Setati SETUP_DIR pentru a sincroniza installerul." >&2
  exit 0
fi

# Scriere atomica (tmp + mv) ca cititorii (VS Code, API) sa nu prinda niciodata
# un fisier partial scris.
APP_VER_TMP="$SETUP_DIR/payload/app-version.json.tmp"
cat > "$APP_VER_TMP" <<EOF
{
    "version": "$HUMAN",
    "sha": "$SHA",
    "short": "$SHORT",
    "build": $BUILD,
    "branch": "$BRANCH",
    "builtAt": "$BUILT_AT"
}
EOF
mv -f "$APP_VER_TMP" "$SETUP_DIR/payload/app-version.json"

# Marcheaza versiunea si pe masina de dezvoltare (logs/ e ignorat de git).
# Scriere atomica (tmp + mv) ca VS Code / API sa nu vada un fisier partial.
mkdir -p logs
STATE_TMP="logs/update_state.json.tmp"
cat > "$STATE_TMP" <<EOF
{
    "sha": "$SHA",
    "short": "$SHORT",
    "build": $BUILD,
    "branch": "$BRANCH",
    "builtAt": "$BUILT_AT",
    "updatedAt": "$BUILT_AT",
    "source": "dev"
}
EOF
mv -f "$STATE_TMP" logs/update_state.json
printf '[%s] VERSION SET: v%s %s build %s source=dev\n' "$BUILT_AT" "$HUMAN" "$SHORT" "$BUILD" >> logs/version.log

tar -czf "$SETUP_DIR/payload/app.tgz" -C "$ROOT/.." \
  --exclude='rual/.git' --exclude='rual/.git/*' \
  --exclude='rual/.venv' --exclude='rual/.venv/*' \
  --exclude='rual/logs' --exclude='rual/logs/*' \
  --exclude='rual/__pycache__' --exclude='rual/__pycache__/*' \
  --exclude='rual/print-service/__pycache__' --exclude='rual/print-service/__pycache__/*' \
  --exclude='rual/print-service/logs' --exclude='rual/print-service/logs/*' \
  --exclude='rual/print-service/preview' --exclude='rual/print-service/preview/*' \
  --exclude='rual/print-service/spool' --exclude='rual/print-service/spool/*' \
  --exclude='rual/sync-service/__pycache__' --exclude='rual/sync-service/__pycache__/*' \
  --exclude='rual/sync-service/logs' --exclude='rual/sync-service/logs/*' \
  --exclude='rual/api/db.local.php' \
  --exclude='rual/github_api.txt' --exclude='rual/ssh.txt' \
  --exclude='rual/AGENTS.md' --exclude='rual/PLAN-INSTALARE-PRODUCTIE.md' \
  --exclude='rual/docs' --exclude='rual/docs/*' \
  --exclude='rual/*.pdf' \
  --exclude='rual/print-service/config.json' --exclude='rual/sync-service/config.json' \
  --exclude='rual/db/tools' --exclude='rual/db/tools/*' rual

(
  cd "$SETUP_DIR"
  git add -A
  if git diff --cached --quiet; then
    echo "Setup: nicio modificare de sincronizat."
  else
    git commit -q -m "Sync app snapshot $BUILT_AT"
    git push -q "https://x-access-token:${TOKEN}@github.com/abcsoft-ro/TableManager-Setup.git" main
    echo "Setup sincronizat: $(git rev-parse --short HEAD) (v${HUMAN} build ${BUILD})"
  fi
)
