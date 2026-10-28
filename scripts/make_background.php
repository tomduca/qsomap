<?php
/**
 * Generate a station's qrz-background.png (in stations/<CALL>/data/), the
 * 600x600 world map behind map-ssb-qrz.php and map-ssb-github.php.
 *
 * The base planisphere (img/world-map-600.png, Web Mercator, centred on
 * Greenwich) is shifted horizontally so the station's QTH ends up in the
 * centre. Run it once at install time and again only if map.my_grid changes.
 * Requires the PHP GD extension.
 */

require __DIR__ . '/common.php';
useStation(requireCliOrToken(true));

const MAP_SIZE = 600;

$grid = (string)(loadConfig()['map']['my_grid'] ?? '');
$qth = gridCentre($grid);
if ($qth === null) {
    fail("invalid or missing map.my_grid in config.json: '$grid'");
}

$sourcePath = QSOMAP_ROOT . '/img/world-map-600.png';
$source = @imagecreatefrompng($sourcePath);
if ($source === false) {
    fail("cannot read base map $sourcePath");
}
$sourceWidth = imagesx($source);
$sourceHeight = imagesy($source);

// Copy the base map column by column, wrapping around the antimeridian.
$output = imagecreatetruecolor(MAP_SIZE, MAP_SIZE);
for ($x = 0; $x < MAP_SIZE; $x++) {
    $longitude = normalizeLongitude($qth[1] + ($x - MAP_SIZE / 2) / MAP_SIZE * 360.0);
    $sourceX = (int)round(($longitude + 180.0) / 360.0 * $sourceWidth) % $sourceWidth;
    imagecopyresampled($output, $source, $x, 0, $sourceX, 0, 1, MAP_SIZE, 1, $sourceHeight);
}

$outputPath = stationData('qrz-background.png');
if (!is_dir(dirname($outputPath))) mkdir(dirname($outputPath), 0755, true);
if (!imagepng($output, $outputPath)) {
    fail("cannot write $outputPath");
}

echo "Generated $outputPath (QTH $grid, lon " . round($qth[1], 2) . ")\n";
