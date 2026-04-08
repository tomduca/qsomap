#!/bin/bash
# Daily QSO sync script
# Syncs from Clublog (primary) with LOTW fallback, rebuilds cache with HamQTH grid lookups

# Get script directory and use it as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Log to local file in script directory
LOGFILE="$SCRIPT_DIR/sync_daily.log"

echo "=== Daily QSO Sync - $(date) ===" >> "$LOGFILE"

# Try Clublog first (primary QSO source with 82% grid coverage)
echo "Syncing from Clublog..." >> "$LOGFILE"
php sync_clublog.php >> "$LOGFILE" 2>&1
CLUBLOG_STATUS=$?

# Fallback to LOTW if Clublog failed
if [ $CLUBLOG_STATUS -ne 0 ]; then
    echo "Clublog sync failed, falling back to LOTW..." >> "$LOGFILE"
    php sync_lotw.php >> "$LOGFILE" 2>&1
fi

# Rebuild cache with grid lookups (HamQTH/Spothole for QSOs without grids)
echo "Rebuilding cache..." >> "$LOGFILE"
php build_cache.php >> "$LOGFILE" 2>&1

echo "=== Sync complete ===" >> "$LOGFILE"
echo "" >> "$LOGFILE"
