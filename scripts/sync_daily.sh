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

    # A CGI build of PHP makes the scripts answer 403 as if called from the web.
    PHP_VERSION="$("$PHP_BIN" -v 2>&1 | head -n 1)"
    if [[ "$PHP_VERSION" != *"(cli)"* ]]; then
        echo "Error: PHP_BIN=$PHP_BIN is not a PHP CLI binary: $PHP_VERSION"
        echo "Set PHP_BIN to a CLI build in the Cron command (see docs/DEPLOYMENT.md, step 5)."
        echo "=== Sync FAILED ==="
        echo
        exit 1
    fi

    # Refresh the DXCC list once a month; the stamp is only written on success,
    # so a failed download is retried on the next run.
    DXCC_STAMP="$DATA_DIR/dxcc_last_update.txt"
    if [ ! -f "$DXCC_STAMP" ] || [ -n "$(find "$DXCC_STAMP" -mtime +29)" ]; then
        "$PHP_BIN" "$SCRIPT_DIR/update_dxcc.php" || echo "DXCC list update failed, keeping the current one"
    fi

    status=0
    if ! "$PHP_BIN" "$SCRIPT_DIR/sync_clublog.php"; then
        echo "Clublog sync failed, falling back to LoTW..."
        "$PHP_BIN" "$SCRIPT_DIR/sync_lotw.php" || status=1
    fi
    "$PHP_BIN" "$SCRIPT_DIR/build_cache.php" || status=1

    if [ "$status" -eq 0 ]; then
        echo "=== Sync complete ==="
    else
        echo "=== Sync FAILED ==="
    fi
    echo
    exit "$status"
} >> "$LOGFILE" 2>&1
