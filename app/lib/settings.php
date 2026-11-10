<?php
/** Configurações editáveis pelo admin (tabela settings) e segredos cifrados. */
declare(strict_types=1);

const SETTING_DEFAULTS = [
    'base_url' => '',
    'allow_registration' => '1',
    'require_verified_email' => '1',
    'click_retention_days' => '365',
    'max_qrcodes_per_user' => '500',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_security' => 'starttls', // starttls | ssl | none
    'smtp_user' => '',
    'smtp_pass' => '',             // cifrado (sodium secretbox)
    'smtp_from_email' => '',
    'smtp_from_name' => 'QR Code Fácil',
];

function setting(string $key): string
{
    static $cache = null;
    if ($key === '__reset') {
        $cache = null;
        return '';
    }
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache[$key] ?? (SETTING_DEFAULTS[$key] ?? '');
}

function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
    setting('__reset');
}

/** Deriva uma subchave a partir da chave mestra do servidor. */
function subkey(string $purpose, int $len = 32): string
{
    return substr(hash_hmac('sha256', $purpose, app_secret(), true), 0, $len);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box = sodium_crypto_secretbox($plain, $nonce, subkey('settings-encryption'));
    return 'v1:' . base64_encode($nonce . $box);
}

function decrypt_secret(string $stored): string
{
    if ($stored === '' || !str_starts_with($stored, 'v1:')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, subkey('settings-encryption'));
    return $plain === false ? '' : $plain;
}

function smtp_configured(): bool
{
    return setting('smtp_host') !== '' && setting('smtp_from_email') !== '';
}
