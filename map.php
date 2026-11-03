<?php
/**
 * Interactive map of the view a station's static map shows (map.static_mode):
 * map.php?LU2MET redirects to map-cw.html?LU2MET, and so on. The GitHub embed
 * links here, so its link follows the mode when it changes.
 */

require __DIR__ . '/scripts/common.php';
$call = usePublicStation();
$view = staticView(readJson(stationDir() . '/config.json') ?? []);
header('Cache-Control: no-cache');
header('Location: ' . MAP_VIEWS[$view]['page'] . '?' . rawurlencode($call), true, 302);
