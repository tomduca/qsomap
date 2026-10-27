<?php
/**
 * Build data/qso_cache.json, the file the maps read, from data/qso_data.json.
 *
 * QSOs without a grid are looked up on HamQTH (if configured) and then
 * Spothole. Grids found in previous runs are reused, so each run only queries
 * callsigns that still have no grid.
 *
 * Every grid, logged or looked up, must match the QSO's DXCC entity according
 * to data/vuccgrids.json (see update_dxcc.php). A grid that does not is dropped
 * and looked up again; if no matching grid is found the QSO has no grid and
 * the maps do not show it.
 */

require __DIR__ . '/common.php';
requireCliOrToken();
set_time_limit(600);

$config = loadConfig();
$qsoDataFile = QSOMAP_DATA . '/qso_data.json';
$cacheFile = QSOMAP_DATA . '/qso_cache.json';

$qsos = readJson($qsoDataFile);
if ($qsos === null) {
    fail("$qsoDataFile not found; run sync_clublog.php or sync_lotw.php first");
}

echo '=== Building cache from ' . count($qsos) . " QSOs ===\n";

// Grids resolved in previous runs, keyed by call|date|time.
$knownGrids = [];
foreach ((readJson($cacheFile)['qsos'] ?? []) as $cached) {
    if (!empty($cached['grid'])) {
        $knownGrids[$cached['call'] . '|' . $cached['date'] . '|' . $cached['time']] = $cached['grid'];
    }
}

$entityGrids = loadEntityGrids();
echo $entityGrids ? "Checking grids against DXCC entities\n" : "data/vuccgrids.json not found, grids are not checked\n";

$hamqthSession = hamqthLogin($config['hamqth']['username'] ?? '', $config['hamqth']['password'] ?? '');
echo $hamqthSession ? "HamQTH login OK\n" : "HamQTH not available, using Spothole only\n";

$stats = ['with_grid' => 0, 'hamqth' => 0, 'spothole' => 0, 'not_found' => 0];
$rejected = [];
$processed = [];

foreach ($qsos as $qso) {
    $entry = [
        'call' => $qso['CALL'] ?? '',
        'band' => $qso['BAND'] ?? '',
        'mode' => $qso['MODE'] ?? '',
        'freq' => isset($qso['FREQ']) ? (float)$qso['FREQ'] : null,
        'date' => $qso['QSO_DATE'] ?? '',
        'time' => $qso['TIME_ON'] ?? '',
        'grid' => $qso['GRIDSQUARE'] ?? '',
        'dxcc' => $qso['DXCC'] ?? '',
        'name' => $qso['NAME'] ?? '',
        'qth' => $qso['QTH'] ?? '',
        'comment' => $qso['COMMENT'] ?? '',
        'lotw_qsl_rcvd' => $qso['LOTW_QSL_RCVD'] ?? null,
        'qsl_rcvd' => $qso['QSL_RCVD'] ?? null,
        'clublog_status' => $qso['CLUBLOG_QSO_UPLOAD_STATUS'] ?? null,
        'sig' => $qso['SIG'] ?? '',
        'sig_info' => $qso['SIG_INFO'] ?? '',
    ];

    // The logged grid wins if it matches the DXCC entity; otherwise reuse a
    // previous lookup, then query. Every candidate goes through the same check.
    $check = function (string $grid, string $source) use ($entry, $entityGrids, &$rejected): bool {
        if ($grid === '' || gridMatchesDxcc($grid, $entry['dxcc'], $entityGrids)) return $grid !== '';
        $rejected[] = "{$entry['call']} {$entry['date']}: $source grid $grid is not in DXCC {$entry['dxcc']}";
        return false;
    };
    if (!$check($entry['grid'], 'logged')) {
        $known = $knownGrids[$entry['call'] . '|' . $entry['date'] . '|' . $entry['time']] ?? '';
        $entry['grid'] = $known !== '' && gridMatchesDxcc($known, $entry['dxcc'], $entityGrids) ? $known : '';
    }
    if ($entry['grid'] === '' && $entry['call'] !== '') {
        if ($hamqthSession && $check($grid = (string)lookupGridHamQTH($entry['call'], $hamqthSession), 'HamQTH')) {
            $entry['grid'] = $grid;
            $stats['hamqth']++;
        } elseif ($check($grid = (string)lookupGridSpothole($entry['call']), 'Spothole')) {
            $entry['grid'] = $grid;
            $stats['spothole']++;
        } else {
            $stats['not_found']++;
        }
        usleep(50000); // be polite with the lookup services
    }

    if ($entry['grid'] !== '') $stats['with_grid']++;
    $processed[] = $entry;
}

