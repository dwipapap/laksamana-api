<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisCalendarService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — the holiday calendar (L8). Reads are open to every HRIS
 * holder (the punch screen explains HARI_LIBUR); writes are HR-only.
 * National holidays come from an HR-uploaded JSON/CSV file — there is no
 * single reliable public API, so none is guessed here.
 */
final class HrisCalendarController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisCalendarService $kalender,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::ok($this->kalender->list($d['dari'] ?? '', $d['sampai'] ?? ''));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'nama' => ['required', 'string', 'max:120'],
            'jenis' => ['nullable', 'string', 'max:16'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Mengatur kalender libur');
            $row = $this->kalender->save($d, self::s($me['name']));

            return ['id' => $row->id, 'tanggal' => $row->tanggal->format('Y-m-d')];
        }, 201);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus libur');
            if (! $this->kalender->delete($id, self::s($me['name']))) {
                throw new HrisError('not_found', 'Libur tidak ada.', 404);
            }

            return ['hapus' => 1];
        });
    }

    /** Import from pasted rows or an uploaded .json/.csv file. HR only. */
    public function import(Request $r): JsonResponse
    {
        $d = $r->validate([
            'libur' => ['nullable', 'array', 'max:500'],
            'libur.*.tanggal' => ['nullable', 'date_format:Y-m-d'],
            'libur.*.nama' => ['nullable', 'string', 'max:120'],
            'libur.*.jenis' => ['nullable', 'string', 'max:16'],
            'file' => ['nullable', 'file', 'max:2048', 'mimetypes:text/csv,application/json,text/plain'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d, $r) {
            $this->lingkup->assertHr($me, 'Mengimpor libur');
            $rows = $d['libur'] ?? [];
            if ($r->hasFile('file')) {
                $f = $r->file('file');
                $rows = HrisCalendarService::parseFile(
                    (string) file_get_contents($f->getRealPath()), (string) $f->getClientOriginalName());
            }

            return $this->kalender->import(is_array($rows) ? $rows : [], self::s($me['name']));
        });
    }
}
