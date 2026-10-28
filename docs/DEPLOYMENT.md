# Deployment guide

How to publish QSO Map on shared hosting with cPanel. For a project overview, see the [README](../README.md).

## How stations are organized

One installation serves one or more stations: yours, and those of other hams you host (e.g. your radio club's members). Each station has its own folder with its settings, credentials and data:

```text
qsomap/                          e.g. public_html/lu4aa/
├── config.json                  manager settings: web.token, default_station
├── stations/
│   ├── LU2MET/config.json       that station's map, LoTW, Clublog and HamQTH settings
│   ├── LU2MET/data/             its QSOs, map cache, background and sync.log
│   └── LU9XYZ/...
└── data/                        shared: DXCC lists, update check, invites, events.log
```

No credential is shared between stations: your Clublog API key and your HamQTH account live in your station's `config.json` and are used only for your map. Nothing under `stations/` is reachable from the web.

The maps take the station's callsign in the query string; one set of pages serves every station:

| Page | URL |
| --- | --- |
| Interactive maps | `https://your-domain.com/qsomap/map-ssb.html?LU9XYZ` (also `map-digi.html`, `map-qsl.html`) |
| Static map for QRZ.com | `https://your-domain.com/qsomap/map-ssb-qrz.php?LU9XYZ` |
| Image for a GitHub README | `https://your-domain.com/qsomap/map-ssb-github.php?LU9XYZ` |

Without a callsign they show `default_station`, so your own map keeps short URLs.

## 1. Upload the files

Upload the repository contents to a public folder, e.g. `public_html/qsomap/` (or a name like your club's callsign, `public_html/lu4aa/`), using the File Manager, FTP or `git clone`. All paths are relative. You need:

- the `map-*` pages, `cache.php`, `background.php`, `join.php`, `VERSION` and `.htaccess`;
- the folders `js/`, `css/`, `img/`, `scripts/`, `admin/` and `stations/` (it only holds its `.htaccess`).

`docs/`, the `.md` files and the `.example` files are not used by the site.

Make sure the three `.htaccess` files (root, `scripts/` and `stations/`) are uploaded: some FTP clients hide files that start with a dot.

## 2. Configure

**Manager settings.** Copy `config.json.example` to `config.json` and fill it in:

```json
{
  "web": { "token": "A_LONG_RANDOM_STRING" },
  "default_station": "LU2MET"
}
```

- **web.token:** enables running the scripts from a browser (step 3). Use at least 16 random characters, e.g. from a password manager, letters and digits only so it needs no URL escaping. If left empty, the scripts can only be run from Cron or a terminal.
- **default_station:** the station shown when a map URL has no callsign. Optional.

**Your own station.** Either invite yourself through the portal (step 8; LoTW only), or create `stations/YOUR_CALLSIGN/config.json` by hand from `docs/station-config.example.json`, which also allows Clublog:

```json
{
  "map":     { "my_callsign": "LU2MET", "my_grid": "FF57oc" },
  "lotw":    { "username": "LU2MET", "password": "...", "days_back": 3650 },
  "clublog": { "email": "...", "password": "...", "api_key": "...", "callsign": "LU2MET" },
  "hamqth":  { "username": "...", "password": "..." }
}
```

- **map:** the station's callsign (as the folder name, upper case, no `/P`) and grid (4, 6 or 8 characters).
- **Clublog:** `password` is an *application password* created in your Clublog settings, not your account password. `api_key` can be requested at [clublog.org/need_api.php](https://clublog.org/need_api.php).
- **LoTW:** the lotw.arrl.org login; no API key needed. Used when Clublog fails, or as the only source if there is no `clublog` section. `days_back` limits how far back the first download goes; later runs are incremental.
- **HamQTH:** optional but recommended. Without it, missing grids are looked up on Spothole only.

To check that the configs are not reachable, open `https://your-domain.com/qsomap/config.json` (must return **403**) and `https://your-domain.com/qsomap/stations/LU2MET/config.json` (must return **403** or **404**).

### Using LoTW only

A station without a `clublog` section syncs from LoTW: the daily sync tries Clublog, logs that it is not configured, and falls back to LoTW. Keep in mind:

- Only QSOs uploaded to LoTW (signed with TQSL) are downloaded.
- LoTW includes the other station's grid only for confirmed QSOs; unconfirmed ones get their grid from HamQTH/Spothole (the station's registered home grid, which may differ from where it operated).

Following ARRL's guidance for automated downloads, only the first LoTW sync downloads everything. Later runs ask only for QSOs uploaded since the last run (`qso_qsorxsince`) and for new confirmations (`qso_qslsince`, which also catches old QSOs confirmed recently), using the timestamps LoTW returns. They are stored in `stations/<CALL>/data/lotw_last_sync.json`.

## 3. First sync of a station you set up by hand

Stations created through the portal (step 8) do this by themselves. For one you created by hand:

**From a browser** (no SSH). Requires `web.token`. Open these URLs in order, waiting for each page to finish loading before opening the next:

1. `https://your-domain.com/qsomap/scripts/update_dxcc.php?token=YOUR_TOKEN`
2. `https://your-domain.com/qsomap/scripts/sync_clublog.php?token=YOUR_TOKEN&station=LU2MET`
3. `https://your-domain.com/qsomap/scripts/build_cache.php?token=YOUR_TOKEN&station=LU2MET`

If the station uses LoTW only, or Clublog fails, open `sync_lotw.php?token=YOUR_TOKEN&station=LU2MET` in step 2 instead. Without `&station=`, scripts use `default_station`. Each script prints its result as plain text. Without the token, or with a wrong one, they return **403**.

Don't run two scripts for the same station at the same time or while Cron is running: they write to the same files.

**From a terminal** (cPanel → *Terminal*, or SSH):

```bash
cd ~/public_html/qsomap
bash scripts/sync_daily.sh LU2MET
tail stations/LU2MET/data/sync.log
```

A single script runs for a station with `QSOMAP_STATION`, e.g. `QSOMAP_STATION=LU2MET php scripts/build_cache.php`.

The first sync downloads the whole log and may take several minutes if many QSOs have no grid. Later syncs, from Clublog or LoTW, are incremental.

If the PHP found by Cron is not the right binary, set its path with `PHP_BIN`:

```bash
PHP_BIN=/usr/local/bin/php bash scripts/sync_daily.sh LU2MET
```

## 4. Generate the static map background

`map-ssb-qrz.php` uses a 600 × 600 px background with the station's QTH in the center. Generate it once, and again only if `map.my_grid` changes:

- Browser: `https://your-domain.com/qsomap/scripts/make_background.php?token=YOUR_TOKEN&station=LU2MET`
- Terminal: `QSOMAP_STATION=LU2MET php scripts/make_background.php`

It creates `stations/LU2MET/data/qrz-background.png` from `img/world-map-600.png`. Requires the GD extension.

## 5. Daily Cron job

In cPanel → *Cron Jobs*, add a daily task (e.g. at 02:00) with this command, replacing `USER` with your cPanel user, `qsomap` with your folder name and the PHP path with the one found below:

```bash
cd /home/USER/public_html/qsomap && PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php /bin/bash scripts/sync_daily.sh
```

Use the absolute path (no `~`). This single job syncs **every station**, one after the other (see below); you never add a job per station.

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

To test without waiting for the night, set the job a couple of minutes ahead (Cron uses the server's time zone, often GMT), check the end of `data/sync.log` in the File Manager, then set it back to daily. A successful run ends with one `CALL: sync complete` line per station. Days missed while Cron was failing are recovered on the next good run.

### What the script does

1. Once a month, refreshes the DXCC list and the grids of each entity (see below), shared by all stations.
2. Checks GitHub for a newer QSO Map version (see *Update notice* below).
3. Then, for each folder in `stations/`, **one station at a time**:
   1. Syncs from Clublog (incremental, with a 2-day overlap).
   2. If Clublog fails or is not configured, syncs from LoTW instead (also incremental) and merges the result.
   3. Rebuilds the station's `qso_cache.json`, reusing grids found in earlier runs and dropping grids that do not match the QSO's DXCC entity (see *Grid check* below).
4. Logs the shared steps, and one line per station, to `data/sync.log`; each station's details go to `stations/<CALL>/data/sync.log`.

Stations never sync at the same time, so the server's load is that of a single map; only the total time grows with the number of stations. A station that fails does not stop the others. If a sync is already running, the new one is skipped. `sync_daily.sh LU2MET` syncs only that station.

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

Each run lists the rejected grids in the station's `sync.log` under `Grids rejected for not matching the DXCC entity`, so you can fix them in your log. Since the cache is rebuilt from the whole log on every run, the check applies to old and new QSOs alike and a fixed QSO shows up again after the next sync.

### Update notice

The installed version is in the `VERSION` file. Every day `scripts/check_update.php` compares it with the version tags (`v1.2.0`, ...) of the [GitHub repository](https://github.com/tomduca/qsomap) and writes the result to `data/update.json`. If the check fails (e.g. GitHub is unreachable), the log shows `Update check failed, will retry tomorrow` and the sync continues.

When a newer version exists, the interactive maps show a banner at the top with a link to it, **only in the owner's browser**, so visitors never see it. To mark your browser, open any map once with `?owner`:

```text
https://your-domain.com/qsomap/map-ssb.html?owner
```

The browser remembers it; open `?owner=0` to undo it. The banner's × hides it until the next version. This is a convenience, not access control: anyone who adds `?owner` only sees the same notice.

To update, upload the new files over the old ones, keeping `config.json`, `data/` and `stations/`. The new `VERSION` file makes the banner go away after the next daily check (or open `scripts/check_update.php?token=YOUR_TOKEN` to check right away).

## 6. Embed the map on QRZ.com

In the QRZ biography editor (HTML mode), add a square iframe with the station's callsign:

```html
<iframe align="top" frameborder="0" height="600" scrolling="no" src="https://your-domain.com/qsomap/map-ssb-qrz.php?LU2MET" width="600"></iframe>
```

Stations created through the portal get this code at the end of their setup, and the admin portal shows it for every station. QRZ may cache the iframe: if it still shows an old version after a change, add or bump a `v` value (e.g. `?LU2MET&v=2`). The PHP already sends no-cache headers and adds a unique parameter to the background URL. Clicking the map opens the interactive SSB view in a new tab.

## 7. Show the map in a GitHub README

GitHub strips iframes from READMEs, so use `map-ssb-github.php` instead: it draws the same map as a self-contained SVG image (background included) and links it to the interactive view:

```markdown
[![SSB map](https://your-domain.com/qsomap/map-ssb-github.php?LU2MET)](https://your-domain.com/qsomap/map-ssb.html?LU2MET)
```

GitHub serves remote images through its own cache (camo). The PHP sends `Cache-Control: no-cache` and an `ETag` that changes whenever the station's map cache or background is rebuilt, so the README picks up each daily sync. The image has no tooltips or clicks of its own; the link opens the interactive map.

## 8. Host maps for other stations (portals)

Two pages let you add stations without receiving anyone's password and without touching files:

- **`admin/`**, your portal: create invites, see pending ones, list the stations with their status and embed codes, delete a station, and read the event log.
- **`join.php`**, public: the invited station enters its callsign, the code, its grid and its passwords, and the page sets up its map by itself.

### Protect the admin portal

`admin/` must be password-protected by the web server, not by QSO Map: in cPanel open **Directory Privacy**, select the `admin` folder of this installation, tick *Password protect this directory*, give it a name and create a user with a strong password. The browser then asks for that user and password before showing the portal. cPanel stores the password hashed outside the website, and the same steps work on any cPanel host.

As a safety net, the portal refuses to work if the server reports no logged-in user, so it is never left open by mistake. If your host protects the folder but does not pass the user to PHP, the page keeps saying *Protect this folder first*; only then, add `"admin": {"allow_without_login": true}` to `config.json`.

### Invite a station

1. In `admin/`, enter the station's base callsign (`LU9XYZ`, not `LU9XYZ/P`) and press *Create code*. The portal shows a 6-digit code **once**, with a message ready to send.
2. Send that message to the station (e.g. by WhatsApp). The code works only together with that callsign, for 48 hours and one use; after 5 failed attempts (wrong code, or a LoTW login that fails) it is cancelled. Create a new one if needed.
3. The station opens `join.php` and enters its callsign, the code, its grid, its LoTW password and, optionally, its HamQTH account. They must confirm that their map will be public.
   - The **LoTW username must be the callsign**, and the login is checked against LoTW before saving: only the owner of that LoTW account can create the map for that callsign, even if someone else gets hold of the code.
   - **HamQTH** is optional but strongly recommended on the form: LoTW only gives the grid of confirmed QSOs, so with HamQTH lookups many more QSOs show up. Their HamQTH account is used only for their map, as yours is only for yours.
4. The page creates `stations/LU9XYZ/`, then runs the first sync step by step: DXCC list (only if missing), LoTW download, grid lookups and the static map background. Grid lookups run in batches of 40 (`build_cache.php?max_lookups=40&offset=...`), so no request runs long enough to hit the hosting's time limits, and each batch saves its progress. If they close the page they can reopen it within 2 hours to resume; after that the nightly sync finishes the job.
5. At the end the page shows the map's address and the QRZ.com and GitHub embed codes.

Nothing is left for you to do: the daily Cron job (step 5) picks up the new station. Stations created through the portal sync from **LoTW only**; the daily sync logs `Clublog credentials not configured` and uses LoTW, which is expected.

Inviting an existing station lets its owner change the grid or passwords; its QSOs are kept. The code is stored hashed in `data/invites.json`, and the pages refuse plain `http://`.

### Delete a station

In the *Stations* table of `admin/`, *Delete* removes `stations/<CALL>/` with its settings, passwords and QSOs, after a confirmation. Its map URLs stop working right away. A station can also stop its own sync at any time by changing its LoTW password.

### Event log

`data/events.log` records invites (created, cancelled, failed attempts), stations created, updated and deleted, with date and IP, never passwords or codes. The admin portal shows the last 40 lines.

### What the LoTW password gives access to

Tell the station owner what they are entrusting to the server:

- The LoTW **website password** only gives access to the LoTW website: viewing and downloading their QSOs and confirmations, and their account settings. QSO Map only reads.
- It **cannot sign or upload QSOs**. Uploading to LoTW requires their TQSL certificate and its private key, which never leave their computer, so nobody can fake contacts with this password.
- Recommend a password they use only for LoTW. Whoever runs the server can technically read the station's `config.json`, so the portal avoids sending the password to you by mail or chat, but it does not hide it from the server's administrator. To revoke access at any time they change their LoTW password.
- All stations' files are read by the same hosting account. A security flaw in any script, or a leaked hosting login, would expose every station's passwords at once, not just one. That is why each station should use passwords it uses nowhere else.

## Upgrading from version 1 (one station per installation)

Version 2 keeps each station in `stations/<CALL>/`. To upgrade, upload the new files over the old ones, keeping `config.json` and `data/`. The next daily sync (or `scripts/migrate_to_stations.php?token=YOUR_TOKEN`, to do it right away) moves your station once:

- its `map`, `lotw`, `clublog` (with `api_key`) and `hamqth` settings go to `stations/<CALL>/config.json`, and its QSOs, map cache, sync state and background to `stations/<CALL>/data/`;
- the root `config.json` keeps `web` and gets `"default_station": "<CALL>"`, so your existing URLs (`map-ssb.html`, `map-ssb-qrz.php` in QRZ, `map-ssb-github.php`) keep working.

Until it runs, the maps show *No station selected*.

## Verification checklist

- [ ] `config.json` returns 403 in the browser.
- [ ] `stations/LU2MET/config.json` and `data/invites.json` return 403 or 404.
- [ ] `scripts/sync_daily.sh`, `scripts/common.php` and `data/sync.log` return 403.
- [ ] `scripts/sync_clublog.php` without `?token=` returns 403, and `scripts/sync_lotw.php?key=anything` returns 403.
- [ ] `admin/` asks for a user and password.
- [ ] `map-ssb.html?LU2MET`, `map-digi.html?LU2MET` and `map-qsl.html?LU2MET` show markers, and the SSB/DIGI/QSL links keep the callsign.
- [ ] `map-ssb-qrz.php?LU2MET` shows the background, points and paths.
- [ ] `map-ssb-github.php?LU2MET` opens as an image (SVG) with the same background, points and paths.
- [ ] Cron ran and added a `CALL: sync complete` line per station to `data/sync.log`.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| "Unknown station …, or its map is not ready yet" | No `stations/<CALL>/config.json`, or its first sync has not built the map yet: check `stations/<CALL>/data/sync.log`. |
| "No station selected" | The URL has no callsign and `default_station` is not set (or, after upgrading from version 1, the migration has not run yet). |
| "Invalid QTH grid" | `map.my_grid` is missing or malformed in the station's config; fix it and run `build_cache.php` for that station again. |
| `Clublog download failed` in a station's log | Wrong credentials or API key. If LoTW is configured, it is used as a fallback. |
| `Clublog credentials not configured` in a station's log | Expected for LoTW-only stations, e.g. those created through the portal. |
| The QRZ or GitHub map has no background | The station's `qrz-background.png` is missing: run `make_background.php` for it. |
| `config.json` downloads in the browser | The host ignores `.htaccess` (`AllowOverride`) or it was not uploaded. Ask your provider before going further. |
| Some QSOs are missing | They have no grid and none was found on HamQTH or Spothole, or their grid does not match their DXCC entity (listed in the station's `sync.log`, see *Grid check*). They are retried on every sync. |
| `php: command not found` in the log | Set `PHP_BIN` in the Cron command (see step 5). |
| `is not a PHP CLI binary` in the log | Cron is using the CGI build of PHP. Set `PHP_BIN` to a CLI build (see *Choosing the PHP binary* in step 5). |
| `admin/` says *Protect this folder first* | Set up Directory Privacy for it (step 8). |
| `join.php` says the code does not match | Wrong callsign or code, expired (48 hours), already used, or cancelled after 5 failed attempts: create a new invite. |
| A run ends with `CALL: sync FAILED` | Look at that station's `stations/<CALL>/data/sync.log`: the sync (Clublog and the LoTW fallback) or the cache rebuild failed. |

To force a full download of a station (e.g. after fixing old grids), delete its `clublog_last_sync.txt` (Clublog) or `lotw_last_sync.json` (LoTW) in `stations/<CALL>/data/` and run the sync again (browser or Cron).

## Publishing a new version (maintainer)

Installed maps learn about a release from its git tag, so a push to `main` alone does not show the banner:

1. Update `VERSION` (e.g. `1.1.0`) and commit it with the changes.
2. Tag that commit and push the tag:

   ```bash
   git tag v1.1.0
   git push origin main v1.1.0
   ```

3. Optional: create a GitHub release from the tag with notes on what changed; the banner links to `releases/tag/v1.1.0`, which shows the notes when the release exists.
