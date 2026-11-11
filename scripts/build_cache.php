<?php
/**
 * Build a station's qso_cache.json, the file the maps read, from its
 * qso_data.json (both in stations/<CALL>/data/).
 *
 * QSOs without a grid get the grid logged for the same callsign in another
 * QSO, or one looked up on HamQTH (if configured) and then Spothole. Lookups
 * are per callsign and kept in grid_lookups.json, so each callsign is queried
 * once, however many QSOs it has; one that is not found is tried again after
 * LOOKUP_RETRY_DAYS.
 *
 * Every grid, logged or looked up, must match the QSO's DXCC entity according
 * to data/vuccgrids.json (see update_dxcc.php). A grid that does not is dropped;
 * if no matching grid is found the QSO has no grid and the maps do not show it.
 *
 * Logs of more than GROUP_ABOVE QSOs are too big to send to a browser one QSO
 * at a time: their cache has "groups" instead of "qsos", one per 4-character
 * grid square, band and mode class (ssb, cw, rtty, ft or other), with the QSO
 * count, the confirmed count, the latest callsigns and the DXCC entities. The
 * maps then show one point per grid square and say why.
 *
 * From a browser it can run in batches, so a first sync with many lookups fits
 * in short web requests: ?max_lookups=N looks up at most N callsigns, starting
 * at the QSO number given by ?offset=. The cache and the lookups are written
 * after every batch, so the next one reuses them; the output ends with
 * "Next offset: X" while lookups remain. join.php uses this for a new
 * station's first sync.
 */

require __DIR__ . '/common.php';
useStation(requireCliOrToken(true));
set_time_limit(600);

const GROUP_ABOVE = 10000;      // QSOs: a bigger log is grouped by grid square
const LOOKUP_RETRY_DAYS = 30;   // a callsign not found is looked up again after this
const GROUP_CALLS = 8;          // latest callsigns kept per group

$config = loadConfig();
$qsoDataFile = stationData('qso_data.json');
$cacheFile = stationData('qso_cache.json');
$lookupsFile = stationData('grid_lookups.json');

$qsos = readJson($qsoDataFile);
if ($qsos === null) {
    fail("$qsoDataFile not found; run sync_clublog.php or sync_lotw.php first");
}
$grouped = count($qsos) > GROUP_ABOVE;

echo '=== Building cache from ' . count($qsos) . ' QSOs' . ($grouped ? ', grouped by grid square' : '') . " ===\n";

$entityGrids = loadEntityGrids();
echo $entityGrids ? "Checking grids against DXCC entities\n" : "data/vuccgrids.json not found, grids are not checked\n";

// Lookups by callsign: call => ['grid' => found grid or '', 'date' => Y-m-d].
// The first time, seed them from the previous cache so callsigns looked up
// before this file existed are not queried again.
$lookups = readJson($lookupsFile);
if ($lookups === null) {
    $lookups = [];
    foreach ((readJson($cacheFile)['qsos'] ?? []) as $cached) {
        $call = strtoupper((string)($cached['call'] ?? ''));
        if ($call !== '' && !empty($cached['grid'])) $lookups[$call] = ['grid' => $cached['grid'], 'date' => date('Y-m-d')];
    }
}
$retryBefore = date('Y-m-d', strtotime('-' . LOOKUP_RETRY_DAYS . ' days'));

// Grids logged for each callsign (the latest QSO with a grid), so QSOs of the
// same station without one need no lookup.
$loggedGrids = [];
foreach ($qsos as $qso) {
    $grid = trim((string)($qso['GRIDSQUARE'] ?? ''));
    if ($grid === '' || ($call = strtoupper((string)($qso['CALL'] ?? ''))) === '') continue;
    $date = ($qso['QSO_DATE'] ?? '') . ($qso['TIME_ON'] ?? '');
    if (!isset($loggedGrids[$call]) || $date > $loggedGrids[$call][1]) $loggedGrids[$call] = [$grid, $date];
}

