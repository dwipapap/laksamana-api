<?php

namespace App\Modules\Radar\Http\V1;

use App\Modules\Marketing\Services\MarketingFiles;
use App\Modules\Radar\Services\RadarBoard;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/radar — the read-only coordination board (docs/api/radar.md).
 * Module `radar` is built in for every account, so these reads must not
 * leak what the source modules guard: see RadarBoard.
 */
class RadarController
{
    /** Longest range one board request may ask for. */
    private const MAX_DAYS = 400;

    public function __construct(private readonly RadarBoard $board) {}

    public function board(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $today = self::today();
        $from = $d['from'] ?? $today->subDays(31)->toDateString();
        $to = $d['to'] ?? $today->addDays(62)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_DAYS) {
            return ApiResponse::error('validation_failed', 'The range may span at most '.self::MAX_DAYS.' days.', 422);
        }

        return ApiResponse::ok($this->board->board($from, $to, $this->money($r)));
    }

    public function detail(Request $r, string $sumber, string $id): JsonResponse
    {
        $row = $this->board->detail($sumber, $id, $this->money($r));

        return $row
            ? ApiResponse::ok($row + ['bolehUang' => $this->money($r)])
            : ApiResponse::error('not_found', 'Not on the board: it may have changed in its own module.', 404);
    }

    public function promos(): JsonResponse
    {
        return ApiResponse::ok($this->board->promos(self::today()->toDateString()));
    }

    /** An attachment of a Marketing event on the board (Detail Lengkap), nothing else. */
    public function file(Request $r, string $key, MarketingFiles $files): Response
    {
        if (! $this->board->attachmentAllowed($key, $this->money($r))) {
            return ApiResponse::error('not_found', 'No such attachment on the board.', 404);
        }

        return $files->stream($key);
    }

    private function money(Request $r): bool
    {
        return $this->board->mayViewMoney((string) $r->user()->getKey());
    }

    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Jakarta')->startOfDay();
    }
}
