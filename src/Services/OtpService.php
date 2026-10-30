<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Providers\Otp\OtpDispatcher;
use MajiLink\Support\Config;
use MajiLink\Support\Crypto;
use MajiLink\Support\Db;
use MajiLink\Support\Jwt;
use MajiLink\Support\RateLimiter;

final class OtpService
{
    public function normalize(string $destination): string
    {
        $destination = trim($destination);
        if (str_contains($destination, '@')) {
            return mb_strtolower($destination);
        }
        $digits = preg_replace('/\D+/', '', $destination) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '254' . substr($digits, 1);
        } elseif (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            $digits = '254' . $digits;
        }
        return '+' . $digits;
    }

    public function request(string $rawDestination, string $purpose, ?string $ip = null): array
    {
        $destination = $this->normalize($rawDestination);
        $isEmail = str_contains($destination, '@');
        if ($isEmail && filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpException::validation(['destination' => 'Enter a valid email address.']);
        }
        if (!$isEmail && !preg_match('/^\+254[17]\d{8}$/', $destination)) {
            throw HttpException::validation(['destination' => 'Enter a valid Kenyan phone number.']);
        }

        $config = (array) Config::get('otp');
        if (!RateLimiter::attempt('otp.destination', $destination, 3, 600)) {
            throw HttpException::tooManyRequests('Too many codes requested for this contact. Wait a few minutes.');
        }
        if ($ip !== null && !RateLimiter::attempt('otp.ip', $ip, 15, 600)) {
            throw HttpException::tooManyRequests('Too many codes requested from this network.');
        }

        $purposeId = Db::value('SELECT id FROM otp_purposes WHERE code = ?', [$purpose]);
        if ($purposeId === null) {
            throw HttpException::badRequest('Unknown verification purpose.');
        }

        $last = Db::one(
            'SELECT created_at FROM otp_codes WHERE destination = ? AND purpose_id = ? ORDER BY id DESC LIMIT 1',
            [$destination, $purposeId]
        );
        if ($last !== null && strtotime($last['created_at'] . ' UTC') > time() - (int) $config['resend_seconds']) {
            throw new HttpException(429, 'otp_cooldown', 'Please wait before requesting another code.');
        }

        $channel = $this->chooseChannel($isEmail);
        if ($channel === null) {
            throw new HttpException(503, 'otp_channel_unavailable', 'No verification channel is available right now.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::insert('otp_codes', [
            'destination' => $destination,
            'channel_id'  => $channel['channel_id'],
            'purpose_id'  => $purposeId,
            'code_hash'   => Crypto::otpHash($code, $destination),
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + (int) $config['ttl']),
        ]);

        $driver = OtpDispatcher::driverFor($channel['code'], $channel['provider']);
        $sent = $driver->send($destination, $code, $purpose, OtpDispatcher::config($channel['config_enc']));
        if (!$sent) {
            throw new HttpException(502, 'otp_send_failed', 'We could not send the code. Try another channel.');
        }

        $response = [
            'destination' => $this->mask($destination),
            'channel'     => $channel['code'],
            'expires_in'  => (int) $config['ttl'],
        ];
        if (Config::get('otp_dev_expose') === true) {
            $response['dev_code'] = $code;
        }
        return $response;
    }

    public function verify(string $rawDestination, string $purpose, string $code, ?string $ip = null): array
    {
        $destination = $this->normalize($rawDestination);
        if ($ip !== null && !RateLimiter::attempt('otp.verify', $ip . '|' . $destination, 10, 600)) {
            throw HttpException::tooManyRequests('Too many attempts. Try again later.');
        }
        $purposeId = Db::value('SELECT id FROM otp_purposes WHERE code = ?', [$purpose]);
        $row = Db::one(
            'SELECT * FROM otp_codes
             WHERE destination = ? AND purpose_id = ? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$destination, $purposeId]
        );
        if ($row === null) {
            throw HttpException::badRequest('Request a new code first.', 'otp_missing');
        }
        if (strtotime($row['expires_at'] . ' UTC') < time()) {
            throw HttpException::badRequest('That code has expired. Request a new one.', 'otp_expired');
        }
        $maxAttempts = (int) Config::get('otp')['max_attempts'];
        if ((int) $row['attempts'] >= $maxAttempts) {
            throw HttpException::badRequest('Too many wrong attempts for this code. Request a new one.', 'otp_locked');
        }
        if (!hash_equals($row['code_hash'], Crypto::otpHash($code, $destination))) {
            Db::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            throw HttpException::validation(['code' => 'That code is not correct.']);
        }

        Db::run('UPDATE otp_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        $token = Jwt::encode([
            'typ'    => 'otp',
            'sub'    => $destination,
            'purpose'=> $purpose,
            'iat'    => time(),
            'exp'    => time() + 900,
        ], (string) Config::get('key'));

        return [
            'verification_token' => $token,
            'verified'           => true,
            'destination'        => $this->mask($destination),
        ];
    }

    /** @return string the verified destination */
    public function consume(string $token, string $purpose): string
    {
        try {
            [$claims] = Jwt::decode($token, (string) Config::get('key'));
        } catch (\RuntimeException $e) {
            throw HttpException::validation(['verification_token' => 'Verification has expired. Request a new code.']);
        }
        if (($claims['typ'] ?? '') !== 'otp' || ($claims['purpose'] ?? '') !== $purpose) {
            throw HttpException::validation(['verification_token' => 'This verification does not match.']);
        }
        return (string) $claims['sub'];
    }

    private function chooseChannel(bool $isEmail): ?array
    {
        $rows = Db::all(
            'SELECT c.id AS channel_id, c.code, s.provider, s.config_enc, s.priority
             FROM otp_channels c JOIN otp_channel_settings s ON s.channel_id = c.id
             WHERE s.is_enabled = 1 ORDER BY s.priority ASC'
        );
        foreach ($rows as $row) {
            if ($isEmail && $row['code'] !== 'email') {
                continue;
            }
            if (!$isEmail && $row['code'] === 'email') {
                continue;
            }
            return $row;
        }
        return null;
    }

    private function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$user, $domain] = explode('@', $destination, 2);
            return substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 2)) . '@' . $domain;
        }
        return substr($destination, 0, 5) . str_repeat('*', max(1, strlen($destination) - 7)) . substr($destination, -2);
    }
}
