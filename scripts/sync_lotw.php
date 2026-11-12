<?php
/**
 * Download a station's QSOs from LoTW (Logbook of The World) into
 * stations/<CALL>/data/qsos_<YEAR>.json, and its LoTW confirmations into
 * lotw_confirmed_<YEAR>.json for the club ranking (see ranking_data.php).
 * The log is kept in one file per year (see storeQsos() in common.php), so
 * the memory a sync needs depends on the busiest year, not the whole log.
 *
 * Follows ARRL's guidance for automated downloads: the first run downloads
 * the whole log (since FULL_SINCE: without a date LoTW only returns what is
 * new since the account's previous query); later runs only ask for what
 * changed, using the timestamps LoTW returns in each report header:
 *   - qso_qsorxsince: QSOs uploaded since the last run, confirmed or not
 *   - qso_qslsince:   new confirmations since the last run, including old QSOs
 * Results are merged into the year files, so LoTW works both as the only
 * source and as a fallback for Clublog. A station without year files (new, or
 * synced before version 2.5.0, when the log was a single qso_data.json that
 * is no longer read) downloads everything again. Delete
 * stations/<CALL>/data/lotw_last_sync.json to force a full download.
 *
 * The full download goes in parts by QSO date, so a log of hundreds of
 * thousands of QSOs never needs one huge report that LoTW cannot produce in
 * time. Confirmations come first, for the whole log: they are what the
 * ranking counts, and a much smaller report than every QSO uploaded, so the
 * ranking is complete long before the map. Then come the uploads (every QSO,
 * confirmed or not), which only the map uses. Each part is saved before the
 * next one starts and the progress is kept in lotw_last_sync.json, so an
 * interrupted download resumes where it stopped. Each part is sized from the
 * previous one so LoTW takes about TARGET_SECONDS for it, and halved when it
 * times out. From a browser each run stops after WEB_BUDGET seconds and ends
 * with "Run again to continue from <date>" while parts remain (join.php
 * repeats it); from the terminal it runs to the end.
 * Reports are saved to a temporary file and read record by record, so their
 * size does not matter for memory.
 *
 * With --confirmations-only (command line, used by sync_daily.sh for stations
 * synced from Clublog while the ranking is on) only the confirmations report
 * runs and only the confirmations change; a station without LoTW
 * credentials is skipped without error.
 */

require __DIR__ . '/ranking_data.php';
useStation(requireCliOrToken(true));
raiseMemoryLimit();
ignore_user_abort(true); // a closed browser tab must not stop a part half saved
// From a browser, hold the output so an error after some progress lines can
// still answer HTTP 500 (join.php tells errors from progress by the status).
if (PHP_SAPI !== 'cli') ob_start();

const FULL_SINCE = '1900-01-01';
const FIRST_QSO_DATE = '1945-11-01';  // LoTW accepts no earlier QSOs
const REPORT_TIMEOUT = 900;           // seconds LoTW gets for one report of a part
const STALL_TIMEOUT = 90;             // seconds without data after which a report is given up
// LoTW sends big reports slowly (about 17 KB/s seen) but steadily: a report is
// only given up when it stops arriving, or after REPORT_TIMEOUT.
const INCREMENTAL_TIMEOUT = 600;      // seconds for the reports of an incremental run
const PART_START_DAYS = 1461;         // first part: 4 years
const PART_MAX_DAYS = 1461;           // 4 years: bigger reports take LoTW too long
const PART_MIN_DAYS = 7;
const TARGET_SECONDS = 120;           // parts are sized so LoTW takes about this long for each
const WEB_BUDGET = 180;               // seconds: from a browser, no new part after this
const BIG_PART = 30000;               // QSOs: parts are sized to stay below this (memory)
const TOO_BIG_PART = 60000;           // QSOs: a report with more is dropped and its part halved
const PART_TOO_BIG = 1;               // exception code for that case
const LOTW_BUSY = 2;                  // exception code: LoTW refused the report for now (HTTP 503...)
const BUSY_WAIT = 120;                // seconds to wait before asking a busy LoTW again (it may still be building an abandoned report)
const BUSY_TRIES = 5;                 // from the terminal: tries before giving up
// Fields kept from LoTW records: the ones build_cache.php and the ranking use.
// LoTW adds many APP_LoTW_* fields that would only make the log bigger.
const LOTW_FIELDS = ['CALL', 'QSO_DATE', 'TIME_ON', 'BAND', 'FREQ', 'MODE', 'SUBMODE', 'GRIDSQUARE', 'DXCC', 'QSL_RCVD'];

