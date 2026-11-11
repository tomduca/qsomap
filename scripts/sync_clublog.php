<?php
/**
 * Download a station's QSOs from Clublog into its log, kept in one file per
 * year in stations/<CALL>/data/qsos_<YEAR>.json (see storeQsos() in common.php).
 *
 * The first run downloads the whole log; later runs are incremental (from two
 * days before the last successful sync) and merge into the existing files. A
 * station without year files (new, or synced before version 2.5.0, when the
 * log was a single qso_data.json) downloads the whole log again. The ADIF is
 * saved to a temporary file and stored BATCH QSOs at a time, so the size of
 * the log does not matter for memory.
 * Exits non-zero on failure so sync_daily.sh can fall back to LoTW.
 */

require __DIR__ . '/common.php';
useStation(requireCliOrToken());
raiseMemoryLimit();
set_time_limit(600);

const BATCH = 20000; // QSOs read from the ADIF before storing them

$clublog = loadConfig()['clublog'] ?? [];
$email = $clublog['email'] ?? '';
$password = $clublog['password'] ?? '';  // Clublog application password
$apiKey = $clublog['api_key'] ?? '';     // Clublog developer API key
$callsign = $clublog['callsign'] ?? '';

if ($email === '' || $password === '' || $apiKey === '' || $callsign === '') {
    fail('Clublog credentials not configured');
}

$lastSyncFile = stationData('clublog_last_sync.txt');

echo "=== Clublog sync: $callsign ===\n";

$params = ['email' => $email, 'password' => $password, 'api' => $apiKey, 'call' => $callsign];

// Incremental sync: start two days before the last successful sync.
$lastSync = is_file($lastSyncFile) ? DateTime::createFromFormat('Ymd', trim(file_get_contents($lastSyncFile))) : false;
$incremental = $lastSync instanceof DateTime && yearFiles(QSOS_FILES);
if ($incremental) {
    $start = $lastSync->modify('-2 days');
    $params += ['startyear' => $start->format('Y'), 'startmonth' => $start->format('n'), 'startday' => $start->format('j')];
    echo 'Incremental sync since ' . $start->format('Y-m-d') . "\n";
} else {
    echo "Full sync\n";
}

$path = stationData('clublog_download.tmp');
$status = httpDownload('https://clublog.org/getadif.php', $path, $params, 540);
if ($status !== 200) {
    @unlink($path);
    fail('Clublog download failed (' . ($status === null ? 'no answer' : "HTTP $status") . ')');
}
$downloaded = 0;
$new = 0;
$batch = [];
$header = readAdifFile($path, function (string $record) use (&$batch, &$downloaded, &$new) {
    $qso = parseAdifRecord($record);
    if (!isset($qso['CALL'], $qso['QSO_DATE'])) return;
    $batch[] = $qso;
    $downloaded++;
    if (count($batch) >= BATCH) {
        $new += storeQsos(QSOS_FILES, $batch);
        $batch = [];
    }
});
@unlink($path);
if ($header === null) fail('Clublog returned invalid ADIF');
$new += storeQsos(QSOS_FILES, $batch);
file_put_contents($lastSyncFile, date('Ymd'));

$total = 0;
$withGrid = 0;
foreach (storedQsos(QSOS_FILES) as $qso) {
    $total++;
    if (!empty($qso['GRIDSQUARE'])) $withGrid++;
}
echo "Downloaded: $downloaded QSOs, new: $new\n";
echo "Total: $total QSOs ($withGrid with grid)\n";
echo memoryUse();
