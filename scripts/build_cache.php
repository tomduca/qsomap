<?php
/**
 * Build data/qso_cache.json, the file the maps read, from data/qso_data.json.
 *
 * QSOs without a grid are looked up on HamQTH (if configured) and then
 * Spothole. Grids found in previous runs are reused, so each run only queries
 * callsigns that still have no grid.
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

$hamqthSession = hamqthLogin($config['hamqth']['username'] ?? '', $config['hamqth']['password'] ?? '');
echo $hamqthSession ? "HamQTH login OK\n" : "HamQTH not available, using Spothole only\n";

$stats = ['with_grid' => 0, 'hamqth' => 0, 'spothole' => 0, 'not_found' => 0];
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

    // The logged grid always wins; otherwise reuse a previous lookup, then query.
    if ($entry['grid'] === '') {
        $entry['grid'] = $knownGrids[$entry['call'] . '|' . $entry['date'] . '|' . $entry['time']] ?? '';
    }
    if ($entry['grid'] === '' && $entry['call'] !== '') {
        if ($hamqthSession && ($grid = lookupGridHamQTH($entry['call'], $hamqthSession))) {
            $entry['grid'] = $grid;
            $stats['hamqth']++;
        } elseif ($grid = lookupGridSpothole($entry['call'])) {
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
