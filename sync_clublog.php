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

$email = $config['clublog']['email'];
$apiKey = $config['clublog']['api_key'];
$callsign = $config['clublog']['callsign'];

// Paths
$dataDir = __DIR__ . '/data';
$jsonFile = $dataDir . '/qso_data.json';

// Create data directory if needed
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

echo "=== Clublog Sync Started ===\n";
echo "Callsign: $callsign\n\n";

// Clublog download endpoint with API password
// Using POST as per Clublog API documentation
$url = "https://clublog.org/getadif.php";

// Use API key to download full log
$postData = http_build_query([
    'api' => $apiKey,
    'email' => $email,
    'callsign' => $callsign,
    'full' => 'yes'  // Request full log
]);

echo "API Key: " . substr($apiKey, 0, 10) . "...\n";
echo "Email: $email\n";
echo "Callsign: $callsign\n";

echo "Downloading from Clublog (POST with cURL)...\n";

// Use cURL for better error handling
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$adifData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Response Code: $httpCode\n";

if ($httpCode !== 200) {
    echo "Error: HTTP $httpCode\n";
    if ($curlError) {
        echo "cURL Error: $curlError\n";
    }
    echo "Response: " . substr($adifData, 0, 500) . "\n";
    die("Error: Failed to download from Clublog (HTTP $httpCode)\n");
}

if (empty($adifData)) {
    die("Error: Empty response from Clublog\n");
}

echo "Response length: " . strlen($adifData) . " bytes\n";
echo "First 200 chars: " . substr($adifData, 0, 200) . "\n";

// Check if we got an error message instead of ADIF
if (strpos($adifData, '<eor>') === false) {
    die("Error: Invalid response from Clublog: " . substr($adifData, 0, 200) . "\n");
}

echo "✓ Downloaded ADIF data\n";

// Parse ADIF to extract QSOs
$qsos = parseADIF($adifData);
echo "✓ Parsed " . count($qsos) . " QSOs from Clublog\n";

// Load existing JSON data for merging
$existingData = [];
if (file_exists($jsonFile)) {
    $existingData = json_decode(file_get_contents($jsonFile), true);
    if (!$existingData) {
        $existingData = [];
    }
    echo "✓ Loaded " . count($existingData) . " existing QSOs from JSON\n";
}

// Merge QSOs (incremental - avoid duplicates)
$merged = mergeQSOs($existingData, $qsos);
echo "✓ Total QSOs after merge: " . count($merged) . "\n";

// Count QSOs with grids
$withGrid = 0;
foreach ($merged as $qso) {
    if (!empty($qso['GRIDSQUARE'])) {
        $withGrid++;
    }
}
echo "✓ QSOs with grid from Clublog: $withGrid\n";

// Save merged JSON
$jsonData = json_encode($merged, JSON_PRETTY_PRINT);
file_put_contents($jsonFile, $jsonData);
echo "✓ JSON saved to: $jsonFile\n";

echo "\n=== Clublog Sync Complete ===\n";
echo "Total QSOs: " . count($merged) . "\n";
echo "QSOs with grid: $withGrid\n";

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
            if (in_array($field, ['CALL', 'BAND', 'MODE', 'FREQ', 'QSO_DATE', 'TIME_ON', 'GRIDSQUARE', 'COMMENT', 'SIG', 'SIG_INFO'])) {
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
