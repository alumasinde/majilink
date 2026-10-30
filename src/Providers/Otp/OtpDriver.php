<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

interface OtpDriver
{
    /**
     * @param array<string,string> $config decrypted provider credentials
     */
    public function send(string $destination, string $code, string $purpose, array $config): bool;
}
