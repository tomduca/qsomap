<?php
/**
 * Genera un fondo cuadrado 1:1 centrado en la grilla del QTH.
 * Mantiene el mundo completo y lo desplaza de tal forma que el QTH queda
 * centrado horizontalmente, sin recortar ni deformar el planisferio.
 */

const MAP_WIDTH = 900;
const MAP_HEIGHT = 900;

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$qthGrid = strtoupper((string)($config['map']['my_grid'] ?? 'FF57oc'));
$qthCall = (string)($config['map']['my_callsign'] ?? 'LU2MET');

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
$sourceUrl = 'https://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/export?'
    . http_build_query([
        'bbox' => '-180,-90,180,90',
        'bboxSR' => 4326,
        'size' => MAP_WIDTH . ',' . MAP_HEIGHT,
        'adjustAspectRatio' => 'false',
        'format' => 'png',
        'f' => 'image',
    ]);

$sourceData = @file_get_contents($sourceUrl);
if ($sourceData === false) {
    die('Error al descargar el mapa base de ArcGIS.' . PHP_EOL);
}

$sourceImage = imagecreatefromstring($sourceData);
if ($sourceImage === false) {
    die('No se pudo crear la imagen a partir del mapa base.' . PHP_EOL);
}

$sourceWidth = imagesx($sourceImage);
$sourceHeight = imagesy($sourceImage);
$sourceHeightScaled = MAP_HEIGHT;
$sourceWidthScaled = (int)round(($sourceWidth / $sourceHeight) * $sourceHeightScaled);
$baseImage = imagecreatetruecolor($sourceWidthScaled, $sourceHeightScaled);
imagecopyresampled($baseImage, $sourceImage, 0, 0, 0, 0, $sourceWidthScaled, $sourceHeightScaled, $sourceWidth, $sourceHeight);

$wideImage = imagecreatetruecolor($sourceWidthScaled * 3, $sourceHeightScaled);
imagecopy($wideImage, $baseImage, 0, 0, 0, 0, $sourceWidthScaled, $sourceHeightScaled);
imagecopy($wideImage, $baseImage, $sourceWidthScaled, 0, 0, 0, $sourceWidthScaled, $sourceHeightScaled);
imagecopy($wideImage, $baseImage, $sourceWidthScaled * 2, 0, 0, 0, $sourceWidthScaled, $sourceHeightScaled);

$finalImage = imagecreatetruecolor(MAP_WIDTH, MAP_HEIGHT);
$centerX = MAP_WIDTH / 2;

for ($x = 0; $x < MAP_WIDTH; $x++) {
    $offsetFromCenter = $x - $centerX;
    $longitude = $qthLon + (($offsetFromCenter / MAP_WIDTH) * 360.0);
    $normalizedLon = normalizeLongitude($longitude);
    $srcX = (($normalizedLon + 180.0) / 360.0) * $sourceWidthScaled;
    $srcX = (int)round($srcX);
    $wideX = $srcX + $sourceWidthScaled;
    imagecopy($finalImage, $wideImage, $x, 0, $wideX, 0, 1, MAP_HEIGHT);
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
echo 'QTH call: ' . $qthCall . PHP_EOL;
echo 'QTH grid: ' . $qthGrid . ' lon=' . $qthLon . PHP_EOL;
