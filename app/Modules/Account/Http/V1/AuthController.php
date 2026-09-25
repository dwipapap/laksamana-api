<?php

namespace App\Modules\Account\Http\V1;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * /api/v1/auth — Sanctum tokens issued against the existing Office accounts.
 *
 * Credentials are the same ones every Office module uses: username OR full
 * name + PIN, active accounts only (OfficeAccess::userByCredentials).
 * Unlike the legacy login, failed attempts are rate limited.
 */
class AuthController
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly AccountService $account,
        private readonly AccountRepository $users,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'pin' => ['required', 'string', 'max:16'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        $key = 'login:'.$request->ip().':'.mb_strtolower(trim($data['login']));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiResponse::error('too_many_attempts', 'Too many failed logins. Try again later.', 429,
                ['retryAfter' => RateLimiter::availableIn($key)]);
        }

        $row = $this->access->userByCredentials($data['login'], $data['pin']);
        if (! $row) {
            RateLimiter::hit($key, 15 * 60);

            return ApiResponse::error('invalid_credentials', 'Wrong name/username or PIN, or the account is inactive.', 401);
        }
        RateLimiter::clear($key);

        $user = $this->users->tokenOwner($row['id']);
        $days = (int) config('sanctum.token_days', 30);
        $token = $user->createToken($data['device'] ?? 'api', ['*'], now()->addDays($days));

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->access->profile($row),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $row = $this->access->userById((string) $request->user()->getKey());

        return ApiResponse::ok($this->access->profile($row) + AccountService::hrJson($row) + [
            'noHp' => (string) ($row['no_hp'] ?? ''),
            'talentaId' => (string) ($row['talenta_id'] ?? ''),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(['loggedOut' => true]);
    }

    public function changePin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPin' => ['required', 'string'],
            'newPin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        $id = (string) $request->user()->getKey();
        $row = $this->access->userById($id);
        // Unlike legacy changePin (name only), v1 requires the current PIN.
        if (! $row || trim((string) $row['pin']) !== trim($data['currentPin'])) {
            return ApiResponse::error('invalid_credentials', 'Current PIN is wrong.', 422);
        }
        $this->users->updatePinById($id, $data['newPin']);

        return ApiResponse::ok(['changed' => true]);
    }

    public function setUsername(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['present', 'nullable', 'string', 'max:40']]);
        $r = $this->account->applyUsername((string) $request->user()->getKey(), trim((string) ($data['username'] ?? '')));

        return empty($r['ok'])
            ? ApiResponse::error($r['error'], 'Username not accepted.', 422)
            : ApiResponse::ok(['username' => $r['username']]);
    }
}
