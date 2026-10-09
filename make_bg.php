<?php
/**
 * Genera un fondo estático del mundo centrado en el grid del QTH.
 * Mantiene continuidad total a través del meridiano antimeridiano y
 * produce un archivo listo para usar en el iframe QRZ.
 */

const MAP_WIDTH = 1200;
const MAP_HEIGHT = 600;
const QTH_GRID = 'FF57oc';

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

$qth = gridCentre(QTH_GRID);
if ($qth === null) {
    die('Grid QTH inválido: ' . QTH_GRID . PHP_EOL);
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

$outputPath = __DIR__ . '/background_lu2met.png';
if (!imagepng($finalImage, $outputPath)) {
    imagedestroy($sourceImage);
    imagedestroy($wideImage);
    imagedestroy($finalImage);
    die('No se pudo escribir ' . $outputPath . PHP_EOL);
}

imagedestroy($sourceImage);
imagedestroy($baseImage);
imagedestroy($wideImage);
imagedestroy($finalImage);

echo 'Mapa estático generado correctamente: ' . $outputPath . PHP_EOL;
echo 'QTH: ' . QTH_GRID . ' => lon=' . $qthLon . '°' . PHP_EOL;