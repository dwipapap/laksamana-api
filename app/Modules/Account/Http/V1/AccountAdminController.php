<?php

namespace App\Modules\Account\Http\V1;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/account — user & access administration for new apps.
 * Same rules as legacy (AccountService *Core methods), but the caller is the
 * Sanctum user instead of callerName+callerPin. PINs are never returned.
 */
class AccountAdminController
{
    public function __construct(
        private readonly AccountService $account,
        private readonly OfficeAccess $access,
        private readonly AccountRepository $users,
    ) {}

    private static function result(array $r, int $okStatus = 200): JsonResponse
    {
        if (! empty($r['ok'])) {
            unset($r['ok']);

            return ApiResponse::ok($r, [], $okStatus);
        }
        $code = (string) ($r['error'] ?? 'error');
        $status = match ($code) {
            'not_found' => 404,
            'forbidden' => 403,
            'is_kepala_divisi' => 409,
            default => 422,
        };
        unset($r['ok'], $r['error']);

        return ApiResponse::error($code, $code, $status, $r);
    }

    // superadmin -----------------------------------------------------------

    public function users(): JsonResponse
    {
        $p = $this->account->usersPayload(false);
        $users = array_map(fn ($u) => $u + ['ulid' => $this->users->userUlid($u['id'])], $p['users']);

        return ApiResponse::ok($users, ['modules' => $p['modules'], 'rules' => $this->account->rulePayload()]);
    }

    public function saveUser(Request $request, ?string $id = null): JsonResponse
    {
        $body = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'active' => ['sometimes', 'boolean'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'noHp' => ['nullable', 'string', 'max:32'],
            'talentaId' => ['nullable', 'string', 'max:64'],
            'username' => ['sometimes', 'nullable', 'string', 'max:40'],
            'branch' => ['sometimes', 'nullable', 'string'],
            'organization' => ['sometimes', 'nullable', 'string'],
            'jobPosition' => ['sometimes', 'nullable', 'string'],
            'jobLevel' => ['sometimes', 'nullable', 'string'],
            'employmentStatus' => ['sometimes', 'nullable', 'string'],
            'joinDate' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($id !== null) {
            $old = $this->access->userById($id);
            if (! $old) {
                return ApiResponse::error('not_found', 'User not found.', 404);
            }
            $body['id'] = $id;
            // v1 never makes the PIN silently fall back to 1111 on edit.
            if (($body['pin'] ?? '') === '') {
                $body['pin'] = (string) $old['pin'];
            }
            if (! array_key_exists('active', $body)) {
                $body['active'] = (int) $old['active'] === 1;
            }
        }

        return self::result($this->account->saveUserCore($body), $id === null ? 201 : 200);
    }

    public function setActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        return self::result($this->account->setActiveCore((string) $request->user()->getKey(), $id, (bool) $data['active']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return self::result($this->account->deleteUserCore($id, (string) $request->user()->getKey(), false));
    }

    public function setModuleAccess(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['module' => ['required', 'string', 'max:64'], 'access' => ['required', 'boolean']]);

        return self::result($this->account->setModuleAccessCore((string) $request->user()->getKey(), $id, $data['module'], (bool) $data['access']));
    }

    public function setAdmin(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['module' => ['required', 'string', 'max:64'], 'admin' => ['required', 'boolean']]);
        $r = $this->account->setAdminCore((string) $request->user()->getKey(), $id, $data['module'], (bool) $data['admin']);
        if (empty($r['ok'])) {
            // Same shape as before the seam: 422 with the legacy guard message.
            return ApiResponse::error($r['error'], 'The last superadmin cannot remove itself.', 422);
        }

        return ApiResponse::ok(['userId' => $id, 'module' => $data['module'], 'admin' => (bool) $data['admin']]);
    }

    public function modules(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->account->modulesList($request->boolean('all')));
    }

    /** Bulk User import (legacy saveUsers): {users:[…]} -> {sukses, gagal, baris}. */
    public function bulkUsers(Request $request): JsonResponse
    {
        $rows = $request->input('users');
        if (! is_array($rows)) {
            $rows = [];
        }

        return self::result($this->account->saveUsersCore($rows), 201);
    }

    /** One-time Sheet migration (legacy import): users/modules/grants/admins upserts. */
    public function import(Request $request): JsonResponse
    {
        return self::result($this->account->importCore(
            $request->only(['users', 'modules', 'grants', 'admins'])));
    }

    /** Register NEW Modul keys (legacy syncModules). Existing keys are never touched. */
    public function syncModules(Request $request): JsonResponse
    {
        $modules = $request->input('modules');
        if (! is_array($modules)) {
            $modules = [];
        }

        return self::result($this->account->syncModulesCore($modules), 201);
    }

    /** Edit one Modul's label and/or active flag (legacy saveModule). */
    public function saveModule(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable'],
        ]);

        return self::result($this->account->saveModuleCore(
            $key,
            array_key_exists('label', $data) ? $data['label'] : null,
            array_key_exists('active', $data) ? $data['active'] : null,
        ));
    }

    // any authenticated user -------------------------------------------------

    public function divisiRoster(): JsonResponse
    {
        return ApiResponse::ok($this->account->divisiRoster());
    }

    public function moduleRoster(string $module): JsonResponse
    {
        return ApiResponse::ok($this->account->moduleMembers($module, true));
    }

    // roster managers (admin of `jadwal`, incl. Tim HRD) --------------------

    public function rosterSave(Request $request, ?string $id = null): JsonResponse
    {
        $body = $request->only(['name', 'keterangan', 'noHp', 'talentaId', 'active',
            'branch', 'organization', 'jobPosition', 'jobLevel', 'employmentStatus', 'joinDate']);
        // `active` goes through the rosterSetActive rules below, so a PATCH cannot
        // deactivate the caller or a superadmin (the shared rosterSaveUserCore is
        // the legacy whitelist and would write active straight through).
        $active = array_key_exists('active', $body) ? ! ($body['active'] === false) : null;
        unset($body['active']);
        if ($id !== null) {
            $body['id'] = $id;
        }

        // One transaction: a refused `active` rolls the identity write back, so a
        // 422 never leaves a half-applied PATCH behind.
        $refused = null;
        try {
            $r = Modules::db('account')->transaction(function () use ($body, $active, $id, $request, &$refused) {
                $r = $this->account->rosterSaveUserCore($body);
                if (! empty($r['ok']) && $active !== null) {
                    $ra = $this->account->setActiveCore((string) $request->user()->getKey(), (string) ($id ?? $r['id']), $active);
                    if (empty($ra['ok'])) {
                        $refused = $ra;
                        throw new RuntimeException('roster active refused');
                    }
                }

                return $r;
            });
        } catch (RuntimeException $e) {
            if ($refused === null) {
                throw $e;
            }

            return self::result($refused);
        }

        return self::result($r, $id === null ? 201 : 200);
    }

    /** Permanently delete a deactivated roster row as a Pengelola Roster (legacy rosterHapusUser). */
    public function rosterDestroy(Request $request, string $id): JsonResponse
    {
        return self::result($this->account->deleteUserCore($id, (string) $request->user()->getKey(), true));
    }

    /** Active / inactive as a Pengelola Roster (legacy rosterSetActive). */
    public function rosterSetActive(Request $request, string $id): JsonResponse
    {
        // Legacy default: anything but an explicit false means active.
        $active = ! ($request->input('active') === false);

        return self::result($this->account->setActiveCore((string) $request->user()->getKey(), $id, $active));
    }
}
