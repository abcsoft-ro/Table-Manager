#!/usr/bin/env bash
#
# Sincronizare TableManager cu GitHub (abcsoft-ro/Table-Manager).
# Face commit doar daca exista modificari, apoi push pe branch-ul main.
# Tokenul se citeste din github_api.txt (ignorat de git).
#
set -euo pipefail
cd "$(dirname "$0")"

REPO_URL="https://github.com/abcsoft-ro/Table-Manager.git"

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
  exit 0
fi

git commit -q -m "Sync $(date '+%Y-%m-%d %H:%M:%S')"
git push -q "https://x-access-token:${TOKEN}@github.com/abcsoft-ro/Table-Manager.git" main
echo "Sincronizat: $(git rev-parse --short HEAD) ($(date '+%Y-%m-%d %H:%M:%S'))"
