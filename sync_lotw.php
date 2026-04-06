<?php
/**
 * LOTW Sync to Incremental JSON
 * Downloads QSOs from LOTW and merges with existing JSON cache
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Load config
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
    die("Error: Cannot load config.json\n");
}

$username = $config['lotw']['username'];
$password = $config['lotw']['password'];
$daysBack = $config['lotw']['days_back'] ?? 3650;

// Paths
$dataDir = __DIR__ . '/data';
$adifFile = $dataDir . '/lotw_qsos.adi';
$jsonFile = $dataDir . '/qso_data.json';

// Create data directory if needed
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

echo "=== LOTW Sync Started ===\n";
echo "Username: $username\n";
echo "Days back: $daysBack\n\n";

// Build LOTW URL - download ALL QSOs (not just confirmed)
$startDate = date('Y-m-d', strtotime("-$daysBack days"));
$url = "https://lotw.arrl.org/lotwuser/lotwreport.adi?" . http_build_query([
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_qsl' => 'no',        // Download all QSOs, not just confirmed
    'qso_withown' => 'yes',   // Include own QSOs
    'qso_startdate' => $startDate,
    'qso_starttime' => '0000',
    'qso_enddate' => date('Y-m-d'),
    'qso_endtime' => '2359'
]);

echo "Downloading from LOTW...\n";
$adifData = @file_get_contents($url);

if ($adifData === false) {
    die("Error: Failed to download from LOTW\n");
}

// Save ADIF file
file_put_contents($adifFile, $adifData);
echo "✓ ADIF saved to: $adifFile\n";

// Parse ADIF to extract QSOs
$qsos = parseADIF($adifData);
echo "✓ Parsed " . count($qsos) . " QSOs from LOTW\n";

// Load existing JSON data
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

// Save merged JSON
$jsonData = json_encode($merged, JSON_PRETTY_PRINT);
file_put_contents($jsonFile, $jsonData);
echo "✓ JSON saved to: $jsonFile\n";

echo "\n=== LOTW Sync Complete ===\n";
echo "Total QSOs: " . count($merged) . "\n";

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
