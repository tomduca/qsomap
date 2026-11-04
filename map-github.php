<?php
/**
 * Static 600x600 map served as a self-contained SVG image, for embedding in a
 * GitHub README (GitHub strips iframes, but renders remote images).
 *
 * Same drawing as map-qrz.php, but everything lives inside one SVG: the
 * background is inlined as a data: URI, because browsers do not load external
 * resources from an SVG shown through <img>. GitHub proxies images through its
 * camo cache, so the response carries an ETag that changes with every sync
 * and with the station's settings (e.g. its map.static_mode). The station
 * comes from the query string (map-github.php?LU2MET), or is default_station.
 */

require __DIR__ . '/scripts/static_map.php';
$station = usePublicStation();

$backgroundPath = stationData('qrz-background.png');

// Revalidate on every request, answer 304 while no input has changed.
$inputs = [stationData('qso_cache.json'), $backgroundPath, stationDir() . '/config.json', __FILE__, __DIR__ . '/scripts/static_map.php'];
$etag = '"' . md5($station . '|' . implode('|', array_map(fn($path) => @filemtime($path), $inputs))) . '"';
header('Cache-Control: public, no-cache, max-age=0');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: image/svg+xml; charset=utf-8');

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$map = staticMapData();
$qth = $map['qth'];
$heading = trim($map['call'] . ' ' . $map['name']);
$count = locationCount(count($map['groups']));
$title = "$heading map: $count";
$labelWidth = 24 + 8.5 * strlen($heading) + 7 * strlen($count);
$background = is_file($backgroundPath) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($backgroundPath)) : null;

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="600" height="600" viewBox="0 0 600 600" role="img" aria-label="<?= esc($title) ?>">
<title><?= esc($title) ?></title>
<rect width="600" height="600" fill="#d9e2e5"/>
<?php if ($background !== null): ?>
<image width="600" height="600" href="<?= $background ?>" xlink:href="<?= $background ?>"/>
<?php endif; ?>
<g fill="none" stroke-width="0.8" stroke-opacity=".8">
<?php foreach ($map['groups'] as $group): ?>
<polyline points="<?= pathPoints($qth, $group['position']) ?>" stroke="<?= $group['color'] ?>"/>
<?php endforeach; ?>
</g>
<g stroke="#fff" stroke-width="1">
<?php foreach ($map['groups'] as $group): ?>
<circle cx="<?= round(mapX($group['position'][1], $qth[1]), 1) ?>" cy="<?= round(mapY($group['position'][0]), 1) ?>" r="2.5" fill="<?= $group['color'] ?>"><title><?= esc($group['label']) ?></title></circle>
<?php endforeach; ?>
</g>
<circle cx="<?= round(mapX($qth[1], $qth[1]), 1) ?>" cy="<?= round(mapY($qth[0]), 1) ?>" r="3.5" fill="#f3c969" stroke="#18343a" stroke-width="1"><title><?= esc($map['call'] . ' ' . $map['grid']) ?></title></circle>
<rect x="10" y="10" width="<?= round($labelWidth) ?>" height="31" rx="4" fill="#fff" fill-opacity=".92"/>
<text x="20" y="31" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#18343a"><tspan font-weight="bold"><?= esc($heading) ?></tspan> <?= esc($count) ?></text>
</svg>
