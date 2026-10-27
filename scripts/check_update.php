<?php
/**
 * Compare the installed version (VERSION) with the latest version tag on
 * GitHub and write data/update.json, which the maps read to show the owner
 * an update banner. sync_daily.sh runs it once a day; a failed check keeps
 * the previous data/update.json.
 */

require __DIR__ . '/common.php';
requireCliOrToken();

const REPOSITORY = 'tomduca/qsomap';

echo "=== Update check ===\n";

$current = trim((string)@file_get_contents(QSOMAP_ROOT . '/VERSION'));
if ($current === '') {
    fail('cannot read VERSION');
}

$body = httpRequest('https://api.github.com/repos/' . REPOSITORY . '/tags?per_page=100', null, 30);
$tags = $body === null ? null : json_decode($body, true);
if (!is_array($tags)) {
    fail('cannot read tags from GitHub');
}

// Version tags look like v1.2.0; anything else (e.g. test tags) is ignored.
$latest = $current;
foreach ($tags as $tag) {
    $version = ltrim((string)($tag['name'] ?? ''), 'v');
    if (preg_match('/^\d+(\.\d+)*$/', $version) && version_compare($version, $latest, '>')) {
        $latest = $version;
    }
}

$available = version_compare($latest, $current, '>');
writeJson(QSOMAP_DATA . '/update.json', [
    'current' => $current,
    'latest' => $latest,
    'available' => $available,
    'url' => 'https://github.com/' . REPOSITORY . ($available ? '/releases/tag/v' . $latest : ''),
    'checked' => gmdate('c'),
]);

echo $available ? "New version available: v$latest (installed: v$current)\n" : "Up to date (v$current)\n";
