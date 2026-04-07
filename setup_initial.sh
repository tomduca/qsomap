#!/bin/bash
#
# QSO Map - Initial Setup Script
# Run this ONCE after uploading to hosting to initialize the system
# After this completes successfully, configure cron to run sync_daily.sh
#

echo "========================================="
echo "QSO Map - Initial Setup"
echo "========================================="
echo ""

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo "Working directory: $SCRIPT_DIR"
echo ""

# Create data directory if needed
if [ ! -d "data" ]; then
    echo "Creating data directory..."
    mkdir -p data
    chmod 755 data
fi

# Step 1: Full sync from Clublog
echo "========================================="
echo "Step 1: Downloading ALL QSOs from Clublog"
echo "========================================="
echo ""

# Remove last sync file to force full sync
rm -f data/clublog_last_sync.txt

# Run Clublog sync
php sync_clublog.php

if [ $? -ne 0 ]; then
    echo ""
    echo "ERROR: Clublog sync failed!"
    echo "Trying LOTW as fallback..."
    echo ""
    php sync_lotw.php
    
    if [ $? -ne 0 ]; then
        echo ""
        echo "ERROR: Both Clublog and LOTW sync failed!"
        echo "Please check your config.json credentials and try again."
        exit 1
    fi
fi

echo ""
echo "✓ QSO sync completed"
echo ""

# Step 2: Build cache with grid lookups
echo "========================================="
echo "Step 2: Building cache with grid lookups"
echo "========================================="
echo ""
echo "This may take a few minutes for the first time..."
echo ""

php build_cache.php

if [ $? -ne 0 ]; then
    echo ""
    echo "ERROR: Cache build failed!"
    exit 1
fi

echo ""
echo "✓ Cache build completed"
echo ""

# Verify cache was created
if [ ! -f "data/qso_cache.json" ]; then
    echo "ERROR: qso_cache.json was not created!"
    exit 1
fi

# Show results
echo "========================================="
echo "Initial Setup Complete!"
echo "========================================="
echo ""
echo "Files created:"
ls -lh data/*.json 2>/dev/null
echo ""
echo "Next steps:"
echo "1. Verify the map works: https://your-domain.com/qsomap/index-headless.html"
echo "2. Configure daily cron job to run: sync_daily.sh"
echo "3. Remove this setup_initial.sh script (no longer needed)"
echo ""
echo "Cron job example (runs daily at 2 AM):"
echo "0 2 * * * cd ~/public_html/qsomap && /bin/bash sync_daily.sh >> ~/qsomap_cron.log 2>&1"
echo ""
echo "========================================="
