<?php
/**
 * Shared helpers for the sync scripts, the maps and the portals.
 *
 * One installation serves several stations. The root config.json holds only
 * the manager's settings (web.token, default_station); each station has its
 * own folder, credentials included, and never sees another station's:
 *   stations/<CALL>/config.json   map, lotw and optional hamqth and clublog
 *   stations/<CALL>/data/         its QSOs, map cache, background and sync log
 * data/ keeps what all stations share (DXCC lists, update check, invites,
 * event log). Nothing under stations/ is reachable from the web.
 *
 * The root config.json can move the station folders out of the web folder
 * (storage.stations_dir) and turn on encryption of the stored passwords
 * (storage.key_file, a key kept outside the web folder; see secretKey()).
 */

// Write floats in JSON as their shortest form (144.42, not 144.41999999999998...):
// some hosts set serialize_precision to 17 or more, which bloats the map caches.
ini_set('serialize_precision', '-1');

define('QSOMAP_ROOT', dirname(__DIR__));
define('QSOMAP_DATA', QSOMAP_ROOT . '/data');
define('QSOMAP_STATIONS', storagePath('stations_dir') ?? QSOMAP_ROOT . '/stations');

/** Credentials in a station's config.json, encrypted when storage.key_file is set. */
const SECRET_FIELDS = [['lotw', 'password'], ['clublog', 'password'], ['clublog', 'api_key'], ['hamqth', 'password']];
const SECRET_PREFIX = 'enc:v1:';  // then base64 of IV (12 bytes), GCM tag (16 bytes) and ciphertext
const SECRET_CIPHER = 'aes-256-gcm';

/**
 * Views of the map, as in js/map.js: name, interactive page and the modes a
 * QSO matches on MODE or SUBMODE (null: confirmed QSOs in any mode). Each
 * station's static map (QRZ.com, GitHub) shows one of them, map.static_mode
 * in its config.json, SSB by default.
 */
const MAP_VIEWS = [
    'ssb' => ['name' => 'SSB', 'page' => 'map-ssb.html', 'modes' => ['SSB', 'USB', 'LSB', 'FM', 'AM']],
    'cw' => ['name' => 'CW', 'page' => 'map-cw.html', 'modes' => ['CW']],
    'rtty' => ['name' => 'RTTY', 'page' => 'map-rtty.html', 'modes' => ['RTTY']],
    'ft' => ['name' => 'FT8/FT4', 'page' => 'map-ft.html', 'modes' => ['FT8', 'FT4']],
    'qsl' => ['name' => 'QSL LoTW', 'page' => 'map-qsl.html', 'modes' => null],
];
const DEFAULT_STATIC_VIEW = 'ssb';

/** Invites from the admin portal and setups in progress, keyed by callsign. */
const INVITES_FILE = QSOMAP_DATA . '/invites.json';

/**
 * Sync scripts handle credentials and call external APIs. They always run from
 * the command line (Cron, terminal); from a browser they require
 * ?token=<web.token from config.json>, and are disabled if no token is set.
 * The first-sync scripts also accept ?key=<setup key> while join.php is setting
 * up a station; the callsign of that station is returned, null otherwise.
 */
function requireCliOrToken(bool $allowSetupKey = false): ?string {
    if (PHP_SAPI === 'cli') return null;
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if ($allowSetupKey) {
        $call = inviteByKey((string)($_GET['key'] ?? ''), 'sync');
        if ($call !== null) return $call;
    }
    $expected = (string)(rootConfig()['web']['token'] ?? '');
    $given = (string)($_GET['token'] ?? '');
    if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit(1); // non-zero so sync_daily.sh notices if Cron runs a CGI build of PHP
    }
    return null;
}

/** Report an error and exit non-zero so sync_daily.sh can react. */
function fail(string $message): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: $message\n");
    } else {
        http_response_code(500);
        echo "Error: $message\n";
    }
    exit(1);
}

/** The root config.json (manager settings), or [] if missing. */
function rootConfig(): array {
    return readJson(QSOMAP_ROOT . '/config.json') ?? [];
}

