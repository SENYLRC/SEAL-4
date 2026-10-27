#!/usr/bin/env bash
# Copy changed plugin + cron files from this folder to the server set in deploy/deploy.conf.
#
#   bash deploy/rsync-to-node.sh          # dry run: lists what WOULD change, changes nothing
#   bash deploy/rsync-to-node.sh --go     # copy for real
#
#   plugin  -> $PLUGIN_DEST   (without cron/)
#   cron    -> $CRON_DEST     (the scripts and cron_local.php; the server's ill_db.inc is never touched)
#
# deploy/deploy.conf (copy deploy.conf.example) sets HOST, PLUGIN_DEST and CRON_DEST; it is not in version control.
# Copies new and changed files only (by size and time). Never deletes anything on the server.
# Notes, docs, tests and git files stay here. Permissions are set to folders 755 / files 644 whatever they are locally
# (a network share can show everything as rwx------, which copied as-is would leave the files unreadable to the web server).
# Both copies share one SSH connection (ControlMaster), so a hardware SSH key needs only one touch; the connection
# closes 60 s after the last copy.
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF="$SRC/deploy/deploy.conf"
[[ -f "$CONF" ]] || { echo "Missing $CONF - copy deploy/deploy.conf.example and fill it in." >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONF"
: "${HOST:?HOST not set in deploy.conf}" "${PLUGIN_DEST:?PLUGIN_DEST not set in deploy.conf}" "${CRON_DEST:?CRON_DEST not set in deploy.conf}"

MODE="--dry-run"
[[ "${1:-}" == "--go" ]] && MODE=""

COMMON=( -rltz --itemize-changes $MODE --perms --chmod=D755,F644
         -e "ssh -o StrictHostKeyChecking=accept-new -o ControlMaster=auto -o ControlPath=$HOME/.ssh/cm-%r@%h:%p -o ControlPersist=60"
         --exclude='.git/' --exclude='.gitignore' --exclude='*.example'
         --exclude='*.md' --exclude='*.bak' --exclude='.DS_Store' --exclude='Thumbs.db'
         --exclude='holidays_*.json' --exclude='*_db.inc' )

echo "== Plugin -> $HOST:$PLUGIN_DEST"
rsync "${COMMON[@]}" \
  --exclude='/docs/' --exclude='/deploy/' --exclude='/tests/' --exclude='/file-history/' --exclude='/cron/' --exclude='/venv/' \
  "$SRC/" "$HOST:$PLUGIN_DEST"

echo
echo "== Cron scripts -> $HOST:$CRON_DEST"
rsync "${COMMON[@]}" \
  --exclude='/tests/' \
  "$SRC/cron/scripts/" "$HOST:$CRON_DEST"

if [[ -n "$MODE" ]]; then
  echo
  echo "Dry run only: nothing was copied. Run again with --go to copy these files."
fi
