<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\V1;

use App\Modules\Automation\Feeds\EventPublik;
use App\Modules\Automation\Feeds\InfoPagi;
use App\Modules\Automation\Feeds\ReservasiHarian;
use App\Modules\Automation\Maps\DenahLink;
use App\Modules\Automation\Maps\DenahMap;
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
        private readonly EventPublik $eventPublik,
        private readonly DenahMap $denah,
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

    /**
     * GET /api/v1/automation/event-publik?from=&to=
     * Public events in a date range (event DB only, no marketing).
     */
    public function eventPublik(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = (string) ($v['from'] ?? EventPublik::today());
        $to = (string) ($v['to'] ?? EventPublik::plusDays(6));
        if ($to < $from) {
            return ApiResponse::error('invalid_range', 'to must be on or after from.', 422);
        }
        if ((strtotime($to) - strtotime($from)) > 31 * 86400) {
            return ApiResponse::error('range_too_wide', 'The range may span at most 31 days.', 422);
        }
        $out = $this->eventPublik->mingguan($from, $to);

        return ApiResponse::ok($out['data'], $this->meta('event-publik', $from, $out['errors']));
    }

    /** GET /api/v1/automation/talent-hari-ini?date= */
    public function talentHariIni(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = (string) ($v['date'] ?? EventPublik::today());
        $out = $this->eventPublik->talentHarian($date);

        return ApiResponse::ok($out['data'], $this->meta('talent-hari-ini', $date, $out['errors']));
    }

    /**
     * POST /api/v1/automation/denah-link — mint a temporary public denah URL
     * for one date + time (n8n attaches it to the draft/confirm message).
     */
    public function denahLink(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $minted = DenahLink::mint((string) $v['date'], (string) $v['time'], time());
        if ($minted === null) {
            return ApiResponse::error('invalid_request', 'date must be YYYY-MM-DD and time HH:MM.', 422);
        }

        return ApiResponse::ok(
            ['url' => url('api/v1/automation/denah').'?t='.$minted['token']],
            $this->meta('denah-link', (string) $v['date'], [])
        );
    }

    /**
     * GET /api/v1/automation/denah?t= — PUBLIC (no login): the temporary map
     * the guest opens. The token is the only credential; it carries no PII
     * and dies after DenahLink::TTL_SECONDS. Nothing is stored server-side.
     */
    public function denah(Request $request): \Illuminate\Http\Response
    {
        $checked = DenahLink::verify((string) $request->query('t', ''), time());
        if (!$checked['ok']) {
            $code = $checked['error'] === 'expired' ? 410 : 403;
            $msg = $checked['error'] === 'expired' ? 'Link denah sudah kedaluwarsa.' : 'Link denah tidak valid.';

            return response(
                '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                .'<title>Denah</title></head><body style="font-family:system-ui,sans-serif;padding:24px"><p>'.e($msg).' Minta link baru ke asisten reservasi.</p></body></html>',
                $code
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        try {
            $html = $this->denah->render($checked['date'], $checked['time'], $checked['exp']);
        } catch (\Throwable $e) {
            report($e);

            return response(
                '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                .'<title>Denah</title></head><body style="font-family:system-ui,sans-serif;padding:24px"><p>Data reservasi tidak bisa dibaca saat ini. Coba lagi sebentar.</p></body></html>',
                503
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
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
