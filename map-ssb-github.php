<?php
/**
 * Static 600x600 SSB map served as a self-contained SVG image, for embedding
 * in a GitHub README (GitHub strips iframes, but renders remote images).
 *
 * Same drawing as map-ssb-qrz.php, but everything lives inside one SVG: the
 * background is inlined as a data: URI, because browsers do not load external
 * resources from an SVG shown through <img>. GitHub proxies images through its
 * camo cache, so the response carries an ETag that changes with every sync.
 */

require __DIR__ . '/scripts/common.php';

const MAP_SIZE = 600;
const BAND_COLORS = ['10M' => '#e45756', '15M' => '#f28e2b', '20M' => '#59a14f', '40M' => '#4e79a7', '80M' => '#7b61a8'];
const DEFAULT_COLOR = '#68747d';

$cachePath = QSOMAP_DATA . '/qso_cache.json';
$backgroundPath = QSOMAP_DATA . '/qrz-background.png';

// Revalidate on every request, answer 304 while neither input has changed.
$etag = '"' . md5(implode('|', [@filemtime($cachePath), @filemtime($backgroundPath), filemtime(__FILE__)])) . '"';
header('Cache-Control: public, no-cache, max-age=0');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: image/svg+xml; charset=utf-8');

$cache = readJson($cachePath) ?? [];
$station = $cache['station'] ?? (loadConfig(false)['map'] ?? []);
$qthCall = (string)($station['callsign'] ?? $station['my_callsign'] ?? '');
$qthGrid = strtoupper((string)($station['grid'] ?? $station['my_grid'] ?? ''));
$qth = gridCentre($qthGrid) ?? [0.0, 0.0];

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

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// One point per callsign + grid + band, phone/CW QSOs only.
$groups = [];
foreach ($cache['qsos'] ?? [] as $qso) {
    if (!in_array(strtoupper((string)($qso['mode'] ?? '')), PHONE_MODES, true)) continue;
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

$background = is_file($backgroundPath) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($backgroundPath)) : null;
$title = trim($qthCall . ' SSB') . ' map: ' . count($groups) . ' locations';
$labelWidth = 24 + 8.5 * strlen(trim($qthCall . ' SSB')) + 7 * strlen(count($groups) . ' locations');

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="600" height="600" viewBox="0 0 600 600" role="img" aria-label="<?= esc($title) ?>">
<title><?= esc($title) ?></title>
<rect width="600" height="600" fill="#d9e2e5"/>
<?php if ($background !== null): ?>
<image width="600" height="600" href="<?= $background ?>" xlink:href="<?= $background ?>"/>
<?php endif; ?>
<g fill="none" stroke-width="0.8" stroke-opacity=".8">
<?php foreach ($groups as $group):
    $path = array_map(fn($point) => round(mapX($point[1], $qth[1]), 1) . ',' . round(mapY($point[0]), 1), greatCirclePoints($qth, $group['position']));
?>
<polyline points="<?= implode(' ', $path) ?>" stroke="<?= $group['color'] ?>"/>
<?php endforeach; ?>
</g>
<g stroke="#fff" stroke-width="1">
<?php foreach ($groups as $group): ?>
<circle cx="<?= round(mapX($group['position'][1], $qth[1]), 1) ?>" cy="<?= round(mapY($group['position'][0]), 1) ?>" r="2.5" fill="<?= $group['color'] ?>"><title><?= esc($group['label']) ?></title></circle>
<?php endforeach; ?>
</g>
<circle cx="<?= round(mapX($qth[1], $qth[1]), 1) ?>" cy="<?= round(mapY($qth[0]), 1) ?>" r="3.5" fill="#f3c969" stroke="#18343a" stroke-width="1"><title><?= esc($qthCall . ' ' . $qthGrid) ?></title></circle>
<rect x="10" y="10" width="<?= round($labelWidth) ?>" height="31" rx="4" fill="#fff" fill-opacity=".92"/>
<text x="20" y="31" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#18343a"><tspan font-weight="bold"><?= esc(trim($qthCall . ' SSB')) ?></tspan> <?= count($groups) ?> locations</text>
</svg>
