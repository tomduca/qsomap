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
require __DIR__ . '/scripts/join_texts.php';

const MAX_ATTEMPTS = 5;
const FIRST_SYNC_SECONDS = 2 * 3600;
const LOOKUPS_PER_BATCH = 40; // about 20-30 s per build_cache.php request
const DEFAULT_YEARS = 5;      // how far back the first LoTW download goes
const MAX_YEARS = 25;         // LoTW started in 2003

portalHeaders();
$lang = language();

if (!isHttps()) {
    joinPage(t('https_title'), '<p class="error">' . t('https_text') . '</p>', 400);
}
// Encryption is on but its key was not created: passwords could not be stored.
$keyFile = storagePath('key_file');
if ($keyFile !== null && !is_readable($keyFile)) {
    joinPage(t('unavailable_title'), '<p class="error">' . t('unavailable_text') . '</p>', 503);
}

$key = (string)($_GET['key'] ?? '');
if ($key !== '') {
    $call = inviteByKey($key, 'sync');
    if ($call === null) {
        joinPage(t('expired_title'), '<p>' . t('expired_text') . '</p>', 403);
    }
    joinPage(t('sync_title', $call), firstSyncPage($key, $call));
}

$values = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['callsign', 'code', 'grid', 'years', 'lotw_password', 'hamqth_username', 'hamqth_password'] as $field) {
        $values[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $call = normalizeCall($values['callsign']);
    $grid = strtoupper(substr($values['grid'], 0, 4)) . strtolower(substr($values['grid'], 4));

    if ($call === null) $errors[] = t('error_callsign');
    if (!preg_match('/^\d{6}$/', $values['code'])) $errors[] = t('error_code_format');
    if (gridCentre($grid) === null) $errors[] = t('error_grid');
    if (!ctype_digit($values['years']) || (int)$values['years'] < 1 || (int)$values['years'] > MAX_YEARS) {
        $errors[] = t('error_years', MAX_YEARS);
    }
    if ($values['lotw_password'] === '') $errors[] = t('error_lotw_password');
    if (($values['hamqth_username'] === '') !== ($values['hamqth_password'] === '')) {
        $errors[] = t('error_hamqth');
    }
    if (empty($_POST['public_ok'])) $errors[] = t('error_public');

    if (!$errors) {
        $codeOk = checkCode($call, $values['code']);
        if (!$codeOk) {
            $errors[] = t('error_code', MAX_ATTEMPTS);
        } elseif (!lotwLoginWorks($call, $values['lotw_password'])) {
            countFailedAttempt($call, 'LoTW login rejected');
            $errors[] = t('error_lotw_login', $call);
        } else {
            $newKey = createStation($call, $grid, $values);
            if ($newKey === null) {
                $errors[] = t('error_invite_expired');
            } else {
                header('Location: join.php?key=' . $newKey, true, 303);
                exit;
            }
        }
    }
}

joinPage(t('form_title'), joinForm($values, $errors));

/**
 * Language of the page: ?lang=en|es (remembered in a cookie, so it survives
 * the form post and the redirect to the first sync page), English by default.
 */
function language(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $asked = (string)($_GET['lang'] ?? '');
    if (isset(TEXTS[$asked])) {
        setcookie('qsomap_lang', $asked, ['expires' => time() + 365 * 86400, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        return $lang = $asked;
    }
    $saved = (string)($_COOKIE['qsomap_lang'] ?? '');
    return $lang = isset(TEXTS[$saved]) ? $saved : 'en';
}

/** Text $id in the page language, with sprintf() arguments. Plain texts are HTML-escaped by the caller. */
function t(string $id, ...$args): string {
    return vsprintf(TEXTS[language()][$id] ?? TEXTS['en'][$id], $args);
}

/** showPage() with the language switch in the top corner (same page, other language). */
function joinPage(string $title, string $body, int $status = 200): void {
    $other = language() === 'en' ? 'es' : 'en';
    $query = array_merge($_GET, ['lang' => $other]);
    $switch = '<a class="lang-switch" href="join.php?' . h(http_build_query($query)) . '" title="' . h(t('switch_title'))
        . '" lang="' . $other . '">' . strtoupper($other) . '</a>';
    showPage($title, $switch . $body, $status, '36rem', language());
}

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
    $config['lotw'] = ['username' => $call, 'password' => $values['lotw_password'], 'days_back' => (int)round((int)$values['years'] * 365.25)];
    if ($values['hamqth_username'] !== '') {
        $config['hamqth'] = ['username' => $values['hamqth_username'], 'password' => $values['hamqth_password']];
    }
    if (!is_dir(QSOMAP_STATIONS . "/$call/data")) mkdir(QSOMAP_STATIONS . "/$call/data", 0755, true);
    writeStationConfig($call, $config);
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
    $v['years'] = h($values['years'] ?? (string)DEFAULT_YEARS);
    $maxYears = MAX_YEARS;
    $defaultYears = DEFAULT_YEARS;
    $html = '';
    foreach ($errors as $error) $html .= '<p class="error">' . h($error) . '</p>';
    $text = fn(string $id, ...$args) => t($id, ...$args);
    return $html . <<<HTML
<p>{$text('intro')}</p>
<form method="post" autocomplete="off">
  <fieldset>
    <legend>{$text('invite')}</legend>
    <label>{$text('callsign')} <input name="callsign" value="{$v['callsign']}" placeholder="LU9XYZ" required></label>
    <label>{$text('code')} <input name="code" value="{$v['code']}" inputmode="numeric" maxlength="6" required></label>
  </fieldset>
  <fieldset>
    <legend>{$text('station')}</legend>
    <label>{$text('grid')} <input name="grid" value="{$v['grid']}" placeholder="FF57oc" required></label>
  </fieldset>
  <fieldset>
    <legend>LoTW</legend>
    <p class="note">{$text('lotw_username')}</p>
    <label>{$text('years')} <input type="number" name="years" value="{$v['years']}" min="1" max="{$maxYears}" required></label>
    <p class="note">{$text('years_note', $defaultYears)}</p>
    <label>{$text('lotw_password')} <input type="password" name="lotw_password" autocomplete="new-password" required></label>
    <p class="note">{$text('lotw_note')}</p>
  </fieldset>
  <fieldset>
    <legend>{$text('hamqth')}</legend>
    <p class="warning">{$text('hamqth_note')}</p>
    <label>{$text('hamqth_username')} <input name="hamqth_username" value="{$v['hamqth_username']}"></label>
    <label>{$text('hamqth_password')} <input type="password" name="hamqth_password" autocomplete="new-password"></label>
  </fieldset>
  <label><input type="checkbox" name="public_ok" value="1" required> {$text('public')}</label>
  <p><button type="submit">{$text('submit')}</button></p>
</form>
HTML;
}

/** Progress page that runs the first-sync scripts one after another. */
function firstSyncPage(string $key, string $call): string {
    $base = instanceUrl();
    $steps = [];
    if (!is_file(QSOMAP_DATA . '/vuccgrids.json')) {
        $steps[] = ['label' => t('step_dxcc'), 'url' => 'update_dxcc.php', 'optional' => true];
    }
    $steps[] = ['label' => t('step_lotw'), 'url' => 'sync_lotw.php'];
    $steps[] = ['label' => t('step_cache'), 'url' => 'build_cache.php', 'batches' => true];
    $steps[] = ['label' => t('step_background'), 'url' => 'make_background.php'];
    $texts = [];
    foreach (['js_no_answer', 'js_left', 'js_retry', 'js_nightly', 'js_skipped'] as $id) $texts[$id] = t($id);
    $setup = json_encode(['key' => $key, 'batch' => LOOKUPS_PER_BATCH, 'steps' => $steps, 'text' => $texts], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    $text = fn(string $id, ...$args) => t($id, ...$args);
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
  throw new Error(text.startsWith('Error:') ? text.trim() : SETUP.text.js_no_answer.replace('%s', response.status));
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
    item.dataset.detail = left ? SETUP.text.js_left.replace('%s', left[1]) : '';
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
      if (steps[i].optional) { item.className = 'skipped'; item.dataset.skipped = SETUP.text.js_skipped; continue; }
      item.className = 'failed';
      message.append(error.message + ' ', Object.assign(document.createElement('button'), {
        textContent: SETUP.text.js_retry, onclick: () => run(i),
      }));
      message.append(Object.assign(document.createElement('p'), {
        textContent: SETUP.text.js_nightly,
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
<p>{$text('sync_intro')}</p>
<ol id="steps" class="steps"></ol>
<div id="message" role="status"></div>
<div id="done" hidden>
  <p>{$text('done', $mapUrl)}</p>
  <label>{$text('embed_qrz')}
    <textarea rows="3" readonly onclick="this.select()">$qrz</textarea></label>
  <label>{$text('embed_github')}
    <textarea rows="2" readonly onclick="this.select()">$github</textarea></label>
</div>
<script>const SETUP = $setup;
$script</script>
HTML;
}

