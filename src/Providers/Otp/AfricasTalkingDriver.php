<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

/**
 * SMS driver for Africa's Talking. Enabled from the admin portal once the
 * username and API key are saved and a test send has succeeded.
 */
final class AfricasTalkingDriver implements OtpDriver
{
    public function send(string $destination, string $code, string $purpose, array $config): bool
    {
        $username = $config['username'] ?? '';
        $apiKey = $config['api_key'] ?? '';
        $sender = $config['sender_id'] ?? '';
        if ($username === '' || $apiKey === '') {
            return false;
        }
        $payload = [
            'username' => $username,
            'to'       => $destination,
            'message'  => "Your MajiLink code is {$code}. It expires in 10 minutes.",
        ];
        if ($sender !== '') {
            $payload['from'] = $sender;
        }

        $ch = curl_init('https://api.africastalking.com/version1/messaging');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['apiKey: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => http_build_query($payload),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $status >= 300) {
            return false;
        }
        $decoded = json_decode($raw, true);
        $recipients = $decoded['SMSMessageData']['Recipients'] ?? [];
        foreach ($recipients as $recipient) {
            if (($recipient['status'] ?? '') === 'Success') {
                return true;
            }
        }
        return false;
    }
}
