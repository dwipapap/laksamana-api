<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisDisiplinService;
use App\Modules\Hris\Services\HrisLingkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — discipline record (S4). Writes are HR-only; reads are
 * scoped by Lingkup (crew see their own — it is feedback, not a secret).
 */
final class HrisDisiplinController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisDisiplinService $disiplin,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['nullable', 'string', 'max:64'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);

            return $this->disiplin->list($scope, $d['dari'] ?? '', $d['sampai'] ?? '');
        });
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['required', 'string', 'max:64'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'jenis' => ['nullable', 'string', 'max:64'],
            'tingkat' => ['nullable', 'string', 'max:16'],
            'sp_ke' => ['nullable', 'integer', 'min:0', 'max:10'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Mencatat pelanggaran');

            return $this->disiplin->shape($this->disiplin->create($d, self::s($me['name'])));
        }, 201);
    }

    public function batal(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Membatalkan pelanggaran');

            return $this->disiplin->shape($this->disiplin->batal($id, self::s($me['name'])));
        });
    }
}