/** storage.<$name> from the root config.json as an absolute path, or null if unset. */
function storagePath(string $name): ?string {
    $path = trim((string)(rootConfig()['storage'][$name] ?? ''));
    if ($path === '') return null;
    return rtrim($path[0] === '/' ? $path : QSOMAP_ROOT . '/' . $path, '/');
}

/** Whether $path is inside this installation or the site's web folder (public_html). */
function insideWebFolder(string $path): bool {
    $path = rtrim($path, '/') . '/';
    foreach ([QSOMAP_ROOT, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $web) {
        $web = $web !== '' ? realpath($web) : false;
        if ($web !== false && strpos($path, rtrim($web, '/') . '/') === 0) return true;
    }
    return preg_match('#/(public_html|www|htdocs)/#', $path) === 1;
}

/**
 * A base callsign in upper case (e.g. LU2MET), or null. Station folders are
 * named after it, so portable prefixes and suffixes (/P, EA8/) are rejected.
 */
function normalizeCall(string $call): ?string {
    $call = strtoupper(trim($call));
    return preg_match('/^(?=.*\d)(?=.*[A-Z])[A-Z0-9]{3,10}$/', $call) ? $call : null;
}

function stationExists(string $call): bool {
    return is_file(QSOMAP_STATIONS . "/$call/config.json");
}

/** Callsigns of all set-up stations, sorted. */
function stationList(): array {
    $calls = [];
    foreach (glob(QSOMAP_STATIONS . '/*/config.json') ?: [] as $file) {
        $call = normalizeCall(basename(dirname($file)));
        if ($call !== null) $calls[] = $call;
    }
    sort($calls);
    return $calls;
}

/** Get or set the station the current script works on. */
function station(?string $set = null): ?string {
    static $current = null;
    if ($set !== null) $current = $set;
    return $current;
}

/**
 * The station a request or command asks for: ?station=CALL, or a bare ?CALL
 * as in map-ssb.html?LU2MET, or QSOMAP_STATION in the environment (Cron);
 * otherwise default_station from the root config.json. Null if that station
 * does not exist.
 */
function requestedStation(): ?string {
    $candidates = [$_GET['station'] ?? null];
    foreach ($_GET as $name => $value) {
        if ($value === '') $candidates[] = $name;
    }
    $candidates[] = getenv('QSOMAP_STATION') ?: null;
    foreach ($candidates as $candidate) {
        $call = normalizeCall((string)$candidate);
        if ($call !== null) return stationExists($call) ? $call : null;
    }
    $default = normalizeCall((string)(rootConfig()['default_station'] ?? ''));
    return $default !== null && stationExists($default) ? $default : null;
}

/**
 * Select the station for a station script: the one a setup key belongs to,
 * or the requested one. Fails if there is none.
 */
function useStation(?string $setupStation): string {
    $call = $setupStation ?? requestedStation();
    if ($call === null) {
        fail('unknown station: pass ?station=CALL (browser) or QSOMAP_STATION=CALL (terminal), or set default_station');
    }
    station($call);
    return $call;
}

function stationDir(?string $call = null): string {
    $call = $call ?? station();
    if ($call === null) fail('no station selected');
    return QSOMAP_STATIONS . '/' . $call;
}

/**
 * Select the station a public page shows (?CALL, or default_station), or
 * answer 404 if there is none.
 */
function usePublicStation(): string {
    $call = requestedStation();
    if ($call === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unknown station\n";
        exit;
    }
    station($call);
    return $call;
}

/**
 * Send a file from the current station's data folder (cache.php,
 * background.php), revalidated on every request and answered with 304 while
 * it has not changed.
 */
function serveStationFile(string $file, string $contentType): void {
    $path = stationData($file);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not generated yet\n";
        exit;
    }
    // JSON is sent gzipped when the browser accepts it: a big log's map data
    // shrinks to about a fifth. Each encoding has its own ETag.
    $gzip = strpos($contentType, 'json') !== false && function_exists('gzencode') && !ini_get('zlib.output_compression')
        && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false;
    $etag = '"' . md5(station() . '|' . filemtime($path) . '|' . filesize($path)) . ($gzip ? '-gz' : '') . '"';
    header('Cache-Control: public, no-cache, max-age=0');
    header('ETag: ' . $etag);
    if (strpos($contentType, 'json') !== false) header('Vary: Accept-Encoding');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $contentType);
    if ($gzip) {
        header('Content-Encoding: gzip');
        echo gzencode((string)file_get_contents($path), 6);
    } else {
        readfile($path);
    }
    exit;
}

