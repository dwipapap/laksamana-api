<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisDesignRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — Marketing → Konten design requests, read-only (R6).
 * HRIS never writes a single Marketing row; projections are allow-lists.
 */
final class HrisDesignRequestController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisDesignRequestService $desain,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['aktif' => ['nullable', 'boolean']]);

        return $this->run(fn () => $this->desain->daftar(! empty($d['aktif'])));
    }

    public function show(Request $r, string $id): JsonResponse
    {
        return $this->run(fn () => $this->desain->satu($id));
    }
}
