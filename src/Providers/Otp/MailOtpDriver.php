<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

use MajiLink\Support\Config;

final class MailOtpDriver implements OtpDriver
{
    public function send(string $destination, string $code, string $purpose, array $config): bool
    {
        $subject = 'Your MajiLink verification code';
        $message = "Your MajiLink code is {$code}. It expires in " . ((int) Config::get('otp')['ttl'] / 60) . " minutes.\n"
            . "If you did not request this, ignore this email.";

        // Local development (and any host without a mail transfer agent) writes
        // the code to storage/logs/otp.log instead of dropping the message.
        if (Config::get('env') === 'local' || Config::get('otp_dev_expose') === true) {
            return (new LogOtpDriver())->send($destination, $code, $purpose, $config);
        }

        $headers = [
            'From: ' . Config::get('mail_from_name') . ' <' . Config::get('mail_from') . '>',
            'Reply-To: ' . Config::get('mail_from'),
            'Content-Type: text/plain; charset=UTF-8',
        ];
        return @mail($destination, $subject, $message, implode("\r\n", $headers));
    }
}
