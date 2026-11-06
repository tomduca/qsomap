<?php
/**
 * Club ranking: DXCC entities confirmed in LoTW per station, by mode and band.
 *
 * sync_lotw.php keeps each station's LoTW confirmations in
 * stations/<CALL>/data/lotw_confirmed.json (nothing from Clublog, and every
 * QSO counts whatever its grid) and writes the per-station totals to
 * stations/<CALL>/data/dxcc_counts.json. ranking.php only reads those small
 * totals, ranks the stations and leaves out the ones the manager opted out
 * (ranking.opt_out in the station's config.json). The manager turns the
 * ranking on or off, and sets its title and default language, in admin/;
 * those settings live in data/ranking_settings.json.
 */

require_once __DIR__ . '/common.php';

const RANKING_SETTINGS_FILE = QSOMAP_DATA . '/ranking_settings.json';

/**
 * Ranking modes: names in each language, the interactive map a callsign links
 * to, and the MODE or SUBMODE values a confirmed QSO matches.
 */
const RANKING_MODES = [
    'phone' => ['en' => 'Phone (SSB, AM, FM)', 'es' => 'Fonía (SSB, AM, FM)', 'view' => 'ssb', 'modes' => ['SSB', 'USB', 'LSB', 'AM', 'FM']],
    'cw' => ['en' => 'CW', 'es' => 'CW', 'view' => 'cw', 'modes' => ['CW']],
    'rtty' => ['en' => 'RTTY', 'es' => 'RTTY', 'view' => 'rtty', 'modes' => ['RTTY']],
    'ft8' => ['en' => 'FT8/FT4', 'es' => 'FT8/FT4', 'view' => 'ft', 'modes' => ['FT8', 'FT4']],
];

/**
 * Key of the all-modes totals in dxcc_counts.json: entities confirmed in any
 * mode, PSK and others included, each counted once (as DXCC Mixed). Its
 * callsign links open the map of QSOs confirmed in LoTW.
 */
const RANKING_MIXED = 'mixed';
const RANKING_MIXED_VIEW = 'qsl';

/**
 * Deleted DXCC entities (ADIF specification, DXCC_Entity_Code enumeration,
 * column "Deleted"). They are confirmed and shown, but do not count for the
 * position. ARRL has not deleted an entity since 2010; new ones are current.
 */
const DELETED_DXCC = [
    2, 8, 19, 23, 25, 26, 28, 30, 39, 42, 44, 55, 57, 58, 59, 67, 68, 81, 85, 93, 101, 102, 113, 115,
    119, 127, 128, 134, 139, 151, 154, 155, 164, 178, 183, 184, 186, 193, 194, 196, 198, 200, 208, 210,
    218, 220, 226, 228, 229, 231, 243, 244, 255, 258, 261, 264, 267, 268, 271, 307, 488, 493,
];

/** Bands from longest to shortest wavelength, the order of the band menu. */
const BAND_ORDER = [
    '2190M', '630M', '560M', '160M', '80M', '60M', '40M', '30M', '20M', '17M', '15M', '12M', '10M',
    '8M', '6M', '5M', '4M', '2M', '1.25M', '70CM', '33CM', '23CM', '13CM', '9CM', '6CM', '3CM',
    '1.25CM', '6MM', '4MM', '2.5MM', '2MM', '1MM', 'SUBMM',
];

/** Fields kept from each LoTW confirmation: enough to count entities by mode and band. */
const CONFIRMED_FIELDS = ['CALL', 'QSO_DATE', 'TIME_ON', 'BAND', 'MODE', 'SUBMODE', 'DXCC', 'QSL_RCVD'];

/** Manager settings of the ranking: enabled, title, default language. */
function rankingSettings(): array {
    $settings = readJson(RANKING_SETTINGS_FILE) ?? [];
    return [
        'enabled' => !empty($settings['enabled']),
        'title' => trim((string)($settings['title'] ?? '')),
        'lang' => ($settings['lang'] ?? '') === 'es' ? 'es' : 'en',
    ];
}

function writeRankingSettings(array $settings): void {
    writeJson(RANKING_SETTINGS_FILE, [
        'enabled' => !empty($settings['enabled']),
        'title' => preg_replace('/^(.{0,80}).*$/su', '$1', trim((string)($settings['title'] ?? ''))),
        'lang' => ($settings['lang'] ?? '') === 'es' ? 'es' : 'en',
    ]);
}

