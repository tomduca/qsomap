<?php
/**
 * Manager portal: create invites for new stations, see and cancel pending
 * ones, list the stations with their status, delete a station, and read the
 * event log.
 *
 * The folder must be password-protected by the web server (cPanel →
 * Directory Privacy, i.e. HTTP Basic auth). The page refuses to work if the
 * server reports no logged-in user, unless config.json sets
 * "admin": {"allow_without_login": true} for hosts that hide it.
 */

require dirname(__DIR__) . '/scripts/common.php';
require dirname(__DIR__) . '/scripts/portal.php';

const INVITE_SECONDS = 48 * 3600;

portalHeaders();

$user = (string)($_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '');
if ($user === '' && empty(rootConfig()['admin']['allow_without_login'])) {
    showPage('Protect this folder first', '<p class="error">This portal is not password-protected.</p>'
        . '<p>In cPanel open <strong>Directory Privacy</strong>, select the <code>admin</code> folder of QSO Map, '
        . 'tick <em>Password protect this directory</em> and create a user. Then reload this page.</p>', 403);
}
if (!isHttps()) {
    showPage('HTTPS required', '<p class="error">Open the portal with <code>https://</code>.</p>', 400);
}

session_name('qsomap_admin');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        showPage('Expired form', '<p class="error">The form expired. <a href="./">Reload the portal</a> and try again.</p>', 400);
    }
    $call = normalizeCall((string)($_POST['callsign'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($call === null) {
        $_SESSION['flash'] = ['error' => 'Enter a callsign without prefixes or suffixes (e.g. LU9XYZ, not LU9XYZ/P).'];
    } elseif ($action === 'invite') {
        $_SESSION['flash'] = ['invite' => createInvite($call, $user)];
    } elseif ($action === 'cancel') {
        withInvites(function (array &$invites) use ($call): void { unset($invites[$call]); });
        logEvent("invite for $call cancelled by $user");
    } elseif ($action === 'delete' && stationExists($call)) {
        deleteStation($call);
        logEvent("station deleted: $call, by $user");
        $_SESSION['flash'] = ['message' => "Station $call deleted."];
    }
    header('Location: ./', true, 303);
    exit;
}

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
showPage('QSO Map stations', portalPage($flash, $_SESSION['csrf']), 200, '60rem');

/** New invite for $call (replacing any pending one); returns what to show once. */
function createInvite(string $call, string $user): array {
    $code = sprintf('%06d', random_int(0, 999999));
    $expires = time() + INVITE_SECONDS;
    withInvites(function (array &$invites) use ($call, $code, $expires): void {
        $invites[$call] = ['stage' => 'invite', 'code' => password_hash($code, PASSWORD_DEFAULT), 'expires' => $expires, 'attempts' => 0];
    });
    logEvent("invite created for $call by $user" . (stationExists($call) ? ' (existing station)' : ''));
    return ['call' => $call, 'code' => $code, 'expires' => $expires];
}

/** Remove stations/<CALL>/ with its config (credentials included) and data. */
function deleteStation(string $call): void {
    $dir = QSOMAP_STATIONS . "/$call";
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
    withInvites(function (array &$invites) use ($call): void { unset($invites[$call]); });
}

/** Last "=== Sync complete/FAILED ===" line of a station's sync.log, with its date. */
function lastSyncResult(string $call): string {
    $log = @file(QSOMAP_STATIONS . "/$call/data/sync.log", FILE_IGNORE_NEW_LINES) ?: [];
    $date = '';
    $result = '';
    foreach ($log as $line) {
        if (preg_match('/^=== Daily QSO sync: \S+ - (.+) ===$/', $line, $match)) $date = $match[1];
        if (preg_match('/^=== Sync (complete|FAILED) ===$/', $line, $match)) $result = $match[1] . ' (' . $date . ')';
    }
    return $result ?: 'never';
}

function portalPage(array $flash, string $csrf): string {
    $base = instanceUrl(1);
    $csrf = h($csrf);
    $form = fn(string $action, string $call, string $label, string $confirm = '') =>
        '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="callsign" value="' . h($call) . '"><button>' . h($label) . '</button></form>';

    $html = '';
    if (isset($flash['error'])) $html .= '<p class="error">' . h($flash['error']) . '</p>';
    if (isset($flash['message'])) $html .= '<p>' . h($flash['message']) . '</p>';
    if (isset($flash['invite'])) {
        $invite = $flash['invite'];
        $until = gmdate('Y-m-d H:i', $invite['expires']) . ' UTC';
        $message = "To create your QSO map, open $base/join.php and enter your callsign {$invite['call']} "
            . "and the code {$invite['code']}. It is valid until $until, for one use.";
        $html .= '<div class="warning"><p>Code for <strong>' . h($invite['call']) . '</strong>, shown only now:</p>'
            . '<p class="code">' . h($invite['code']) . '</p><p>Valid until ' . h($until) . ', one use, '
            . 'cancelled after 5 failed attempts.</p><label>Message to send:<textarea rows="3" readonly onclick="this.select()">'
            . h($message) . '</textarea></label></div>';
    }

    $html .= '<h2>New invite</h2><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="invite"><label>Callsign of the station (no /P or prefixes)'
        . '<input name="callsign" placeholder="LU9XYZ" required></label><button>Create code</button></form>'
        . '<p class="note">Inviting an existing station lets its owner update the grid or passwords; its QSOs are kept.</p>';

    $rows = '';
    foreach (readJson(INVITES_FILE) ?? [] as $call => $invite) {
        if ((int)($invite['expires'] ?? 0) < time()) continue;
        $stage = ($invite['stage'] ?? '') === 'sync' ? 'setting up the map' : 'waiting for the code (' . (int)($invite['attempts'] ?? 0) . ' failed attempts)';
        $rows .= '<tr><td>' . h($call) . '</td><td>' . h($stage) . '</td><td>' . h(gmdate('Y-m-d H:i', (int)$invite['expires'])) . ' UTC</td><td>'
            . $form('cancel', $call, 'Cancel') . '</td></tr>';
    }
    $html .= '<h2>Pending invites</h2>' . ($rows ? '<div class="table-wrap"><table><tr><th>Callsign</th><th>Status</th><th>Expires</th><th></th></tr>'
        . $rows . '</table></div>' : '<p class="note">None.</p>');

    $default = normalizeCall((string)(rootConfig()['default_station'] ?? ''));
    $rows = '';
    foreach (stationList() as $call) {
        $config = readJson(QSOMAP_STATIONS . "/$call/config.json") ?? [];
        $cache = readJson(QSOMAP_STATIONS . "/$call/data/qso_cache.json") ?? [];
        $sources = implode(', ', array_filter([
            ($config['clublog']['email'] ?? '') !== '' ? 'Clublog' : '',
            ($config['lotw']['password'] ?? '') !== '' ? 'LoTW' : '',
            ($config['hamqth']['username'] ?? '') !== '' ? 'HamQTH' : '',
        ]));
        $embed = embedCodes($base, $call);
        $rows .= '<tr><td><strong>' . h($call) . '</strong>' . ($call === $default ? ' (default)' : '') . '<br>'
            . h($config['map']['my_grid'] ?? '') . '</td><td>' . h($sources) . '</td><td>'
            . (int)($cache['total_qsos'] ?? 0) . ' QSOs<br><span class="note">' . h($cache['generated'] ?? 'no map yet') . '</span></td><td>'
            . h(lastSyncResult($call)) . '</td><td><a href="' . h("$base/map-ssb.html?$call") . '" target="_blank" rel="noopener">Map</a> · '
            . '<a href="' . h("$base/map-ssb-qrz.php?$call") . '" target="_blank" rel="noopener">QRZ</a>'
            . '<details><summary>Embed codes</summary><textarea rows="4" readonly onclick="this.select()">' . h($embed['qrz'] . "\n\n" . $embed['github'])
            . '</textarea></details></td><td>'
            . $form('delete', $call, 'Delete', "Delete $call? Its settings, passwords and QSOs are removed from this server. This cannot be undone.")
            . '</td></tr>';
    }
    $html .= '<h2>Stations</h2>' . ($rows ? '<div class="table-wrap"><table><tr><th>Station</th><th>Sources</th><th>Map</th><th>Last sync</th><th>Links</th><th></th></tr>'
        . $rows . '</table></div>' : '<p class="note">No stations yet.</p>');

    $events = array_slice(@file(QSOMAP_DATA . '/events.log', FILE_IGNORE_NEW_LINES) ?: [], -40);
    $html .= '<h2>Recent events</h2>' . ($events ? '<pre>' . h(implode("\n", array_reverse($events))) . '</pre>' : '<p class="note">None yet.</p>');
    return $html;
}
