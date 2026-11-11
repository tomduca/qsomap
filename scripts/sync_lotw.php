<?php
/**
 * Download a station's QSOs from LoTW (Logbook of The World) into
 * stations/<CALL>/data/qso_data.json, and its LoTW confirmations into
 * lotw_confirmed.json for the club ranking (see ranking_data.php).
 *
 * Follows ARRL's guidance for automated downloads: the first run downloads
 * the whole log (since FULL_SINCE: without a date LoTW only returns what is
 * new since the account's previous query); later runs only ask for what
 * changed, using the timestamps LoTW returns in each report header:
 *   - qso_qsorxsince: QSOs uploaded since the last run, confirmed or not
 *   - qso_qslsince:   new confirmations since the last run, including old QSOs
 * Results are merged into qso_data.json, so LoTW works both as the only source
 * and as a fallback for Clublog. Stations synced before version 2.2.0 only
 * had the last years of their log: they download everything once more.
 * Delete stations/<CALL>/data/lotw_last_sync.json to force a full download.
 *
 * The full download goes in parts by QSO date, so a log of hundreds of
 * thousands of QSOs never needs one huge report that LoTW cannot produce in
 * time. Each part is saved before the next one starts and the progress is
 * kept in lotw_last_sync.json, so an interrupted download resumes where it
 * stopped. The parts adapt to the log: they grow while LoTW answers fast and
 * shrink when it is slow or times out. From a browser each run stops after
 * WEB_BUDGET seconds and ends with "Run again to continue from <date>" while
 * parts remain (join.php repeats it); from the terminal it runs to the end.
 * Reports are saved to a temporary file and read record by record, so their
 * size does not matter for memory.
 *
 * With --confirmations-only (command line, used by sync_daily.sh for stations
 * synced from Clublog while the ranking is on) only the confirmations report
 * runs and only lotw_confirmed.json changes; a station without LoTW
 * credentials is skipped without error.
 */

require __DIR__ . '/ranking_data.php';
useStation(requireCliOrToken(true));
ignore_user_abort(true); // a closed browser tab must not stop a part half saved
// From a browser, hold the output so an error after some progress lines can
// still answer HTTP 500 (join.php tells errors from progress by the status).
if (PHP_SAPI !== 'cli') ob_start();

const FULL_SINCE = '1900-01-01';
const FIRST_QSO_DATE = '1945-11-01';  // LoTW accepts no earlier QSOs
const REPORT_TIMEOUT = 240;           // seconds LoTW gets for one report of a part
const INCREMENTAL_TIMEOUT = 600;      // seconds for the reports of an incremental run
const PART_START_DAYS = 1461;         // first part: 4 years
const PART_MAX_DAYS = 1461;           // 4 years: bigger reports take LoTW too long
const PART_MIN_DAYS = 7;
const FAST_PART = 30;                 // seconds: a faster part doubles the next one
const SLOW_PART = 120;                // seconds: a slower part halves the next one
const WEB_BUDGET = 180;               // seconds: from a browser, no new part after this
const BIG_PART = 30000;               // QSOs: a part with more shrinks the next one (memory)
const TOO_BIG_PART = 60000;           // QSOs: a report with more is dropped and its part halved
const PART_TOO_BIG = 1;               // exception code for that case
const LOTW_BUSY = 2;                  // exception code: LoTW refused the report for now (HTTP 503...)
const BUSY_WAIT = 60;                 // seconds to wait before asking a busy LoTW again
const BUSY_TRIES = 5;                 // from the terminal: tries before giving up
// Fields kept from LoTW records: the ones build_cache.php and the ranking use.
// LoTW adds many APP_LoTW_* fields that would only make qso_data.json bigger.
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

$jsonFile = stationData('qso_data.json');
$confirmedFile = stationData('lotw_confirmed.json');
$stateFile = stationData('lotw_last_sync.json');
if (!is_dir(dirname($stateFile))) mkdir(dirname($stateFile), 0755, true);

// One sync per station at a time: the nightly cron and a browser could overlap.
$lock = fopen(stationData('lotw_sync.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('another LoTW sync of this station is running; try again when it ends');
}

$state = readJson($stateFile) ?? [];
$confirmationsKnown = isset($state['qsl']) && !empty($state['full_confirmations']) && is_file($confirmedFile);
$incremental = $confirmationsOnly
    ? $confirmationsKnown
    : $confirmationsKnown && isset($state['qsorx']) && !empty($state['full_uploads']) && is_file($jsonFile);
$kinds = $confirmationsOnly ? ['confirmations'] : ['uploads', 'confirmations'];

$query = [
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_qsldetail' => 'yes',
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
        echo "Total: {$saved['total']} QSOs\n";
    } else {
        echo 'Confirmations: ' . count($reports['confirmations']['qsos']) . "\n";
    }
    echo "Confirmed in LoTW (ranking): {$saved['confirmed']} QSOs\n";
    echo memoryUse();
    exit(0);
}

// Full download, in parts by QSO date.
$today = gmdate('Y-m-d');
$progress = $state['full_progress'] ?? null;
if (!is_array($progress) || ($progress['kinds'] ?? null) !== $kinds || !isset($progress['next'], $progress['days'], $progress['started'])) {
    $progress = ['kinds' => $kinds, 'next' => FIRST_QSO_DATE, 'days' => PART_START_DAYS, 'started' => $today];
    echo "=== LoTW full $what: $username (whole log, in parts by QSO date) ===\n";
} else {
    echo "=== LoTW full $what: $username, resuming from QSOs of {$progress['next']} ===\n";
    $progress['days'] = min((int)$progress['days'], PART_MAX_DAYS);
}