writeJson($cacheFile, [
    'generated' => date('Y-m-d H:i:s'),
    'station' => [
        'callsign' => $config['map']['my_callsign'] ?? '',
        'grid' => $config['map']['my_grid'] ?? '',
    ],
    'total_qsos' => count($processed),
    'qsos' => $processed,
]);

echo "QSOs with grid: {$stats['with_grid']} / " . count($processed) . "\n";
echo "New lookups: HamQTH {$stats['hamqth']}, Spothole {$stats['spothole']}, not found {$stats['not_found']}\n";
if ($rejected) {
    echo 'Grids rejected for not matching the DXCC entity (' . count($rejected) . "):\n  " . implode("\n  ", $rejected) . "\n";
}

/** data/vuccgrids.json as entity => set of 4-character grids, or [] if missing. */
function loadEntityGrids(): array {
    $sets = [];
    foreach (readJson(QSOMAP_DATA . '/vuccgrids.json') ?? [] as $entity => $grids) {
        $sets[(int)$entity] = array_fill_keys(explode(' ', $grids), true);
    }
    return $sets;
}

/**
 * Whether a grid can belong to the DXCC entity: its 4-character square, or one
 * of the 8 around it, is listed for the entity. The one-square tolerance avoids
 * false alarms along borders the file does not cover exactly. QSOs without a
 * DXCC code, entities missing from the file and malformed grids are accepted
 * (the maps skip malformed grids anyway).
 */
function gridMatchesDxcc(string $grid, string $dxcc, array $entityGrids): bool {
    $grids = $entityGrids[(int)$dxcc] ?? null;
    $square = strtoupper(substr(trim($grid), 0, 4));
    if (!$grids || !ctype_digit(trim($dxcc)) || !preg_match('/^[A-R]{2}\d{2}$/', $square)) return true;
    $x = (ord($square[0]) - 65) * 10 + (int)$square[2]; // 0..179, west to east
    $y = (ord($square[1]) - 65) * 10 + (int)$square[3]; // 0..179, south to north
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            $nx = ($x + $dx + 180) % 180;
            $ny = $y + $dy;
            if ($ny < 0 || $ny > 179) continue;
            $name = chr(65 + intdiv($nx, 10)) . chr(65 + intdiv($ny, 10)) . ($nx % 10) . ($ny % 10);
            if (isset($grids[$name])) return true;
        }
    }
    return false;
}

function hamqthLogin(string $username, string $password): ?string {
    if ($username === '' || $password === '') return null;
    $xml = httpRequest('https://www.hamqth.com/xml.php?' . http_build_query(['u' => $username, 'p' => $password]));
    $data = $xml ? @simplexml_load_string($xml) : false;
    $session = $data ? (string)$data->session->session_id : '';
    return $session !== '' ? $session : null;
}

function lookupGridHamQTH(string $callsign, string $sessionId): ?string {
    $xml = httpRequest('https://www.hamqth.com/xml.php?' . http_build_query([
        'id' => $sessionId,
        'callsign' => $callsign,
        'prg' => 'qsomap',
    ]));
    $data = $xml ? @simplexml_load_string($xml) : false;
    $grid = $data && isset($data->search->grid) ? trim((string)$data->search->grid) : '';
    return $grid !== '' ? $grid : null;
}

function lookupGridSpothole(string $callsign): ?string {
    $json = httpRequest('https://api.spothole.net/lookup/call/' . rawurlencode($callsign));
    $grid = trim((string)(json_decode((string)$json, true)['grid'] ?? ''));
    return $grid !== '' ? $grid : null;
}