/** Path of a file in the current station's data folder. */
function stationData(string $file): string {
    return stationDir() . '/data/' . $file;
}

/**
 * Configuration of the current station: its own config.json, plus web from the
 * root one. Nothing else is inherited, so the manager's Clublog and HamQTH
 * accounts are only used for the manager's own station.
 */
function loadConfig(bool $required = true): array {
    $root = rootConfig();
    $config = station() !== null ? readJson(stationDir() . '/config.json') : null;
    if ($config === null) {
        if ($required) fail('cannot load the station config.json');
        return ['web' => $root['web'] ?? []];
    }
    $config['web'] = $root['web'] ?? [];
    return decryptSecrets($config);
}

/** Write a station's config.json, encrypting its passwords when a key is set. */
function writeStationConfig(string $call, array $config): void {
    writeJson(QSOMAP_STATIONS . "/$call/config.json", encryptSecrets($config));
}

/**
 * The 32-byte key from storage.key_file, or null if encryption is off. The
 * file holds the key in base64 and must live outside the web folder: it only
 * protects the passwords if a copy of the configs (a backup, a commit, an
 * .htaccess that failed) does not include it. scripts/encrypt_secrets.php
 * creates it.
 */
function secretKey(): ?string {
    static $key = null;
    if ($key !== null) return $key;
    $file = storagePath('key_file');
    if ($file === null) return null;
    if (!function_exists('openssl_encrypt')) fail('storage.key_file is set but PHP has no OpenSSL extension');
    $raw = is_readable($file) ? base64_decode(trim((string)file_get_contents($file)), true) : false;
    if ($raw === false || strlen($raw) !== 32) {
        fail("cannot read the key in storage.key_file ($file): run scripts/encrypt_secrets.php to create it");
    }
    return $key = $raw;
}

function isEncrypted($value): bool {
    return is_string($value) && strncmp($value, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0;
}

/** Encrypt the plain-text passwords of a station config (no change without a key). */
function encryptSecrets(array $config): array {
    $key = secretKey();
    if ($key === null) return $config;
    foreach (SECRET_FIELDS as [$section, $field]) {
        $value = $config[$section][$field] ?? '';
        if (!is_string($value) || $value === '' || isEncrypted($value)) continue;
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($value, SECRET_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) fail("cannot encrypt $section.$field");
        $config[$section][$field] = SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
    }
    return $config;
}

/** Decrypt the passwords of a station config; plain-text ones are kept as they are. */
function decryptSecrets(array $config): array {
    foreach (SECRET_FIELDS as [$section, $field]) {
        $value = $config[$section][$field] ?? '';
        if (!isEncrypted($value)) continue;
        $key = secretKey();
        if ($key === null) fail("$section.$field is encrypted but storage.key_file is not set in config.json");
        $data = base64_decode(substr($value, strlen(SECRET_PREFIX)), true) ?: '';
        $plain = strlen($data) > 28
            ? openssl_decrypt(substr($data, 28), SECRET_CIPHER, $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16))
            : false;
        if ($plain === false) fail("cannot decrypt $section.$field: storage.key_file is not the key it was encrypted with");
        $config[$section][$field] = $plain;
    }
    return $config;
}

