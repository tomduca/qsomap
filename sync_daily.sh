#!/bin/bash
# Daily QSO sync script
# Syncs from Clublog (primary) with LOTW fallback, rebuilds cache with HamQTH grid lookups

cd /var/www/html/qsomap

echo "=== Daily QSO Sync - $(date) ===" >> /var/log/qsomap-sync.log

# Try Clublog first (primary QSO source with 82% grid coverage)
echo "Syncing from Clublog..." >> /var/log/qsomap-sync.log
php sync_clublog.php >> /var/log/qsomap-sync.log 2>&1
CLUBLOG_STATUS=$?

# Fallback to LOTW if Clublog failed
if [ $CLUBLOG_STATUS -ne 0 ]; then
    echo "Clublog sync failed, falling back to LOTW..." >> /var/log/qsomap-sync.log
    php sync_lotw.php >> /var/log/qsomap-sync.log 2>&1
fi

# Rebuild cache with grid lookups (HamQTH/Spothole for QSOs without grids)
echo "Rebuilding cache..." >> /var/log/qsomap-sync.log
php build_cache.php >> /var/log/qsomap-sync.log 2>&1

echo "=== Sync complete ===" >> /var/log/qsomap-sync.log
echo "" >> /var/log/qsomap-sync.log
