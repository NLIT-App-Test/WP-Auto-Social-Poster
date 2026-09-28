<?php

namespace Admun\GrowthEngine\Support;

defined('ABSPATH') || exit;

/**
 * Encrypts API keys and social tokens at rest with a key derived from the site's salts.
 * Moving the database to a site with different salts makes stored secrets unreadable (by design).
 */
class Crypto
{
    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . 'age-secrets', true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        $mac = hash_hmac('sha256', $iv . $cipher, self::key(), true);
        return 'o1:' . base64_encode($iv . $mac . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false) {
            return '';
        }
        if (strpos($stored, 's1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
            return $plain === false ? '' : $plain;
        }
        if (strpos($stored, 'o1:') === 0) {
            $iv = substr($raw, 0, 16);
            $mac = substr($raw, 16, 32);
            $cipher = substr($raw, 48);
            if (!hash_equals(hash_hmac('sha256', $iv . $cipher, self::key(), true), $mac)) {
                return '';
            }
            $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
            return $plain === false ? '' : $plain;
        }
        return '';
    }

    public static function encrypt_array(array $data): string
    {
        return self::encrypt((string) wp_json_encode($data));
    }

    public static function decrypt_array(string $stored): array
    {
        $data = json_decode(self::decrypt($stored), true);
        return is_array($data) ? $data : [];
    }
}
