<?php
/**
 * A station's static map background (stations/<CALL>/data/qrz-background.png)
 * for map-ssb-qrz.php: background.php?LU2MET, or default_station.
 */

require __DIR__ . '/scripts/common.php';
usePublicStation();
serveStationFile('qrz-background.png', 'image/png');
