<?php
/**
 * Build QSO Cache with Grid Lookups
 * - Uses HamQTH as primary source for grid lookups
 * - Falls back to Spothole API if HamQTH fails
 * - Preserves existing lookups to avoid redundant API calls
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

// Load config
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
    die("Error: Cannot load config.json\n");
}

$hamqthUser = $config['hamqth']['username'];
$hamqthPass = $config['hamqth']['password'];
$qrzUser = $config['qrz']['username'] ?? '';
$qrzPass = $config['qrz']['password'] ?? '';

// Paths
$dataDir = __DIR__ . '/data';
$qsoDataFile = $dataDir . '/qso_data.json';
$cacheFile = $dataDir . '/qso_cache.json';

if (!file_exists($qsoDataFile)) {
    die("Error: QSO data file not found. Run sync_lotw.php first.\n");
}

echo "=== Building QSO Cache ===\n\n";

// Load QSO data
$qsos = json_decode(file_get_contents($qsoDataFile), true);
echo "Loaded " . count($qsos) . " QSOs\n";

// Load existing cache to preserve lookups
$existingCache = [];
if (file_exists($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true);
    if ($cache && isset($cache['qsos'])) {
        foreach ($cache['qsos'] as $qso) {
            $key = ($qso['call'] ?? '') . '|' . ($qso['date'] ?? '') . '|' . ($qso['time'] ?? '');
            $existingCache[$key] = $qso;
        }
        echo "Loaded " . count($existingCache) . " cached QSOs\n";
    }
}

// Login to HamQTH
echo "\nLogging in to HamQTH...\n";
$hamqthSession = hamqthLogin($hamqthUser, $hamqthPass);
if ($hamqthSession) {
    echo "✓ HamQTH session: $hamqthSession\n";
} else {
    echo "⚠ HamQTH login failed\n";
}

// Login to QRZ
$qrzSession = null;
if ($qrzUser && $qrzPass) {
    echo "Logging in to QRZ.com...\n";
    $qrzSession = qrzLogin($qrzUser, $qrzPass);
    if ($qrzSession) {
        echo "✓ QRZ.com session: $qrzSession\n";
    } else {
        echo "⚠ QRZ.com login failed\n";
    }
}

// Process QSOs
$stats = [
    'total' => 0,
    'with_grid' => 0,
    'lookups' => 0,
    'hamqth' => 0,
    'spothole' => 0,
    'failed' => 0
];

$processedQSOs = [];

echo "\nProcessing QSOs...\n";

foreach ($qsos as $qso) {
    $stats['total']++;
    
    // Build cache key
    $cacheKey = ($qso['CALL'] ?? '') . '|' . ($qso['QSO_DATE'] ?? '') . '|' . ($qso['TIME_ON'] ?? '');
    
    // Check if already in cache
    if (isset($existingCache[$cacheKey]) && !empty($existingCache[$cacheKey]['grid'])) {
        $processedQSOs[] = $existingCache[$cacheKey];
        $stats['with_grid']++;
        continue;
    }
    
    // Build processed QSO
    $processed = [
        'call' => $qso['CALL'] ?? '',
        'band' => $qso['BAND'] ?? '',
        'mode' => $qso['MODE'] ?? '',
        'freq' => isset($qso['FREQ']) ? floatval($qso['FREQ']) : null,
        'date' => $qso['QSO_DATE'] ?? '',
        'time' => $qso['TIME_ON'] ?? '',
        'grid' => $qso['GRIDSQUARE'] ?? '',
        'name' => $qso['NAME'] ?? '',
        'qth' => $qso['QTH'] ?? '',
        'comment' => $qso['COMMENT'] ?? '',
        'sig' => $qso['SIG'] ?? '',
        'sig_info' => $qso['SIG_INFO'] ?? ''
    ];
    
    // Only lookup missing fields, never overwrite existing data from Clublog
    if ((empty($processed['grid']) || empty($processed['name']) || empty($processed['qth'])) && !empty($processed['call'])) {
        $stats['lookups']++;
        
        // Try HamQTH first
        if ($hamqthSession) {
            $info = lookupCallsignHamQTH($processed['call'], $hamqthSession);
            if ($info) {
                // Only fill missing fields, never overwrite existing ones
                if (empty($processed['grid']) && !empty($info['grid'])) {
                    $processed['grid'] = $info['grid'];
                }
                if (empty($processed['name']) && !empty($info['name'])) {
                    $processed['name'] = $info['name'];
                }
                if (empty($processed['qth']) && !empty($info['qth'])) {
                    $processed['qth'] = $info['qth'];
                }
                $stats['hamqth']++;
                echo ".";
                usleep(20000); // 20ms delay
            }
        }
        
        // Fallback to Spothole for grid only if still missing
        if (empty($processed['grid'])) {
            $grid = lookupGridSpothole($processed['call']);
            if ($grid) {
                $processed['grid'] = $grid;
                $stats['spothole']++;
                echo ".";
                usleep(50000); // 50ms delay
            } else {
                $stats['failed']++;
            }
        }
    } elseif (!empty($processed['grid'])) {
        $stats['with_grid']++;
    }
    
    $processedQSOs[] = $processed;
    
    // Progress indicator
    if ($stats['total'] % 50 == 0) {
        echo " [{$stats['total']}]\n";
    }
}

echo "\n\n";

// Build final cache
$cache = [
    'generated' => date('Y-m-d H:i:s'),
    'total_qsos' => $stats['total'],
    'qsos' => $processedQSOs
];

// Save cache
file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));

echo "=== Cache Build Complete ===\n";
echo "Total QSOs: {$stats['total']}\n";
echo "QSOs with grid: {$stats['with_grid']}\n";
echo "Lookups performed: {$stats['lookups']}\n";
echo "  - HamQTH: {$stats['hamqth']}\n";
echo "  - Spothole: {$stats['spothole']}\n";
echo "  - Failed: {$stats['failed']}\n";
echo "Cache saved to: $cacheFile\n";

/**
 * Login to HamQTH and get session ID
 */
