<?php
/**
 * Monthly refresh of the DXCC reference data:
 *   - data/dxcc.json: current DXCC entities as code => name, from AD1C's
 *     country file (cty.csv, country-files.com). js/dxcc.js stays the base
 *     list (names, flags and deleted entities); the maps load data/dxcc.json
 *     on top of it to pick up entities added since.
 *   - data/vuccgrids.json: the 4-character grids of each entity, from TQSL's
 *     vuccgrids.dat (ARRL's TrustedQSL repository), so build_cache.php can drop grids
 *     that do not match the QSO's DXCC entity.
 *
 * sync_daily.sh runs this once a month. On failure the previous files are kept
 * and data/dxcc_last_update.txt is not updated, so the next run tries again.
 */

require __DIR__ . '/common.php';
requireCliOrToken(true); // shared by all stations

const CTY_URL = 'https://www.country-files.com/cty/cty.csv';
const MIN_ENTITIES = 300; // sanity check: a valid file lists about 340
const VUCC_URL = 'https://sourceforge.net/p/trustedqsl/tqsl/ci/master/tree/apps/vuccgrids.dat?format=raw';
const MIN_GRIDS = 10000; // sanity check: a valid file lists about 14,800 entity/grid pairs

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

echo count($entities) . " entities saved to data/dxcc.json\n";
if ($previous && $added) {
    echo 'New since last update: ' . implode(', ', array_map(
        fn($code, $name) => "$name ($code)", array_keys($added), $added)) . "\n";
}

$dat = httpRequest(VUCC_URL);
if ($dat === null) {
    fail('cannot download ' . VUCC_URL);
}
// Each line looks like <vucc entity="1" pas="AB" grid="CO93" />, one line per entity and grid.
$grids = [];
preg_match_all('/<vucc\b[^>]*>/i', $dat, $tags);
foreach ($tags[0] as $tag) {
    if (preg_match('/\bentity="(\d+)"/', $tag, $entity) && preg_match('/\bgrid="([A-R]{2}\d{2})"/i', $tag, $grid)) {
        $grids[(int)$entity[1]][strtoupper($grid[1])] = true;
    }
}
$pairs = array_sum(array_map('count', $grids));
if ($pairs < MIN_GRIDS) {
    fail("unexpected vuccgrids.dat content: only $pairs grids found");
}
ksort($grids);
writeJson(QSOMAP_DATA . '/vuccgrids.json', array_map(fn($set) => implode(' ', array_keys($set)), $grids));
echo "$pairs grids of " . count($grids) . " entities saved to data/vuccgrids.json\n";

file_put_contents(QSOMAP_DATA . '/dxcc_last_update.txt', date('Ymd'));

