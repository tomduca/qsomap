# Deployment guide

How to publish QSO Map on shared hosting with cPanel. For a project overview, see the [README](../README.md).

## 1. Upload the files

Upload the repository contents to a public folder, e.g. `public_html/qsomap/`, using the File Manager, FTP or `git clone`. Any folder name works. You only need the `map-*` pages, `js/`, `css/`, `img/`, `scripts/` and `.htaccess`; `docs/`, the `.md` files and `config.json.example` are not used by the site.

Make sure both `.htaccess` files (root and `scripts/`) are uploaded: some FTP clients hide files that start with a dot.

## 2. Configure `config.json`

Copy `config.json.example` to `config.json` in the same folder and fill it in:

```json
{
  "lotw":    { "username": "YOUR_CALLSIGN", "password": "...", "days_back": 3650 },
  "clublog": { "email": "...", "password": "...", "api_key": "...", "callsign": "YOUR_CALLSIGN" },
  "hamqth":  { "username": "...", "password": "..." },
  "map":     { "my_callsign": "YOUR_CALLSIGN", "my_grid": "FF57oc" },
  "web":     { "token": "A_LONG_RANDOM_STRING" }
}
```

- **Clublog:** `password` is an *application password* created in your Clublog settings, not your account password. `api_key` can be requested at [clublog.org/need_api.php](https://clublog.org/need_api.php).
- **LoTW:** your lotw.arrl.org login; no API key needed. Used when Clublog fails, or as the only source if you leave the Clublog fields empty. `days_back` limits how far back the first download goes; later runs are incremental.
- **HamQTH:** optional. Without it, missing grids are looked up on Spothole only.
- **map:** your station's callsign and grid (4, 6 or 8 characters).
- **web.token:** enables running the scripts from a browser (step 3). Use at least 16 random characters, e.g. from a password manager, letters and digits only so it needs no URL escaping. If left empty, the scripts can only be run from Cron or a terminal.

To check that `config.json` is not reachable, open `https://your-domain.com/qsomap/config.json`: it must return **403 Forbidden**.

### Using LoTW only

If you don't have a Clublog API key, fill in only the `lotw` section. The daily sync tries Clublog, logs that it is not configured, and falls back to LoTW. Keep in mind:

- Only QSOs you uploaded to LoTW (signed with TQSL) are downloaded.
- LoTW includes the other station's grid only for confirmed QSOs; unconfirmed ones get their grid from HamQTH/Spothole (the station's registered home grid, which may differ from where it operated).

Following ARRL's guidance for automated downloads, only the first LoTW sync downloads everything. Later runs ask only for QSOs uploaded since the last run (`qso_qsorxsince`) and for new confirmations (`qso_qslsince`, which also catches old QSOs confirmed recently), using the timestamps LoTW returns. They are stored in `data/lotw_last_sync.json`.

## 3. First sync

**From a browser** (no SSH). Requires `web.token`. Open these URLs in order, waiting for each page to finish loading before opening the next:

1. `https://your-domain.com/qsomap/scripts/sync_clublog.php?token=YOUR_TOKEN`
2. `https://your-domain.com/qsomap/scripts/build_cache.php?token=YOUR_TOKEN`

If you use LoTW only, or Clublog fails, open `sync_lotw.php?token=YOUR_TOKEN` in step 1 instead. Each script prints its result as plain text. Without the token, or with a wrong one, they return **403**.

Don't run two scripts at the same time or while Cron is running: they all write to the same files in `data/`.

**From a terminal** (cPanel → *Terminal*, or SSH):

```bash
cd ~/public_html/qsomap
bash scripts/sync_daily.sh
tail data/sync.log
```

The first sync downloads the whole log and may take several minutes if many QSOs have no grid. Later syncs, from Clublog or LoTW, are incremental.

If the PHP found by Cron is not the right binary, set its path with `PHP_BIN`:

```bash
PHP_BIN=/usr/local/bin/php bash scripts/sync_daily.sh
```

## 4. Generate the static map background

