<?php
/**
 * Download AD1C's country file (cty.csv, country-files.com) and write
 * data/dxcc.json, the current DXCC entities as code => name.
 *
 * js/dxcc.js stays the base list (names, flags and deleted entities); the maps
 * load data/dxcc.json on top of it to pick up entities added since. sync_daily.sh
 * runs this once a month. On failure the previous data/dxcc.json is kept.
 */

require __DIR__ . '/common.php';
requireCliOrToken();

const CTY_URL = 'https://www.country-files.com/cty/cty.csv';
const MIN_ENTITIES = 300; // sanity check: a valid file lists about 340

echo "=== DXCC list update ===\n";

$csv = httpRequest(CTY_URL);
if ($csv === null) {
    fail('cannot download ' . CTY_URL);
}

// Columns: primary prefix, name, DXCC code, continent, CQ zone, ITU zone, lat, lon, UTC offset, prefixes.
// A primary prefix starting with '*' marks a WAE/CQ-only country (e.g. *IT9 Sicily), not a DXCC entity.
$entities = [];
foreach (preg_split('/\R/', $csv) as $line) {
    $fields = str_getcsv($line);
    if (count($fields) < 3 || substr($fields[0], 0, 1) === '*') continue;
    $code = trim($fields[2]);
    $name = trim($fields[1]);
    if (ctype_digit($code) && $name !== '') $entities[(int)$code] = $name;
}
if (count($entities) < MIN_ENTITIES) {
    fail('unexpected cty.csv content: only ' . count($entities) . ' entities found');
}
ksort($entities);

$jsonFile = QSOMAP_DATA . '/dxcc.json';
$previous = readJson($jsonFile) ?? [];
$added = array_diff_key($entities, $previous);
writeJson($jsonFile, $entities);
file_put_contents(QSOMAP_DATA . '/dxcc_last_update.txt', date('Ymd'));

echo count($entities) . " entities saved to data/dxcc.json\n";
if ($previous && $added) {
    echo 'New since last update: ' . implode(', ', array_map(
        fn($code, $name) => "$name ($code)", array_keys($added), $added)) . "\n";
}

