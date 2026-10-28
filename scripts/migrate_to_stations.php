<?php
/**
 * Move a single-station installation (before version 2: the station in the
 * root config.json and its files in data/) to stations/<CALL>/, and make it
 * the default station so existing URLs (map-ssb.html, map-ssb-qrz.php) keep
 * working. sync_daily.sh runs it once when stations/ is empty; it can also be
 * run by hand. The station keeps all its credentials (LoTW, Clublog with the
 * api_key, HamQTH); the root config.json keeps only web and default_station.
 */

require __DIR__ . '/common.php';
requireCliOrToken();

const STATION_FILES = ['qso_data.json', 'qso_cache.json', 'lotw_last_sync.json', 'clublog_last_sync.txt', 'qrz-background.png'];

echo "=== Migration to stations/ ===\n";

$root = rootConfig();
$call = normalizeCall((string)($root['map']['my_callsign'] ?? ''));
if ($call === null) {
    fail('config.json has no valid map.my_callsign: nothing to migrate');
}
if (stationExists($call)) {
    fail("stations/$call already exists");
}

$station = array_intersect_key($root, array_flip(['map', 'lotw', 'clublog', 'hamqth']));

$dataDir = QSOMAP_STATIONS . "/$call/data";
if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
    fail("cannot create $dataDir");
}
foreach (STATION_FILES as $file) {
    if (is_file(QSOMAP_DATA . "/$file") && !rename(QSOMAP_DATA . "/$file", "$dataDir/$file")) {
        fail("cannot move data/$file");
    }
}
writeJson(QSOMAP_STATIONS . "/$call/config.json", $station);

writeJson(QSOMAP_ROOT . '/config.json', ['default_station' => $call] + array_diff_key($root, $station));

logEvent("migrated $call to stations/");
echo "Moved $call to stations/$call and set it as default_station\n";
