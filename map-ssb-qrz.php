<?php
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Static SSB map using a pre-generated background image stored on the hosting.
const MAP_WIDTH = 600;
const MAP_HEIGHT = 600;
//const MAP_LAT_MIN = -90;
//const MAP_LAT_MAX = 90;

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$qthCall = (string)($config['map']['my_callsign'] ?? 'LU2MET');
$qthGrid = strtoupper((string)($config['map']['my_grid'] ?? 'FF57oc'));

$cacheFile = __DIR__ . '/data/qso_cache.json';
$cache = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;
$qsos = is_array($cache['qsos'] ?? null) ? $cache['qsos'] : [];
$phoneModes = ['SSB', 'USB', 'LSB', 'FM', 'AM', 'CW'];
$bandColors = [
    '10M' => '#e45756',
    '15M' => '#f28e2b',
    '20M' => '#59a14f',
    '40M' => '#4e79a7',
    '80M' => '#7b61a8'
];

function gridCentre(string $grid): ?array {
    $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($grid)));
    if (strlen($value) < 4 || strlen($value) % 2 !== 0) return null;
    $lat = 0.0;
    $lon = 0.0;
    $latSize = 10.0;
    $lonSize = 20.0;
    $pairs = strlen($value) / 2;
    for ($index = 0; $index < $pairs; $index++) {
        $first = $value[$index * 2];
        $second = $value[$index * 2 + 1];
        $letters = $index % 2 === 0;
        $lonIndex = $letters ? ord($first) - 65 : intval($first);
        $latIndex = $letters ? ord($second) - 65 : intval($second);
        $maxIndex = $letters ? ($index === 0 ? 17 : 23) : 9;
        if ($lonIndex < 0 || $latIndex < 0 || $lonIndex > $maxIndex || $latIndex > $maxIndex) return null;
        $lon += $lonIndex * $lonSize;
        $lat += $latIndex * $latSize;
        if ($index < $pairs - 1) {
            if ($letters) {
                $lonSize /= 10;
                $latSize /= 10;
            } else {
                $lonSize /= 24;
                $latSize /= 24;
            }
        }
    }
    return [$lat - 90 + $latSize / 2, $lon - 180 + $lonSize / 2];
}

function normalizeLongitude(float $longitude): float {
    $value = fmod($longitude + 180.0, 360.0);
    if ($value < 0.0) {
        $value += 360.0;
    }
    return $value - 180.0;
}

function shortestLongitude(float $longitude, float $origin): float {
    return $origin + fmod($longitude - $origin + 540, 360) - 180;
}

function greatCirclePoints(array $start, array $end, int $count = 48): array {
    $toRadians = M_PI / 180;
    $aLat = $start[0] * $toRadians;
    $aLon = $start[1] * $toRadians;
    $bLat = $end[0] * $toRadians;
    $bLon = $end[1] * $toRadians;
    $a = [cos($aLat) * cos($aLon), cos($aLat) * sin($aLon), sin($aLat)];
    $b = [cos($bLat) * cos($bLon), cos($bLat) * sin($bLon), sin($bLat)];
    $dot = max(-1, min(1, $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]));
    $angle = acos($dot);
    if ($angle < 0.000001) return [$start, $end];
    $sinAngle = sin($angle);
    $points = [];
    $previousLon = $start[1];
    for ($index = 0; $index <= $count; $index++) {
        $fraction = $index / $count;
        $firstWeight = sin((1 - $fraction) * $angle) / $sinAngle;
        $secondWeight = sin($fraction * $angle) / $sinAngle;
        $x = $firstWeight * $a[0] + $secondWeight * $b[0];
        $y = $firstWeight * $a[1] + $secondWeight * $b[1];
        $z = $firstWeight * $a[2] + $secondWeight * $b[2];
        $lon = atan2($y, $x) / $toRadians;
        $lon = shortestLongitude($lon, $previousLon);
        $previousLon = $lon;
        $points[] = [atan2($z, sqrt($x * $x + $y * $y)) / $toRadians, $lon];
    }
    return $points;
}

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$qth = gridCentre($qthGrid) ?: [-34.0, -58.0];
$groups = [];
foreach ($qsos as $qso) {
    $mode = strtoupper((string)($qso['mode'] ?? ''));
    if (!in_array($mode, $phoneModes, true) || empty($qso['grid'])) continue;
    $position = gridCentre((string)$qso['grid']);
    if ($position === null) continue;
    $position[1] = shortestLongitude($position[1], $qth[1]);
    $band = strtoupper((string)($qso['band'] ?? ''));
    $key = (string)($qso['call'] ?? '') . '|' . strtoupper((string)$qso['grid']) . '|' . $band;
    if (!isset($groups[$key])) $groups[$key] = ['qso' => $qso, 'position' => $position, 'band' => $band];
}

