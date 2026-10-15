<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisCoordinationService;
use App\Modules\Hris\Services\HrisError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — inter-division tickets (R6). Screens for every Divisi;
 * the rows live in BD Permintaan Koordinasi and every write honours the
 * BD version guard (stale `berubah` is a 409 with the live record).
 */
final class HrisCoordinationController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisCoordinationService $tiket,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'divisi' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->tiket->list($me,
            self::s($d['status'] ?? '') === '' ? null : self::s($d['status']),
            self::s($d['divisi'] ?? '') === '' ? null : self::s($d['divisi'])));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'judul' => ['nullable', 'string', 'max:190'],
            'title' => ['nullable', 'string', 'max:190'],
            'keDivisi' => ['nullable', 'string', 'max:64'],
            'toDiv' => ['nullable', 'string', 'max:64'],
            'prioritas' => ['nullable', 'string', 'max:16'],
            'tenggat' => ['nullable', 'date_format:Y-m-d'],
            'due' => ['nullable', 'date_format:Y-m-d'],
            'proyek' => ['nullable', 'string', 'max:64'],
            'detail' => ['nullable', 'string', 'max:2000'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->tiket->buat($me, $d), 201);
    }

    public function show(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->tiket->show($me, $id));
    }

    /** Assign and/or advance, with the BD version guard. */
    public function update(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'pelaksana' => ['nullable', 'string', 'max:64'],
            'assignee' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:16'],
            'berubah' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $berubah = (int) ($d['berubah'] ?? $d['version'] ?? 0);
            if ($berubah < 1) {
                throw new HrisError('version_required', 'Kirim berubah (versi yang terlihat).', 428);
            }

            return $this->tiket->ubah($me, $id, $d, $berubah);
        });
    }
}
