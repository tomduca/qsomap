<?php
/**
 * Download QSOs from LoTW (Logbook of The World) into data/qso_data.json.
 *
 * Follows ARRL's guidance for automated downloads: the first run downloads
 * everything (limited by lotw.days_back); later runs only ask for what changed,
 * using the timestamps LoTW returns in each report header:
 *   - qso_qsorxsince: QSOs uploaded since the last run, confirmed or not
 *   - qso_qslsince:   new confirmations since the last run, including old QSOs
 * Results are merged into qso_data.json, so LoTW works both as the only source
 * and as a fallback for Clublog. Delete data/lotw_last_sync.json to force a
 * full download.
 */

require __DIR__ . '/common.php';
requireCliOrToken();
set_time_limit(300);

$lotw = loadConfig()['lotw'] ?? [];
$username = $lotw['username'] ?? '';
$password = $lotw['password'] ?? '';
$daysBack = (int)($lotw['days_back'] ?? 3650);

if ($username === '' || $password === '') {
    fail('LoTW credentials not configured');
}

$jsonFile = QSOMAP_DATA . '/qso_data.json';
$stateFile = QSOMAP_DATA . '/lotw_last_sync.json';
$state = readJson($stateFile) ?? [];
$incremental = isset($state['qsorx'], $state['qsl']) && is_file($jsonFile);

$query = [
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_qsldetail' => 'yes',
    'qso_withown' => 'yes',
];

if ($incremental) {
    echo "=== LoTW incremental sync: $username ===\n";
    echo "Uploads since {$state['qsorx']}, confirmations since {$state['qsl']}\n";
    $uploadsSince = ['qso_qsorxsince' => $state['qsorx']];
    $confirmationsSince = ['qso_qslsince' => $state['qsl']];
} else {
    $startDate = date('Y-m-d', strtotime("-$daysBack days"));
    echo "=== LoTW full sync: $username (QSOs since $startDate) ===\n";
    $uploadsSince = $confirmationsSince = ['qso_startdate' => $startDate];
}

$uploads = lotwReport($query + ['qso_qsl' => 'no'] + $uploadsSince);
$confirmations = lotwReport($query + ['qso_qsl' => 'yes'] + $confirmationsSince);

// Merge into the existing data; confirmations go last so their QSL details win.
$existing = mergeQsos(readJson($jsonFile) ?? []);
$before = count($existing);
$merged = mergeQsos($existing, $uploads['qsos'], $confirmations['qsos']);
writeJson($jsonFile, $merged);

// Next run starts where LoTW says this one ended. If a report had no records
// (and so no timestamp), keep the previous value; on a first run, use yesterday.
$fallback = gmdate('Y-m-d', strtotime('-1 day'));
writeJson($stateFile, [
    'qsorx' => $uploads['last'] ?? $state['qsorx'] ?? $fallback,
    'qsl' => $confirmations['last'] ?? $state['qsl'] ?? $fallback,
]);

echo 'Uploads: ' . count($uploads['qsos']) . ', confirmations: ' . count($confirmations['qsos'])
    . ', new QSOs: ' . (count($merged) - $before) . "\n";
echo 'Total: ' . count($merged) . " QSOs\n";

/**
 * Run one lotwreport.adi query. Returns the parsed QSOs and the header
 * timestamp to use as the next "since" value (null if the report has none).
 */
function lotwReport(array $params): array {
    $adif = httpRequest('https://lotw.arrl.org/lotwuser/lotwreport.adi?' . http_build_query($params), null, 300);
    if ($adif === null || stripos($adif, '<eoh>') === false) {
        fail('LoTW download failed or returned invalid ADIF (check username/password)');
    }
    $field = $params['qso_qsl'] === 'yes' ? 'APP_LoTW_LASTQSL' : 'APP_LoTW_LASTQSORX';
    return ['qsos' => parseAdif($adif), 'last' => adifHeaderField($adif, $field)];
}

/** Value of an ADIF header field (the part before <eoh>), or null. */
function adifHeaderField(string $adif, string $field): ?string {
    $header = substr($adif, 0, (int)stripos($adif, '<eoh>'));
    if (!preg_match('/<' . preg_quote($field, '/') . ':(\d+)(?::\w)?>/i', $header, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $value = trim(substr($header, $match[0][1] + strlen($match[0][0]), (int)$match[1][0]));
    return $value !== '' ? $value : null;
}
