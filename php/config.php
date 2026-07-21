<?php
if (file_exists(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';

defined('WAKELAB_DB_HOST') || define('WAKELAB_DB_HOST', getenv('WAKELAB_DB_HOST') ?: 'db');
defined('WAKELAB_DB_NAME') || define('WAKELAB_DB_NAME', getenv('WAKELAB_DB_NAME') ?: 'wakelab');
defined('WAKELAB_DB_USER') || define('WAKELAB_DB_USER', getenv('WAKELAB_DB_USER') ?: 'wakelab');
defined('WAKELAB_DB_PASS') || define('WAKELAB_DB_PASS', getenv('WAKELAB_DB_PASS') ?: '');
defined('WAKELAB_DB_PORT') || define('WAKELAB_DB_PORT', (int)(getenv('WAKELAB_DB_PORT') ?: 3306));
defined('WAKELAB_SECRET')  || define('WAKELAB_SECRET',  getenv('WAKELAB_SECRET')  ?: '');

function wlEncrypt(string $value): string {
    if (WAKELAB_SECRET === '' || $value === '') return $value;
    $key = hash('sha256', WAKELAB_SECRET, true);
    $iv  = random_bytes(12);
    $tag = '';
    $enc = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return 'enc:' . base64_encode($iv . $tag . $enc);
}

function wlDecrypt(string $value): string {
    if (!str_starts_with($value, 'enc:')) return $value;
    if (WAKELAB_SECRET === '') return '';
    $key = hash('sha256', WAKELAB_SECRET, true);
    $raw = base64_decode(substr($value, 4));
    $dec = openssl_decrypt(
        substr($raw, 28), 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
    );
    return $dec !== false ? $dec : '';
}