`map-ssb-qrz.php` uses a 600 × 600 px background with your QTH in the center. Generate it once, and again only if `map.my_grid` changes:

- Browser: `https://your-domain.com/qsomap/scripts/make_background.php?token=YOUR_TOKEN`
- Terminal: `php scripts/make_background.php`

It creates `data/qrz-background.png` from `img/world-map-600.png`. Requires the GD extension.

## 5. Daily Cron job

In cPanel → *Cron Jobs*, add a daily task (e.g. at 02:00) with this command, replacing `USER` with your cPanel user and `qsomap` with your folder name:

```bash
cd /home/USER/public_html/qsomap && /bin/bash scripts/sync_daily.sh
```

Use the absolute path (no `~`). The script:

1. Syncs from Clublog (incremental, with a 2-day overlap).
2. If Clublog fails or is not configured, syncs from LoTW instead (also incremental) and merges the result.
3. Rebuilds `data/qso_cache.json`, reusing grids found in earlier runs.
4. Logs everything to `data/sync.log`.

If a sync is already running, the new one is skipped.

Both sources are merged by callsign, date, time (to the minute) and band, so a night on the LoTW fallback followed by Clublog the next day never creates duplicate QSOs: each contact stays a single record combining the fields from both.

## 6. Embed the map on QRZ.com

In your QRZ biography editor, add a square iframe:

```html
<iframe src="https://your-domain.com/qsomap/map-ssb-qrz.php?v=1" width="600" height="600" frameborder="0" scrolling="no"></iframe>
```

QRZ may cache the iframe: if it still shows an old version after a change, bump the `v` value (e.g. `?v=2`). The PHP already sends no-cache headers and adds a unique parameter to the background URL. Clicking the map opens the interactive SSB view in a new tab.

## 7. Show the map in a GitHub README

GitHub strips iframes from READMEs, so use `map-ssb-github.php` instead: it draws the same map as a self-contained SVG image (background included) and links it to the interactive view:

```markdown
[![SSB map](https://your-domain.com/qsomap/map-ssb-github.php)](https://your-domain.com/qsomap/map-ssb.html)
```

GitHub serves remote images through its own cache (camo). The PHP sends `Cache-Control: no-cache` and an `ETag` that changes whenever `data/qso_cache.json` or the background is rebuilt, so the README picks up each daily sync. The image has no tooltips or clicks of its own; the link opens the interactive map.

## Verification checklist

- [ ] `config.json` returns 403 in the browser.
- [ ] `scripts/sync_daily.sh`, `scripts/common.php` and `data/sync.log` return 403.
- [ ] `scripts/sync_clublog.php` without `?token=` returns 403.
- [ ] `data/qso_cache.json` exists and contains QSOs.
- [ ] `map-ssb.html`, `map-digi.html` and `map-qsl.html` show markers.
- [ ] `map-ssb-qrz.php` shows the background, points and paths.
- [ ] `map-ssb-github.php` opens as an image (SVG) with the same background, points and paths.
- [ ] Cron ran and added a new entry to `data/sync.log`.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| "Could not load data/qso_cache.json" | The sync has not run, or it failed: check `data/sync.log`. |
| "Invalid QTH grid" | `map.my_grid` is missing or malformed; fix it and run `scripts/build_cache.php` again. |
| `Clublog download failed` in the log | Wrong credentials or API key. If LoTW is configured, it is used as a fallback. |
| `Clublog credentials not configured` in the log | Expected if you use LoTW only. |
| The QRZ or GitHub map has no background | `data/qrz-background.png` is missing: run `scripts/make_background.php`. |
| `config.json` downloads in the browser | The host ignores `.htaccess` (`AllowOverride`) or it was not uploaded. Ask your provider before going further. |
| Some QSOs are missing | They have no grid and none was found on HamQTH or Spothole. They are retried on every sync. |
| `php: command not found` in the log | Set `PHP_BIN` in the Cron command (see step 3). |

To force a full download (e.g. after fixing old grids), delete `data/clublog_last_sync.txt` (Clublog) or `data/lotw_last_sync.json` (LoTW) and run the sync again (browser or Cron).