/** Append a line to data/events.log (never with secrets). */
function logEvent(string $event): void {
    if (!is_dir(QSOMAP_DATA)) mkdir(QSOMAP_DATA, 0755, true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    file_put_contents(QSOMAP_DATA . '/events.log', gmdate('Y-m-d H:i:s') . " UTC  $ip  $event\n", FILE_APPEND | LOCK_EX);
}

/**
 * Read, change and write data/invites.json under a lock. $change receives the
 * invites (expired ones already removed) and may modify them; its return
 * value is passed back.
 */
function withInvites(callable $change) {
    if (!is_dir(QSOMAP_DATA)) mkdir(QSOMAP_DATA, 0755, true);
    $lock = fopen(INVITES_FILE . '.lock', 'c');
    flock($lock, LOCK_EX);
    $invites = readJson(INVITES_FILE) ?? [];
    $invites = array_filter($invites, fn($invite) => (int)($invite['expires'] ?? 0) >= time());
    $result = $change($invites);
    writeJson(INVITES_FILE, $invites);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

/** Callsign whose invite is at $stage ('sync') and matches $key, or null. */
function inviteByKey(string $key, string $stage): ?string {
    if ($key === '' || !is_file(INVITES_FILE)) return null;
    $hash = hash('sha256', $key);
    foreach (readJson(INVITES_FILE) ?? [] as $call => $invite) {
        if (($invite['stage'] ?? '') === $stage && (int)($invite['expires'] ?? 0) >= time()
            && hash_equals((string)($invite['key'] ?? ''), $hash)) {
            return (string)$call;
        }
    }
    return null;
}

/** https://host/path of this installation, from a script $depth folders below the root. */
function instanceUrl(int $depth = 0): string {
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    for ($level = 0; $level < $depth; $level++) $path = dirname($path);
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . rtrim($path, '/');
}

function readJson(string $path): ?array {
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * Write JSON atomically so the maps never read a half-written file. Files the
 * browser downloads are written without indentation ($pretty = false).
 */
function writeJson(string $path, array $data, bool $pretty = true): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, json_encode($data, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE)) === false
        || !rename($tmp, $path)) {
        fail("cannot write $path");
    }
}

/**
 * When a station's map was built and how many QSOs it has, read from the
 * start of qso_cache.json: the cache of a big log is several MB, too much to
 * decode just for that (e.g. once per station in the admin list).
 */
function cacheSummary(string $path): array {
    $start = is_file($path) ? (string)file_get_contents($path, false, null, 0, 1024) : '';
    return [
        'generated' => preg_match('/"generated":"([^"]*)"/', $start, $match) ? $match[1] : null,
        'total_qsos' => preg_match('/"total_qsos":(\d+)/', $start, $match) ? (int)$match[1] : 0,
    ];
}

/** Memory a script may use with a big log (one year of it is loaded at a time). */
const MEMORY_LIMIT = '512M';

/**
 * Raise PHP's memory limit to MEMORY_LIMIT for the scripts that process a
 * log: hosting plans often default to 128M, and a busy year of FT8 can have
 * tens of thousands of QSOs. Never lowers a higher (or unlimited) limit; if the
 * host forbids the change, the script runs with the limit it has.
 */
function raiseMemoryLimit(): void {
    $current = bytesFromIni((string)ini_get('memory_limit'));
    if ($current !== -1 && $current < bytesFromIni(MEMORY_LIMIT)) @ini_set('memory_limit', MEMORY_LIMIT);
}

/** A php.ini size ("128M", "1G", "-1") in bytes; -1 means unlimited. */
function bytesFromIni(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') return -1;
    $number = (int)$value;
    switch (strtoupper(substr($value, -1))) {
        case 'G': return $number * 1073741824;
        case 'M': return $number * 1048576;
        case 'K': return $number * 1024;
        default: return $number;
    }
}

function formatBytes(int $bytes): string {
    return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', $bytes / 1024);
}

/** "Memory: 119.0 MB peak, limit 512M", to see how close a big log gets. */
function memoryUse(): string {
    return 'Memory: ' . formatBytes(memory_get_peak_usage(true)) . ' peak, limit ' . ini_get('memory_limit') . "\n";
}

/** GET (or POST when $postFields is given) with cURL. Returns null on failure. */
function httpRequest(string $url, ?array $postFields = null, int $timeout = 60): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'QSOMap/1.0',
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch); // no effect since PHP 8.0, deprecated in 8.5
    return ($body === false || $status !== 200) ? null : $body;
}

