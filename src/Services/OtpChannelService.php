<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Providers\Otp\OtpDispatcher;
use MajiLink\Support\Crypto;
use MajiLink\Support\Db;

final class OtpChannelService
{
    private const PROVIDERS = [
        'email'    => ['log', 'mail'],
        'sms'      => ['africastalking'],
        'whatsapp' => ['meta_cloud'],
    ];

    public function __construct(
        private readonly AuditService $audit = new AuditService(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    public function channels(): array
    {
        $rows = Db::all(
            'SELECT c.id, c.code, c.name, s.provider, s.config_enc, s.is_enabled, s.priority,
                    s.last_tested_at, s.last_test_ok, s.updated_at,
                    CONCAT(u.first_name, " ", u.last_name) AS updated_by_name
             FROM otp_channels c
             JOIN otp_channel_settings s ON s.channel_id = c.id
             LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.priority ASC, c.id ASC'
        );
        foreach ($rows as &$row) {
            $config = OtpDispatcher::config($row['config_enc']);
            $row['config_fields'] = array_keys($config);
            // Secrets are never sent back: only whether each field is stored.
            $row['config_masked'] = array_map(static fn ($k) => $k . ': ••••', array_keys($config));
            $row['providers'] = self::PROVIDERS[$row['code']] ?? [];
            unset($row['config_enc']);
        }
        return $rows;
    }

    public function channel(int $channelId): array
    {
        foreach ($this->channels() as $channel) {
            if ((int) $channel['id'] === $channelId) {
                return $channel;
            }
        }
        throw HttpException::notFound('Channel not found.');
    }

    /**
     * @param array<string,string> $config fields to store; empty values keep the existing secret
     */
    public function save(int $channelId, string $provider, array $config, int $adminId, ?string $ip = null): void
    {
        $channel = $this->channel($channelId);
        if (!in_array($provider, $channel['providers'], true)) {
            throw HttpException::validation(['provider' => 'Choose a supported provider for this channel.']);
        }

        $existing = OtpDispatcher::config((string) Db::value('SELECT config_enc FROM otp_channel_settings WHERE channel_id = ?', [$channelId]));
        $merged = $existing;
        foreach ($config as $key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $merged[$key] = trim((string) $value);
        }

        Db::update('otp_channel_settings', [
            'provider'     => $provider,
            'config_enc'   => Crypto::encrypt(json_encode($merged, JSON_UNESCAPED_SLASHES)),
            'updated_by'   => $adminId,
            'last_test_ok' => null,
        ], 'channel_id = ?', [$channelId]);

        $this->audit->record($adminId, 'settings.otp_channel.save', 'otp_channel_settings', $channelId, [
            'provider' => $provider, 'fields' => array_keys($config),
        ], $ip);
    }

    public function priority(int $channelId, int $priority, int $adminId, ?string $ip = null): void
    {
        Db::update('otp_channel_settings', ['priority' => max(1, min(99, $priority)), 'updated_by' => $adminId], 'channel_id = ?', [$channelId]);
        $this->audit->record($adminId, 'settings.otp_channel.priority', 'otp_channel_settings', $channelId, ['priority' => $priority], $ip);
    }

    public function sendTest(int $channelId, string $destination, int $adminId, ?string $ip = null): array
    {
        $channel = $this->channel($channelId);
        $config = OtpDispatcher::config((string) Db::value('SELECT config_enc FROM otp_channel_settings WHERE channel_id = ?', [$channelId]));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $driver = OtpDispatcher::driverFor($channel['code'], $channel['provider']);
        $ok = $driver->send($destination, $code, 'channel_test', $config);

        Db::update('otp_channel_settings', [
            'last_tested_at' => gmdate('Y-m-d H:i:s'),
            'last_test_ok'   => $ok ? 1 : 0,
            'updated_by'     => $adminId,
        ], 'channel_id = ?', [$channelId]);
        $this->audit->record($adminId, 'settings.otp_channel.test', 'otp_channel_settings', $channelId, ['ok' => $ok, 'destination' => $destination], $ip);

        return ['ok' => $ok, 'message' => $ok ? 'Test code sent.' : 'The provider rejected the test send. Check the credentials.'];
    }

    public function toggle(int $channelId, bool $enabled, int $adminId, ?string $ip = null): array
    {
        $channels = $this->channels();
        $channel = null;
        foreach ($channels as $row) {
            if ((int) $row['id'] === $channelId) {
                $channel = $row;
            }
        }
        if ($channel === null) {
            throw HttpException::notFound('Channel not found.');
        }

        if ($enabled) {
            if ((int) $channel['last_test_ok'] !== 1) {
                throw HttpException::conflict('Send a successful test before enabling this channel.');
            }
        } else {
            $remaining = array_filter($channels, static fn ($c) => (int) $c['is_enabled'] === 1 && (int) $c['id'] !== $channelId);
            if ($remaining === []) {
                throw HttpException::conflict('At least one verification channel must stay enabled.');
            }
        }

        Db::update('otp_channel_settings', ['is_enabled' => $enabled ? 1 : 0, 'updated_by' => $adminId], 'channel_id = ?', [$channelId]);
        $this->audit->record($adminId, $enabled ? 'settings.otp_channel.enable' : 'settings.otp_channel.disable', 'otp_channel_settings', $channelId, [], $ip);
        return ['enabled' => $enabled];
    }

    public function enabledChannels(): array
    {
        return Db::all(
            'SELECT c.code, c.name FROM otp_channels c JOIN otp_channel_settings s ON s.channel_id = c.id
             WHERE s.is_enabled = 1 ORDER BY s.priority ASC'
        );
    }
}
