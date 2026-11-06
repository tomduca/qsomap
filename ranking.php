<?php
/**
 * Club ranking: DXCC entities confirmed in LoTW per station, as plain tables
 * meant to be embedded with an iframe (QRZ.com, a blog, any web page).
 *
 *   ranking.php?mode=cw            one mode: phone, cw, rtty or ft8 (FT8/FT4)
 *   ranking.php?mode=all           every mode someone has entities in, one table each (default)
 *   &band=20M                      one band instead of all of them
 *   &lang=es                       Spanish or English (default: set in admin/)
 *
 * Positions count current entities only; confirmed deleted entities are shown
 * next to the total and explained in a footnote. Each callsign opens that
 * station's interactive map of the mode. The mode and band menus only offer
 * what the stations have confirmed. The page needs no JavaScript: the menus
 * submit themselves when scripts run and have a button otherwise. The
 * manager turns the ranking on in admin/; while it is off this answers 404.
 */

require __DIR__ . '/scripts/ranking_data.php';

const RANKING_TEXTS = [
    'en' => [
        'default_title' => 'DXCC ranking',
        'subtitle' => 'DXCC entities confirmed in LoTW',
        'all_bands' => 'All bands',
        'band' => 'Band',
        'all_modes' => 'All modes',
        'mode' => 'Mode',
        'show' => 'Show',
        'position' => '#',
        'callsign' => 'Callsign',
        'entities' => 'Entities',
        'empty' => 'No stations with confirmed entities yet.',
        'deleted_note' => '* Confirmed deleted DXCC entities; they do not count for the position.',
        'source' => 'Data from LoTW, updated every night. Click a callsign to open its map.',
        'off' => 'The ranking is not available.',
    ],
    'es' => [
        'default_title' => 'Ranking DXCC',
        'subtitle' => 'Entidades DXCC confirmadas en LoTW',
        'all_bands' => 'Todas las bandas',
        'band' => 'Banda',
        'all_modes' => 'Todos los modos',
        'mode' => 'Modo',
        'show' => 'Ver',
        'position' => '#',
        'callsign' => 'Indicativo',
        'entities' => 'Entidades',
        'empty' => 'Todavía no hay estaciones con entidades confirmadas.',
        'deleted_note' => '* Entidades DXCC borradas (deleted) confirmadas; no cuentan para el puesto.',
        'source' => 'Datos de LoTW, actualizados cada noche. Haz clic en un indicativo para ver su mapa.',
        'off' => 'El ranking no está disponible.',
    ],
];
const MODE_ALIASES = ['ssb' => 'phone', 'ft' => 'ft8'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$settings = rankingSettings();
$asked = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : '';
$lang = isset(RANKING_TEXTS[$asked]) ? $asked : $settings['lang'];
$text = RANKING_TEXTS[$lang];

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (!$settings['enabled']) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>' . esc($text['default_title']) . '</title><p>' . esc($text['off']) . '</p>';
    exit;
}

$stations = rankingStations();
// Modes in the menu: those some station has a current entity in. A mode
// asked for in the URL (e.g. an embed) is kept even while it is empty.
$modeOptions = array_values(array_filter(array_keys(RANKING_MODES), function (string $option) use ($stations): bool {
    foreach ($stations as $counts) {
        if (($counts[$option]['all'][0] ?? 0) > 0) return true;
    }
    return false;
}));
$mode = strtolower((string)($_GET['mode'] ?? 'all'));
$mode = MODE_ALIASES[$mode] ?? $mode;
if (isset(RANKING_MODES[$mode])) {
    $modes = [$mode];
    $modeOptions = array_values(array_intersect(array_keys(RANKING_MODES), array_merge($modeOptions, [$mode])));
} else {
    $mode = 'all';
    $modes = $modeOptions;
}
$band = strtoupper(trim((string)($_GET['band'] ?? '')));
if (!preg_match('/^(\d+(\.\d+)?(M|CM|MM)|SUBMM)$/', $band)) $band = 'all';

