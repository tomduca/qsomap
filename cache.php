<?php
/**
 * A station's map data (stations/<CALL>/data/qso_cache.json) for the
 * interactive maps: cache.php?LU2MET, or default_station without a callsign.
 */

require __DIR__ . '/scripts/common.php';
usePublicStation();
serveStationFile('qso_cache.json', 'application/json; charset=utf-8');
