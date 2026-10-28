<?php
/**
 * One-time setup page: the station owner opens the link made by
 * make_setup_link.php and enters their own callsign, grid and LoTW login,
 * which are written straight into config.json. The host never has to receive
 * or type them. The form works once: after a successful save the same link
 * runs the first sync from the browser for two hours (update_dxcc, sync_lotw,
 * build_cache in batches, make_background) and can be reopened to resume it.
 *
 * Fields left empty keep the current value in config.json, so the host can
 * pre-fill shared settings (web.token, hamqth) beforehand. Hosted stations
 * sync from LoTW only, so this page does not ask for Clublog credentials.
 */

require __DIR__ . '/common.php';

const FIRST_SYNC_SECONDS = 2 * 3600;
const LOOKUPS_PER_BATCH = 40; // about 20-30 s per build_cache.php request

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$key = (string)($_POST['key'] ?? $_GET['key'] ?? '');

if (!isHttps()) {
    showPage('HTTPS required', '<p class="error">Open this link with <code>https://</code> so your passwords are encrypted on the way.</p>', 400);
}
if (setupKeyIsValid($key, true)) {
    showPage('Setting up your map', firstSyncPage($key));
}
if (!setupKeyIsValid($key, false)) {
    showPage('Link not valid', '<p class="error">This setup link has expired or was already used. Ask the map host for a new one.</p>', 403);
}

$values = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['callsign', 'grid', 'lotw_username', 'lotw_password', 'hamqth_username', 'hamqth_password'] as $field) {
        $values[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $values['callsign'] = strtoupper($values['callsign']);
    $values['grid'] = strtoupper(substr($values['grid'], 0, 4)) . strtolower(substr($values['grid'], 4));
    if ($values['lotw_username'] === '') $values['lotw_username'] = $values['callsign'];

    if (!preg_match('/^[A-Z0-9]{1,4}\/?[A-Z0-9]{1,8}(\/[A-Z0-9]{1,4})?$/', $values['callsign'])) {
        $errors[] = 'Enter a valid callsign.';
    }
    if (gridCentre($values['grid']) === null) {
        $errors[] = 'Enter a valid grid (4, 6 or 8 characters, e.g. FF57oc).';
    }
    if ($values['lotw_password'] === '') {
        $errors[] = 'Enter your LoTW password.';
    }
    if (!$errors && !lotwLoginWorks($values['lotw_username'], $values['lotw_password'])) {
        $errors[] = 'LoTW did not accept that username and password. Check them on lotw.arrl.org and try again.';
    }

    if (!$errors) {
        saveConfig($values);
        // The form is done; the key now only runs the first sync, for a while.
        writeJson(SETUP_LINK_FILE, ['used' => true, 'expires' => time() + FIRST_SYNC_SECONDS] + readJson(SETUP_LINK_FILE));
        showPage('Setting up your map', firstSyncPage($key));
    }
}

showPage('QSO Map setup', setupForm($key, $values, $errors));

function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); // local testing
}

/** Ask LoTW for an empty report: it returns ADIF only when the login is right. */
function lotwLoginWorks(string $username, string $password): bool {
    $adif = httpRequest('https://lotw.arrl.org/lotwuser/lotwreport.adi?' . http_build_query([
        'login' => $username,
        'password' => $password,
        'qso_query' => 1,
        'qso_qsl' => 'yes',
        'qso_qslsince' => gmdate('Y-m-d', strtotime('+1 day')),
    ]), null, 60);
    return $adif !== null && stripos($adif, '<eoh>') !== false;
}

/** Merge the submitted values into config.json; empty fields keep what is there. */
function saveConfig(array $values): void {
    $config = loadConfig(false);
    $set = function (string $section, string $field, string $value) use (&$config): void {
        if ($value !== '') $config[$section][$field] = $value;
    };
    $set('map', 'my_callsign', $values['callsign']);
    $set('map', 'my_grid', $values['grid']);
    $set('lotw', 'username', $values['lotw_username']);
    $set('lotw', 'password', $values['lotw_password']);
    $config['lotw']['days_back'] = $config['lotw']['days_back'] ?? 3650;
    $set('hamqth', 'username', $values['hamqth_username']);
    $set('hamqth', 'password', $values['hamqth_password']);
    writeJson(QSOMAP_ROOT . '/config.json', $config);
}

