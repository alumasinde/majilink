<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

use MajiLink\Support\Config;

final class LogOtpDriver implements OtpDriver
{
    public function send(string $destination, string $code, string $purpose, array $config): bool
    {
        $dir = (string) Config::get('log_dir');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] purpose=%s destination=%s code=%s\n", gmdate('c'), $purpose, $destination, $code);
        return @file_put_contents($dir . '/otp.log', $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