$runStart = microtime(true);
$saved = null;
$busyTries = 0;
while ($progress['next'] <= $today) {
    if (PHP_SAPI !== 'cli' && microtime(true) - $runStart > WEB_BUDGET) {
        echo "Run again to continue from {$progress['next']}\n";
        exit(0);
    }
    $from = $progress['next'];
    $to = min(addDays($from, $progress['days'] - 1), $today);
    set_time_limit(REPORT_TIMEOUT * count($kinds) + 120);
    $range = ['qso_startdate' => $from, 'qso_enddate' => $to];
    $since = ['uploads' => ['qso_qsorxsince' => FULL_SINCE], 'confirmations' => ['qso_qslsince' => FULL_SINCE]];
    $reports = [];
    try {
        foreach ($kinds as $kind) $reports[$kind] = lotwReport($query, $kind, $since[$kind] + $range, REPORT_TIMEOUT, TOO_BIG_PART);
    } catch (RuntimeException $e) {
        if ($e->getCode() === LOTW_BUSY) {
            // Usually LoTW is still preparing a report that timed out here,
            // and refuses another one of the same account until it ends.
            $state['full_progress'] = $progress;
            writeJson($stateFile, $state);
            if (PHP_SAPI !== 'cli' || ++$busyTries >= BUSY_TRIES) {
                fail("QSOs $from to $to: " . $e->getMessage() . '. LoTW is probably still preparing an earlier report:'
                    . " wait a few minutes and run this again, it continues from $from");
            }
            echo "QSOs $from to $to: " . $e->getMessage() . ', waiting ' . BUSY_WAIT . " s\n";
            sleep(BUSY_WAIT);
            continue;
        }
        $busyTries = 0;
        if (!in_array($e->getCode(), [CURLE_OPERATION_TIMEDOUT, PART_TOO_BIG], true)) fail("QSOs $from to $to: " . $e->getMessage());
        if ($progress['days'] <= PART_MIN_DAYS) {
            fail("QSOs $from to $to: " . $e->getMessage() . ', even for ' . PART_MIN_DAYS . ' days; LoTW may be overloaded, try again later');
        }
        // Parts this long are too much for LoTW or for memory: never grow back to that size.
        $progress['days'] = max(PART_MIN_DAYS, intdiv($progress['days'], 2));
        $progress['max_days'] = $progress['days'];
        $state['full_progress'] = $progress;
        writeJson($stateFile, $state);
        echo "QSOs $from to $to: " . $e->getMessage() . ", trying again with {$progress['days']} days\n";
        continue;
    }
    $saved = saveReports($reports);
    $busyTries = 0;
    $seconds = max(array_column($reports, 'seconds'));
    $counts = [];
    foreach ($reports as $kind => $report) $counts[] = count($report['qsos']) . " $kind";
    printf("QSOs %s to %s: %s (%d s)\n", $from, $to, implode(', ', $counts), $seconds);

    // Size the next part: double it while LoTW is fast and the part small,
    // shrink it when LoTW was slow or the part had too many QSOs for memory.
    $progress['next'] = addDays($to, 1);
    $size = max(array_map(fn($report) => count($report['qsos']), $reports));
    if ($seconds > SLOW_PART || $size > BIG_PART) {
        $days = $size > BIG_PART ? (int)($progress['days'] * BIG_PART / $size) : intdiv($progress['days'], 2);
        $progress['days'] = $progress['max_days'] = max(PART_MIN_DAYS, $days);
    } elseif ($seconds < FAST_PART && $size < BIG_PART / 2) {
        $progress['days'] = min($progress['max_days'] ?? PART_MAX_DAYS, $progress['days'] * 2);
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
    $total = $saved['total'] ?? count(readJson($jsonFile) ?? []);
    echo "Total: $total QSOs\n";
}
$confirmed = $saved['confirmed'] ?? count(readJson($confirmedFile) ?? []);
echo "Confirmed in LoTW (ranking): $confirmed QSOs\n";
echo memoryUse();

/**
 * Merge the reports of one run or part into qso_data.json (uploads first, so
 * the QSL details of confirmations win) and lotw_confirmed.json. Returns the
 * number of new QSOs, the QSO total and the confirmed total.
 */
function saveReports(array $reports): array {
    global $jsonFile;
    $result = ['new' => 0, 'total' => null];
    if (isset($reports['uploads'])) {
        // Re-key only if some key is in an older format: copying a big log
        // just to re-key it could double the memory it needs.
        $merged = readJson($jsonFile) ?? [];
        foreach ($merged as $key => $qso) {
            if (qsoKey($qso) !== (string)$key) {
                $merged = mergeQsos($merged);
                break;
            }
        }
        $before = count($merged);
        addQsos($merged, $reports['uploads']['qsos']);
        addQsos($merged, $reports['confirmations']['qsos']);
        writeJson($jsonFile, $merged);
        $result = ['new' => count($merged) - $before, 'total' => count($merged)];
        unset($merged);
    }
    $result['confirmed'] = storeLotwConfirmations($reports['confirmations']['qsos']);
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
    $params = $query + ['qso_qsl' => $kind === 'confirmations' ? 'yes' : 'no'] + $params;
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
            throw new RuntimeException("LoTW did not finish the $kind report in $took ($received received)", CURLE_OPERATION_TIMEDOUT);
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

function formatBytes(int $bytes): string {
    return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', $bytes / 1024);
}

function memoryUse(): string {
    return 'Memory: ' . formatBytes(memory_get_peak_usage(true)) . ' peak, limit ' . ini_get('memory_limit') . "\n";
}