function setupForm(string $key, array $values, array $errors): string {
    $value = [];
    foreach (['callsign', 'grid', 'lotw_username', 'hamqth_username'] as $field) {
        $value[$field] = htmlspecialchars($values[$field] ?? '', ENT_QUOTES);
    }
    $key = htmlspecialchars($key, ENT_QUOTES);
    $html = '';
    foreach ($errors as $error) {
        $html .= '<p class="error">' . htmlspecialchars($error) . '</p>';
    }
    return $html . <<<HTML
<p>Enter your details to set up your QSO map. They go straight from your browser to this map's
settings over an encrypted connection: the map host does not need to receive or type them.</p>
<form method="post" autocomplete="off">
  <input type="hidden" name="key" value="$key">
  <fieldset>
    <legend>Station</legend>
    <label>Callsign <input name="callsign" value="{$value['callsign']}" required></label>
    <label>Grid <input name="grid" value="{$value['grid']}" placeholder="FF57oc" required></label>
  </fieldset>
  <fieldset>
    <legend>LoTW</legend>
    <label>Username <input name="lotw_username" value="{$value['lotw_username']}" placeholder="Same as callsign"></label>
    <label>Password <input type="password" name="lotw_password" autocomplete="new-password" required></label>
    <p class="note">The LoTW website password only gives access to the LoTW website: viewing and
    downloading your QSOs and confirmations, and your account settings. It <strong>cannot sign or upload
    QSOs</strong>: that needs your TQSL certificate, which never leaves your computer, so nobody can
    fake contacts with it. We recommend a password you do not use anywhere else. Keep in mind that
    whoever runs this server can technically read the stored password; changing it on LoTW cuts off
    access at any time.</p>
  </fieldset>
  <fieldset>
    <legend>HamQTH (optional)</legend>
    <label>Username <input name="hamqth_username" value="{$value['hamqth_username']}"></label>
    <label>Password <input type="password" name="hamqth_password" autocomplete="new-password"></label>
    <p class="note">Used to look up grids missing from your log. Leave empty if the host provides it.</p>
  </fieldset>
  <button type="submit">Save</button>
</form>
HTML;
}

/** Progress page that runs the first-sync scripts one after another. */
function firstSyncPage(string $key): string {
    $config = json_encode(['key' => $key, 'batch' => LOOKUPS_PER_BATCH], JSON_HEX_TAG);
    $script = <<<'JS'
const steps = [
  { label: 'Downloading the DXCC list', url: 'update_dxcc.php', optional: true },
  { label: 'Downloading your QSOs from LoTW (the first time can take a few minutes)', url: 'sync_lotw.php' },
  { label: 'Finding grids and building your map', url: 'build_cache.php', batches: true },
  { label: 'Drawing the static map', url: 'make_background.php' },
];
const list = document.getElementById('steps');
const message = document.getElementById('message');
const items = steps.map(step => list.appendChild(Object.assign(document.createElement('li'), { textContent: step.label })));

async function request(url) {
  const response = await fetch(url + (url.includes('?') ? '&' : '?') + 'key=' + SETUP.key, { cache: 'no-store' });
  const text = await response.text();
  if (response.ok) return text;
  throw new Error(text.startsWith('Error:') ? text.trim() : 'The server did not answer (HTTP ' + response.status + ').');
}

async function runStep(step, item) {
  if (!step.batches) return request(step.url);
  let offset = 0;
  for (;;) {
    const text = await request(step.url + '?max_lookups=' + SETUP.batch + '&offset=' + offset);
    const next = text.match(/Next offset: (\d+)/);
    if (!next) return text;
    offset = Number(next[1]);
    const left = text.match(/Lookups left for the next batch: (\d+)/);
    item.dataset.detail = left ? left[1] + ' callsigns left to look up' : '';
  }
}

async function run(from) {
  message.textContent = '';
  for (let i = from; i < steps.length; i++) {
    const item = items[i];
    item.className = 'running';
    try {
      await runStep(steps[i], item);
      item.className = 'done';
    } catch (error) {
      if (steps[i].optional) { item.className = 'skipped'; continue; }
      item.className = 'failed';
      message.innerHTML = '';
      message.append(error.message + ' ', Object.assign(document.createElement('button'), {
        textContent: 'Try again', onclick: () => run(i),
      }));
      message.append(Object.assign(document.createElement('p'), {
        textContent: 'If it keeps failing, close this page: the nightly sync will finish the job.',
      }));
      return;
    }
    item.dataset.detail = '';
  }
  message.innerHTML = '<strong>Your map is ready.</strong> <a href="../map-ssb.html">Open it</a>. '
    + 'From now on it updates every night. To stop it at any time, change your LoTW password.';
}
run(0);
JS;
    return <<<HTML
<p>Your settings were saved. Keep this page open while your map is set up; it takes from one to several
minutes depending on the size of your log. If you close it, open the same link again to resume.</p>
<ol id="steps" class="steps"></ol>
<div id="message" role="status"></div>
<script>const SETUP = $config;
$script</script>
HTML;
}

function showPage(string $title, string $body, int $status = 200): void {
    http_response_code($status);
    $title = htmlspecialchars($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>$title</title>
<style>
  body { font: 16px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 2rem auto; padding: 0 16px; color: #1d1d1f; background: #fff; }
  fieldset { border: 1px solid #ccc; border-radius: 6px; margin: 0 0 1rem; }
  label { display: block; margin: .5rem 0; }
  input { display: block; width: 100%; box-sizing: border-box; padding: .4rem; font: inherit; }
  button { padding: .5rem 1.5rem; font: inherit; }
  .note { font-size: .875rem; color: #555; }
  .error, .failed { color: #b00020; }
  .steps li { margin: .4rem 0; color: #888; }
  .steps li.running { color: inherit; font-weight: 600; }
  .steps li.running::after { content: ' … ' attr(data-detail); font-weight: normal; }
  .steps li.done { color: inherit; }
  .steps li.done::before { content: '✓ '; color: #1b7f3b; }
  .steps li.failed::before { content: '✗ '; }
  .steps li.skipped::after { content: ' (skipped, will retry tonight)'; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;
    exit;
}
