<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\CutiEntitlement;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisLingkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — leave/PH balances (L2). The ledger is append-only;
 * saldo = SUM(delta). FIFO, expiry and transition rules live in
 * HrisBalanceService; this controller only scopes and validates.
 */
final class HrisBalanceController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisBalanceService $saldo,
        private readonly HrisLingkup $lingkup,
        private readonly CutiEntitlement $jatah,
        private readonly AccountRepository $users,
    ) {
        parent::__construct($access);
    }

    /** Own balances with the nearest PH expiry and the CUTI quota. */
    public function mine(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->saldo->ringkas(
            self::s($me['id']), $this->jatah->jatahTahunan(self::s($me['id']))));
    }

    /** Balances, scoped by Lingkup (?user= for HR/heads; HR without a filter sees their own). */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['user' => ['nullable', 'string', 'max:64']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);
            $out = [];
            foreach ($scope ?? [self::s($me['id'])] as $uid) {
                $out[] = ['user_id' => $uid, 'nama' => $this->nama($uid)]
                    + $this->saldo->ringkas($uid, $this->jatah->jatahTahunan($uid));
            }

            return $out;
        });
    }

    /** Ledger history for one person, scoped by Lingkup (C2 riwayat mutasi). */
    public function mutations(Request $r): JsonResponse
    {
        $d = $r->validate(['user' => ['nullable', 'string', 'max:64']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            if ($req === '') {
                $req = self::s($me['id']);
            }
            $this->lingkup->assertCanSee($me, $req);

            return ['user_id' => $req, 'nama' => $this->nama($req),
                'mutasi' => $this->saldo->riwayat($req)];
        });
    }

    private function nama(string $userId): string
    {
        try {
            $u = $this->users->userById($userId);

            return $u ? (string) ($u['name'] ?? $userId) : $userId;
        } catch (\Throwable) {
            return $userId;
        }
    }

    /** HR manual adjustment (positive or negative, reason required). */
    public function adjust(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $this->lingkup->assertHr($me, 'Menyesuaikan saldo');
        $d = $r->validate([
            'user' => ['required', 'string', 'max:64'],
            'jenis' => ['required', 'string', 'max:8'],
            'delta' => ['required', 'integer', 'min:-365', 'max:365'],
            'catatan' => ['required', 'string', 'max:255'],
        ]);

        return $this->run(function () use ($me, $d) {
            $this->saldo->adjust(self::s($me['name']), self::s($d['user']),
                self::s($d['jenis']), (int) $d['delta'], self::s($d['catatan']));

            return $this->saldo->ringkas(self::s($d['user']),
                $this->jatah->jatahTahunan(self::s($d['user'])));
        });
    }
}
