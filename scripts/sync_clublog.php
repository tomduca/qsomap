<?php
/**
 * Download QSOs from Clublog into data/qso_data.json.
 *
 * The first run downloads the whole log; later runs are incremental (from two
 * days before the last successful sync) and merge into the existing file.
 * Exits non-zero on failure so sync_daily.sh can fall back to LoTW.
 */

require __DIR__ . '/common.php';
requireCliOrToken();
set_time_limit(300);

$clublog = loadConfig()['clublog'] ?? [];
$email = $clublog['email'] ?? '';
$password = $clublog['password'] ?? '';  // Clublog application password
$apiKey = $clublog['api_key'] ?? '';     // Clublog developer API key
$callsign = $clublog['callsign'] ?? '';

if ($email === '' || $password === '' || $apiKey === '' || $callsign === '') {
    fail('Clublog credentials not configured');
}

$jsonFile = QSOMAP_DATA . '/qso_data.json';
$lastSyncFile = QSOMAP_DATA . '/clublog_last_sync.txt';

echo "=== Clublog sync: $callsign ===\n";

$params = ['email' => $email, 'password' => $password, 'api' => $apiKey, 'call' => $callsign];

// Incremental sync: start two days before the last successful sync.
$lastSync = is_file($lastSyncFile) ? DateTime::createFromFormat('Ymd', trim(file_get_contents($lastSyncFile))) : false;
$incremental = $lastSync instanceof DateTime && is_file($jsonFile);
if ($incremental) {
    $start = $lastSync->modify('-2 days');
    $params += ['startyear' => $start->format('Y'), 'startmonth' => $start->format('n'), 'startday' => $start->format('j')];
    echo 'Incremental sync since ' . $start->format('Y-m-d') . "\n";
} else {
    echo "Full sync\n";
}

$adif = httpRequest('https://clublog.org/getadif.php', $params, 300);
if ($adif === null || stripos($adif, '<eoh>') === false) {
    fail('Clublog download failed or returned invalid ADIF');
}

$qsos = parseAdif($adif);
$existing = $incremental ? mergeQsos(readJson($jsonFile) ?? []) : [];
$before = count($existing);
$merged = mergeQsos($existing, $qsos);

writeJson($jsonFile, $merged);
file_put_contents($lastSyncFile, date('Ymd'));

$withGrid = count(array_filter($merged, fn($qso) => !empty($qso['GRIDSQUARE'])));
echo 'Downloaded: ' . count($qsos) . ' QSOs, new: ' . (count($merged) - $before) . "\n";
echo 'Total: ' . count($merged) . " QSOs ($withGrid with grid)\n";
