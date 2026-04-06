# Clublog Integration - Pending

## Status
Clublog API key obtained but sync returns HTTP 403 Forbidden.

## API Key
Stored in `config.json` (not in git):
- Field: `clublog.api_key`
- Value: `28a3f0c3f9f42a01f6a0cf3dcda74903edfce6f8`

## Issue
The Clublog API endpoint returns 403 even with valid API key:
```
POST https://clublog.org/getadif.php
Parameters: api, email, callsign, full=yes
Response: HTTP 403 Forbidden
```

## Likely Cause
Your Clublog account may not have QSOs uploaded yet. Clublog requires you to upload your log before you can download it via API.

## Next Steps

### 1. Upload QSOs to Clublog
Visit https://clublog.org and upload your ADIF file from LOTW:
```bash
# First, sync from LOTW to get ADIF
php sync_lotw.php

# The ADIF data is in data/qso_data.json
# You may need to export it as ADIF format for Clublog upload
```

### 2. Test Clublog Sync
Once QSOs are uploaded to Clublog:
```bash
php sync_clublog.php
```

### 3. Enable in Daily Sync
Uncomment the Clublog sync lines in `sync_daily.sh`:
```bash
echo "Syncing from Clublog..." >> /var/log/qsomap-sync.log
php sync_clublog.php >> /var/log/qsomap-sync.log 2>&1
```

### 4. Update build_cache.php (Optional)
If Clublog provides better grid coverage than HamQTH, you can modify `build_cache.php` to prefer Clublog grids.

## Current System (Working)
- **Primary Source**: LOTW (285 QSOs)
- **Grid Lookups**: HamQTH → Spothole fallback (98 QSOs with grids, 34%)
- **Daily Sync**: LOTW only

## Benefits of Clublog (When Working)
- Potentially better grid square coverage
- Additional QSO metadata
- Integration with Clublog's DXCC and awards tracking
