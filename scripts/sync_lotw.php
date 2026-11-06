<?php
/**
 * Download a station's QSOs from LoTW (Logbook of The World) into
 * stations/<CALL>/data/qso_data.json, and its LoTW confirmations into
 * lotw_confirmed.json for the club ranking (see ranking_data.php).
 *
 * Follows ARRL's guidance for automated downloads: the first run downloads
 * the whole log; later runs only ask for what changed, using the timestamps
 * LoTW returns in each report header:
 *   - qso_qsorxsince: QSOs uploaded since the last run, confirmed or not
 *   - qso_qslsince:   new confirmations since the last run, including old QSOs
 * Results are merged into qso_data.json, so LoTW works both as the only source
 * and as a fallback for Clublog. Stations synced before version 2.2.0 only
 * had the last years of their log: they download everything once more.
 * Delete stations/<CALL>/data/lotw_last_sync.json to force a full download.
 *
 * With --confirmations-only (command line, used by sync_daily.sh for stations
 * synced from Clublog while the ranking is on) only the confirmations report
 * runs and only lotw_confirmed.json changes; a station without LoTW
 * credentials is skipped without error.
 */

require __DIR__ . '/ranking_data.php';
useStation(requireCliOrToken(true));
set_time_limit(900);

$confirmationsOnly = PHP_SAPI === 'cli' && in_array('--confirmations-only', $argv ?? [], true);
$lotw = loadConfig()['lotw'] ?? [];
$username = $lotw['username'] ?? '';
$password = $lotw['password'] ?? '';

if ($username === '' || $password === '') {
    if ($confirmationsOnly) {
        echo "LoTW credentials not configured: this station is not in the ranking\n";
        exit(0);
    }
    fail('LoTW credentials not configured');
}

$jsonFile = stationData('qso_data.json');
$confirmedFile = stationData('lotw_confirmed.json');
$stateFile = stationData('lotw_last_sync.json');
$state = readJson($stateFile) ?? [];
$confirmationsKnown = isset($state['qsl']) && !empty($state['full_confirmations']) && is_file($confirmedFile);
$incremental = $confirmationsOnly
    ? $confirmationsKnown
    : $confirmationsKnown && isset($state['qsorx']) && !empty($state['full_uploads']) && is_file($jsonFile);

$query = [
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_qsldetail' => 'yes',
    'qso_withown' => 'yes',
];

$what = $confirmationsOnly ? 'confirmations' : 'sync';
if ($incremental) {
    echo "=== LoTW incremental $what: $username ===\n";
    echo ($confirmationsOnly ? '' : "Uploads since {$state['qsorx']}, ") . "confirmations since {$state['qsl']}\n";
    $uploadsSince = ['qso_qsorxsince' => $state['qsorx'] ?? ''];
    $confirmationsSince = ['qso_qslsince' => $state['qsl']];
} else {
    echo "=== LoTW full $what: $username (whole log, this can take several minutes) ===\n";
    $uploadsSince = $confirmationsSince = [];
}

$uploads = $confirmationsOnly ? null : lotwReport($query + ['qso_qsl' => 'no'] + $uploadsSince);
$confirmations = lotwReport($query + ['qso_qsl' => 'yes'] + $confirmationsSince);

if ($uploads !== null) {
    // Merge into the existing data; confirmations go last so their QSL details win.
    $existing = mergeQsos(readJson($jsonFile) ?? []);
    $before = count($existing);
    $merged = mergeQsos($existing, $uploads['qsos'], $confirmations['qsos']);
    writeJson($jsonFile, $merged);
}
$confirmedTotal = storeLotwConfirmations($confirmations['qsos']);

// Next run starts where LoTW says this one ended. If a report had no records
// (and so no timestamp), keep the previous value; on a first run, use yesterday.
$fallback = gmdate('Y-m-d', strtotime('-1 day'));
$state['qsl'] = $confirmations['last'] ?? $state['qsl'] ?? $fallback;
$state['full_confirmations'] = true;
if ($uploads !== null) {
    $state['qsorx'] = $uploads['last'] ?? $state['qsorx'] ?? $fallback;
    $state['full_uploads'] = true;
}
writeJson($stateFile, $state);

if ($uploads !== null) {
    echo 'Uploads: ' . count($uploads['qsos']) . ', confirmations: ' . count($confirmations['qsos'])
        . ', new QSOs: ' . (count($merged) - $before) . "\n";
    echo 'Total: ' . count($merged) . " QSOs\n";
} else {
    echo 'Confirmations: ' . count($confirmations['qsos']) . "\n";
}
echo "Confirmed in LoTW (ranking): $confirmedTotal QSOs\n";

/**
 * Run one lotwreport.adi query. Returns the parsed QSOs and the header
 * timestamp to use as the next "since" value (null if the report has none).
 */
function lotwReport(array $params): array {
    $adif = httpRequest('https://lotw.arrl.org/lotwuser/lotwreport.adi?' . http_build_query($params), null, 840);
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
