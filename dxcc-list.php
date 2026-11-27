<?php
/**
 * One station's confirmed DXCC entities, newest first: for each entity the
 * earliest QSO confirmed in LoTW in any mode (date, time, worked callsign,
 * band, mode). The ranking links here from each callsign's list link.
 *
 *   dxcc-list.php?LU2MET           (also ?station=LU2MET; previous callsigns work too)
 *   &lang=es                       Spanish or English (default: set in admin/)
 *   &theme=light|dark              fixed colors (default: the device's light or dark theme)
 *
 * Public like the ranking: it answers 404 while the ranking is off, and for
 * stations out of it (opted out) or without confirmations. The data comes
 * from dxcc_counts.json ('firsts'), so the page does not read the log.
 */

require __DIR__ . '/scripts/ranking_data.php';

const LIST_TEXTS = [
    'en' => [
        'title' => '%s · confirmed DXCC entities',
        'subtitle' => 'First QSO confirmed in LoTW with each entity, any mode, newest first.',
        'count' => '%d entities',
        'position' => '#',
        'date' => 'QSO date',
        'entity' => 'DXCC entity',
        'callsign' => 'Callsign',
        'band' => 'Band',
        'mode' => 'Mode',
        'deleted_note' => '* Deleted DXCC entity.',
        'source' => 'Data from LoTW, updated every night.',
        'ranking' => 'Ranking',
        'map' => 'DXCC map',
        'unknown' => 'Unknown station.',
        'switch' => 'Ver en español',
    ],
    'es' => [
        'title' => '%s · entidades DXCC confirmadas',
        'subtitle' => 'Primer QSO confirmado en LoTW con cada entidad, en cualquier modo, de la más reciente a la más antigua.',
        'count' => '%d entidades',
        'position' => '#',
        'date' => 'Fecha QSO',
        'entity' => 'Entidad DXCC',
        'callsign' => 'Indicativo',
        'band' => 'Banda',
        'mode' => 'Modo',
        'deleted_note' => '* Entidad DXCC borrada (deleted).',
        'source' => 'Datos de LoTW, actualizados cada noche.',
        'ranking' => 'Ranking',
        'map' => 'Mapa DXCC',
        'unknown' => 'Estación desconocida.',
        'switch' => 'View in English',
    ],
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$settings = rankingSettings();
$asked = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : '';
$lang = isset(LIST_TEXTS[$asked]) ? $asked : $settings['lang'];
$text = LIST_TEXTS[$lang];

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$call = $settings['enabled'] ? requestedStation() : null;
$counts = $call !== null ? stationDxccCounts($call) : null;
if ($counts === null || empty($counts['firsts'])) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>DXCC</title><p>' . esc($text['unknown']) . '</p>';
    exit;
}

$names = dxccNames();
$deleted = array_flip(DELETED_DXCC);
$rows = [];
foreach ($counts['firsts'] as $code => $first) {
    $rows[] = $first + ['code' => (int)$code];
}
usort($rows, fn($a, $b) => [$b['date'] . $b['time'], $a['code']] <=> [$a['date'] . $a['time'], $b['code']]);
$hasDeleted = false;
foreach ($rows as $row) $hasDeleted = $hasDeleted || isset($deleted[$row['code']]);

