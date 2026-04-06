<?php
/**
 * LOTW Sync to JSON (Fallback when Clublog is not available)
 * Downloads QSOs from Logbook of the World
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Load config
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
    die("Error: Cannot load config.json\n");
}

$username = $config['lotw']['username'] ?? '';
$password = $config['lotw']['password'] ?? '';
$daysBack = $config['lotw']['days_back'] ?? 3650;

if (empty($username) || empty($password)) {
    die("Error: LOTW credentials not configured\n");
}

// Paths
$dataDir = __DIR__ . '/data';
$jsonFile = $dataDir . '/qso_data.json';

// Create data directory if needed
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

echo "=== LOTW Sync Started (Fallback Mode) ===\n";
echo "Callsign: $username\n";
echo "Days back: $daysBack\n\n";

// Calculate start date
$startDate = date('Y-m-d', strtotime("-$daysBack days"));

// LOTW download URL
$url = "https://lotw.arrl.org/lotwuser/lotwreport.adi?" . http_build_query([
    'login' => $username,
    'password' => $password,
    'qso_query' => '1',
    'qso_qsl' => 'no',
    'qso_qsldetail' => 'yes',
    'qso_withown' => 'yes',
    'qso_startdate' => $startDate,
    'qso_enddate' => date('Y-m-d')
]);

echo "Downloading from LOTW...\n";
$adifData = @file_get_contents($url);

if ($adifData === false || empty($adifData)) {
    die("Error: Failed to download from LOTW\n");
}

echo "✓ Downloaded ADIF data (" . strlen($adifData) . " bytes)\n";

// Parse ADIF to extract QSOs
$qsos = parseADIF($adifData);
echo "✓ Parsed " . count($qsos) . " QSOs from LOTW\n";

// Convert to JSON format
$merged = [];
foreach ($qsos as $qso) {
    $key = $qso['CALL'] . '_' . $qso['QSO_DATE'] . '_' . $qso['TIME_ON'] . '_' . $qso['BAND'];
    $merged[$key] = $qso;
}

// Save JSON
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
        if (empty(trim($record))) continue;
        
        $qso = [];
        
        // Extract all fields
        preg_match_all('/<(\w+):(\d+)(?::(\w))?>([^<]*)/i', $record, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $field = strtoupper($match[1]);
            $value = trim($match[4]);
            
            if (!empty($value)) {
                $qso[$field] = $value;
            }
        }
        
        if (!empty($qso)) {
            $qsos[] = $qso;
        }
    }
    
    return $qsos;
}
?>
