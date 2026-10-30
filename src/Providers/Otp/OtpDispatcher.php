<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

use MajiLink\Support\Crypto;

final class OtpDispatcher
{
    public static function driverFor(string $channelCode, string $provider): OtpDriver
    {
        return match ($provider) {
            'log'            => new LogOtpDriver(),
            'mail'           => new MailOtpDriver(),
            'africastalking' => new AfricasTalkingDriver(),
            'meta_cloud'     => new MetaWhatsAppDriver(),
            default          => $channelCode === 'email' ? new MailOtpDriver() : new LogOtpDriver(),
        };
    }

    /**
     * @return array<string,string>
     */
    public static function config(?string $encrypted): array
    {
        if ($encrypted === null || $encrypted === '') {
            return [];
        }
        $plain = Crypto::decrypt($encrypted);
        if ($plain === null) {
            return [];
        }
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }
}