$confirmationsOnly = PHP_SAPI === 'cli' && in_array('--confirmations-only', $argv ?? [], true);
$lotw = loadConfig()['lotw'] ?? [];
$username = $lotw['username'] ?? '';
$password = $lotw['password'] ?? '';

if ($username === '' || $password === '') {
    if ($confirmationsOnly) {
        echo "LoTW credentials not configured: this station is not in the ranking\n";
        exit(0);
    }
    fail('LoTW credentials not configured');
}

$stateFile = stationData('lotw_last_sync.json');
if (!is_dir(dirname($stateFile))) mkdir(dirname($stateFile), 0755, true);

// One sync per station at a time: the nightly cron and a browser could overlap.
$lock = fopen(stationData('lotw_sync.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Say how far it got: from a browser, a closed page does not stop it.
    $running = currentPart(readJson($stateFile)['full_progress'] ?? null);
    fail('another LoTW sync of this station is running' . ($running !== null ? " (now at {$running[0]} of QSOs from {$running[1]['next']})" : '')
        . '; try again when it ends');
}

$state = readJson($stateFile) ?? [];
$confirmationsKnown = isset($state['qsl']) && !empty($state['full_confirmations']) && yearFiles(CONFIRMED_FILES);
$incremental = $confirmationsOnly
    ? $confirmationsKnown
    : $confirmationsKnown && isset($state['qsorx']) && !empty($state['full_uploads']) && yearFiles(QSOS_FILES)
        && !isset($state['full_progress']);
$kinds = $confirmationsOnly ? ['confirmations'] : ['uploads', 'confirmations'];

$query = [
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_withown' => 'yes',
];

$what = $confirmationsOnly ? 'confirmations' : 'sync';
if ($incremental) {
    echo "=== LoTW incremental $what: $username ===\n";
    echo ($confirmationsOnly ? '' : "Uploads since {$state['qsorx']}, ") . "confirmations since {$state['qsl']}\n";
    set_time_limit(INCREMENTAL_TIMEOUT * count($kinds) + 120);
    $since = ['uploads' => ['qso_qsorxsince' => $state['qsorx'] ?? ''], 'confirmations' => ['qso_qslsince' => $state['qsl']]];
    $reports = [];
    foreach ($kinds as $kind) {
        try {
            $reports[$kind] = lotwReport($query, $kind, $since[$kind], INCREMENTAL_TIMEOUT);
        } catch (RuntimeException $e) {
            fail($e->getMessage() . ($e->getCode() === LOTW_BUSY ? '; try again later' : ''));
        }
    }
    $saved = saveReports($reports);

    // Next run starts where LoTW says this one ended. If a report had no
    // records (and so no timestamp), keep the previous value.
    $state['qsl'] = $reports['confirmations']['last'] ?? $state['qsl'];
    if (isset($reports['uploads'])) $state['qsorx'] = $reports['uploads']['last'] ?? $state['qsorx'];
    writeJson($stateFile, $state);

    if (isset($reports['uploads'])) {
        echo 'Uploads: ' . count($reports['uploads']['qsos']) . ', confirmations: ' . count($reports['confirmations']['qsos'])
            . ", new QSOs: {$saved['new']}\n";
        echo 'Total: ' . countStoredQsos(QSOS_FILES) . " QSOs\n";
    } else {
        echo 'Confirmations: ' . count($reports['confirmations']['qsos']) . "\n";
    }
    echo "Confirmed in LoTW (ranking): {$saved['confirmed']} QSOs\n";
    echo memoryUse();
    exit(0);
}

// Full download, in parts by QSO date: confirmations first, then uploads.
$today = gmdate('Y-m-d');
$progress = $state['full_progress'] ?? null;
if (is_array($progress) && !isset($progress['parts']) && isset($progress['next'], $progress['days'], $progress['started'])
    && ($progress['kinds'] ?? null) === $kinds) {
    // Saved by the version that asked for both reports of each part: both
    // are done up to the same date.
    $part = ['next' => $progress['next'], 'days' => min((int)$progress['days'], PART_MAX_DAYS)];
    $progress = ['kinds' => $kinds, 'started' => $progress['started'], 'parts' => array_fill_keys($kinds, $part)];
}
if (!is_array($progress) || ($progress['kinds'] ?? null) !== $kinds || !isset($progress['parts'], $progress['started'])) {
    $first = ['next' => FIRST_QSO_DATE, 'days' => PART_START_DAYS];
    $progress = ['kinds' => $kinds, 'started' => $today, 'parts' => array_fill_keys($kinds, $first)];
    // Until this download ends, the station is not up to date.
    foreach ($kinds as $kind) unset($state["full_$kind"]);
    echo "=== LoTW full $what: $username (whole log, in parts by QSO date) ===\n";
} else {
    $running = currentPart($progress);
    echo "=== LoTW full $what: $username, resuming " . ($running ? "{$running[0]} from QSOs of {$running[1]['next']}" : '') . " ===\n";
}

$runStart = microtime(true);
$saved = null;
$busyTries = 0;
while (($running = currentPart($progress)) !== null) {
    [$kind, $part] = $running;
    if (PHP_SAPI !== 'cli' && microtime(true) - $runStart > WEB_BUDGET) {
        echo "Run again to continue from {$part['next']} ($kind)\n";
        exit(0);
    }
    $from = $part['next'];
    $to = min(addDays($from, $part['days'] - 1), $today);
    set_time_limit(REPORT_TIMEOUT + 120);
    $since = $kind === 'uploads' ? ['qso_qsorxsince' => FULL_SINCE] : ['qso_qslsince' => FULL_SINCE];
    try {
        $report = lotwReport($query, $kind, $since + ['qso_startdate' => $from, 'qso_enddate' => $to], REPORT_TIMEOUT, TOO_BIG_PART);
    } catch (RuntimeException $e) {
        if ($e->getCode() === LOTW_BUSY) {
            // Usually LoTW is still preparing a report that timed out here,
            // and refuses another one of the same account until it ends.
            if (PHP_SAPI !== 'cli' || ++$busyTries >= BUSY_TRIES) {
                fail("$kind $from to $to: " . $e->getMessage() . '. LoTW is probably still preparing an earlier report:'
                    . " wait a few minutes and run this again, it continues from $from");
            }
            echo "$kind $from to $to: " . $e->getMessage() . ', waiting ' . BUSY_WAIT . " s\n";
            sleep(BUSY_WAIT);
            continue;
        }
        $busyTries = 0;
        if (!in_array($e->getCode(), [CURLE_OPERATION_TIMEDOUT, PART_TOO_BIG], true)) fail("$kind $from to $to: " . $e->getMessage());
        if ($part['days'] <= PART_MIN_DAYS) {
            fail("$kind $from to $to: " . $e->getMessage() . ', even for ' . PART_MIN_DAYS . ' days; LoTW may be overloaded, try again later');
        }
        // Parts this long are too much for LoTW or for memory: never grow back to that size.
        $part['days'] = $part['max_days'] = max(PART_MIN_DAYS, intdiv($part['days'], 2));
        $progress['parts'][$kind] = $part;
        $state['full_progress'] = $progress;
        writeJson($stateFile, $state);
        echo "$kind $from to $to: " . $e->getMessage() . ", trying again with {$part['days']} days\n";
        continue;
    }
    $busyTries = 0;
    $saved = saveReports([$kind => $report], $kind === 'uploads', true);
    $size = count($report['qsos']);
    printf("%s %s to %s: %d QSOs (%d s)\n", $kind, $from, $to, $size, $report['seconds']);

    // Size the next part so LoTW takes about TARGET_SECONDS and it stays
    // below BIG_PART QSOs, changing by at most 4 times at once.
    $factor = TARGET_SECONDS / max(1.0, $report['seconds']);
    if ($size > 0) $factor = min($factor, BIG_PART / $size);
    $days = (int)round($part['days'] * max(0.25, min(4.0, $factor)));
    $part['next'] = addDays($to, 1);
    $part['days'] = max(PART_MIN_DAYS, min($days, $part['max_days'] ?? PART_MAX_DAYS));
    $progress['parts'][$kind] = $part;

    if ($kind === 'confirmations' && $part['next'] > $today) {
        // The ranking is complete; later runs ask for confirmations since the
        // day before this download started.
        $state['qsl'] = addDays($progress['started'], -1);
        $state['full_confirmations'] = true;
        writeDxccCounts();
        echo "All confirmations downloaded: the ranking is up to date\n";
    }
    $state['full_progress'] = $progress;
    writeJson($stateFile, $state);
}

// Done. Later runs ask for what changed since the day before this download
// started: anything uploaded or confirmed while it ran is not missed (what
// comes twice is merged into the same QSO).
$restart = addDays($progress['started'], -1);
unset($state['full_progress']);
$state['qsl'] = $restart;
$state['full_confirmations'] = true;
if (!$confirmationsOnly) {
    $state['qsorx'] = $restart;
    $state['full_uploads'] = true;
}
writeJson($stateFile, $state);

if (!$confirmationsOnly) {
    echo 'Total: ' . countStoredQsos(QSOS_FILES) . " QSOs\n";
}
$confirmed = $saved['confirmed'] ?? countStoredQsos(CONFIRMED_FILES);
echo "Confirmed in LoTW (ranking): $confirmed QSOs\n";
echo memoryUse();

/**
 * The report kind still to download and its part ([kind, part]), or null when
 * the full download is done: confirmations first, then uploads.
 */
function currentPart(?array $progress): ?array {
    foreach (['confirmations', 'uploads'] as $kind) {
        $part = $progress['parts'][$kind] ?? null;
        if ($part !== null && $part['next'] <= gmdate('Y-m-d')) return [$kind, $part];
    }
    return null;
}

/**
 * Merge the reports of one run or part into the log (unless only
 * confirmations are synced) and the confirmations into the ranking's.
 * Confirmations win over uploads, so their QSL details stay; with
 * $uploadsAfter (a full download, where uploads come after every
 * confirmation) uploads only add what the QSOs do not have yet. $partial: a
 * part of a full download (see storeLotwConfirmations()). Returns the number
 * of new QSOs and the confirmed total.
 */
function saveReports(array $reports, bool $uploadsAfter = false, bool $partial = false): array {
    global $confirmationsOnly;
    $result = ['new' => 0, 'confirmed' => null];
    if (!$confirmationsOnly) {
        $result['new'] = storeQsos(QSOS_FILES, $reports['uploads']['qsos'] ?? [], $uploadsAfter)
            + storeQsos(QSOS_FILES, $reports['confirmations']['qsos'] ?? []);
    }
    if (isset($reports['confirmations'])) $result['confirmed'] = storeLotwConfirmations($reports['confirmations']['qsos'], $partial);
    return $result;
}

/**
 * Run one lotwreport.adi query ($kind 'uploads' or 'confirmations'). Returns
 * the QSOs (LOTW_FIELDS only), the header timestamp to use as the next "since"
 * value (null if the report has none) and the seconds it took. Throws a
 * RuntimeException saying what went wrong, with code CURLE_OPERATION_TIMEDOUT
 * when LoTW did not finish in time and PART_TOO_BIG when the report has more
 * than $maxQsos QSOs (it stops reading there, so memory stays bounded), and
 * LOTW_BUSY when LoTW refuses the report for now (HTTP 429, 502, 503, 504).
 */
function lotwReport(array $query, string $kind, array $params, int $timeout, ?int $maxQsos = null): array {
    // QSL details (the other station's grid, DXCC...) only exist for
    // confirmed QSOs: asking for them in the uploads report only makes it bigger.
    $params = $query + ($kind === 'confirmations' ? ['qso_qsl' => 'yes', 'qso_qsldetail' => 'yes'] : ['qso_qsl' => 'no']) + $params;
    $path = stationData("lotw_report_$kind.tmp");
    $file = fopen($path, 'w+b');
    if ($file === false) throw new RuntimeException("cannot write $path");

    $started = microtime(true);
    $ch = curl_init('https://lotw.arrl.org/lotwuser/lotwreport.adi?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_FILE => $file,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => STALL_TIMEOUT,
        CURLOPT_USERAGENT => 'QSOMap/1.0',
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    fclose($file);
    $seconds = microtime(true) - $started;
    $received = formatBytes((int)filesize($path));
    $took = sprintf('%d s', $seconds);

    try {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $why = $seconds < $timeout - 5
                ? "LoTW stopped sending the $kind report for " . STALL_TIMEOUT . " s (after $took, $received received)"
                : "LoTW did not finish the $kind report in $took ($received received)";
            throw new RuntimeException($why, CURLE_OPERATION_TIMEDOUT);
        }
        if ($errno !== 0) {
            // cURL messages never include the URL, so the password stays out of the log.
            throw new RuntimeException("cannot download the $kind report from LoTW: $error (after $took, $received received)");
        }
        if (in_array($status, [429, 502, 503, 504], true)) {
            throw new RuntimeException("LoTW is busy (HTTP $status to the $kind report)", LOTW_BUSY);
        }
        if ($status !== 200) {
            throw new RuntimeException("LoTW answered HTTP $status to the $kind report (after $took)");
        }
        $qsos = [];
        $fields = array_flip(LOTW_FIELDS);
        $header = readAdifFile($path, function (string $record) use (&$qsos, $fields, $maxQsos, $kind) {
            $qso = array_intersect_key(parseAdifRecord($record), $fields);
            if (!isset($qso['CALL'], $qso['QSO_DATE'])) return;
            if ($maxQsos !== null && count($qsos) >= $maxQsos) {
                $qsos = [];
                throw new RuntimeException("the $kind report has more than $maxQsos QSOs", PART_TOO_BIG);
            }
            $qsos[] = $qso;
        });
        if ($header === null) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)file_get_contents($path, false, null, 0, 4096))));
            if (stripos($text, 'password') !== false) {
                throw new RuntimeException('LoTW rejected the username or password');
            }
            throw new RuntimeException("LoTW did not send an ADIF report ($received in $took): " . substr($text, 0, 200));
        }
    } finally {
        @unlink($path);
    }
    $field = $kind === 'confirmations' ? 'APP_LoTW_LASTQSL' : 'APP_LoTW_LASTQSORX';
    return ['qsos' => $qsos, 'last' => adifHeaderField($header, $field), 'seconds' => $seconds];
}

/** Value of a field in an ADIF header, or null. */
function adifHeaderField(string $header, string $field): ?string {
    if (!preg_match('/<' . preg_quote($field, '/') . ':(\d+)(?::\w)?>/i', $header, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $value = trim(substr($header, $match[0][1] + strlen($match[0][0]), (int)$match[1][0]));
    return $value !== '' ? $value : null;
}

function addDays(string $date, int $days): string {
    return gmdate('Y-m-d', strtotime("$date UTC $days days"));
}

