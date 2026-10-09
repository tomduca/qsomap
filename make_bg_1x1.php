<?php
/**
 * Variant: fixed 600x600 canvas, QTH centered horizontally.
 * The base planisphere comes from the local PNG file map600x600.png and is
 * repeated in a seamless 3x strip so the world wraps continuously without white
 * gaps while keeping the canvas at 600x600.
 */

const MAP_WIDTH = 600;
const MAP_HEIGHT = 600;
const MAP_LAT_MIN = -90;
const MAP_LAT_MAX = 90;

$configPath = __DIR__ . '/config.json';
$configData = @file_get_contents($configPath);
$config = $configData === false ? null : json_decode($configData, true);
$qthGrid = null;

if (is_array($config)) {
    $qthGrid = $config['QTH_GRID'] ?? $config['qth_grid'] ?? $config['grid'] ?? null;
    if (!is_string($qthGrid) || $qthGrid === '') {
        $mapConfig = $config['map'] ?? null;
        if (is_array($mapConfig)) {
            $qthGrid = $mapConfig['QTH_GRID'] ?? $mapConfig['qth_grid'] ?? $mapConfig['my_grid'] ?? $mapConfig['grid'] ?? null;
        }
    }
}

if (!is_string($qthGrid) || $qthGrid === '') {
    die('No se encontró QTH_GRID en ' . $configPath . PHP_EOL);
}

function gridCentre(string $grid): ?array {
    $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($grid)));
    if (strlen($value) < 4 || strlen($value) % 2 !== 0) {
        return null;
    }

    $lat = 0.0;
    $lon = 0.0;
    $latSize = 10.0;
    $lonSize = 20.0;
    $pairs = strlen($value) / 2;

    for ($index = 0; $index < $pairs; $index++) {
        $first = $value[$index * 2];
        $second = $value[$index * 2 + 1];
        $letters = ($index % 2 === 0);

        $lonIndex = $letters ? (ord($first) - 65) : (int)$first;
        $latIndex = $letters ? (ord($second) - 65) : (int)$second;
        $maxIndex = $letters ? (($index === 0) ? 17 : 23) : 9;

        if ($lonIndex < 0 || $latIndex < 0 || $lonIndex > $maxIndex || $latIndex > $maxIndex) {
            return null;
        }

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

    return [$lat - 90 + ($latSize / 2), $lon - 180 + ($lonSize / 2)];
}

function normalizeLongitude(float $longitude): float {
    $value = fmod($longitude + 180.0, 360.0);
    if ($value < 0.0) {
        $value += 360.0;
    }
    return $value - 180.0;
}

$qth = gridCentre($qthGrid);
if ($qth === null) {
    die('Grid QTH inválido: ' . $qthGrid . PHP_EOL);
}

$qthLon = (float)$qth[1];
$sourcePath = __DIR__ . '/map600x600.png';
if (!is_file($sourcePath)) {
    die('No se encontró el mapa base local: ' . $sourcePath . PHP_EOL);
}

$sourceData = @file_get_contents($sourcePath);
if ($sourceData === false) {
    die('No se pudo leer el mapa base local: ' . $sourcePath . PHP_EOL);
}

$sourceImage = @imagecreatefromstring($sourceData);
if ($sourceImage === false) {
    die('No se pudo crear la imagen desde ' . $sourcePath . PHP_EOL);
}

$sourceWidth = imagesx($sourceImage);
$sourceHeight = imagesy($sourceImage);
$baseHeight = $sourceHeight;
$baseWidth = $sourceWidth;
$baseImage = imagecreatetruecolor($baseWidth, $baseHeight);
imagealphablending($baseImage, true);
imagesavealpha($baseImage, true);
imagecopyresampled($baseImage, $sourceImage, 0, 0, 0, 0, $baseWidth, $baseHeight, $sourceWidth, $sourceHeight);

$wideImage = imagecreatetruecolor($baseWidth * 3, $baseHeight);
imagecopy($wideImage, $baseImage, 0, 0, 0, 0, $baseWidth, $baseHeight);
imagecopy($wideImage, $baseImage, $baseWidth, 0, 0, 0, $baseWidth, $baseHeight);
imagecopy($wideImage, $baseImage, $baseWidth * 2, 0, 0, 0, $baseWidth, $baseHeight);

$finalImage = imagecreatetruecolor(MAP_WIDTH, MAP_HEIGHT);
$centerX = MAP_WIDTH / 2;

for ($x = 0; $x < MAP_WIDTH; $x++) {
    $offsetFromCenter = $x - $centerX;
    $longitude = $qthLon + (($offsetFromCenter / MAP_WIDTH) * 360.0);
    $normalizedLon = normalizeLongitude($longitude);
    $srcX = (($normalizedLon + 180.0) / 360.0) * $baseWidth;
    $srcX = (int)round($srcX);
    $wideX = $srcX + $baseWidth;
    imagecopy($finalImage, $wideImage, $x, 0, $wideX, 0, 1, MAP_HEIGHT);
}

$legacyPath = __DIR__ . '/background_1x1.png';
if (is_file($legacyPath)) {
    @unlink($legacyPath);
}

$outputPath = __DIR__ . '/background_lu2met_1x1.png';
if (!imagepng($finalImage, $outputPath)) {
    imagedestroy($sourceImage);
    imagedestroy($baseImage);
    imagedestroy($wideImage);
    imagedestroy($finalImage);
    die('No se pudo escribir ' . $outputPath . PHP_EOL);
}

imagedestroy($sourceImage);
imagedestroy($baseImage);
imagedestroy($wideImage);
imagedestroy($finalImage);

echo '1:1 generated: ' . $outputPath . PHP_EOL;
echo 'QTH: ' . $qthGrid . ' lon=' . $qthLon . PHP_EOL;
