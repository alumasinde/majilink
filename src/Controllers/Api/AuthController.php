<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\AuthService;
use MajiLink\Services\OtpChannelService;
use MajiLink\Services\OtpService;
use MajiLink\Support\Validator;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly OtpService $otp = new OtpService()
    ) {
    }

    public function requestOtp(Request $request): void
    {
        $input = Validator::validate($request->data(), [
            'destination' => 'required|string|max:190',
            'purpose'     => 'required|in:register,reset_password,change_phone',
        ]);
        Response::ok($this->otp->request((string) $input['destination'], (string) $input['purpose'], $request->ip()));
    }

    public function verifyOtp(Request $request): void
    {
        $input = Validator::validate($request->data(), [
            'destination' => 'required|string|max:190',
            'purpose'     => 'required|in:register,reset_password,change_phone',
            'code'        => 'required|string|min:6|max:6',
        ]);
        Response::ok($this->otp->verify((string) $input['destination'], (string) $input['purpose'], (string) $input['code'], $request->ip()));
    }

    public function channels(): void
    {
        Response::ok(['channels' => (new OtpChannelService())->enabledChannels()]);
    }

    public function register(Request $request): void
    {
        Response::created($this->auth->register($request->data(), $request));
    }

    public function login(Request $request): void
    {
        Response::ok($this->auth->loginWithPassword($request->data(), $request));
    }

    public function loginPin(Request $request): void
    {
        Response::ok($this->auth->loginWithPin($request->data(), $request));
    }

    public function refresh(Request $request): void
    {
        Response::ok($this->auth->refresh($request->data()));
    }

    public function setPin(Request $request): void
    {
        Response::ok($this->auth->setPin(Authenticator::id(), $request->data()));
    }

    public function forgotPassword(Request $request): void
    {
        $input = Validator::validate($request->data(), ['identifier' => 'required|string|max:190']);
        Response::ok($this->auth->forgotPassword((string) $input['identifier'], $request->ip()));
    }

    public function resetPassword(Request $request): void
    {
        Response::ok($this->auth->resetPassword($request->data()));
    }

    public function me(): void
    {
        $user = Authenticator::user();
        Response::ok([
            'id'          => (int) $user['id'],
            'first_name'  => $user['first_name'],
            'last_name'   => $user['last_name'],
            'phone'       => $user['phone'],
            'email'       => $user['email'],
            'roles'       => Authenticator::roles(),
            'permissions' => Authenticator::permissions(),
            'device'      => [
                'id'                => (int) Authenticator::device()['id'],
                'device_uid'        => Authenticator::device()['device_uid'],
                'pin_set'           => Authenticator::device()['pin_hash'] !== null,
                'biometric_enabled' => (bool) Authenticator::device()['biometric_enabled'],
            ],
        ]);
    }

    public function devices(): void
    {
        Response::ok(['devices' => $this->auth->devices(Authenticator::id())]);
    }

    public function revokeDevice(Request $request, array $params): void
    {
        $this->auth->revokeDevice(Authenticator::id(), (int) $params['id']);
        Response::ok(['revoked' => true]);
    }

    public function biometric(Request $request): void
    {
        $input = Validator::validate($request->data(), ['enabled' => 'required|bool']);
        Response::ok($this->auth->updateBiometric(Authenticator::id(), !empty($input['enabled'])));
    }

    public function pushToken(Request $request): void
    {
        $input = Validator::validate($request->data(), ['fcm_token' => 'nullable|string|max:255']);
        Response::ok($this->auth->updatePushToken(Authenticator::id(), $input['fcm_token'] ?? null));
    }

    public function logout(): void
    {
        Response::ok($this->auth->logout(Authenticator::id()));
    }
}