/**
 * Download to a file with cURL (POST when $postFields is given), so big
 * answers never sit in memory. Returns the HTTP status, or null if the
 * transfer failed; the file is left for the caller to delete.
 */
function httpDownload(string $url, string $path, ?array $postFields = null, int $timeout = 300): ?int {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    $file = fopen($path, 'wb');
    if ($file === false) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $file,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'QSOMap/1.0',
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    fclose($file);
    return $ok === false ? null : (int)$status;
}

/**
 * Parse ADIF text into a list of QSOs keyed by upper-case field name.
 * Records without CALL and QSO_DATE (e.g. the header) are skipped.
 */
function parseAdif(string $content): array {
    $qsos = [];
    foreach (preg_split('/<eor>/i', $content) as $record) {
        $qso = parseAdifRecord($record);
        if (isset($qso['CALL'], $qso['QSO_DATE'])) $qsos[] = $qso;
    }
    return $qsos;
}

/** Fields of one ADIF record (the text between two <eor>), keyed by upper-case name. */
function parseAdifRecord(string $record): array {
    preg_match_all('/<(\w+):(\d+)(?::\w)?>([^<]*)/i', $record, $matches, PREG_SET_ORDER);
    $qso = [];
    foreach ($matches as $match) {
        $value = trim($match[3]);
        if ($value !== '') $qso[strtoupper($match[1])] = $value;
    }
    return $qso;
}

/**
 * Read an ADIF file without loading it whole, so reports of hundreds of MB
 * fit in memory: calls $onRecord with the text of each record. Returns the
 * header (the text before <eoh>), or null if the file has none, e.g. an HTML
 * error page.
 */
function readAdifFile(string $path, callable $onRecord): ?string {
    $file = fopen($path, 'rb');
    if ($file === false) return null;
    $header = null;
    $buffer = '';
    while (!feof($file)) {
        $buffer .= (string)fread($file, 1048576);
        if ($header === null) {
            $end = stripos($buffer, '<eoh>');
            if ($end === false) {
                if (strlen($buffer) > 1048576) break; // no header in the first MB: not ADIF
                continue;
            }
            $header = substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 5);
        }
        $start = 0;
        while (($end = stripos($buffer, '<eor>', $start)) !== false) {
            $onRecord(substr($buffer, $start, $end - $start));
            $start = $end + 5;
        }
        $buffer = substr($buffer, $start);
    }
    fclose($file);
    return $header;
}

/**
 * Key that identifies a QSO across sources: call, date, time to the minute and
 * band, normalized so Clublog and LoTW records of the same contact always match
 * (e.g. TIME_ON 0930 vs 093015, band 20m vs 20M).
 */
function qsoKey(array $qso): string {
    return strtoupper($qso['CALL'] ?? '') . '_' . ($qso['QSO_DATE'] ?? '') . '_'
        . substr($qso['TIME_ON'] ?? '', 0, 4) . '_' . strtoupper($qso['BAND'] ?? '');
}

/**
 * A station's log is kept in one file per year of QSO date (qsos_2024.json,
 * and lotw_confirmed_2024.json for the ranking's confirmations), each a map
 * keyed by qsoKey(). Scripts load one year at a time, so the memory they need
 * depends on the busiest year, not on the size of the whole log. The year is
 * part of the key (QSO_DATE), so the same contact always lands in the same
 * file. QSOs without a valid date go to year 0000.
 */
const QSOS_FILES = 'qsos_';
const CONFIRMED_FILES = 'lotw_confirmed_';

