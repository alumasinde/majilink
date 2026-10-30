<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Http\Request;
use MajiLink\Middleware\Authenticator;
use MajiLink\Support\Config;
use MajiLink\Support\Crypto;
use MajiLink\Support\Db;
use MajiLink\Support\Hashing;
use MajiLink\Support\Jwt;
use MajiLink\Support\RateLimiter;
use MajiLink\Support\Validator;

final class AuthService
{
    public function __construct(private readonly OtpService $otp = new OtpService())
    {
    }

    public function register(array $data, Request $request): array
    {
        $input = Validator::validate($data, [
            'verification_token' => 'required|string',
            'first_name'         => 'required|string|min:2|max:80',
            'last_name'          => 'required|string|min:2|max:80',
            'phone'              => 'required|phone|unique:users,phone',
            'email'              => 'nullable|email|max:190|unique:users,email',
            'password'           => 'required|password',
            'device'             => 'required',
        ]);

        $device = $this->validateDevice($data['device'] ?? [], true);
        $destination = $this->otp->consume((string) $input['verification_token'], 'register');

        $phone = $this->otp->normalize((string) $input['phone']);
        $email = isset($input['email']) ? mb_strtolower((string) $input['email']) : null;
        if ($destination !== $phone && $destination !== $email) {
            throw HttpException::validation([
                'verification_token' => 'Verify the phone number or email you are registering with.',
            ]);
        }

        $userId = Db::transaction(function () use ($input, $phone, $email, $destination, $device): int {
            $id = Db::insert('users', [
                'first_name'        => $input['first_name'],
                'last_name'         => $input['last_name'],
                'phone'             => $phone,
                'email'             => $email,
                'password_hash'     => Hashing::make((string) $input['password']),
                'is_active'         => 1,
                'phone_verified_at' => $destination === $phone ? gmdate('Y-m-d H:i:s') : null,
                'email_verified_at' => $destination === $email ? gmdate('Y-m-d H:i:s') : null,
                'last_login_at'     => gmdate('Y-m-d H:i:s'),
            ]);
            $roleId = (int) Db::value('SELECT id FROM roles WHERE name = ?', ['customer']);
            Db::insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            return $id;
        });

        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $deviceRow = $this->upsertDevice($userId, $device, true);
        (new AuditService())->record($userId, 'auth.register', 'users', $userId, [], $request->ip());

        return $this->tokenPayload($user, $deviceRow);
    }

    public function loginWithPassword(array $data, Request $request): array
    {
        $input = Validator::validate($data, [
            'identifier' => 'required|string',
            'password'   => 'required|string',
            'device'     => 'required',
        ]);
        $device = $this->validateDevice($data['device'] ?? [], false);

        $identifier = trim((string) $input['identifier']);
        $lookup = str_contains($identifier, '@') ? mb_strtolower($identifier) : $this->otp->normalize($identifier);

        if (!RateLimiter::attempt('login.identifier', $lookup, 5, 300)
            || !RateLimiter::attempt('login.ip', $request->ip(), 20, 300)) {
            throw HttpException::tooManyRequests('Too many login attempts. Try again in a few minutes.');
        }

        $user = Db::one(
            'SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1',
            [$lookup, $lookup]
        );
        if ($user === null || !Hashing::verify((string) $input['password'], $user['password_hash'])) {
            throw new HttpException(401, 'invalid_credentials', 'Those details do not match our records.');
        }
        if ((int) $user['is_active'] !== 1) {
            throw new HttpException(403, 'account_disabled', 'This account has been deactivated.');
        }

        RateLimiter::clear('login.identifier', $lookup);
        Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // A password login on a revoked device re-enables it but clears the PIN,
        // so a lost phone cannot be unlocked with the old PIN.
        $deviceRow = $this->upsertDevice((int) $user['id'], $device, false, true);
        (new AuditService())->record((int) $user['id'], 'auth.login.password', 'users', (int) $user['id'], ['device' => $device['device_uid']], $request->ip());

        return $this->tokenPayload($user, $deviceRow);
    }

