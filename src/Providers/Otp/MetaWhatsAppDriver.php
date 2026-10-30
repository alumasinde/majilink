<?php
declare(strict_types=1);

namespace MajiLink\Providers\Otp;

/**
 * WhatsApp Cloud API driver. Requires an approved authentication template;
 * template_name and template_lang are configured in the admin portal.
 */
final class MetaWhatsAppDriver implements OtpDriver
{
    public function send(string $destination, string $code, string $purpose, array $config): bool
    {
        $token = $config['access_token'] ?? '';
        $phoneId = $config['phone_number_id'] ?? '';
        $template = $config['template_name'] ?? 'majilink_otp';
        $lang = $config['template_lang'] ?? 'en';
        if ($token === '' || $phoneId === '') {
            return false;
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/^\+/', '', $destination),
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $lang],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [['type' => 'text', 'text' => $code]],
                ]],
            ],
        ];

        $ch = curl_init("https://graph.facebook.com/v20.0/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status >= 300) {
            return false;
        }
        $decoded = json_decode($raw, true);
        return isset($decoded['messages'][0]['id']);
    }
}
