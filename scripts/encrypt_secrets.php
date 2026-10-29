<?php
/**
 * Encrypt the passwords stored in every station's config.json with the key in
 * storage.key_file (root config.json), creating the key the first time.
 * Already encrypted values are left alone, so it can run again at any time,
 * e.g. after a password was typed into a config.json by hand; sync_daily.sh
 * runs it every night when storage.key_file is set.
 *
 * The key is only created while no config holds encrypted values: if it goes
 * missing later, a new one could not decrypt them, so this stops instead.
 */

require __DIR__ . '/common.php';
requireCliOrToken();

echo "=== Encrypt stored passwords ===\n";

$keyFile = storagePath('key_file');
if ($keyFile === null) {
    fail('set storage.key_file in config.json to a path outside the web folder (see docs/DEPLOYMENT.md)');
}

$configs = [];
foreach (stationList() as $call) {
    $configs[$call] = readJson(QSOMAP_STATIONS . "/$call/config.json") ?? [];
}

if (!is_file($keyFile)) {
    foreach ($configs as $call => $config) {
        foreach (SECRET_FIELDS as [$section, $field]) {
            if (isEncrypted($config[$section][$field] ?? '')) {
                fail("$keyFile is missing but $call has encrypted passwords: restore the key file instead of creating a new one");
            }
        }
    }
    $keyDir = realpath(dirname($keyFile));
    if ($keyDir === false) fail('the folder of storage.key_file does not exist: ' . dirname($keyFile));
    if (insideWebFolder($keyDir)) {
        fail('storage.key_file must be outside the web folder (public_html), so it is never served or copied with QSO Map');
    }
    if (!function_exists('sodium_crypto_secretbox_keygen')) fail('PHP has no sodium extension');
    $old = umask(0077);
    $written = file_put_contents($keyFile, base64_encode(sodium_crypto_secretbox_keygen()) . "\n", LOCK_EX);
    umask($old);
    if ($written === false) fail("cannot write $keyFile");
    chmod($keyFile, 0600);
    logEvent('encryption key created');
    echo "Created the key in $keyFile: keep a copy somewhere safe, without it the passwords cannot be read\n";
}

$changed = 0;
foreach ($configs as $call => $config) {
    $encrypted = encryptSecrets($config);
    if ($encrypted === $config) continue;
    writeStationConfig($call, $encrypted);
    $changed++;
    echo "$call: passwords encrypted\n";
}
if ($changed > 0) logEvent("passwords encrypted for $changed station(s)");
echo $changed > 0 ? "Done\n" : "Nothing to encrypt\n";
