#!/usr/bin/env php
<?php
/**
 * Generate Base64-encoded HTML for QRZ.com embedding
 * 
 * This script reads index-qrz-embed.html and generates the Base64 string
 * that can be used in QRZ.com biography following the LU1ANG pattern.
 * 
 * Usage: php generate_qrz_embed.php
 */

$embedFile = __DIR__ . '/index-qrz-embed.html';
$outputFile = __DIR__ . '/qrz-embed-code.txt';

if (!file_exists($embedFile)) {
    die("Error: $embedFile not found\n");
}

// Read the embed HTML
$html = file_get_contents($embedFile);

// Replace relative URLs with absolute URLs
$baseUrl = 'https://lu2met.ar/qsomap';
$html = str_replace('href="data/', 'href="' . $baseUrl . '/data/', $html);
$html = str_replace('src="data/', 'src="' . $baseUrl . '/data/', $html);
$html = str_replace("fetch('data/", "fetch('" . $baseUrl . "/data/", $html);

// Encode to Base64
$base64 = base64_encode($html);

// Generate the complete QRZ.com embed code
$qrzCode = <<<HTML
<div style="margin-left:-30px; margin-right:30px; margin-top:-25px; position:absolute; width:100%; z-index:100">
<img src="https://hamawward.cloud/static/award_html/height/420.svg" style="float:left; visibility:hidden; z-index:5">
<iframe height="100%" name="iframepage" scrolling="no" src="about:blank" style="float: left; height:100%; width:100%; position: absolute; left: 0; right:0;top: 0; bottom:0; z-index:10;border:0;margin:0px;" width="100%"></iframe>
</div>
<p> </p>
<div style="float:left; min-height:1px; width:100%">
<img src="https://hamawward.cloud/static/award_html/height/420.svg" style="float:left; visibility:hidden; z-index:5"> 
</div>
<p> </p>

<script>
// This is the Base64-encoded HTML content
var embedHtml = '$base64';

// Decode and inject into iframe
var iframe = document.querySelector('iframe[name="iframepage"]');
if (iframe) {
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(atob(embedHtml));
    doc.close();
}
</script>
HTML;

// Save to file
file_put_contents($outputFile, $qrzCode);

echo "✓ QRZ.com embed code generated successfully!\n\n";
echo "File saved to: $outputFile\n\n";
echo "Instructions:\n";
echo "1. Open $outputFile\n";
echo "2. Copy the entire content\n";
echo "3. Paste it into your QRZ.com biography editor\n";
echo "4. Save your QRZ.com profile\n\n";
echo "Note: The map will load data from https://lu2met.ar/qsomap/data/qso_cache.json\n";
echo "Make sure your cache is up to date and accessible.\n";

?>