$theme = in_array($_GET['theme'] ?? '', ['light', 'dark'], true) ? $_GET['theme'] : '';
$otherLang = $lang === 'es' ? 'en' : 'es';
$query = fn(array $extra): string => http_build_query(array_filter(['station' => $call] + $extra + ['lang' => $lang, 'theme' => $theme]));
$switchUrl = 'dxcc-list.php?' . $query(['lang' => $otherLang]);
$rankingUrl = 'ranking.php?' . http_build_query(array_filter(['lang' => $lang, 'theme' => $theme]));
$mapUrl = MAP_VIEWS['dxcc']['page'] . '?' . $call . '&pref=' . RANKING_MIXED_PREF;
$formatDate = fn(string $date): string => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
$title = sprintf($text['title'], $call);
?>
<!doctype html>
<html lang="<?= $lang ?>"<?= $theme !== '' ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?></title>
<style>
/* Same colors as ranking.php: light by default, dark when the device prefers it; ?theme= fixes one. */
:root { color-scheme: light; --text: #111; --muted: #555; --bg: #fff; --line: #ddd; --line-strong: #999; --link: #0645ad; --button: #333; --button-hover: #eee; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { color-scheme: dark; --text: #e6e8ea; --muted: #9aa4aa; --bg: #15191c; --line: #2f373c; --line-strong: #5a656c; --link: #6cc4cf; --button: #c9d0d4; --button-hover: #2c353b; }
}
:root[data-theme="dark"] { color-scheme: dark; --text: #e6e8ea; --muted: #9aa4aa; --bg: #15191c; --line: #2f373c; --line-strong: #5a656c; --link: #6cc4cf; --button: #c9d0d4; --button-hover: #2c353b; }
body { position: relative; margin: 0; padding: 12px; font: 14px/1.4 Arial, Helvetica, sans-serif; color: var(--text); background: var(--bg); }
h1 { font-size: 18px; margin: 0 0 2px; padding-right: 48px; }
p { margin: 4px 0; }
.subtitle, .note { color: var(--muted); font-size: 12px; }
.links { margin: 8px 0; }
.links a { margin-right: 12px; }
.table-wrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; max-width: 760px; }
th, td { padding: 4px 8px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
td.entity { white-space: normal; }
th { border-bottom: 2px solid var(--line-strong); }
td.position, th.position { width: 3em; text-align: right; color: var(--muted); }
a { color: var(--link); text-decoration: none; }
a:hover { text-decoration: underline; }
.lang-switch { position: absolute; top: 12px; right: 12px; padding: 1px 6px; border: 1px solid var(--line-strong); border-radius: 3px; color: var(--button); font-size: 12px; font-weight: bold; }
.lang-switch:hover { background: var(--button-hover); text-decoration: none; }
</style>
</head>
<body>
<a class="lang-switch" href="<?= esc($switchUrl) ?>" title="<?= esc($text['switch']) ?>" lang="<?= $otherLang ?>"><?= strtoupper($otherLang) ?></a>
<h1><?= esc($title) ?></h1>
<p class="subtitle"><?= esc($text['subtitle']) ?></p>
<p class="links"><strong><?= esc(sprintf($text['count'], count($rows))) ?></strong> · <a href="<?= esc($rankingUrl) ?>"><?= esc($text['ranking']) ?></a><a href="<?= esc($mapUrl) ?>" target="_blank" rel="noopener"><?= esc($text['map']) ?></a></p>
<div class="table-wrap">
<table>
    <tr><th class="position"><?= esc($text['position']) ?></th><th><?= esc($text['date']) ?></th><th><?= esc($text['entity']) ?></th><th><?= esc($text['callsign']) ?></th><th><?= esc($text['band']) ?></th><th><?= esc($text['mode']) ?></th></tr>
<?php foreach ($rows as $index => $row): ?>
<?php $entity = $names[(string)$row['code']] ?? null; ?>
    <tr><td class="position"><?= count($rows) - $index ?></td><td><?= esc($formatDate($row['date'])) ?></td><td class="entity"><?= esc(trim(($entity['flag'] ?? '') . ' ' . ($entity['name'] ?? ('DXCC ' . $row['code'])))) ?><?= isset($deleted[$row['code']]) ? ' *' : '' ?></td><td><a href="https://www.qrz.com/db/<?= str_replace('%2F', '/', rawurlencode($row['call'])) ?>" target="_blank" rel="noopener"><?= esc($row['call']) ?></a></td><td><?= esc(strtolower($row['band'])) ?></td><td><?= esc($row['mode']) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<?php if ($hasDeleted): ?>
<p class="note"><?= esc($text['deleted_note']) ?></p>
<?php endif; ?>
<p class="note"><?= esc($text['source']) ?></p>
</body>
</html>
