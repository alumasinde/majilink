<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Config;

/**
 * Safaricom Daraja STK Push. When MPESA_SIMULATE is on (local development only)
 * a simulated checkout id is returned so the callback flow can be exercised.
 */
final class MpesaService
{
    public function isSimulated(): bool
    {
        return (bool) Config::get('mpesa')['simulate'];
    }

    public function isConfigured(): bool
    {
        $config = (array) Config::get('mpesa');
        return $config['consumer_key'] !== '' && $config['consumer_secret'] !== ''
            && $config['shortcode'] !== '' && $config['passkey'] !== '';
    }

    public function stkPush(string $phone, int $amountCents, string $accountRef, string $description): array
    {
        if ($this->isSimulated()) {
            return [
                'checkout_request_id' => 'SIM-' . strtoupper(bin2hex(random_bytes(6))),
                'merchant_request_id' => 'SIM-MR-' . strtoupper(bin2hex(random_bytes(4))),
                'customer_message'    => 'Simulated STK push. The callback will mark this paid.',
                'simulated'           => true,
            ];
        }
        if (!$this->isConfigured()) {
            throw new HttpException(503, 'mpesa_not_configured', 'M-Pesa is not configured yet. Pay cash for this order.');
        }

        $config = (array) Config::get('mpesa');
        $base = $config['env'] === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
        $token = $this->accessToken($base, (string) $config['consumer_key'], (string) $config['consumer_secret']);
        if ($token === null) {
            throw new HttpException(502, 'mpesa_auth_failed', 'M-Pesa authentication failed. Try cash or retry.');
        }

        $timestamp = gmdate('YmdHis');
        $password = base64_encode((string) $config['shortcode'] . (string) $config['passkey'] . $timestamp);
        $msisdn = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($msisdn, '0')) {
            $msisdn = '254' . substr($msisdn, 1);
        }

        $payload = [
            'BusinessShortCode' => $config['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) round($amountCents / 100),
            'PartyA'            => $msisdn,
            'PartyB'            => $config['shortcode'],
            'PhoneNumber'       => $msisdn,
            'CallBackURL'       => $config['callback_url'] ?: (rtrim((string) Config::get('url'), '/') . '/api/v1/callbacks/mpesa'),
            'AccountReference'  => mb_substr($accountRef, 0, 12),
            'TransactionDesc'   => mb_substr($description, 0, 60),
        ];

        $response = $this->post($base . '/mpesa/stkpush/v1/processrequest', $payload, $token);
        if ($response === null || !isset($response['CheckoutRequestID'])) {
            $message = $response['errorMessage'] ?? 'M-Pesa did not accept the request.';
            throw new HttpException(502, 'mpesa_push_failed', (string) $message);
        }
        return [
            'checkout_request_id' => $response['CheckoutRequestID'],
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'customer_message'    => $response['CustomerMessage'] ?? 'Check your phone for the M-Pesa prompt.',
            'simulated'           => false,
        ];
    }

    private function accessToken(string $base, string $key, string $secret): ?string
    {
        $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $key . ':' . $secret,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
    }

    private function post(string $url, array $payload, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
