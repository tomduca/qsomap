<?php
/**
 * Public portal where a station sets up its own map. The manager creates an
 * invite in admin/ (callsign + 6-digit code, 48 hours, 5 attempts) and sends
 * the code; the station enters it here with its grid and LoTW password, and
 * optionally its HamQTH account. The LoTW username must be the callsign, so
 * only the owner of that LoTW account can create the map.
 *
 * After saving, the page redirects to join.php?key=... and runs the first sync
 * from the browser (update_dxcc if needed, sync_lotw, build_cache in batches,
 * make_background). That key works for two hours, so the page can be reopened
 * to resume; the nightly Cron finishes anything left.
 */

require __DIR__ . '/scripts/common.php';
require __DIR__ . '/scripts/portal.php';

const MAX_ATTEMPTS = 5;
const FIRST_SYNC_SECONDS = 2 * 3600;
const LOOKUPS_PER_BATCH = 40; // about 20-30 s per build_cache.php request

portalHeaders();

if (!isHttps()) {
    showPage('HTTPS required', '<p class="error">Open this page with <code>https://</code> so your passwords are encrypted on the way.</p>', 400);
}

$key = (string)($_GET['key'] ?? '');
if ($key !== '') {
    $call = inviteByKey($key, 'sync');
    if ($call === null) {
        showPage('Link expired', '<p>This setup page has expired. If your map was created, it keeps updating every night; ask the map manager if something is missing.</p>', 403);
    }
    showPage("Setting up the map of $call", firstSyncPage($key, $call));
}