function hamqthLogin($username, $password) {
    $url = "https://www.hamqth.com/xml.php?" . http_build_query([
        'u' => $username,
        'p' => $password
    ]);
    
    $xml = @file_get_contents($url);
    if (!$xml) return null;
    
    $data = @simplexml_load_string($xml);
    if (!$data) return null;
    
    return (string)$data->session->session_id ?? null;
}

/**
 * Lookup callsign info via HamQTH (grid, name, qth)
 */
function lookupCallsignHamQTH($callsign, $sessionId) {
    $url = "https://www.hamqth.com/xml.php?" . http_build_query([
        'id' => $sessionId,
        'callsign' => $callsign,
        'prg' => 'qsomap'
    ]);
    
    $xml = @file_get_contents($url);
    if (!$xml) return null;
    
    $data = @simplexml_load_string($xml);
    if (!$data || !isset($data->search)) return null;
    
    $info = [];
    
    // Grid square
    if (isset($data->search->grid)) {
        $info['grid'] = (string)$data->search->grid;
    }
    
    // Name (try nick first, then adr_name)
    if (isset($data->search->nick) && !empty((string)$data->search->nick)) {
        $info['name'] = (string)$data->search->nick;
    } elseif (isset($data->search->adr_name)) {
        $info['name'] = (string)$data->search->adr_name;
    }
    
    // QTH/City
    if (isset($data->search->adr_city) && !empty((string)$data->search->adr_city)) {
        $info['qth'] = (string)$data->search->adr_city;
    } elseif (isset($data->search->qth)) {
        $info['qth'] = (string)$data->search->qth;
    }
    
    return !empty($info) ? $info : null;
}

/**
 * Lookup grid via Spothole API (fallback)
 */
function lookupGridSpothole($callsign) {
    $url = "https://api.spothole.net/lookup/call/" . urlencode($callsign);
    
    $json = @file_get_contents($url);
    if (!$json) return null;
    
    $data = json_decode($json, true);
    if (!$data) return null;
    
    $grid = $data['grid'] ?? '';
    return !empty($grid) ? $grid : null;
}
?>