$bands = rankingBands($stations, $modes);
if ($band !== 'all' && !in_array($band, $bands, true)) $bands[] = $band;

$title = $settings['title'] !== '' ? $settings['title'] : $text['default_title'];
$bandName = fn(string $band): string => $band === 'all' ? $text['all_bands'] : strtolower($band);
$hasDeleted = false;
$tables = [];
foreach ($modes as $tableMode) {
    $rows = rankingRows($stations, $tableMode, $band);
    foreach ($rows as $row) $hasDeleted = $hasDeleted || $row['deleted'] > 0;
    $tables[$tableMode] = $rows;
}
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?></title>
<style>
:root { color-scheme: light; }
body { margin: 0; padding: 12px; font: 14px/1.4 Arial, Helvetica, sans-serif; color: #111; background: #fff; }
h1 { font-size: 18px; margin: 0 0 2px; }
h2 { font-size: 16px; margin: 18px 0 6px; }
p { margin: 4px 0; }
.subtitle, .note { color: #555; font-size: 12px; }
form { margin: 8px 0 0; }
label { display: inline-block; margin: 0 12px 4px 0; }
select, button { font: inherit; }
table { border-collapse: collapse; width: 100%; max-width: 480px; }
th, td { padding: 4px 8px; border-bottom: 1px solid #ddd; text-align: left; }
th { border-bottom: 2px solid #999; }
td.position, th.position { width: 3em; }
td.entities, th.entities { text-align: right; white-space: nowrap; }
a { color: #0645ad; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>
</head>
<body>
<h1><?= esc($title) ?></h1>
<p class="subtitle"><?= esc($text['subtitle']) ?></p>
<form method="get">
    <input type="hidden" name="lang" value="<?= esc($lang) ?>">
    <label><?= esc($text['mode']) ?>
        <select name="mode" onchange="this.form.submit()">
            <option value="all"><?= esc($text['all_modes']) ?></option>
<?php foreach ($modeOptions as $option): ?>
            <option value="<?= $option ?>"<?= $option === $mode ? ' selected' : '' ?>><?= esc(RANKING_MODES[$option][$lang]) ?></option>
<?php endforeach; ?>
        </select>
    </label>
    <label><?= esc($text['band']) ?>
        <select name="band" onchange="this.form.submit()">
            <option value="all"><?= esc($text['all_bands']) ?></option>
<?php foreach ($bands as $option): ?>
            <option value="<?= esc($option) ?>"<?= $option === $band ? ' selected' : '' ?>><?= esc($bandName($option)) ?></option>
<?php endforeach; ?>
        </select>
    </label>
    <noscript><button><?= esc($text['show']) ?></button></noscript>
</form>
<?php if (!$tables): ?>
<p class="note"><?= esc($text['empty']) ?></p>
<?php endif; ?>
<?php foreach ($tables as $tableMode => $rows): ?>
<h2><?= esc(RANKING_MODES[$tableMode][$lang]) ?> · <?= esc($bandName($band)) ?></h2>
<?php if (!$rows): ?>
<p class="note"><?= esc($text['empty']) ?></p>
<?php else: ?>
<table>
    <tr><th class="position"><?= esc($text['position']) ?></th><th><?= esc($text['callsign']) ?></th><th class="entities"><?= esc($text['entities']) ?></th></tr>
<?php foreach ($rows as $row): ?>
    <tr><td class="position"><?= $row['position'] ?></td><td><a href="<?= esc(MAP_VIEWS[RANKING_MODES[$tableMode]['view']]['page'] . '?' . $row['call']) ?>" target="_blank" rel="noopener"><?= esc($row['call']) ?></a></td><td class="entities"><?= $row['current'] ?><?= $row['deleted'] > 0 ? ' (+' . $row['deleted'] . '*)' : '' ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($hasDeleted): ?>
<p class="note"><?= esc($text['deleted_note']) ?></p>
<?php endif; ?>
<p class="note"><?= esc($text['source']) ?></p>
</body>
</html>
