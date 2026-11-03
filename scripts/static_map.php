<?php
/**
 * Data and projection shared by the static maps: map-qrz.php (HTML + SVG for
 * a QRZ.com iframe) and map-github.php (SVG image for a GitHub README).
 *
 * Points and great-circle paths are projected onto the background generated
 * by scripts/make_background.php: longitude centred on the QTH, latitude in
 * Web Mercator (clamped to +/-85.05). The map shows the station's chosen view
 * (map.static_mode: SSB, CW, RTTY, FT8/FT4 or confirmed QSOs).
 */

require_once __DIR__ . '/common.php';

const MAP_SIZE = 600;
const BAND_COLORS = ['10M' => '#e45756', '15M' => '#f28e2b', '20M' => '#59a14f', '40M' => '#4e79a7', '80M' => '#7b61a8'];
const DEFAULT_COLOR = '#68747d';

/**
 * What a static map draws for the current station: callsign, grid, QTH, view
 * (key, name and interactive page) and one point per callsign + grid + band.
 */
function staticMapData(): array {
    $cache = readJson(stationData('qso_cache.json')) ?? [];
    // Raw config: only map settings are needed, so passwords stay encrypted.
    $config = readJson(stationDir() . '/config.json') ?? [];
    $station = $cache['station'] ?? ($config['map'] ?? []);
    $call = (string)($station['callsign'] ?? $station['my_callsign'] ?? '');
    $grid = strtoupper((string)($station['grid'] ?? $station['my_grid'] ?? ''));
    $qth = gridCentre($grid) ?? [0.0, 0.0];
    $view = staticView($config);

    $groups = [];
    foreach ($cache['qsos'] ?? [] as $qso) {
        if (!qsoInView($qso, $view)) continue;
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

/** "1 location", "25 locations". */
function locationCount(int $count): string {
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
