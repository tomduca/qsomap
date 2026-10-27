<?php
/**
 * Shared helpers for the sync scripts and the static QRZ map.
 */

define('QSOMAP_ROOT', dirname(__DIR__));
define('QSOMAP_DATA', QSOMAP_ROOT . '/data');

/** Phone modes shown on the SSB maps (CW included); everything else is DIGI. */
const PHONE_MODES = ['SSB', 'USB', 'LSB', 'FM', 'AM', 'CW'];

/**
 * Sync scripts handle credentials and call external APIs. They always run from
 * the command line (Cron, terminal); from a browser they require
 * ?token=<web.token from config.json>, and are disabled if no token is set.
 */
function requireCliOrToken(): void {
    if (PHP_SAPI === 'cli') return;
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $expected = (string)(loadConfig(false)['web']['token'] ?? '');
    $given = (string)($_GET['token'] ?? '');
    if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit(1); // non-zero so sync_daily.sh notices if Cron runs a CGI build of PHP
    }
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

/** Load config.json. Returns [] when it is optional and missing. */
function loadConfig(bool $required = true): array {
    $path = QSOMAP_ROOT . '/config.json';
    $config = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($config)) {
        if ($required) fail("cannot load $path (copy config.json.example and fill it in)");
        return [];
    }
    return $config;
}

function readJson(string $path): ?array {
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Write JSON atomically so the maps never read a half-written file. */
function writeJson(string $path, array $data): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false
        || !rename($tmp, $path)) {
        fail("cannot write $path");
    }
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
    curl_close($ch);
    return ($body === false || $status !== 200) ? null : $body;
}

/**
 * Parse ADIF text into a list of QSOs keyed by upper-case field name.
 * Records without CALL and QSO_DATE (e.g. the header) are skipped.
 */
function parseAdif(string $content): array {
    $qsos = [];
    foreach (preg_split('/<eor>/i', $content) as $record) {
        preg_match_all('/<(\w+):(\d+)(?::\w)?>([^<]*)/i', $record, $matches, PREG_SET_ORDER);
        $qso = [];
        foreach ($matches as $match) {
            $value = trim($match[3]);
            if ($value !== '') $qso[strtoupper($match[1])] = $value;
        }
        if (isset($qso['CALL'], $qso['QSO_DATE'])) $qsos[] = $qso;
    }
    return $qsos;
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
 * Merge QSO lists into one map keyed by qsoKey(). When the same contact comes
 * from more than one source (e.g. LoTW on a night Clublog was down, then
 * Clublog again the next day) it stays a single record: fields from both are
 * kept and later values win. Existing data is re-keyed, which also collapses
 * duplicates stored under older key formats.
 */
function mergeQsos(array ...$lists): array {
    $merged = [];
    foreach ($lists as $list) {
        foreach ($list as $qso) {
            $key = qsoKey($qso);
            $merged[$key] = array_merge($merged[$key] ?? [], $qso);
        }
    }
    return $merged;
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

/** Wrap a longitude into [-180, 180). */
function normalizeLongitude(float $longitude): float {
    $value = fmod($longitude + 180.0, 360.0);
    if ($value < 0.0) $value += 360.0;
    return $value - 180.0;
}
