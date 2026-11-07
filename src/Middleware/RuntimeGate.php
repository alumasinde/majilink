<?php
declare(strict_types=1);

namespace MajiLink\Middleware;

use MajiLink\Http\HttpException;
use MajiLink\Http\Request;
use MajiLink\Services\SettingsService;

/**
 * Server-side gate that old APKs cannot bypass by simply ignoring a response
 * field. Runs before authentication on every /api/v1 route so a broken build or a
 * downtime window can be closed without shipping a new APK.
 *
 * A request with no version header is treated as a legacy client and allowed
 * through: the admin portal, the landing page and existing APKs predate the header.
 */
final class RuntimeGate
{
    /** Paths that must always answer, otherwise a gated client cannot learn it is gated. */
    private const EXEMPT = ['/api/v1/config', '/api/v1/health'];

    public static function check(Request $request, ?SettingsService $settings = null): void
    {
        $path = rtrim($request->path, '/') ?: '/';
        if (!str_starts_with($path, '/api/v1/') || in_array($path, self::EXEMPT, true)) {
            return;
        }

        $settings ??= new SettingsService();

        if ($settings->bool('maintenance_mode', false)) {
            throw new HttpException(
                503,
                'maintenance_mode',
                $settings->get('maintenance_message', 'MajiLink is taking a short break. Try again shortly.')
            );
        }

        $raw = $request->header('x-app-version-code');
        if ($raw === null || !ctype_digit($raw)) {
            return;
        }

        $code = (int) $raw;
        $floor = $settings->int('min_supported_version_code', 0);
        $recommended = $settings->int('min_recommended_version_code', 0);
        $forced = $settings->bool('force_update', false) && $code < $recommended;

        if ($code < $floor || $forced) {
            throw new HttpException(
                426,
                'app_update_required',
                $settings->get('update_message', 'A newer version of MajiLink is available. Update to keep ordering.'),
                [
                    'min_supported_version_code' => $floor,
                    'update_url' => $settings->get('update_url', ''),
                ]
            );
        }
    }
}
