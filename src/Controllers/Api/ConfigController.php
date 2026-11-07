<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\Response;
use MajiLink\Services\SettingsService;
use MajiLink\Support\Clock;
use MajiLink\Support\Config;

/**
 * Public runtime configuration. This is what lets one APK talk to any backend:
 * the version gate, maintenance switch, feature flags and public limits all come
 * from the database instead of from a compile-time constant.
 */
final class ConfigController
{
    public function __construct(private readonly SettingsService $settings = new SettingsService())
    {
    }

    public function show(): void
    {
        Response::ok([
            'app' => [
                'min_supported_version_code'   => $this->settings->int('min_supported_version_code', 0),
                'min_recommended_version_code' => $this->settings->int('min_recommended_version_code', 0),
                'latest_version_name'          => $this->settings->get('latest_app_version_name', '1.0.0'),
                'update_url'                   => $this->settings->get('update_url', ''),
                'update_message'               => $this->settings->get(
                    'update_message',
                    'A newer version of MajiLink is available. Update to keep ordering.'
                ),
                // Makes the recommended-version prompt impossible to dismiss.
                'force_update'          => $this->settings->bool('force_update', false),
                // Opens the in-app server-address field on builds that would otherwise hide it.
                'allow_endpoint_override' => $this->settings->bool('allow_endpoint_override', false),
            ],
            'maintenance' => [
                'enabled' => $this->settings->bool('maintenance_mode', false),
                'message' => $this->settings->get(
                    'maintenance_message',
                    'MajiLink is taking a short break. Try again shortly.'
                ),
            ],
            'endpoint' => [
                'api_base_url' => $this->apiBaseUrl(),
                'environment'  => (string) Config::get('env', 'production'),
            ],
            // Cast so an empty flag list serialises as {} - a map parser cannot decode [].
            'flags'  => (object) $this->settings->flags('feature_flags'),
            'limits' => [
                'currency'             => $this->settings->get('currency', 'KES'),
                'min_quantity'         => $this->settings->int('min_quantity', 1),
                'max_quantity'         => $this->settings->int('max_quantity', 20),
                'default_radius_km'    => $this->settings->int('default_radius_km', 3),
                'max_radius_km'        => $this->settings->int('max_radius_km', 5),
                'order_expiry_minutes' => $this->settings->int('order_expiry_minutes', 5),
            ],
            'server_time' => Clock::now(),
        ]);
    }

    private function apiBaseUrl(): string
    {
        $configured = trim($this->settings->get('api_base_url', ''));
        if ($configured === '') {
            $configured = rtrim((string) Config::get('url', 'http://localhost:8099'), '/') . '/api/v1';
        }
        return rtrim($configured, '/') . '/';
    }
}