$values = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['callsign', 'code', 'grid', 'lotw_password', 'hamqth_username', 'hamqth_password'] as $field) {
        $values[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $call = normalizeCall($values['callsign']);
    $grid = strtoupper(substr($values['grid'], 0, 4)) . strtolower(substr($values['grid'], 4));

    if ($call === null) $errors[] = 'Enter your callsign without prefixes or suffixes (e.g. LU9XYZ, not LU9XYZ/P).';
    if (!preg_match('/^\d{6}$/', $values['code'])) $errors[] = 'Enter the 6-digit code you received.';
    if (gridCentre($grid) === null) $errors[] = 'Enter a valid grid (4, 6 or 8 characters, e.g. FF57oc).';
    if ($values['lotw_password'] === '') $errors[] = 'Enter your LoTW password.';
    if (($values['hamqth_username'] === '') !== ($values['hamqth_password'] === '')) {
        $errors[] = 'Enter both your HamQTH username and password, or leave both empty.';
    }
    if (empty($_POST['public_ok'])) $errors[] = 'Confirm that your map will be public.';

    if (!$errors) {
        $codeOk = checkCode($call, $values['code']);
        if (!$codeOk) {
            $errors[] = 'The callsign and code do not match an active invite. After ' . MAX_ATTEMPTS
                . ' failed attempts the code stops working; ask the map manager for a new one if needed.';
        } elseif (!lotwLoginWorks($call, $values['lotw_password'])) {
            countFailedAttempt($call, 'LoTW login rejected');
            $errors[] = "LoTW did not accept the username $call with that password. Check it on lotw.arrl.org and try again.";
        } else {
            $newKey = createStation($call, $grid, $values);
            if ($newKey === null) {
                $errors[] = 'The invite expired while saving. Ask the map manager for a new code.';
            } else {
                header('Location: join.php?key=' . $newKey, true, 303);
                exit;
            }
        }
    }
}

showPage('Create your QSO map', joinForm($values, $errors));

/** Whether $code is right for $call's invite; a wrong one counts as a failed attempt. */
function checkCode(string $call, string $code): bool {
    $ok = withInvites(function (array &$invites) use ($call, $code): bool {
        $invite = $invites[$call] ?? null;
        return $invite !== null && ($invite['stage'] ?? '') === 'invite' && password_verify($code, (string)($invite['code'] ?? ''));
    });
    if (!$ok) countFailedAttempt($call, 'wrong code');
    return $ok;
}

/** Count a failed attempt on $call's invite and cancel it after MAX_ATTEMPTS. */
function countFailedAttempt(string $call, string $reason): void {
    withInvites(function (array &$invites) use ($call, $reason): void {
        if (($invites[$call]['stage'] ?? '') !== 'invite') {
            logEvent("join failed for $call: $reason (no active invite)");
            return;
        }
        $attempts = (int)($invites[$call]['attempts'] ?? 0) + 1;
        if ($attempts >= MAX_ATTEMPTS) {
            unset($invites[$call]);
            logEvent("invite for $call cancelled after $attempts failed attempts ($reason)");
        } else {
            $invites[$call]['attempts'] = $attempts;
            logEvent("join failed for $call: $reason (attempt $attempts of " . MAX_ATTEMPTS . ')');
        }
    });
}

/**
 * Write stations/<CALL>/config.json and turn the invite into a two-hour first
 * sync key. An existing station (invited again, e.g. after a password change)
 * keeps its other settings and data. Returns the key, or null if the invite
 * is gone.
 */
function createStation(string $call, string $grid, array $values): ?string {
    $key = bin2hex(random_bytes(32));
    $ok = withInvites(function (array &$invites) use ($call, $key): bool {
        if (($invites[$call]['stage'] ?? '') !== 'invite') return false;
        $invites[$call] = ['stage' => 'sync', 'key' => hash('sha256', $key), 'expires' => time() + FIRST_SYNC_SECONDS];
        return true;
    });
    if (!$ok) return null;

    $existed = stationExists($call);
    $config = readJson(QSOMAP_STATIONS . "/$call/config.json") ?? [];
    $config['map'] = ['my_callsign' => $call, 'my_grid' => $grid];
    $config['lotw'] = ['username' => $call, 'password' => $values['lotw_password'], 'days_back' => $config['lotw']['days_back'] ?? 3650];
    if ($values['hamqth_username'] !== '') {
        $config['hamqth'] = ['username' => $values['hamqth_username'], 'password' => $values['hamqth_password']];
    }
    if (!is_dir(QSOMAP_STATIONS . "/$call/data")) mkdir(QSOMAP_STATIONS . "/$call/data", 0755, true);
    writeJson(QSOMAP_STATIONS . "/$call/config.json", $config);
    logEvent(($existed ? 'station updated: ' : 'station created: ') . $call
        . ($values['hamqth_username'] !== '' ? ' (with HamQTH)' : ' (without HamQTH)'));
    return $key;
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

function joinForm(array $values, array $errors): string {
    $v = [];
    foreach (['callsign', 'code', 'grid', 'hamqth_username'] as $field) $v[$field] = h($values[$field] ?? '');
    $html = '';
    foreach ($errors as $error) $html .= '<p class="error">' . h($error) . '</p>';
    return $html . <<<HTML
<p>Enter the code the map manager sent you to create your QSO map. Your details go straight from your
browser to this server over an encrypted connection: the manager does not need to receive or type them.</p>
<form method="post" autocomplete="off">
  <fieldset>
    <legend>Invite</legend>
    <label>Callsign <input name="callsign" value="{$v['callsign']}" placeholder="LU9XYZ" required></label>
    <label>6-digit code <input name="code" value="{$v['code']}" inputmode="numeric" maxlength="6" required></label>
  </fieldset>
  <fieldset>
    <legend>Station</legend>
    <label>Grid <input name="grid" value="{$v['grid']}" placeholder="FF57oc" required></label>
  </fieldset>
  <fieldset>
    <legend>LoTW</legend>
    <p class="note">Your LoTW username must be your callsign.</p>
    <label>LoTW website password <input type="password" name="lotw_password" autocomplete="new-password" required></label>
    <p class="note">This password only gives access to the LoTW website: viewing and downloading your QSOs and
    confirmations, and your account settings. It <strong>cannot sign or upload QSOs</strong>: that needs your TQSL
    certificate, which never leaves your computer, so nobody can fake contacts with it. The map only reads.
    Use a password you do not use anywhere else. Whoever runs this server can technically read the stored
    password; to cut off access at any time, change it on LoTW.</p>
  </fieldset>
  <fieldset>
    <legend>HamQTH (optional, strongly recommended)</legend>
    <p class="warning">LoTW only includes the other station's grid for <strong>confirmed</strong> QSOs. For all the
    others the map looks up the grid by callsign; with a free <a href="https://www.hamqth.com/" target="_blank"
    rel="noopener">HamQTH</a> account many more of your QSOs show up on the map.</p>
    <label>HamQTH username <input name="hamqth_username" value="{$v['hamqth_username']}"></label>
    <label>HamQTH password <input type="password" name="hamqth_password" autocomplete="new-password"></label>
  </fieldset>
  <label><input type="checkbox" name="public_ok" value="1" required> I understand that my map, with the callsigns,
  dates, bands and modes of my QSOs, will be <strong>public</strong> on this website.</label>
  <p><button type="submit">Create my map</button></p>
</form>
HTML;
}

/** Progress page that runs the first-sync scripts one after another. */
function firstSyncPage(string $key, string $call): string {
    $base = instanceUrl();
    $steps = [];
    if (!is_file(QSOMAP_DATA . '/vuccgrids.json')) {
        $steps[] = ['label' => 'Downloading the DXCC list', 'url' => 'update_dxcc.php', 'optional' => true];
    }
    $steps[] = ['label' => 'Downloading your QSOs from LoTW (the first time can take a few minutes)', 'url' => 'sync_lotw.php'];
    $steps[] = ['label' => 'Finding grids and building your map', 'url' => 'build_cache.php', 'batches' => true];
    $steps[] = ['label' => 'Drawing the static map', 'url' => 'make_background.php'];
    $setup = json_encode(['key' => $key, 'batch' => LOOKUPS_PER_BATCH, 'steps' => $steps], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    $embed = embedCodes($base, $call);
    $qrz = h($embed['qrz']);
    $github = h($embed['github']);
    $mapUrl = h("$base/map-ssb.html?$call");
    $script = <<<'JS'
const steps = SETUP.steps;
const list = document.getElementById('steps');
const message = document.getElementById('message');
const items = steps.map(step => list.appendChild(Object.assign(document.createElement('li'), { textContent: step.label })));

async function request(url) {
  const response = await fetch('scripts/' + url + (url.includes('?') ? '&' : '?') + 'key=' + SETUP.key, { cache: 'no-store' });
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
  document.getElementById('done').hidden = false;
}
run(0);
JS;
    return <<<HTML
<p>Your settings were saved. Keep this page open while your map is set up; it takes from one to several
minutes depending on the size of your log. If you close it, open this same page again within two hours to resume.</p>
<ol id="steps" class="steps"></ol>
<div id="message" role="status"></div>
<div id="done" hidden>
  <p><strong>Your map is ready:</strong> <a href="$mapUrl" target="_blank" rel="noopener">$mapUrl</a>.
  From now on it updates every night. To stop it at any time, change your LoTW password.</p>
  <label>To show the map on your QRZ.com page, paste this in your biography (HTML mode):
    <textarea rows="3" readonly onclick="this.select()">$qrz</textarea></label>
  <label>To show it in a GitHub README:
    <textarea rows="2" readonly onclick="this.select()">$github</textarea></label>
</div>
<script>const SETUP = $setup;
$script</script>
HTML;
}
