<?php
/**
 * Data and projection shared by the static maps: map-qrz.php (HTML + SVG for
 * a QRZ.com iframe) and map-github.php (SVG image for a GitHub README).
 *
 * Points and great-circle paths are projected onto the background generated
 * by scripts/make_background.php: longitude centred on the QTH, latitude in
 * Web Mercator (clamped to +/-85.05). The map shows the station's chosen view
 * (map.static_mode: SSB, CW, RTTY, FT8/FT4, confirmed QSOs or confirmed DXCC
 * entities).
 */

require_once __DIR__ . '/common.php';

const MAP_SIZE = 600;
const BAND_COLORS = ['10M' => '#e45756', '15M' => '#f28e2b', '20M' => '#59a14f', '40M' => '#4e79a7', '80M' => '#7b61a8'];
const DEFAULT_COLOR = '#68747d';
const DXCC_COLOR = '#087f8c'; // same as the interactive DXCC map

/**
 * What a static map draws for the current station: callsign, grid, QTH, view
 * (key, name and interactive page) and one point per callsign + grid + band.
 */
function staticMapData(): array {
    raiseMemoryLimit(); // the cache of a big log is several MB of JSON
    $cache = readJson(stationData('qso_cache.json')) ?? [];
    // Raw config: only map settings are needed, so passwords stay encrypted.
    $config = readJson(stationDir() . '/config.json') ?? [];
    $station = $cache['station'] ?? ($config['map'] ?? []);
    $call = (string)($station['callsign'] ?? $station['my_callsign'] ?? '');
    $grid = strtoupper((string)($station['grid'] ?? $station['my_grid'] ?? ''));
    $qth = gridCentre($grid) ?? [0.0, 0.0];
    $view = staticView($config);

    $groups = [];
    if ($view === 'dxcc') {
        // One point per confirmed entity, at its cty.csv position or else the
        // grid of its first confirmed QSO (see build_cache.php).
        $names = readJson(QSOMAP_DATA . '/dxcc.json') ?? [];
        foreach ($cache['entities'] ?? [] as $entity) {
            $position = is_array($entity['pos'] ?? null) ? $entity['pos'] : gridCentre((string)($entity['first']['grid'] ?? ''));
            if ($position === null) continue;
            $groups[] = [
                'label' => ($names[$entity['dxcc']] ?? 'DXCC') . ' (' . $entity['dxcc'] . ')',
                'position' => [(float)$position[0], shortestLongitude((float)$position[1], $qth[1])],
                'color' => DXCC_COLOR,
            ];
        }
    }
    // Big logs come grouped by grid square (see build_cache.php): one point per
    // square, in the color of its band with most QSOs.
    $bySquare = [];
    foreach ($cache['groups'] ?? [] as $group) {
        $count = $view === 'qsl' ? (int)$group['q'] : ($group['class'] === $view ? (int)$group['n'] : 0);
        if ($count > 0) $bySquare[$group['grid']][$group['band']] = ($bySquare[$group['grid']][$group['band']] ?? 0) + $count;
    }
    foreach ($bySquare as $square => $bands) {
        $position = gridCentre($square);
        if ($position === null) continue;
        arsort($bands);
        $groups[] = [
            'label' => $square . ' ' . array_sum($bands) . ' QSOs',
            'position' => [$position[0], shortestLongitude($position[1], $qth[1])],
            'color' => BAND_COLORS[array_key_first($bands)] ?? DEFAULT_COLOR,
        ];
    }
    foreach ($cache['qsos'] ?? [] as $qso) {
        if ($view === 'dxcc' || !qsoInView($qso, $view)) continue;
        $position = gridCentre((string)($qso['grid'] ?? ''));
        if ($position === null) continue;
        $band = strtoupper((string)($qso['band'] ?? ''));
        $key = ($qso['call'] ?? '') . '|' . strtoupper($qso['grid']) . '|' . $band;
        $groups[$key] ??= [
            'label' => trim(($qso['call'] ?? '') . ' ' . $qso['grid'] . ' ' . $band),
            'position' => [$position[0], shortestLongitude($position[1], $qth[1])],
            'color' => BAND_COLORS[$band] ?? DEFAULT_COLOR,
        ];
    }

    return [
        'call' => $call,
        'grid' => $grid,
        'qth' => $qth,
        'view' => $view,
        'name' => MAP_VIEWS[$view]['name'],
        'page' => MAP_VIEWS[$view]['page'],
        'groups' => array_values($groups),
    ];
}

/** "1 location", "25 locations"; on the DXCC view "1 entity", "25 entities". */
function locationCount(int $count, string $view = ''): string {
    if ($view === 'dxcc') return $count . ($count === 1 ? ' entity' : ' entities');
    return $count . ($count === 1 ? ' location' : ' locations');
}

function shortestLongitude(float $longitude, float $origin): float {
    return $origin + fmod($longitude - $origin + 540, 360) - 180;
}

function greatCirclePoints(array $start, array $end, int $count = 48): array {
    $toRadians = M_PI / 180;
    $a = [cos($start[0] * $toRadians) * cos($start[1] * $toRadians), cos($start[0] * $toRadians) * sin($start[1] * $toRadians), sin($start[0] * $toRadians)];
    $b = [cos($end[0] * $toRadians) * cos($end[1] * $toRadians), cos($end[0] * $toRadians) * sin($end[1] * $toRadians), sin($end[0] * $toRadians)];
    $angle = acos(max(-1, min(1, $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2])));
    if ($angle < 0.000001) return [$start, $end];
    $sinAngle = sin($angle);
    $points = [];
    $previousLon = $start[1];
    for ($index = 0; $index <= $count; $index++) {
        $fraction = $index / $count;
        $w1 = sin((1 - $fraction) * $angle) / $sinAngle;
        $w2 = sin($fraction * $angle) / $sinAngle;
        $x = $w1 * $a[0] + $w2 * $b[0];
        $y = $w1 * $a[1] + $w2 * $b[1];
        $z = $w1 * $a[2] + $w2 * $b[2];
        $previousLon = shortestLongitude(atan2($y, $x) / $toRadians, $previousLon);
        $points[] = [atan2($z, sqrt($x * $x + $y * $y)) / $toRadians, $previousLon];
    }
    return $points;
}

function mapX(float $longitude, float $qthLongitude): float {
    return (normalizeLongitude($longitude - $qthLongitude) + 180.0) / 360.0 * MAP_SIZE;
}

function mapY(float $latitude): float {
    $limit = 85.0511287798;
    $lat = deg2rad(max(-$limit, min($limit, $latitude)));
    return (0.5 - log(tan(M_PI / 4 + $lat / 2)) / (2 * M_PI)) * MAP_SIZE;
}

/** SVG polyline points of the great-circle path from the QTH to $position. */
function pathPoints(array $qth, array $position): string {
    return implode(' ', array_map(fn($point) => round(mapX($point[1], $qth[1]), 1) . ',' . round(mapY($point[0]), 1),
        greatCirclePoints($qth, $position)));
}
