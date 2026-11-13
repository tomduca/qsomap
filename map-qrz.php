<?php
/**
 * Static 600x600 map for embedding in a QRZ.com profile (no JavaScript):
 * points and great-circle paths as HTML/SVG over the station's background.
 * It shows the view chosen for the station (map.static_mode, SSB by default)
 * and links to that interactive map. The station comes from the query string
 * (map-qrz.php?LU2MET), or is default_station.
 */

require __DIR__ . '/scripts/static_map.php';
$station = usePublicStation();

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$map = staticMapData();
$qth = $map['qth'];
$title = trim($map['call'] . ' ' . $map['name']);

// Unique URL per response so QRZ never reuses a stale background.
$backgroundUrl = 'background.php?' . $station . '&v=' . rawurlencode((string)microtime(true));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=600">
<title><?= esc($title) ?> map</title>
<style>
html, body { margin: 0; padding: 0; background: #d9e2e5; }
.qrz-map { position: relative; display: block; width: 600px; height: 600px; overflow: hidden; font: 14px Arial, sans-serif; text-decoration: none; background: url('<?= esc($backgroundUrl) ?>') center / 600px 600px no-repeat; }
.qrz-map-lines { position: absolute; inset: 0; width: 600px; height: 600px; }
.qrz-map-point { position: absolute; width: 4px; height: 4px; margin: -2px 0 0 -2px; border: 1px solid #fff; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,.4); }
.qrz-map-qth { background: #f3c969; border-color: #18343a; width: 6px; height: 6px; margin: -3px 0 0 -3px; }
.qrz-map-label { position: absolute; left: 10px; top: 10px; padding: 7px 10px; color: #18343a; background: rgba(255,255,255,.92); border-radius: 4px; font-weight: bold; }
.qrz-map-count { font-weight: normal; }
</style>
</head>
<body>
<a href="<?= esc($map['page'] . '?' . $station) ?>" target="_blank" rel="noopener noreferrer" class="qrz-map" aria-label="Open the interactive map">
    <svg class="qrz-map-lines" viewBox="0 0 600 600" aria-hidden="true">
<?php foreach ($map['groups'] as $group): ?>
        <polyline points="<?= pathPoints($qth, $group['position']) ?>" fill="none" stroke="<?= $group['color'] ?>" stroke-width="0.8" stroke-opacity=".8" />
<?php endforeach; ?>
    </svg>
    <div class="qrz-map-point qrz-map-qth" style="left:<?= round(mapX($qth[1], $qth[1]), 1) ?>px;top:<?= round(mapY($qth[0]), 1) ?>px" title="<?= esc($map['call'] . ' ' . $map['grid']) ?>"></div>
<?php foreach ($map['groups'] as $group): ?>
    <div class="qrz-map-point" style="left:<?= round(mapX($group['position'][1], $qth[1]), 1) ?>px;top:<?= round(mapY($group['position'][0]), 1) ?>px;background:<?= $group['color'] ?>" title="<?= esc($group['label']) ?>"></div>
<?php endforeach; ?>
    <div class="qrz-map-label"><?= esc($title) ?> <span class="qrz-map-count"><?= locationCount(count($map['groups']), $map['view']) ?></span></div>
</a>
</body>
</html>