$hamqthSession = null;
$hamqthTried = false;

$offset = max(0, (int)($_GET['offset'] ?? 0));
$maxLookups = PHP_SAPI === 'cli' || !isset($_GET['max_lookups']) ? PHP_INT_MAX : max(1, (int)$_GET['max_lookups']);
$nextOffset = null; // first QSO left for the next batch
$remaining = [];    // callsigns from $nextOffset on that still need a lookup

$stats = ['with_grid' => 0, 'logged_elsewhere' => 0, 'hamqth' => 0, 'spothole' => 0, 'not_found' => 0];
$lookupsDone = 0;
$rejected = [];
$processed = []; // per-QSO entries (small logs)
$groups = [];    // grid square|band|class => group (big logs)

$index = -1;
foreach ($qsos as $qso) {
    $index++;
    $entry = [
        'call' => $qso['CALL'] ?? '',
        'band' => $qso['BAND'] ?? '',
        'mode' => $qso['MODE'] ?? '',
        'submode' => $qso['SUBMODE'] ?? '',
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
    $call = strtoupper($entry['call']);

    // The logged grid wins if it matches the DXCC entity; then the grid logged
    // for the callsign in another QSO, then a lookup (cached or new). Every
    // candidate goes through the same check.
    $check = function (string $grid, string $source) use ($entry, $entityGrids, &$rejected): bool {
        if ($grid === '' || gridMatchesDxcc($grid, $entry['dxcc'], $entityGrids)) return $grid !== '';
        if (count($rejected) < 200) $rejected[] = "{$entry['call']} {$entry['date']}: $source grid $grid is not in DXCC {$entry['dxcc']}";
        return false;
    };
    if (!$check($entry['grid'], 'logged')) {
        $entry['grid'] = '';
        if ($call !== '' && isset($loggedGrids[$call]) && $loggedGrids[$call][0] !== trim((string)($qso['GRIDSQUARE'] ?? ''))
            && gridMatchesDxcc($loggedGrids[$call][0], $entry['dxcc'], $entityGrids)) {
            $entry['grid'] = $loggedGrids[$call][0];
            $stats['logged_elsewhere']++;
        }
    }
    if ($entry['grid'] === '' && $call !== '') {
        $known = $lookups[$call] ?? null;
        $fresh = $known !== null && ($known['grid'] !== '' || $known['date'] >= $retryBefore);
        if ($fresh) {
            if ($known['grid'] !== '' && gridMatchesDxcc($known['grid'], $entry['dxcc'], $entityGrids)) $entry['grid'] = $known['grid'];
        } elseif ($nextOffset === null && $lookupsDone >= $maxLookups) {
            $nextOffset = $index;
        }
        if (!$fresh && ($nextOffset !== null || $index < $offset)) {
            if ($nextOffset !== null) $remaining[$call] = true;
        } elseif (!$fresh) {
            $hamqthSession ??= $hamqthTried ? null : hamqthLogin($config['hamqth']['username'] ?? '', $config['hamqth']['password'] ?? '');
            if (!$hamqthTried) echo $hamqthSession ? "HamQTH login OK\n" : "HamQTH not available, using Spothole only\n";
            $hamqthTried = true;
            $found = '';
            if ($hamqthSession && ($grid = (string)lookupGridHamQTH($entry['call'], $hamqthSession)) !== '') {
                $found = $grid;
                $stats['hamqth']++;
            } elseif (($grid = (string)lookupGridSpothole($entry['call'])) !== '') {
                $found = $grid;
                $stats['spothole']++;
            } else {
                $stats['not_found']++;
            }
            $lookups[$call] = ['grid' => $found, 'date' => date('Y-m-d')];
            $lookupsDone++;
            if ($check($found, 'Looked up')) $entry['grid'] = $found;
            usleep(50000); // be polite with the lookup services
        }
    }

    if ($entry['grid'] !== '') $stats['with_grid']++;
    if ($grouped) {
        addToGroup($groups, $entry);
    } else {
        $processed[] = $entry;
    }
}
unset($qsos);

$cache = [
    'generated' => date('Y-m-d H:i:s'),
    'station' => [
        'callsign' => $config['map']['my_callsign'] ?? '',
        'grid' => $config['map']['my_grid'] ?? '',
    ],
    'total_qsos' => $index + 1,
];
if ($grouped) {
    $cache['grouped'] = true;
    $cache['groups'] = finishGroups($groups);
} else {
    $cache['qsos'] = $processed;
}
writeJson($lookupsFile, $lookups);
writeJson($cacheFile, $cache, false);

echo "QSOs with grid: {$stats['with_grid']} / " . ($index + 1) . "\n";
echo "Grids logged for the callsign in another QSO: {$stats['logged_elsewhere']}\n";
echo "New lookups: HamQTH {$stats['hamqth']}, Spothole {$stats['spothole']}, not found {$stats['not_found']}\n";
if ($grouped) echo 'Groups (grid square, band, mode): ' . count($cache['groups']) . "\n";
if ($nextOffset !== null) {
    echo 'Lookups left for the next batch: ' . count($remaining) . "\n";
    echo "Next offset: $nextOffset\n";
}
if ($rejected) {
    echo 'Grids rejected for not matching the DXCC entity (' . count($rejected) . (count($rejected) >= 200 ? ', first 200' : '') . "):\n  "
        . implode("\n  ", $rejected) . "\n";
}

/** Map view class of a QSO for grouping: ssb, cw, rtty, ft or other. */
function modeClass(array $entry): string {
    foreach (MAP_VIEWS as $view => $definition) {
        if ($definition['modes'] !== null && qsoInView($entry, $view)) return $view;
    }
    return 'other';
}

/** Count a QSO with a grid into its group (grid square, band, mode class). */
function addToGroup(array &$groups, array $entry): void {
    $square = strtoupper(substr(trim($entry['grid']), 0, 4));
    if (!preg_match('/^[A-R]{2}\d{2}$/', $square)) return;
    $band = strtoupper(trim((string)$entry['band']));
    $class = modeClass($entry);
    $key = "$square|$band|$class";
    $group = &$groups[$key];
    $group ??= ['grid' => $square, 'band' => $band, 'class' => $class, 'n' => 0, 'q' => 0, 'calls' => [], 'qsl_calls' => [], 'dxcc' => []];
    $group['n']++;
    $confirmed = strtoupper((string)$entry['qsl_rcvd']) === 'Y';
    if ($confirmed) $group['q']++;
    if ($entry['dxcc'] !== '') $group['dxcc'][(string)$entry['dxcc']] = true;
    $when = $entry['date'] . $entry['time'];
    keepLatestCall($group['calls'], $entry['call'], $when);
    if ($confirmed) keepLatestCall($group['qsl_calls'], $entry['call'], $when);
}

/** Keep the GROUP_CALLS callsigns with the latest QSOs, as call => date. */
function keepLatestCall(array &$calls, string $call, string $when): void {
    if ($call === '') return;
    if (isset($calls[$call]) || count($calls) < GROUP_CALLS) {
        $calls[$call] = max($calls[$call] ?? '', $when);
        return;
    }
    $oldest = array_search(min($calls), $calls, true);
    if ($when > $calls[$oldest]) {
        unset($calls[$oldest]);
        $calls[$call] = $when;
    }
}

/** Groups as the maps read them: callsigns latest first, DXCC codes as a list. */
function finishGroups(array $groups): array {
    $list = [];
    foreach ($groups as $group) {
        arsort($group['calls']);
        arsort($group['qsl_calls']);
        $group['calls'] = array_map('strval', array_keys($group['calls']));
        $group['qsl_calls'] = array_map('strval', array_keys($group['qsl_calls']));
        $group['dxcc'] = array_map('strval', array_keys($group['dxcc']));
        $list[] = $group;
    }
    return $list;
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
