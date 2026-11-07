<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Support\Db;
use MajiLink\Support\Clock;

final class SettingsService
{
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (Db::all('SELECT * FROM app_settings ORDER BY setting_key') as $row) {
                $this->cache[$row['setting_key']] = $row;
            }
        }
        return $this->cache;
    }

    public function get(string $key, string $default = ''): string
    {
        return (string) ($this->all()[$key]['setting_value'] ?? $default);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->all()[$key]['setting_value'] ?? null;
        return $value === null ? $default : (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->all()[$key]['setting_value'] ?? null;
        return $value === null ? $default : (float) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->all()[$key]['setting_value'] ?? null;
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Comma-separated list of enabled feature names rendered as a flag map, so a
     * client can read flags["live_tracking"] instead of parsing a string.
     *
     * @return array<string,bool>
     */
    public function flags(string $key): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $this->get($key)))));
        return array_fill_keys($names, true);
    }

    /**
     * @param array<string,string> $values
     */
    public function update(array $values, ?int $userId, ?string $ip = null): void
    {
        $known = array_keys($this->all());
        $changed = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $known, true)) {
                continue;
            }
            $current = (string) ($this->all()[$key]['setting_value'] ?? '');
            if ((string) $value === $current) {
                continue;
            }
            Db::update('app_settings', ['setting_value' => (string) $value], 'setting_key = ?', [$key]);
            $changed[$key] = ['from' => $current, 'to' => (string) $value];
        }
        $this->cache = null;
        if ($changed !== []) {
            (new AuditService())->record($userId, 'settings.update', 'app_settings', null, $changed, $ip);
        }
    }

    /** Used by the heartbeat and expiry cron jobs. */
    public function heartbeatCutoff(): string
    {
        $minutes = max(1, $this->int('heartbeat_timeout_minutes', 15));
        return gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    }

    public function nowLocalTime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('H:i:s');
    }

    public function utcStamp(): string
    {
        return Clock::now();
    }
}
