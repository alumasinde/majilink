<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Crypto
{
    private static function key(): string
    {
        return hash('sha256', (string) Config::get('key'), true);
    }

    /** libsodium secretbox; provider credentials never leave the server in plaintext. */
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    public static function otpHash(string $code, string $destination): string
    {
        return hash_hmac('sha256', $destination . '|' . $code, (string) Config::get('key'));
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