    public function loginWithPin(array $data, Request $request): array
    {
        $input = Validator::validate($data, [
            'identifier' => 'required|string',
            'device_uid' => 'required|string|max:120',
            'pin'        => 'required|string',
        ]);
        $identifier = trim((string) $input['identifier']);
        $lookup = str_contains($identifier, '@') ? mb_strtolower($identifier) : $this->otp->normalize($identifier);

        if (!RateLimiter::attempt('login.pin.ip', $request->ip(), 20, 300)) {
            throw HttpException::tooManyRequests('Too many attempts. Try again later.');
        }

        $user = Db::one('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1', [$lookup, $lookup]);
        if ($user === null) {
            throw new HttpException(401, 'invalid_credentials', 'Those details do not match our records.');
        }
        $device = Db::one('SELECT * FROM user_devices WHERE user_id = ? AND device_uid = ?', [$user['id'], $input['device_uid']]);
        if ($device === null || $device['revoked_at'] !== null || $device['pin_hash'] === null) {
            throw new HttpException(401, 'pin_unavailable', 'Sign in with your password to set up this device.');
        }
        if ($device['pin_locked_until'] !== null && strtotime($device['pin_locked_until'] . ' UTC') > time()) {
            throw new HttpException(423, 'pin_locked', 'Too many wrong PINs. Sign in with your password.');
        }
        if (!Hashing::verify((string) $input['pin'], $device['pin_hash'])) {
            $attempts = (int) $device['pin_failed_attempts'] + 1;
            $lockUntil = $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
            Db::update('user_devices', [
                'pin_failed_attempts' => $attempts,
                'pin_locked_until'    => $lockUntil,
            ], 'id = ?', [$device['id']]);
            $fields = ['pin' => $attempts >= 5 ? 'Too many wrong PINs. Sign in with your password.' : 'Wrong PIN. ' . (5 - $attempts) . ' attempt(s) left.'];
            throw HttpException::validation($fields);
        }

        Db::update('user_devices', [
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
            'last_used_at'        => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$device['id']]);
        Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        return $this->tokenPayload($user, Db::one('SELECT * FROM user_devices WHERE id = ?', [$device['id']]));
    }

