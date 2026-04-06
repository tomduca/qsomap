#!/bin/bash
# Daily QSO sync script
# Syncs from LOTW and rebuilds cache with HamQTH lookups

cd /var/www/html/qsomap

echo "=== Daily QSO Sync - $(date) ===" >> /var/log/qsomap-sync.log

# Sync from LOTW
echo "Syncing from LOTW..." >> /var/log/qsomap-sync.log
php sync_lotw.php >> /var/log/qsomap-sync.log 2>&1

# TODO: Enable Clublog sync once account has QSOs uploaded
# echo "Syncing from Clublog..." >> /var/log/qsomap-sync.log
# php sync_clublog.php >> /var/log/qsomap-sync.log 2>&1

# Rebuild cache with grid lookups (HamQTH primary, Spothole fallback)
echo "Rebuilding cache..." >> /var/log/qsomap-sync.log
php build_cache.php >> /var/log/qsomap-sync.log 2>&1

echo "=== Sync complete ===" >> /var/log/qsomap-sync.log
echo "" >> /var/log/qsomap-sync.log
