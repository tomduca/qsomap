#!/bin/bash
# Run the daily sync of every QSO Map instance under a folder, one after the
# other, so a single Cron job covers all the stations you host.
# Usage: /bin/bash /path/to/qsomap/scripts/sync_all.sh /home/USER/public_html
# Set PHP_BIN as for sync_daily.sh; each instance logs to its own data/sync.log.

PARENT_DIR="${1:?usage: sync_all.sh /path/to/folder/with/instances}"
status=0

for instance in "${PARENT_DIR%/}"/*/; do
    instance="${instance%/}"
    [ -f "$instance/scripts/sync_daily.sh" ] || continue
    # Instances still waiting for their station to open the setup link.
    if ! grep -q '"my_callsign"' "$instance/config.json" 2>/dev/null; then
        echo "$(date) skipping $instance: not set up yet"
        continue
    fi
    /bin/bash "$instance/scripts/sync_daily.sh" || { status=1; echo "$(date) sync failed: $instance (see its data/sync.log)"; }
done

exit "$status"
