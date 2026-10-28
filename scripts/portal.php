<?php
/**
 * Page helpers shared by the two portals: join.php (public, for a station
 * setting up its map) and admin/index.php (the manager's invites and stations).
 */

function portalHeaders(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}

function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); // local testing
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Embed codes for a station's static maps (QRZ.com iframe, GitHub README image). */
function embedCodes(string $baseUrl, string $call): array {
    return [
        'qrz' => '<iframe align="top" frameborder="0" height="600" scrolling="no" src="'
            . "$baseUrl/map-ssb-qrz.php?$call" . '" width="600"></iframe>',
        'github' => "[![$call SSB map]($baseUrl/map-ssb-github.php?$call)]($baseUrl/map-ssb.html?$call)",
    ];
}

function showPage(string $title, string $body, int $status = 200, string $width = '36rem'): void {
    http_response_code($status);
    $title = h($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>$title</title>
<style>
  body { font: 16px/1.5 system-ui, sans-serif; max-width: $width; margin: 2rem auto; padding: 0 16px; color: #1d1d1f; background: #fff; }
  fieldset { border: 1px solid #ccc; border-radius: 6px; margin: 0 0 1rem; }
  label { display: block; margin: .5rem 0; }
  input[type=text], input[type=password], input:not([type]), textarea { display: block; width: 100%; box-sizing: border-box; padding: .4rem; font: inherit; }
  textarea { font: 13px/1.4 ui-monospace, monospace; }
  button { padding: .4rem 1.2rem; font: inherit; }
  table { border-collapse: collapse; width: 100%; margin: .5rem 0 1.5rem; }
  th, td { text-align: left; padding: .35rem .5rem; border-bottom: 1px solid #ddd; vertical-align: top; }
  .table-wrap { overflow-x: auto; }
  .note { font-size: .875rem; color: #555; }
  .warning { background: #fff6e0; border-left: 4px solid #e0a000; padding: .5rem .75rem; }
  .error, .failed { color: #b00020; }
  .code { font: 700 2rem ui-monospace, monospace; letter-spacing: .2em; }
  .inline { display: inline; }
  .steps li { margin: .4rem 0; color: #888; }
  .steps li.running { color: inherit; font-weight: 600; }
  .steps li.running::after { content: ' … ' attr(data-detail); font-weight: normal; }
  .steps li.done { color: inherit; }
  .steps li.done::before { content: '✓ '; color: #1b7f3b; }
  .steps li.failed::before { content: '✗ '; }
  .steps li.skipped::after { content: ' (skipped, will retry tonight)'; }
  pre { white-space: pre-wrap; font-size: .8rem; background: #f5f5f5; padding: .5rem; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;
    exit;
}
