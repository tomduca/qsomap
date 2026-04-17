<?php
/**
 * Clublog Sync to JSON
 * Downloads QSOs from Clublog (includes grids when available)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Load config
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
    die("Error: Cannot load config.json\n");
}

$email = $config['clublog']['email'] ?? '';
$password = $config['clublog']['password'] ?? '';  // Application Password
$apiKey = $config['clublog']['api_key'] ?? '';     // Developer API Key
$callsign = $config['clublog']['callsign'] ?? '';

// Check if Clublog is configured
if (empty($email) || empty($password) || empty($apiKey) || empty($callsign)) {
    echo "Warning: Clublog credentials not configured, skipping Clublog sync\n";
    exit(0);  // Exit gracefully to allow fallback to LOTW
}

// Paths
$dataDir = __DIR__ . '/data';
$jsonFile = $dataDir . '/qso_data.json';
$lastSyncFile = $dataDir . '/clublog_last_sync.txt';

// Create data directory if needed
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

echo "=== Clublog Sync Started ===\n";
echo "Callsign: $callsign\n\n";

// Check for incremental sync
$isIncrementalSync = false;
$startDate = null;

if (file_exists($lastSyncFile)) {
    $lastSync = trim(file_get_contents($lastSyncFile));
    if (!empty($lastSync)) {
        // Parse last sync date (format: YYYYMMDD)
        $lastSyncDate = DateTime::createFromFormat('Ymd', $lastSync);
        if ($lastSyncDate) {
            // Download QSOs from 2 days before last sync (safety margin to avoid missing QSOs)
            $startDate = $lastSyncDate->modify('-2 days');
            $isIncrementalSync = true;
            echo "Incremental sync: downloading QSOs since " . $startDate->format('Y-m-d') . " (2-day overlap for safety)\n";
        }
    }
}

if (!$isIncrementalSync) {
    echo "Full sync: downloading all historical QSOs\n";
}

// Clublog download endpoint with API password
// Using POST as per Clublog API documentation
$url = "https://clublog.org/getadif.php";

echo "Email: $email\n";
echo "Callsign: $callsign\n";

// Build request parameters
$params = [
    'email' => $email,
    'password' => $password,  // Application Password (user auth)
    'api' => $apiKey,         // API Key (developer/app identifier)
    'call' => $callsign       // Callsign to download
];

// Add date filter for incremental sync
if ($isIncrementalSync && $startDate) {
    $params['startyear'] = $startDate->format('Y');
    $params['startmonth'] = $startDate->format('n');
    $params['startday'] = $startDate->format('j');
    echo "Filtering: from " . $startDate->format('Y-m-d') . "\n";
}

$postData = http_build_query($params);

echo "Downloading from Clublog (API key method)...\n";

// Use cURL for better error handling
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: QSOMap/1.0'
]);

$adifData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Response Code: $httpCode\n";

if ($httpCode !== 200) {
    echo "Warning: Clublog returned HTTP $httpCode\n";
    if ($curlError) {
        echo "cURL Error: $curlError\n";
    }
    echo "Clublog sync failed, will fallback to LOTW if available\n";
    exit(1);  // Exit with error code to trigger fallback
}

if (empty($adifData)) {
    die("Error: Empty response from Clublog\n");
}

echo "Response length: " . strlen($adifData) . " bytes\n";

// Check if we got valid ADIF data (should contain ADIF markers)
if (strpos($adifData, 'ADIF') === false && strpos($adifData, '<eoh>') === false) {
    die("Error: Invalid response from Clublog: " . substr($adifData, 0, 500) . "\n");
}

echo "✓ Downloaded ADIF data\n";

// Parse ADIF to extract QSOs
$qsos = parseADIF($adifData);
echo "✓ Parsed " . count($qsos) . " QSOs from Clublog\n";

// Load existing QSOs for incremental sync
$merged = [];
if ($isIncrementalSync && file_exists($jsonFile)) {
    $existingJson = file_get_contents($jsonFile);
    $merged = json_decode($existingJson, true);
    if (!is_array($merged)) {
        $merged = [];
    }
    echo "✓ Loaded " . count($merged) . " existing QSOs\n";
}

// Merge new QSOs
$newCount = 0;
$updatedCount = 0;
$gridCount = 0;

foreach ($qsos as $qso) {
    $key = $qso['CALL'] . '_' . $qso['QSO_DATE'] . '_' . $qso['TIME_ON'] . '_' . $qso['BAND'];
    
    if (!empty($qso['GRIDSQUARE'])) {
        $gridCount++;
    }
    
    if (isset($merged[$key])) {
        $updatedCount++;
    } else {
        $newCount++;
    }
    
    $merged[$key] = $qso;
}

if ($isIncrementalSync) {
    echo "✓ New QSOs: $newCount\n";
    echo "✓ Updated QSOs: $updatedCount\n";
} else {
    echo "✓ Total QSOs from Clublog: " . count($merged) . "\n";
}
echo "✓ QSOs with grid in this batch: $gridCount\n";

// Save merged JSON
$jsonData = json_encode($merged, JSON_PRETTY_PRINT);
file_put_contents($jsonFile, $jsonData);
echo "✓ JSON saved to: $jsonFile\n";

// Update last sync date (today)
$today = date('Ymd');
file_put_contents($lastSyncFile, $today);
echo "✓ Last sync date updated: " . date('Y-m-d') . "\n";

echo "\n=== Clublog Sync Complete ===\n";
echo "Total QSOs in database: " . count($merged) . "\n";
if ($isIncrementalSync) {
    echo "New/Updated in this sync: " . ($newCount + $updatedCount) . "\n";
}

// Count total grids in final dataset
$totalGrids = 0;
foreach ($merged as $qso) {
    if (!empty($qso['GRIDSQUARE'])) {
        $totalGrids++;
    }
}
echo "Total QSOs with grid: $totalGrids\n";

/**
 * Parse ADIF content into array of QSOs
 */
