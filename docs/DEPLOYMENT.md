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
| Interactive maps | `https://your-domain.com/qsomap/map-ssb.html?LU9XYZ` (also `map-cw.html`, `map-rtty.html`, `map-ft.html` for FT8/FT4, and `map-qsl.html` for confirmed QSOs in any mode) |
| Static map for QRZ.com | `https://your-domain.com/qsomap/map-qrz.php?LU9XYZ` |
| Image for a GitHub README | `https://your-domain.com/qsomap/map-github.php?LU9XYZ` |
| Club ranking (all stations) | `https://your-domain.com/qsomap/ranking.php?mode=cw` (see step 9) |

Until version 2.0 the static maps were `map-ssb-qrz.php` and `map-ssb-github.php`; those URLs still work and show the same maps.

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
- **storage:** optional, recommended once everything works: keeps the station folders out of the web folder and encrypts the stored passwords. See [Protecting stored passwords](#protecting-stored-passwords).

**Your own station.** Either invite yourself through the portal (step 8; LoTW only), or create `stations/YOUR_CALLSIGN/config.json` by hand from `docs/station-config.example.json`, which also allows Clublog:

```json
{
  "map":     { "my_callsign": "LU2MET", "my_grid": "FF57oc" },
  "lotw":    { "username": "LU2MET", "password": "..." },
  "clublog": { "email": "...", "password": "...", "api_key": "...", "callsign": "LU2MET" },
  "hamqth":  { "username": "...", "password": "..." }
}
```

- **map:** the station's callsign (as the folder name, upper case, no `/P`) and grid (4, 6 or 8 characters).
- **Clublog:** `password` is an *application password* created in your Clublog settings, not your account password. `api_key` can be requested at [clublog.org/need_api.php](https://clublog.org/need_api.php).
- **LoTW:** the lotw.arrl.org login; no API key needed. Used when Clublog fails, or as the only source if there is no `clublog` section, and always for the club ranking (step 9). The first download brings the **whole log** in parts (see *Big logs* below): with many thousands of QSOs it can take from minutes to an hour, plus the grid lookups; later runs are incremental. Before version 2.2.0 the first download was limited by `days_back` (5 years by default); that setting is now ignored, and each station downloads its whole log once more on its next LoTW sync.
- **HamQTH:** optional but recommended. Without it, missing grids are looked up on Spothole only.

To check that the configs are not reachable, open `https://your-domain.com/qsomap/config.json` (must return **403**) and `https://your-domain.com/qsomap/stations/LU2MET/config.json` (must return **403** or **404**).

### Using LoTW only

A station without a `clublog` section syncs from LoTW: the daily sync tries Clublog, logs that it is not configured, and falls back to LoTW. Keep in mind:

- Only QSOs uploaded to LoTW (signed with TQSL) are downloaded.
- LoTW includes the other station's grid only for confirmed QSOs; unconfirmed ones get their grid from HamQTH/Spothole (the station's registered home grid, which may differ from where it operated).

Following ARRL's guidance for automated downloads, only the first LoTW sync downloads everything (the whole log, since version 2.2.0). Later runs ask only for QSOs uploaded since the last run (`qso_qsorxsince`) and for new confirmations (`qso_qslsince`, which also catches old QSOs confirmed recently), using the timestamps LoTW returns. They are stored in `stations/<CALL>/data/lotw_last_sync.json`.

### Big logs

LoTW builds its reports slowly (about 17 KB/s has been seen), so a log of tens of thousands of QSOs cannot come in a single request. Since version 2.4.0:

- **The first LoTW download goes in parts by QSO date.** Confirmations come first, for the whole log: they are what the ranking counts and a much smaller report, so the ranking is complete long before the map. Then come the uploads (every QSO, confirmed or not), which only the map uses. Each part is sized from the previous one so LoTW takes about 2 minutes for it, and halved when LoTW stops sending data for 90 s or does not finish in 15 minutes. The progress is saved after every part (`full_progress` in `lotw_last_sync.json`), so an interrupted download resumes where it stopped.
- **From a browser** each run stops starting new parts after 3 minutes and ends with `Run again to continue from <date> (confirmations|uploads)`: open the same URL again until it prints `Total: N QSOs`. A run keeps going on the server when the page is closed, so a new one meanwhile answers `another LoTW sync of this station is running (now at … of QSOs from <date>)` with how far it got. `join.php` repeats the runs by itself, and from the terminal (Cron) the whole download runs in one go.
- **HTTP 503 from LoTW** usually means it is still building a report given up earlier and refuses another one for the same account: from Cron the sync waits a minute and tries again, up to five times; from a browser it stops and asks to try again in a few minutes. The progress is kept.
- **Maps of more than 10,000 QSOs are grouped by grid square.** `build_cache.php` then writes one entry per 4-character grid square, band and mode class (SSB, CW, RTTY, FT or other) with its QSO count, confirmed count, DXCC entities and latest 8 callsigns, instead of one entry per QSO: a log of 70,000 QSOs gives a map file of about 2 MB. The interactive maps draw one point per square, sized by its QSOs and colored by its band with most QSOs, and say why; the QRZ and GitHub maps draw the same squares.
- **The log is kept by year** (since version 2.5.0): `qsos_<YEAR>.json` and, for the ranking, `lotw_confirmed_<YEAR>.json`. The sync scripts merge each batch into the years it touches, and `build_cache.php` and the ranking read one year at a time, so the memory they need depends on the busiest year, not on the size of the whole log. Clublog's ADIF is saved to a temporary file and read record by record, like LoTW's reports.
- **Memory.** The scripts still raise PHP's memory limit to 512M when they can (never lowering a higher one), and print their peak use at the end (`Memory: … peak, limit …`). A busy year of 40,000 QSOs takes about 55 MB to load; what remains is `build_cache.php`'s per-callsign grids and grid-square groups, which grow with the number of different callsigns and grid squares, not with the QSOs. In a test with 334,000 QSOs (40,000 a year) the sync stayed at about 120 MB and `build_cache.php` at about 300 MB. If the host forbids raising the limit and a script runs out of memory, set `memory_limit` to 256M or more in cPanel's *MultiPHP INI Editor*.
- **Map data is sent gzipped** when the browser accepts it: the map file of a big log shrinks to about a fifth.

### Protecting stored passwords

LoTW, Clublog and HamQTH have no tokens: the sync has to send them the plain password every night, so the server must be able to read it. Two settings in the root `config.json` reduce what can leak:

```json
{
  "web": { "token": "..." },
  "default_station": "LU2MET",
  "storage": {
    "stations_dir": "/home/USER/qsomap-private/qsomap/stations",
    "key_file": "/home/USER/qsomap-private/qsomap.key"
  }
}
```

- **stations_dir** moves the station folders out of `public_html`, so no web request can ever reach them, even if an `.htaccess` file is lost or ignored. Absolute path, or relative to the QSO Map folder.
- **key_file** turns on encryption: passwords and the Clublog API key are stored as `enc:v1:...` (AES-256-GCM with PHP's OpenSSL extension, present on practically every host), and the scripts decrypt them when they run. The key file must be outside `public_html`; QSO Map refuses to create it inside.

To set it up with the cPanel File Manager (no terminal needed):

1. Create a folder next to `public_html`, not inside it, e.g. `/home/USER/qsomap-private/qsomap/`. Your home path (`/home/USER`) is shown in the File Manager's sidebar.
2. Move the `stations` folder of the installation into it, and right away add `storage.stations_dir` to `config.json`. Do it outside the Cron time; maps show *Unknown station* in between.
3. Add `storage.key_file` and open `https://your-domain.com/qsomap/scripts/encrypt_secrets.php?token=YOUR_TOKEN`. It creates the key (permissions 600) and encrypts the passwords of every station.
4. **Download a copy of the key file** and keep it somewhere safe, e.g. in your password manager. Without it the stored passwords cannot be read, and every station must be invited again.
5. Open `admin/`: the *Storage* warning must be gone. Check a map and the next night's `data/sync.log`.

From then on `join.php` stores new passwords encrypted, and the daily sync encrypts any password you type into a `config.json` by hand. If the key file is missing while configs hold encrypted values, the sync stops with an error instead of creating a new key: restore your copy. Each installation (e.g. `qsomap2` and `qsomap3`) needs its own `stations_dir`; they may share the key file or use one each.

What this protects, and what it does not:

| Leak | Protected? |
| --- | --- |
| An `.htaccess` lost or ignored, a config downloaded from the web | Yes (not reachable, and encrypted) |
| A config committed to GitHub or sent by mistake | Yes, as long as the key file is not with it |
| A zip or backup of `public_html` | Yes, neither the stations nor the key are in it |
| A full backup of the hosting account | No, it holds the key too: treat it as confidential |
| Someone with your cPanel login, or a flaw that runs code on the account | No: they can read the key just like the sync. Turn on two-factor authentication in cPanel (*Security → Two-Factor Authentication*). |

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

The first sync downloads the whole log and may take several minutes if many QSOs have no grid; a big LoTW log goes in parts and, from a browser, needs the URL opened several times (see *Big logs* in step 2). For grid lookups in short requests, add `&max_lookups=200` to the `build_cache.php` URL and repeat it with the `&offset=N` that each run prints as `Next offset: N`. Later syncs, from Clublog or LoTW, are incremental.

If the PHP found by Cron is not the right binary, set its path with `PHP_BIN`:

```bash
PHP_BIN=/usr/local/bin/php bash scripts/sync_daily.sh LU2MET
```

## 4. Generate the static map background

`map-qrz.php` uses a 600 × 600 px background with the station's QTH in the center. The daily sync creates it when it is missing and recreates it when the station's `config.json` changes (e.g. a new `map.my_grid`). To create it right away:

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
   2. If Clublog fails or is not configured, syncs from LoTW instead (also incremental) and merges the result. If Clublog works and the club ranking is on, downloads only the station's new LoTW confirmations, for the ranking (a station without LoTW credentials is skipped and stays out of it).
   3. Rebuilds the station's `qso_cache.json`, dropping grids that do not match the QSO's DXCC entity (see *Grid check* below). A QSO without a grid takes the one logged for the same callsign in another QSO; otherwise the callsign is looked up on HamQTH and Spothole once, and the result is kept in `grid_lookups.json` (a callsign not found is tried again after 30 days).
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
- If it does not match, it is dropped and the grid logged for that callsign in another QSO, or the callsign's lookup on HamQTH and Spothole, is used instead; it goes through the same check. A matching grid from the lookup puts the QSO in the right place.
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
<iframe align="top" frameborder="0" height="600" scrolling="no" src="https://your-domain.com/qsomap/map-qrz.php?LU2MET" width="600"></iframe>
```

Stations created through the portal get this code at the end of their setup, and the admin portal shows it for every station. QRZ may cache the iframe: if it still shows an old version after a change, add or bump a `v` value (e.g. `?LU2MET&v=2`). The PHP already sends no-cache headers and adds a unique parameter to the background URL. Clicking the map opens the interactive view of the same mode in a new tab (e.g. `map-cw.html?LU2MET` for a CW static map).

The static map shows one view: SSB (phone: SSB, AM, FM) by default, CW, RTTY, FT8/FT4, or QSOs confirmed in LoTW in any mode. The box in the corner names it, with the number of locations. Stations created through the portal choose it on the form; you can change it for any station in the *Stations* table of `admin/` (column *Static map*), or by hand with `map.static_mode` (`ssb`, `cw`, `rtty`, `ft` or `qsl`) in its `config.json`. The embed codes do not change.

## 7. Show the map in a GitHub README

GitHub strips iframes from READMEs, so use `map-github.php` instead: it draws the same map as a self-contained SVG image (background included) and links it to `map.php`, which opens the interactive view of the station's static map mode:

```markdown
[![LU2MET QSO map](https://your-domain.com/qsomap/map-github.php?LU2MET)](https://your-domain.com/qsomap/map.php?LU2MET)
```

GitHub serves remote images through its own cache (camo). The PHP sends `Cache-Control: no-cache` and an `ETag` that changes whenever the station's map cache, background or settings change, so the README picks up each daily sync and a new static map mode. The image has no tooltips or clicks of its own; the link opens the interactive map.

## 8. Host maps for other stations (portals)

Two pages let you add stations without receiving anyone's password and without touching files:

- **`admin/`**, your portal: create invites, see pending ones, list the stations with their status and embed codes, turn the club ranking on and leave stations out of it (step 9), delete a station, and read the event log.
- **`join.php`**, public: the invited station enters its callsign, the code, its grid and its passwords, and the page sets up its map by itself.

### Protect the admin portal

`admin/` must be password-protected by the web server, not by QSO Map: in cPanel open **Directory Privacy**, select the `admin` folder of this installation, tick *Password protect this directory*, give it a name and create a user with a strong password. The browser then asks for that user and password before showing the portal. cPanel stores the password hashed outside the website, and the same steps work on any cPanel host.

As a safety net, the portal refuses to work if the server reports no logged-in user, so it is never left open by mistake. If your host protects the folder but does not pass the user to PHP, the page keeps saying *Protect this folder first*; only then, add `"admin": {"allow_without_login": true}` to `config.json`.

### Invite a station

1. In `admin/`, enter the station's base callsign (`LU9XYZ`, not `LU9XYZ/P`) and press *Create code*. The portal shows a 6-digit code **once**, with a message ready to send.
2. Send that message to the station (e.g. by WhatsApp). The code works only together with that callsign, for 48 hours and one use; after 5 failed attempts (wrong code, or a LoTW login that fails) it is cancelled. Create a new one if needed.
3. The station opens `join.php` and enters its callsign, the code, its grid, which QSOs its static map shows (SSB by default; CW, RTTY, FT8/FT4 or confirmed in LoTW), its LoTW password and, optionally, its HamQTH account. They must confirm that their map will be public. The page is in English, with an **ES** button in the top corner for Spanish (or send the link as `join.php?lang=es`); the choice is remembered for the rest of the setup.
   - The **LoTW username must be the callsign**, and the login is checked against LoTW before saving: only the owner of that LoTW account can create the map for that callsign, even if someone else gets hold of the code.
   - **HamQTH** is optional but strongly recommended on the form: LoTW only gives the grid of confirmed QSOs, so with HamQTH lookups many more QSOs show up. Their HamQTH account is used only for their map, as yours is only for yours.
4. The page creates `stations/LU9XYZ/`, then runs the first sync step by step: DXCC list (only if missing), LoTW download of the whole log (the form warns that with many QSOs this takes many minutes; a big log goes in parts, which the page requests one after the other, see *Big logs* in step 2), grid lookups and the static map background. Grid lookups run in batches of 40 (`build_cache.php?max_lookups=40&offset=...`), so no request runs long enough to hit the hosting's time limits, and each batch saves its progress. If they close the page they can reopen it within 2 hours to resume; after that the nightly sync finishes the job.
5. At the end the page shows the map's address and the QRZ.com and GitHub embed codes.

Nothing is left for you to do: the daily Cron job (step 5) picks up the new station. Stations created through the portal sync from **LoTW only**; the daily sync logs `Clublog credentials not configured` and uses LoTW, which is expected.

Inviting an existing station lets its owner change the grid, passwords or static map mode; its QSOs are kept. The code is stored hashed in `data/invites.json`, and the pages refuse plain `http://`.

### Delete a station

In the *Stations* table of `admin/`, *Delete* removes `stations/<CALL>/` with its settings, passwords and QSOs, after a confirmation. Its map URLs stop working right away. A station can also stop its own sync at any time by changing its LoTW password.

### Event log

`data/events.log` records invites (created, cancelled, failed attempts), stations created, updated and deleted, with date and IP, never passwords or codes. The admin portal shows the last 40 lines.

### What the LoTW password gives access to

Tell the station owner what they are entrusting to the server:

- The LoTW **website password** only gives access to the LoTW website: viewing and downloading their QSOs and confirmations, and their account settings. QSO Map only reads.
- It **cannot sign or upload QSOs**. Uploading to LoTW requires their TQSL certificate and its private key, which never leave their computer, so nobody can fake contacts with this password.
- Recommend a password they use only for LoTW. Whoever runs the server can technically read it, even when encrypted (the key is on the same server), so the portal avoids sending the password to you by mail or chat, but it does not hide it from the server's administrator. To revoke access at any time they change their LoTW password.
- All stations' files are read by the same hosting account. A security flaw in any script, or a leaked hosting login, would expose every station's passwords at once, not just one. That is why each station should use passwords it uses nowhere else.

## 9. Club ranking

`ranking.php` ranks the stations by DXCC entities confirmed in LoTW, one plain table per mode, to embed with an iframe on QRZ.com, a blog or the club's website.

- **Turn it on** in the *Club ranking* section of `admin/`: tick the box, optionally set a title (e.g. the club's name) and the default language, and save. While it is off, `ranking.php` answers 404 and the nightly sync downloads nothing extra.
- **Modes:** by default (`mode=all`) one table with each station's entities confirmed in **any mode**, each entity counted once as in DXCC Mixed (PSK and other modes included). `mode=phone|cw|rtty|ft8` shows one mode: Phone (SSB, AM, FM), CW, RTTY or FT8/FT4. `mode=each` shows one table per mode on one page. Two menus at the top choose the mode (all modes, each mode separately, or one) and the band (all, or one; also `&band=20M`), in any combination. They only offer the modes and bands some station has confirmed entities in: if nobody has RTTY, there is no RTTY option or table (an embed that asks for `mode=rtty` still shows its empty table). `&lang=en|es` overrides the default language.
- **What counts:** every QSO confirmed in LoTW, whatever its grid (QSOs without a grid, or with one dropped by the grid check, are not on the map but do count). Nothing comes from Clublog, not even for stations synced from it. A station's position counts current DXCC entities only; confirmed **deleted** entities are shown next to the total as `(+3*)`, with a footnote. Equal totals share the position (1, 1, 3), and stations with no entity in that mode or band are not listed.
- **Links:** each callsign opens that station's interactive map of the mode (`map-ssb.html`, `map-cw.html`, `map-rtty.html` or `map-ft.html`; `map-qsl.html`, its confirmed QSOs, from the all-modes table) in a new tab.
- **Leaving a station out:** in the *Stations* table of `admin/`, the *Ranking* column shows its totals and a *Leave out* button (it sets `ranking.opt_out` in its `config.json`; *Show in ranking* undoes it). It takes effect right away.
- **Embed codes** for all modes, each mode, and each mode separately are in the *Club ranking* section once it is on, e.g.:

  ```html
  <iframe src="https://your-domain.com/qsomap/ranking.php?mode=cw&lang=es" width="480" height="700" frameborder="0"></iframe>
  ```

**Data.** `sync_lotw.php` keeps each station's LoTW confirmations in `stations/<CALL>/data/lotw_confirmed_<YEAR>.json` and writes its totals by mode and band to `dxcc_counts.json`; `ranking.php` only reads those totals. Stations synced from LoTW get them on every sync; stations synced from Clublog get them from a confirmations-only LoTW download (`sync_lotw.php --confirmations-only`) that `sync_daily.sh` runs while the ranking is on. The settings are in `data/ranking_settings.json`. The list of deleted entities comes from the ADIF specification and is part of `scripts/ranking_data.php`.

## Upgrading to 2.5.0 (log by year)

Version 2.5.0 keeps each station's log in one file per year instead of a single `qso_data.json`, and the ranking's confirmations likewise instead of `lotw_confirmed.json`. There is no conversion: upload the new files, and on its next sync (the nightly Cron, or the sync URLs) each station finds no year files and downloads its whole log again, from Clublog or LoTW (see *Big logs* in step 2). Meanwhile:

- the map keeps showing the current `qso_cache.json`: `build_cache.php` does not replace it while a LoTW download is under way and has fewer QSOs than the map;
- the ranking keeps the current totals until the new ones are at least as high, or all confirmations are downloaded;
- grids already looked up are kept in `grid_lookups.json`, so they are not looked up again.

A big LoTW log takes hours to download again (from Cron it runs in one go). When a station's sync has finished, its old `qso_data.json` and `lotw_confirmed.json` are no longer read and can be deleted.

## Upgrading from version 1 (one station per installation)

Version 2 keeps each station in `stations/<CALL>/`. To upgrade, upload the new files over the old ones, keeping `config.json` and `data/`. The next daily sync (or `scripts/migrate_to_stations.php?token=YOUR_TOKEN`, to do it right away) moves your station once:

- its `map`, `lotw`, `clublog` (with `api_key`) and `hamqth` settings go to `stations/<CALL>/config.json`, and its QSOs, map cache, sync state and background to `stations/<CALL>/data/`;
- the root `config.json` keeps `web` and gets `"default_station": "<CALL>"`, so your existing URLs (`map-ssb.html`, `map-ssb-qrz.php` in QRZ, `map-ssb-github.php`) keep working; since 2.1 the static maps are also at `map-qrz.php` and `map-github.php`.

Until it runs, the maps show *No station selected*.

## Verification checklist

- [ ] `config.json` returns 403 in the browser.
- [ ] `stations/LU2MET/config.json` and `data/invites.json` return 403 or 404.
- [ ] `scripts/sync_daily.sh`, `scripts/common.php` and `data/sync.log` return 403.
- [ ] `scripts/sync_clublog.php` without `?token=` returns 403, and `scripts/sync_lotw.php?key=anything` returns 403.
- [ ] `admin/` asks for a user and password, and shows no *Storage* warning (see [Protecting stored passwords](#protecting-stored-passwords)).
- [ ] With `key_file` set, the stations' `config.json` show `enc:v1:` instead of passwords, and you have a copy of the key file.
- [ ] `map-ssb.html?LU2MET`, `map-cw.html`, `map-rtty.html`, `map-ft.html` and `map-qsl.html` (each with `?LU2MET`) show markers, the box shows `LU2MET SSB` (and so on), and the mode links keep the callsign.
- [ ] `map-qrz.php?LU2MET` shows the background, points and paths, and its box names the station's static map mode.
- [ ] Changing that mode in `admin/` changes the points and the box of `map-qrz.php?LU2MET`; the old `map-ssb-qrz.php?LU2MET` shows the same.
- [ ] `map-github.php?LU2MET` opens as an image (SVG) with the same background, points and paths.
- [ ] Cron ran and added a `CALL: sync complete` line per station to `data/sync.log`.
- [ ] With the ranking on, `ranking.php?mode=cw` lists the stations with CW entities, the band menu changes the table, and a callsign opens `map-cw.html?CALL`; a station left out in `admin/` disappears from it. With the ranking off it returns 404.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| "Unknown station …, or its map is not ready yet" | No `stations/<CALL>/config.json`, or its first sync has not built the map yet: check `stations/<CALL>/data/sync.log`. |
| "No station selected" | The URL has no callsign and `default_station` is not set (or, after upgrading from version 1, the migration has not run yet). |
| "Invalid QTH grid" | `map.my_grid` is missing or malformed in the station's config; fix it and run `build_cache.php` for that station again. |
| `Clublog download failed` in a station's log | Wrong credentials or API key. If LoTW is configured, it is used as a fallback. |
| `Clublog credentials not configured` in a station's log | Expected for LoTW-only stations, e.g. those created through the portal. |
| The QRZ or GitHub map has no background | The station's `qrz-background.png` is missing. The next daily sync creates it; to do it now, run `make_background.php` for that station (step 4). If it fails with *no GD extension*, enable GD for the web PHP version too. |
| `config.json` downloads in the browser | The host ignores `.htaccess` (`AllowOverride`) or it was not uploaded. Ask your provider before going further. |
| Some QSOs are missing | They have no grid and none was found on HamQTH or Spothole, or their grid does not match their DXCC entity (listed in the station's `sync.log`, see *Grid check*). Callsigns not found are looked up again after 30 days; delete `grid_lookups.json` to retry them all on the next sync. |
| The map says the log is grouped by grid square | Expected for logs of more than 10,000 QSOs (see *Big logs* in step 2). |
| `Run again to continue from …` | A big LoTW log downloading in parts from a browser: open the same URL again (see *Big logs* in step 2). |
| `another LoTW sync of this station is running` | A previous run is still working on the server, even if its page was closed. Wait: the message says how far it got. |
| `LoTW is busy (HTTP 503 …)` | LoTW is still building an earlier report: try again in a few minutes. The progress is kept. |
| `Allowed memory size … exhausted` | The host does not let the scripts raise PHP's memory limit: set `memory_limit` to 256M or more in cPanel's *MultiPHP INI Editor* (see *Big logs* in step 2). |
| `LoTW download in progress (…): keeping the current map` | Expected while a station downloads its LoTW log again (e.g. after upgrading to 2.5.0): the map is rebuilt when the download ends. |
| `php: command not found` in the log | Set `PHP_BIN` in the Cron command (see step 5). |
| `is not a PHP CLI binary` in the log | Cron is using the CGI build of PHP. Set `PHP_BIN` to a CLI build (see *Choosing the PHP binary* in step 5). |
| `admin/` says *Protect this folder first* | Set up Directory Privacy for it (step 8). |
| `join.php` says the code does not match | Wrong callsign or code, expired (48 hours), already used, or cancelled after 5 failed attempts: create a new invite. |
| A station is missing from the ranking | It was left out in `admin/`, has no LoTW credentials, has no confirmed entity in that mode or band, or its LoTW confirmations were not downloaded yet: the *Ranking* column of `admin/` shows its totals or *no LoTW confirmations yet*. Stations synced from Clublog get them in the first nightly sync after the ranking is turned on. |
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
