<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Hashing
{
    /**
     * Argon2id is required by the specification. Some Windows PHP builds ship
     * without libargon2, so bcrypt is used only when Argon2id is unavailable.
     */
    public static function algorithm(): string
    {
        return in_array(PASSWORD_ARGON2ID, password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function make(string $plain): string
    {
        $hash = password_hash($plain, self::algorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash secret.');
        }
        return $hash;
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
