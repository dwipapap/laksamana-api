<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\V1;

use App\Modules\Automation\Feeds\InfoPagi;
use App\Modules\Automation\Feeds\ReservasiHarian;
use App\Support\Api\ApiResponse;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/automation/* — the read-only n8n feeds (#234).
 *
 * One envelope for every feed:
 *   data = the feed's own payload
 *   meta = {feed, date, generatedAt (ISO8601 Asia/Jakarta), errors}
 *
 * A failed section is reported in meta.errors with a 200, never a 500: the
 * automation must be able to tell "no events" from "the source failed".
 * There is no ?source= — the server's AUTOMATION_DB_* decides where it reads.
 */
class FeedController
{
    public function __construct(
        private readonly ReservasiHarian $reservasi,
        private readonly InfoPagi $info,
    ) {}

    /** GET /api/v1/automation/reservasi-harian?date=&days=0..7 */
    public function reservasiHarian(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:0', 'max:7'],
        ]);

        $date = (string) ($v['date'] ?? ReservasiHarian::plusDays(0));
        $out = $this->reservasi->forDates($date, (int) ($v['days'] ?? 0));

        return ApiResponse::ok($out['data'], $this->meta('reservasi-harian', $date, $out['errors']));
    }

    /** GET /api/v1/automation/info-pagi?date= */
    public function infoPagi(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = (string) ($v['date'] ?? InfoPagi::today());
        $out = $this->info->briefing($date);

        return ApiResponse::ok($out['data'], $this->meta('info-pagi', $date, $out['errors']));
    }

    private function meta(string $feed, string $date, array $errors): array
    {
        return [
            'feed' => $feed,
            'date' => $date,
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format(DateTimeInterface::ATOM),
            'errors' => (object) $errors,
        ];
    }
}
