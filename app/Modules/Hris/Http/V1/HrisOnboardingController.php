<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisOnboarding;
use App\Modules\Hris\Models\HrisOnboardingLangkah;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — onboarding (O1): HR templates and starts, the newcomer
 * ticks their own non-review steps, reviews take HR or the head.
 */
final class HrisOnboardingController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisOnboardingService $onboarding,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    // ---------------------------------------------------------------- templates

    public function templates(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->lingkup->assertHr($me, 'Melihat template onboarding');

            return $this->onboarding->daftarTemplate();
        });
    }

    public function saveTemplate(Request $r): JsonResponse
    {
        $d = $r->validate([
            'divisi' => ['required', 'string', 'max:64'],
            'langkah' => ['required', 'array', 'min:1', 'max:20'],
            'program_akademi_id' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Menyimpan template onboarding');
            $t = $this->onboarding->simpanTemplate($d, self::s($me['name']));

            return ['id' => $t->id, 'divisi' => $t->divisi];
        }, 201);
    }

    // ---------------------------------------------------------------- progress

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['user' => ['nullable', 'string', 'max:64']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);

            return $this->onboarding->daftar($scope);
        });
    }

    /** Start onboarding for one user (HR). */
    public function start(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['required', 'string', 'max:64'],
            'mulai' => ['required', 'date_format:Y-m-d'],
            'divisi' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Memulai onboarding');

            return $this->onboarding->bentuk($this->onboarding->mulai(
                $me, self::s($d['user']), $d['mulai'], self::s($d['divisi'] ?? '')));
        }, 201);
    }

    /** Tick a step (self/HR; eval-* takes HR/head). */
    public function langkah(Request $r, string $id, string $kunci): JsonResponse
    {
        $d = $r->validate([
            'selesai' => ['required', 'boolean'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $kunci, $d) {
            $ob = HrisOnboarding::query()->find($id);
            if (! $ob) {
                throw new HrisError('not_found', 'Onboarding tidak ada.', 404);
            }
            $this->lingkup->assertCanSee($me, $ob->user_id);
            $l = HrisOnboardingLangkah::query()->where('onboarding_id', $id)
                ->where('kunci', $kunci)->first();
            if (! $l) {
                throw new HrisError('not_found', 'Langkah tidak ada.', 404);
            }
            $l = $this->onboarding->selesaikanLangkah($me, $l, (bool) $d['selesai'], self::s($d['catatan'] ?? ''));

            return ['kunci' => $l->kunci, 'selesai' => (bool) $l->selesai];
        });
    }
}
