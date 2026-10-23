#!/bin/bash
# Daily sync, meant for Cron: Clublog (LoTW as fallback), then rebuild the map cache.
# Usage: /bin/bash /path/to/qsomap/scripts/sync_daily.sh
# Set PHP_BIN if the PHP CLI is not on Cron's PATH (e.g. PHP_BIN=/usr/local/bin/php).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_DIR="$SCRIPT_DIR/../data"
LOGFILE="$DATA_DIR/sync.log"
PHP_BIN="${PHP_BIN:-php}"

mkdir -p "$DATA_DIR"

# Never run two syncs at the same time.
exec 9>"$DATA_DIR/.sync.lock"
if command -v flock >/dev/null && ! flock -n 9; then
    echo "$(date) sync already running, skipping" >> "$LOGFILE"
    exit 1
fi

{
    echo "=== Daily QSO sync - $(date) ==="
    if ! "$PHP_BIN" "$SCRIPT_DIR/sync_clublog.php"; then
        echo "Clublog sync failed, falling back to LoTW..."
        "$PHP_BIN" "$SCRIPT_DIR/sync_lotw.php"
    fi
    "$PHP_BIN" "$SCRIPT_DIR/build_cache.php"
    echo "=== Sync complete ==="
    echo
} >> "$LOGFILE" 2>&1
