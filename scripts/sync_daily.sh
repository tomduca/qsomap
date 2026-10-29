#!/bin/bash
# Daily sync, meant for Cron: shared data first (DXCC list, update check), then
# every station one after the other: Clublog (LoTW as fallback), then its map
# cache. Stations never sync at the same time, so the load is that of one map.
# Usage: /bin/bash /path/to/qsomap/scripts/sync_daily.sh [CALL]
#   CALL: sync only that station (default: all of them)
# Set PHP_BIN if the PHP CLI is not on Cron's PATH (e.g. PHP_BIN=/usr/local/bin/php).
# Shared steps log to data/sync.log, each station to stations/<CALL>/data/sync.log
# (or the folder set in storage.stations_dir of config.json).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
DATA_DIR="$ROOT_DIR/data"
LOGFILE="$DATA_DIR/sync.log"
PHP_BIN="${PHP_BIN:-php}"
ONLY_STATION="$(echo "${1:-}" | tr '[:lower:]' '[:upper:]')"

mkdir -p "$DATA_DIR"

# Never run two syncs at the same time.
exec 9>"$DATA_DIR/.sync.lock"
if command -v flock >/dev/null && ! flock -n 9; then
    echo "$(date) sync already running, skipping" >> "$LOGFILE"
    exit 1
fi

status=0
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

    STATIONS_DIR="$("$PHP_BIN" -r 'require $argv[1]; echo QSOMAP_STATIONS;' "$SCRIPT_DIR/common.php")"
    if [ -z "$STATIONS_DIR" ]; then
        echo "Error: cannot read the stations folder from config.json"
        echo "=== Sync FAILED ==="
        echo
        exit 1
    fi

    # Installations from before version 2 kept one station in config.json and
    # data/: move it to stations/<CALL>/ once.
    if ! ls "$STATIONS_DIR"/*/config.json >/dev/null 2>&1 && grep -q '"my_callsign"' "$ROOT_DIR/config.json" 2>/dev/null; then
        "$PHP_BIN" "$SCRIPT_DIR/migrate_to_stations.php" || echo "Migration to stations/ failed"
    fi

    # Encrypt passwords typed into a station's config.json by hand.
    if grep -q '"key_file"' "$ROOT_DIR/config.json" 2>/dev/null; then
        "$PHP_BIN" "$SCRIPT_DIR/encrypt_secrets.php" || echo "Encrypting stored passwords failed"
    fi

    # Refresh the DXCC list and grids once a month; the stamp is only written on
    # success, so a failed download is retried on the next run.
    DXCC_STAMP="$DATA_DIR/dxcc_last_update.txt"
    if [ ! -f "$DXCC_STAMP" ] || [ ! -f "$DATA_DIR/vuccgrids.json" ] || [ -n "$(find "$DXCC_STAMP" -mtime +29)" ]; then
        "$PHP_BIN" "$SCRIPT_DIR/update_dxcc.php" || echo "DXCC list update failed, keeping the current one"
    fi

    # Look for a newer QSO Map version on GitHub (shown to the owner as a banner).
    "$PHP_BIN" "$SCRIPT_DIR/check_update.php" || echo "Update check failed, will retry tomorrow"
} >> "$LOGFILE" 2>&1 || exit 1

for config in "$STATIONS_DIR"/*/config.json; do
    [ -f "$config" ] || continue
    call="$(basename "$(dirname "$config")")"
    [ -z "$ONLY_STATION" ] || [ "$call" = "$ONLY_STATION" ] || continue
    mkdir -p "$STATIONS_DIR/$call/data"

    station_status=0
    (
        echo "=== Daily QSO sync: $call - $(date) ==="
        export QSOMAP_STATION="$call"
        if ! "$PHP_BIN" "$SCRIPT_DIR/sync_clublog.php"; then
            echo "Clublog sync failed, falling back to LoTW..."
            "$PHP_BIN" "$SCRIPT_DIR/sync_lotw.php" || station_status=1
        fi
        "$PHP_BIN" "$SCRIPT_DIR/build_cache.php" || station_status=1
        if [ "$station_status" -eq 0 ]; then
            echo "=== Sync complete ==="
        else
            echo "=== Sync FAILED ==="
        fi
        echo
        exit "$station_status"
    ) >> "$STATIONS_DIR/$call/data/sync.log" 2>&1 || station_status=1

    if [ "$station_status" -eq 0 ]; then
        echo "$call: sync complete" >> "$LOGFILE"
    else
        echo "$call: sync FAILED (see $STATIONS_DIR/$call/data/sync.log)" >> "$LOGFILE"
        status=1
    fi
done

echo >> "$LOGFILE"
exit "$status"