// IMPORTANT: this map uses the square 1:1 world projection to match the generated
// background image exactly.
$cacheBuster = rawurlencode((string)microtime(true));
$backgroundUrl = 'background_lu2met_1x1.png?v=' . $cacheBuster;

function mapX(float $longitude): float {
    global $qth;
    $relativeLongitude = normalizeLongitude($longitude - $qth[1]);
    return (($relativeLongitude + 180.0) / 360.0) * MAP_WIDTH;
}

function mapY(float $latitude): float {
    $R = 6378137.0;                    // radio esférico de Web Mercator
    $halfExtent = M_PI * $R;           // 20037508.34 m: mitad del extent cuadrado
    $limit = 85.0511287798;            // límite de Web Mercator
    $lat = max(-$limit, min($limit, $latitude));
    $y = $R * log(tan(M_PI / 4 + deg2rad($lat) / 2));
    return (0.5 - $y / (2 * $halfExtent)) * MAP_HEIGHT;
}?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=1200">
<title><?php echo esc($qthCall); ?> SSB map</title>
<style>
html, body { margin: 0; padding: 0; background: #d9e2e5; }
.qrz-map-link { display: block; width: 600px; height: 600px; text-decoration: none; cursor: pointer; position: relative; }
.qrz-map { position: relative; width: 600px; height: 600px; overflow: hidden; font: 14px Arial, sans-serif; }
.qrz-map-background { position: absolute; inset: 0; width: 600px; height: 600px; background-image: url('<?php echo htmlspecialchars($backgroundUrl, ENT_QUOTES, 'UTF-8'); ?>'); background-size: 600px 600px; background-position: center center; background-repeat: no-repeat; }
.qrz-map-lines { position: absolute; inset: 0; width: 600px; height: 600px; pointer-events: none; z-index: 2; }
.qrz-map-points { position: absolute; inset: 0; width: 600px; height: 600px; z-index: 3; pointer-events: none; }
.qrz-map-point { position: absolute; width: 4px; height: 4px; margin: -2px 0 0 -2px; border: 1px solid #fff; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,.4); }
.qrz-map-qth { background: #f3c969; border-color: #18343a; width: 6px; height: 6px; margin: -3px 0 0 -3px; }
.qrz-map-label { position: absolute; left: 10px; top: 10px; padding: 7px 10px; color: #18343a; background: rgba(255,255,255,.92); border-radius: 4px; font-weight: bold; z-index: 10; }
.qrz-map-count { font-weight: normal; }
.qrz-map-hit { position: absolute; inset: 0; display: block; z-index: 20; }
</style>
</head>
<body>
<a href="https://lu2met.ar/qsomap/map-ssb.html" target="_blank" rel="noopener noreferrer" class="qrz-map-link" aria-label="Open map details">
    <div class="qrz-map">
        <div class="qrz-map-background" role="img" aria-label="World map"></div>
        <svg class="qrz-map-lines" viewBox="0 0 600 600" aria-hidden="true">
<?php foreach ($groups as $group):
    $points = greatCirclePoints($qth, $group['position']);
    $path = [];
    foreach ($points as $point) $path[] = round(mapX($point[1]), 1) . ',' . round(mapY($point[0]), 1);
    $color = $bandColors[$group['band']] ?? '#68747d';
?>
            <polyline points="<?php echo esc(implode(' ', $path)); ?>" fill="none" stroke="<?php echo esc($color); ?>" stroke-width="0.8" stroke-opacity=".8" />
<?php endforeach; ?>
        </svg>
        <div class="qrz-map-points">
            <div class="qrz-map-point qrz-map-qth" style="left:<?php echo round(mapX($qth[1]), 1); ?>px;top:<?php echo round(mapY($qth[0]), 1); ?>px" title="<?php echo esc($qthCall . ' ' . $qthGrid); ?>"></div>
<?php foreach ($groups as $group):
    $qso = $group['qso'];
    $color = $bandColors[$group['band']] ?? '#68747d';
    $label = ($qso['call'] ?? '') . ' ' . ($qso['grid'] ?? '') . ' ' . $group['band'];
?>
            <div class="qrz-map-point" style="left:<?php echo round(mapX($group['position'][1]), 1); ?>px;top:<?php echo round(mapY($group['position'][0]), 1); ?>px;background:<?php echo esc($color); ?>" title="<?php echo esc(trim($label)); ?>"></div>
<?php endforeach; ?>
        </div>
        <div class="qrz-map-label"><?php echo esc($qthCall); ?> SSB <span class="qrz-map-count"><?php echo count($groups); ?> locations</span></div>
    </div>
</a>
</body>
</html>