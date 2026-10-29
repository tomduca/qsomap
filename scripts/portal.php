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

function showPage(string $title, string $body, int $status = 200, string $width = '36rem', string $lang = 'en'): void {
    http_response_code($status);
    $title = h($title);
    echo <<<HTML
<!doctype html>
<html lang="$lang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>$title</title>
<style>
  :root { color-scheme: dark; }
  body { font: 16px/1.5 system-ui, sans-serif; max-width: $width; margin: 2rem auto; padding: 0 16px; color: #e6e8ea; background: #15191c; }
  a { color: #6cc4cf; }
  fieldset { border: 1px solid #3a4248; border-radius: 6px; margin: 0 0 1rem; }
  label { display: block; margin: .5rem 0; }
  input[type=text], input[type=password], input[type=number], input:not([type]), textarea { display: block; width: 100%; box-sizing: border-box; padding: .4rem; font: inherit; color: inherit; background: #1f2529; border: 1px solid #3a4248; border-radius: 4px; }
  textarea { font: 13px/1.4 ui-monospace, monospace; }
  button { padding: .4rem 1.2rem; font: inherit; color: #e6e8ea; background: #2c353b; border: 1px solid #4a555c; border-radius: 4px; cursor: pointer; }
  button:hover { background: #36424a; }
  table { border-collapse: collapse; width: 100%; margin: .5rem 0 1.5rem; }
  th, td { text-align: left; padding: .35rem .5rem; border-bottom: 1px solid #2f373c; vertical-align: top; }
  .table-wrap { overflow-x: auto; }
  .note { font-size: .875rem; color: #9aa4aa; }
  .warning { background: #2e2716; border-left: 4px solid #d9a21b; padding: .5rem .75rem; }
  .error, .failed { color: #ff7b7b; }
  .code { font: 700 2rem ui-monospace, monospace; letter-spacing: .2em; }
  .inline { display: inline; }
  .steps li { margin: .4rem 0; color: #7d878d; }
  .steps li.running { color: inherit; font-weight: 600; }
  .steps li.running::after { content: ' … ' attr(data-detail); font-weight: normal; }
  .steps li.done { color: inherit; }
  .steps li.done::before { content: '✓ '; color: #5fd38a; }
  .steps li.failed::before { content: '✗ '; }
  .steps li.skipped::after { content: ' ' attr(data-skipped); }
  .lang-switch { position: absolute; top: 1rem; right: 16px; padding: .15rem .6rem; border: 1px solid #4a555c; border-radius: 4px; font-size: .875rem; font-weight: 600; text-decoration: none; }
  .lang-switch:hover { background: #2c353b; }
  pre { white-space: pre-wrap; font-size: .8rem; background: #1f2529; padding: .5rem; border-radius: 4px; }
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
