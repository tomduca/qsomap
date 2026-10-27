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

In cPanel → *Cron Jobs*, add a daily task (e.g. at 02:00) with this command, replacing `USER` with your cPanel user, `qsomap` with your folder name and the PHP path with the one found below:

```bash
cd /home/USER/public_html/qsomap && PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php /bin/bash scripts/sync_daily.sh
```

Use the absolute path (no `~`).

### Choosing the PHP binary

`PHP_BIN` must point to a **CLI** build of PHP 7.4 or newer. On many cPanel hosts the plain `php` on Cron's PATH is the CGI build (`cgi-fcgi`): the scripts then treat the run as a web request without a token, so `sync_daily.sh` checks the binary first and stops with `is not a PHP CLI binary` in the log.

To find the right path without a terminal:

1. In *Cron Jobs*, add a temporary job set to *Once Per Minute* with this command (one line, `USER` replaced):

   ```bash
   for p in /opt/cpanel/ea-php*/root/usr/bin/php /usr/local/bin/php /usr/bin/php; do echo "$p"; "$p" -v 2>&1 | head -1; done > /home/USER/php_check.txt 2>&1
   ```

2. Wait two minutes and delete that job.
3. In the *File Manager*, open `php_check.txt` in your home folder (not in `public_html`). Each path is followed by its version, e.g. `PHP 7.4.33 (cli)` or `PHP 7.3.33 (cgi-fcgi)`.
4. Pick a path marked `(cli)`, preferably the same version your domain uses in cPanel → *MultiPHP Manager* (e.g. `ea-php74` for PHP 7.4), and use it as `PHP_BIN`. Then delete `php_check.txt`.

If you later change the domain's PHP version in MultiPHP Manager, update `PHP_BIN` to match. If no path shows `(cli)`, ask your hosting provider for the PHP CLI path for Cron.

To test without waiting for the night, set the job a couple of minutes ahead (Cron uses the server's time zone, often GMT), check the end of `data/sync.log` in the File Manager, then set it back to daily. A successful run shows `=== Clublog sync: YOUR_CALLSIGN ===` and `data/clublog_last_sync.txt` holds today's date. Days missed while Cron was failing are recovered on the next good run.

### What the script does

1. Once a month, refreshes the DXCC list and the grids of each entity (see below).
2. Checks GitHub for a newer QSO Map version (see *Update notice* below).
3. Syncs from Clublog (incremental, with a 2-day overlap).
4. If Clublog fails or is not configured, syncs from LoTW instead (also incremental) and merges the result.
5. Rebuilds `data/qso_cache.json`, reusing grids found in earlier runs and dropping grids that do not match the QSO's DXCC entity (see *Grid check* below).
6. Logs everything to `data/sync.log`.

If a sync is already running, the new one is skipped.

Both sources are merged by callsign, date, time (to the minute) and band, so a night on the LoTW fallback followed by Clublog the next day never creates duplicate QSOs: each contact stays a single record combining the fields from both.

### DXCC list and grids

The popups name each contact's DXCC entity using `js/dxcc.js`, a base list with names, flags and deleted entities. When `data/dxcc_last_update.txt` is missing or more than 30 days old, the daily sync runs `scripts/update_dxcc.php`, which downloads AD1C's `cty.csv` from [country-files.com](https://www.country-files.com/) and writes the current entities to `data/dxcc.json`. The maps add any entity from that file that the base list does not have yet; names already in the base list are kept.

The same script downloads `vuccgrids.dat`, the list of 4-character grids in each DXCC entity maintained in ARRL's [TrustedQSL repository](https://sourceforge.net/p/trustedqsl/tqsl/ci/master/tree/apps/vuccgrids.dat), and writes it to `data/vuccgrids.json`. It also runs when that file is missing.

If a download fails, the log shows `DXCC list update failed, keeping the current one`, the sync continues, and the update is retried the next day. To force an update, delete `data/dxcc_last_update.txt` or open `scripts/update_dxcc.php?token=YOUR_TOKEN`.

### Grid check

A wrong grid puts a contact in the wrong place, e.g. `AA00` (the default value of some logging programs) for a US station, or an operator's home grid logged while operating abroad. On every run, `build_cache.php` checks each grid, logged or looked up, against the QSO's DXCC entity in `data/vuccgrids.json`:

- The grid is accepted if its 4-character square, **or any of the 8 squares around it**, belongs to the entity. This one-square tolerance avoids false alarms along borders the file does not cover exactly (e.g. along the Mexico/US border, or the Russian regions that count as Asiatic Russia for DXCC).
- If it does not match, it is dropped and the call is looked up on HamQTH and Spothole; the lookup result goes through the same check. A matching grid from the lookup puts the QSO in the right place.
- If no matching grid is found, the QSO has no grid and no map shows it, including the QRZ and GitHub maps.
- QSOs without a DXCC code, and entities missing from the file, are not checked.

Each run lists the rejected grids in `data/sync.log` under `Grids rejected for not matching the DXCC entity`, so you can fix them in your log. Since the cache is rebuilt from the whole log on every run, the check applies to old and new QSOs alike and a fixed QSO shows up again after the next sync.

### Update notice

The installed version is in the `VERSION` file. Every day `scripts/check_update.php` compares it with the version tags (`v1.2.0`, ...) of the [GitHub repository](https://github.com/tomduca/qsomap) and writes the result to `data/update.json`. If the check fails (e.g. GitHub is unreachable), the log shows `Update check failed, will retry tomorrow` and the sync continues.

When a newer version exists, the interactive maps show a banner at the top with a link to it, **only in the owner's browser**, so visitors never see it. To mark your browser, open any map once with `?owner`:

```text
https://your-domain.com/qsomap/map-ssb.html?owner
```

The browser remembers it; open `?owner=0` to undo it. The banner's × hides it until the next version. This is a convenience, not access control: anyone who adds `?owner` only sees the same notice.

To update, upload the new files over the old ones, keeping `config.json` and `data/`. The new `VERSION` file makes the banner go away after the next daily check (or open `scripts/check_update.php?token=YOUR_TOKEN` to check right away).

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
| Some QSOs are missing | They have no grid and none was found on HamQTH or Spothole, or their grid does not match their DXCC entity (listed in `data/sync.log`, see *Grid check*). They are retried on every sync. |
| `php: command not found` in the log | Set `PHP_BIN` in the Cron command (see step 5). |
| `is not a PHP CLI binary` in the log | Cron is using the CGI build of PHP. Set `PHP_BIN` to a CLI build (see *Choosing the PHP binary* in step 5). |
| A run ends with `Sync FAILED` | Look at the lines above it: the sync (Clublog and the LoTW fallback) or the cache rebuild failed. |

To force a full download (e.g. after fixing old grids), delete `data/clublog_last_sync.txt` (Clublog) or `data/lotw_last_sync.json` (LoTW) and run the sync again (browser or Cron).

## Publishing a new version (maintainer)

Installed maps learn about a release from its git tag, so a push to `main` alone does not show the banner:

1. Update `VERSION` (e.g. `1.1.0`) and commit it with the changes.
2. Tag that commit and push the tag:

   ```bash
   git tag v1.1.0
   git push origin main v1.1.0
   ```

3. Optional: create a GitHub release from the tag with notes on what changed; the banner links to `releases/tag/v1.1.0`, which shows the notes when the release exists.