function parseADIF($content) {
    $qsos = [];
    
    // Split by <eor> tag
    $records = preg_split('/<eor>/i', $content);
    
    foreach ($records as $record) {
        if (trim($record) == '' || strpos($record, '<CALL') === false) {
            continue;
        }
        
        $qso = [];
        
        // Extract fields using regex
        preg_match_all('/<(\w+):(\d+)(?::(\w))?>([^<]*)/i', $record, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $field = strtoupper($match[1]);
            $value = trim($match[4]);
            
            // Store important fields
            if (in_array($field, ['CALL', 'BAND', 'MODE', 'FREQ', 'QSO_DATE', 'TIME_ON', 'GRIDSQUARE', 'DXCC', 'COMMENT', 'SIG', 'SIG_INFO', 'LOTW_QSL_RCVD', 'QSL_RCVD', 'CLUBLOG_QSO_UPLOAD_STATUS'])) {
                $qso[$field] = $value;
            }
        }
        
        // Only add if we have minimum required fields
        if (isset($qso['CALL']) && isset($qso['QSO_DATE'])) {
            $qsos[] = $qso;
        }
    }
    
    return $qsos;
}

/**
 * Merge new QSOs with existing, avoiding duplicates
 */
function mergeQSOs($existing, $new) {
    // Create index of existing QSOs by unique key
    $index = [];
    foreach ($existing as $qso) {
        $key = getQSOKey($qso);
        $index[$key] = $qso;
    }
    
    // Add new QSOs
    foreach ($new as $qso) {
        $key = getQSOKey($qso);
        $index[$key] = $qso; // Overwrites if exists (updates data)
    }
    
    return array_values($index);
}

/**
 * Generate unique key for QSO
 */
function getQSOKey($qso) {
    $call = $qso['CALL'] ?? '';
    $date = $qso['QSO_DATE'] ?? '';
    $time = $qso['TIME_ON'] ?? '';
    $band = $qso['BAND'] ?? '';
    
    return "$call|$date|$time|$band";
}
?>