/** Whether the manager left a station out of the ranking, from its raw config. */
function rankingOptOut(array $config): bool {
    return !empty($config['ranking']['opt_out']);
}

/** Ranking mode of a QSO (raw ADIF fields), or null if it is in none. */
function rankingMode(array $qso): ?string {
    foreach (RANKING_MODES as $mode => $info) {
        foreach (['MODE', 'SUBMODE'] as $field) {
            if (in_array(strtoupper((string)($qso[$field] ?? '')), $info['modes'], true)) return $mode;
        }
    }
    return null;
}

/**
 * Merge new LoTW confirmations into the current station's lotw_confirmed.json
 * and rewrite its dxcc_counts.json. Only QSL_RCVD = Y records are kept.
 */
function storeLotwConfirmations(array $confirmations): int {
    $file = stationData('lotw_confirmed.json');
    $kept = [];
    foreach ($confirmations as $qso) {
        if (strtoupper((string)($qso['QSL_RCVD'] ?? '')) !== 'Y') continue;
        $kept[] = array_intersect_key($qso, array_flip(CONFIRMED_FIELDS));
    }
    $merged = mergeQsos(readJson($file) ?? [], $kept);
    writeJson($file, $merged);
    writeJson(stationData('dxcc_counts.json'), dxccCounts($merged));
    return count($merged);
}

/**
 * Entities confirmed per ranking mode and band ('all' = every band), as
 * [current, deleted] counts, plus RANKING_MIXED for any mode. Code 0 (no
 * entity, e.g. /MM) is ignored.
 */
function dxccCounts(array $confirmed): array {
    $deleted = array_flip(DELETED_DXCC);
    $sets = [];
    foreach ($confirmed as $qso) {
        $code = (int)($qso['DXCC'] ?? 0);
        if ($code <= 0) continue;
        $mode = rankingMode($qso);
        $kind = isset($deleted[$code]) ? 1 : 0;
        $band = strtoupper(trim((string)($qso['BAND'] ?? '')));
        foreach ($band !== '' ? ['all', $band] : ['all'] as $key) {
            $sets[RANKING_MIXED][$key][$kind][$code] = true;
            if ($mode !== null) $sets[$mode][$key][$kind][$code] = true;
        }
    }
    $counts = [];
    foreach ($sets as $mode => $bands) {
        foreach ($bands as $band => $kinds) {
            $counts[$mode][$band] = [count($kinds[0] ?? []), count($kinds[1] ?? [])];
        }
    }
    return ['generated' => date('Y-m-d H:i:s'), 'modes' => $counts];
}

/**
 * Rows of one ranking: stations in the ranking with at least one current
 * entity in $mode and $band, best first. Equal totals share the position
 * (1, 1, 3). Each row: position, call, current, deleted.
 */
function rankingRows(array $stations, string $mode, string $band): array {
    $rows = [];
    foreach ($stations as $call => $counts) {
        [$current, $deleted] = $counts[$mode][$band] ?? [0, 0];
        if ($current > 0) $rows[] = ['call' => $call, 'current' => $current, 'deleted' => $deleted];
    }
    usort($rows, fn($a, $b) => [$b['current'], $a['call']] <=> [$a['current'], $b['call']]);
    foreach ($rows as $index => &$row) {
        $row['position'] = $index > 0 && $rows[$index - 1]['current'] === $row['current'] ? $rows[$index - 1]['position'] : $index + 1;
    }
    unset($row);
    return $rows;
}

/** dxcc_counts.json modes of every station in the ranking (not opted out), by callsign. */
function rankingStations(): array {
    $stations = [];
    foreach (stationList() as $call) {
        $config = readJson(QSOMAP_STATIONS . "/$call/config.json") ?? [];
        if (rankingOptOut($config)) continue;
        $counts = readJson(QSOMAP_STATIONS . "/$call/data/dxcc_counts.json");
        if ($counts !== null) $stations[$call] = $counts['modes'] ?? [];
    }
    return $stations;
}

/** Bands with any count in $modes among $stations, in BAND_ORDER (unknown bands last). */
function rankingBands(array $stations, array $modes): array {
    $bands = [];
    foreach ($stations as $counts) {
        foreach ($modes as $mode) {
            foreach (array_keys($counts[$mode] ?? []) as $band) {
                if ($band !== 'all') $bands[$band] = true;
            }
        }
    }
    $order = array_flip(BAND_ORDER);
    $bands = array_keys($bands);
    usort($bands, fn($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
    return $bands;
}
