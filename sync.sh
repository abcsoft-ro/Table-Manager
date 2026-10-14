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

git add -A

if git diff --cached --quiet; then
  echo "Nicio modificare de sincronizat ($(date '+%Y-%m-%d %H:%M:%S'))."
else
  git commit -q -m "Sync $(date '+%Y-%m-%d %H:%M:%S')"
  git push -q "https://x-access-token:${TOKEN}@github.com/abcsoft-ro/Table-Manager.git" main
  echo "Sincronizat: $(git rev-parse --short HEAD) ($(date '+%Y-%m-%d %H:%M:%S'))"
fi

# ------------------------------------------------------- snapshot de instalare
if [ ! -d "$SETUP_DIR/.git" ]; then
  echo "Setup: '$SETUP_DIR' nu este un repo git (sar peste). Setati SETUP_DIR pentru a sincroniza installerul." >&2
  exit 0
fi

SHA="$(git rev-parse HEAD)"
SHORT="$(git rev-parse --short HEAD)"
BUILD="$(git rev-list --count HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
BUILT_AT="$(date '+%Y-%m-%d %H:%M:%S')"
if [ -f VERSION ]; then
  HUMAN="$(tr -d ' \t\r\n' < VERSION)"
else
  HUMAN=""
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