    public function refresh(array $data): array
    {
        $input = Validator::validate($data, [
            'refresh_token' => 'required|string',
            'device_uid'    => 'required|string',
        ]);
        if (!RateLimiter::attempt('auth.refresh', $input['device_uid'], 60, 3600)) {
            throw HttpException::tooManyRequests('Too many refresh attempts.');
        }
        $device = Db::one(
            'SELECT * FROM user_devices WHERE device_uid = ? AND refresh_token_hash = ?',
            [$input['device_uid'], Hashing::tokenHash((string) $input['refresh_token'])]
        );
        if ($device === null || $device['revoked_at'] !== null) {
            throw new HttpException(401, 'invalid_refresh', 'Session expired. Sign in again.');
        }
        if ($device['last_used_at'] !== null && strtotime($device['last_used_at'] . ' UTC') < time() - (int) Config::get('refresh_ttl')) {
            throw new HttpException(401, 'invalid_refresh', 'Session expired. Sign in again.');
        }
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$device['user_id']]);
        if ($user === null || (int) $user['is_active'] !== 1) {
            throw new HttpException(403, 'account_disabled', 'This account is not active.');
        }
        return $this->tokenPayload($user, $device);
    }

    public function setPin(int $userId, array $data): array
    {
        $input = Validator::validate($data, [
            'pin'              => 'required|pin',
            'current_password' => 'nullable|string',
        ]);
        $device = Authenticator::device();
        $user = Authenticator::user();

        if ($device['pin_hash'] !== null) {
            if (!isset($input['current_password'])
                || !Hashing::verify((string) $input['current_password'], $user['password_hash'])) {
                throw HttpException::validation(['current_password' => 'Enter your password to change the PIN.']);
            }
        }
        Db::update('user_devices', [
            'pin_hash'            => Hashing::make((string) $input['pin']),
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
        ], 'id = ?', [$device['id']]);

        return ['pin_set' => true];
    }

    public function forgotPassword(string $identifier, ?string $ip = null): array
    {
        $identifier = trim($identifier);
        $result = $this->otp->request($identifier, 'reset_password', $ip);
        $lookup = str_contains($identifier, '@') ? mb_strtolower($identifier) : $this->otp->normalize($identifier);
        $exists = Db::value('SELECT 1 FROM users WHERE email = ? OR phone = ?', [$lookup, $lookup]) !== null;
        // Never reveal whether an account exists.
        $result['message'] = 'If that account exists, a reset code has been sent.';
        $result['account_exists'] = $exists && (bool) Config::get('debug');
        return $result;
    }

    public function resetPassword(array $data): array
    {
        $input = Validator::validate($data, [
            'verification_token' => 'required|string',
            'password'           => 'required|password',
        ]);
        $destination = $this->otp->consume((string) $input['verification_token'], 'reset_password');
        $user = Db::one('SELECT * FROM users WHERE email = ? OR phone = ?', [$destination, $destination]);
        if ($user === null) {
            throw HttpException::validation(['verification_token' => 'This reset link is no longer valid.']);
        }
        Db::update('users', ['password_hash' => Hashing::make((string) $input['password'])], 'id = ?', [$user['id']]);
        // Every device must sign in again after a password reset.
        Db::run('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP(), refresh_token_hash = NULL WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
        (new AuditService())->record((int) $user['id'], 'auth.password.reset', 'users', (int) $user['id']);

        return ['message' => 'Password updated. Sign in with your new password.'];
    }

    public function devices(int $userId): array
    {
        return array_map(static fn (array $row): array => [
            'id'                 => (int) $row['id'],
            'device_name'        => $row['device_name'],
            'device_uid'         => $row['device_uid'],
            'pin_set'            => $row['pin_hash'] !== null,
            'biometric_enabled'  => (bool) $row['biometric_enabled'],
            'last_used_at'       => $row['last_used_at'],
            'revoked'            => $row['revoked_at'] !== null,
            'created_at'         => $row['created_at'],
        ], Db::all('SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_used_at DESC, id DESC', [$userId]));
    }

    public function revokeDevice(int $userId, int $deviceId): void
    {
        $device = Db::one('SELECT * FROM user_devices WHERE id = ? AND user_id = ?', [$deviceId, $userId]);
        if ($device === null) {
            throw HttpException::notFound('Device not found.');
        }
        Db::update('user_devices', [
            'revoked_at'           => gmdate('Y-m-d H:i:s'),
            'refresh_token_hash'   => null,
            'pin_hash'             => null,
            'biometric_enabled'    => 0,
        ], 'id = ?', [$deviceId]);
        (new AuditService())->record($userId, 'auth.device.revoke', 'user_devices', $deviceId);
    }

    public function updateBiometric(int $userId, bool $enabled): array
    {
        Db::update('user_devices', ['biometric_enabled' => $enabled ? 1 : 0], 'id = ?', [Authenticator::device()['id']]);
        return ['biometric_enabled' => $enabled];
    }

    public function logout(int $userId): array
    {
        Db::update('user_devices', ['refresh_token_hash' => null], 'id = ?', [Authenticator::device()['id']]);
        return ['message' => 'Signed out on this device.'];
    }

    public function updatePushToken(int $userId, ?string $fcmToken): array
    {
        Db::update('user_devices', ['fcm_token' => $fcmToken], 'id = ?', [Authenticator::device()['id']]);
        return ['saved' => true];
    }

    private function tokenPayload(array $user, array $device): array
    {
        $roles = Authenticator::rolesFor((int) $user['id']);
        $permissions = Authenticator::permissionsFor((int) $user['id']);
        $refresh = Crypto::token(32);
        Db::update('user_devices', [
            'refresh_token_hash' => Hashing::tokenHash($refresh),
            'last_used_at'       => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$device['id']]);

        $access = Jwt::encode([
            'typ'   => 'access',
            'sub'   => (int) $user['id'],
            'dev'   => (int) $device['id'],
            'roles' => $roles,
            'perms' => $permissions,
            'iat'   => time(),
            'exp'   => time() + (int) Config::get('access_ttl'),
        ], (string) Config::get('key'));

        return [
            'user'          => $this->presentUser($user, $roles, $permissions),
            'device'        => [
                'id'                => (int) $device['id'],
                'device_uid'        => $device['device_uid'],
                'pin_set'           => $device['pin_hash'] !== null,
                'biometric_enabled' => (bool) $device['biometric_enabled'],
            ],
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) Config::get('access_ttl'),
            'refresh_token' => $refresh,
        ];
    }

    private function presentUser(array $user, array $roles, array $permissions): array
    {
        $vendor = Db::one(
            'SELECT v.id, v.business_name, s.name AS status
             FROM vendors v JOIN vendor_application_statuses s ON s.id = v.application_status_id
             WHERE v.user_id = ?',
            [$user['id']]
        );
        $modes = ['customer'];
        if (in_array('vendor', $roles, true) && $vendor !== null && $vendor['status'] === 'Approved') {
            $modes[] = 'vendor';
        }
        return [
            'id'               => (int) $user['id'],
            'first_name'       => $user['first_name'],
            'last_name'        => $user['last_name'],
            'phone'            => $user['phone'],
            'email'            => $user['email'],
            'roles'            => $roles,
            'permissions'      => $permissions,
            'modes'            => $modes,
            'vendor'           => $vendor,
        ];
    }

    private function validateDevice(mixed $device, bool $requirePin): array
    {
        if (!is_array($device) || !isset($device['device_uid'])) {
            throw HttpException::validation(['device' => 'Device details are required.']);
        }
        $rules = [
            'device_uid'  => 'required|string|max:120',
            'device_name' => 'nullable|string|max:120',
            'pin'         => $requirePin ? 'required|pin' : 'nullable|pin',
            'biometric_enabled' => 'nullable|bool',
        ];
        return Validator::validate($device, $rules);
    }

    private function upsertDevice(int $userId, array $device, bool $requirePin, bool $wasPasswordLogin = false): array
    {
        $existing = Db::one(
            'SELECT * FROM user_devices WHERE user_id = ? AND device_uid = ?',
            [$userId, $device['device_uid']]
        );
        $data = [
            'device_name'       => $device['device_name'] ?? 'Android device',
            'biometric_enabled' => !empty($device['biometric_enabled']) ? 1 : 0,
            'last_used_at'      => gmdate('Y-m-d H:i:s'),
        ];
        if ($requirePin) {
            $data['pin_hash'] = Hashing::make((string) $device['pin']);
            $data['pin_failed_attempts'] = 0;
            $data['pin_locked_until'] = null;
        }

        if ($existing === null) {
            if (!$requirePin) {
                $data['pin_hash'] = null;
            }
            $data['user_id'] = $userId;
            $data['device_uid'] = $device['device_uid'];
            $id = Db::insert('user_devices', $data);
        } else {
            if ($existing['revoked_at'] !== null) {
                if (!$wasPasswordLogin) {
                    throw new HttpException(401, 'device_revoked', 'This device was signed out. Sign in with your password.');
                }
                $data['revoked_at'] = null;
                $data['pin_hash'] = $requirePin ? Hashing::make((string) $device['pin']) : null;
                $data['pin_failed_attempts'] = 0;
                $data['pin_locked_until'] = null;
            }
            Db::update('user_devices', $data, 'id = ?', [$existing['id']]);
            $id = (int) $existing['id'];
        }
        return Db::one('SELECT * FROM user_devices WHERE id = ?', [$id]);
    }
}