/** The year files of a log ($prefix QSOS_FILES or CONFIRMED_FILES) as year => path, oldest first. */
function yearFiles(string $prefix, ?string $dataDir = null): array {
    $files = [];
    foreach (glob(($dataDir ?? stationDir() . '/data') . "/$prefix" . '[0-9][0-9][0-9][0-9].json') ?: [] as $path) {
        $files[substr(basename($path, '.json'), -4)] = $path;
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** Year of a QSO's file: the first 4 digits of QSO_DATE, or 0000. */
function qsoYear(array $qso): string {
    $year = substr((string)($qso['QSO_DATE'] ?? ''), 0, 4);
    return strlen($year) === 4 && ctype_digit($year) ? $year : '0000';
}

/**
 * Merge QSOs into a log's year files, loading only the years they belong to.
 * When a contact is already stored (e.g. from LoTW on a night Clublog was
 * down, then from Clublog the next day) it stays a single record with the
 * fields of both: new values win, or with $existingWins the stored ones do.
 * Returns how many contacts were new.
 */
function storeQsos(string $prefix, array $qsos, bool $existingWins = false): int {
    $byYear = [];
    foreach ($qsos as $qso) $byYear[qsoYear($qso)][] = $qso;
    $new = 0;
    foreach ($byYear as $year => $list) {
        $path = stationData("$prefix$year.json");
        $stored = readJson($path) ?? [];
        $before = count($stored);
        foreach ($list as $qso) {
            $key = qsoKey($qso);
            $stored[$key] = $existingWins ? array_merge($qso, $stored[$key] ?? []) : array_merge($stored[$key] ?? [], $qso);
        }
        $new += count($stored) - $before;
        writeJson($path, $stored, false);
        unset($stored);
    }
    return $new;
}

/** Every QSO of a log, one year file at a time (oldest first). */
function storedQsos(string $prefix, ?string $dataDir = null): Generator {
    foreach (yearFiles($prefix, $dataDir) as $path) {
        $qsos = readJson($path) ?? [];
        foreach ($qsos as $qso) yield $qso;
        unset($qsos);
    }
}

/** Number of QSOs in a log. */
function countStoredQsos(string $prefix): int {
    $count = 0;
    foreach (yearFiles($prefix) as $path) $count += count(readJson($path) ?? []);
    return $count;
}

/** Centre of a Maidenhead locator (4, 6 or 8 chars) as [lat, lon], or null if invalid. */
function gridCentre(string $grid): ?array {
    $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $grid));
    if (strlen($value) < 4 || strlen($value) % 2 !== 0) return null;
    $lat = 0.0;
    $lon = 0.0;
    $latSize = 10.0;
    $lonSize = 20.0;
    $pairs = strlen($value) / 2;
    for ($index = 0; $index < $pairs; $index++) {
        $first = $value[$index * 2];
        $second = $value[$index * 2 + 1];
        $letters = $index % 2 === 0;
        if (!($letters ? ctype_alpha($first . $second) : ctype_digit($first . $second))) return null;
        $lonIndex = $letters ? ord($first) - 65 : (int)$first;
        $latIndex = $letters ? ord($second) - 65 : (int)$second;
        $maxIndex = $letters ? ($index === 0 ? 17 : 23) : 9;
        if ($lonIndex > $maxIndex || $latIndex > $maxIndex) return null;
        $lon += $lonIndex * $lonSize;
        $lat += $latIndex * $latSize;
        if ($index < $pairs - 1) {
            $divisor = $letters ? 10 : 24;
            $lonSize /= $divisor;
            $latSize /= $divisor;
        }
    }
    return [$lat - 90 + $latSize / 2, $lon - 180 + $lonSize / 2];
}

/** The view shown on a station's static map, from its (raw or loaded) config. */
function staticView(array $config): string {
    $view = strtolower((string)($config['map']['static_mode'] ?? ''));
    return isset(MAP_VIEWS[$view]) ? $view : DEFAULT_STATIC_VIEW;
}

/** Whether a map cache QSO belongs to $view (same rule as js/map.js). */
function qsoInView(array $qso, string $view): bool {
    $modes = MAP_VIEWS[$view]['modes'];
    if ($modes === null) return strtoupper((string)($qso['qsl_rcvd'] ?? '')) === 'Y';
    foreach (['mode', 'submode'] as $field) {
        if (in_array(strtoupper((string)($qso[$field] ?? '')), $modes, true)) return true;
    }
    return false;
}

/** Wrap a longitude into [-180, 180). */
function normalizeLongitude(float $longitude): float {
    $value = fmod($longitude + 180.0, 360.0);
    if ($value < 0.0) $value += 360.0;
    return $value - 180.0;
}
