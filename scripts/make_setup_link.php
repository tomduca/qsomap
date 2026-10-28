<?php
/**
 * Create a one-time link to setup.php, so a station hosted on someone else's
 * server can enter its own credentials without sending them to the host.
 *
 * Only a hash of the key is stored (data/setup_link.json); the form stops
 * working once used, when it expires, or when a new one is created. After the
 * form is saved the same key runs the first sync for two more hours.
 *
 *   php scripts/make_setup_link.php [hours]        (default 48, max 168)
 *   scripts/make_setup_link.php?token=YOUR_TOKEN&hours=48
 */

require __DIR__ . '/common.php';
requireCliOrToken();

$hours = (int)(PHP_SAPI === 'cli' ? ($argv[1] ?? 48) : ($_GET['hours'] ?? 48));
if ($hours < 1 || $hours > 168) {
    fail('hours must be between 1 and 168');
}

$key = bin2hex(random_bytes(32));
$expires = time() + $hours * 3600;
writeJson(SETUP_LINK_FILE, ['hash' => hash('sha256', $key), 'expires' => $expires, 'used' => false]);

$path = 'scripts/setup.php?key=' . $key;
if (PHP_SAPI !== 'cli') {
    // This script lives in scripts/, so the instance root is one level up.
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $path = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . $base . '/' . $path;
}

echo "=== Setup link ===\n";
echo "$path\n";
echo 'Valid until ' . gmdate('Y-m-d H:i', $expires) . " UTC, for one use.\n";
echo "Send it to the station owner; any previous link no longer works.\n";
