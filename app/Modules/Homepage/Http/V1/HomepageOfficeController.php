<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Http\V1;

use App\Auth\AccountRepository;
use App\Modules\Homepage\Services\HomepageEvents;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/homepage/office — the Event switch screen (Sanctum + module:homepage).
 * It never changes EMS data: it only upserts the `homepage_event.tampil` switch.
 * The toggle is exempt from If-Match (a boolean has nothing to merge, like the
 * news publish toggle); every write stamps `updated_by` from the token.
 */
class HomepageOfficeController
{
    public function __construct(
        private readonly HomepageEvents $events,
        private readonly AccountRepository $users,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->events->officeList();

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function poster(string $id): Response
    {
        $found = $this->events->posterOffice($id);
        if ($found === null) {
            return ApiResponse::error('not_found', 'Poster tidak ada.', 404);
        }
        if (isset($found['redirect'])) {
            return redirect()->away($found['redirect'])->header('Cache-Control', 'public, max-age=86400');
        }

        return response()->file($found['file'], [
            'Content-Type' => $found['type'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function tampil(Request $r, string $id): JsonResponse
    {
        $body = $this->body($r);
        if (! array_key_exists('tampil', $body)) {
            return ApiResponse::error('validation_failed', 'Kirim {"tampil": true|false}.', 422);
        }

        try {
            return ApiResponse::ok($this->events->setTampil($id, (bool) $body['tampil'], $this->actor($r)));
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'not_found' => ApiResponse::error('not_found', 'Event tidak ditemukan.', 404),
                'tidak_eligible' => ApiResponse::error('tidak_eligible', 'Event ini belum boleh tampil di website.', 422),
                default => throw $e,
            };
        }
    }

    /** The acting User's ULID for created_by/updated_by (NULL on a legacy-account run). */
    private function actor(Request $r): ?string
    {
        $id = $r->user()?->getKey();

        return $id === null ? null : $this->users->userUlid((string) $id);
    }

    /** Raw JSON object body (empty object allowed). */
    private function body(Request $r): array
    {
        $b = json_decode((string) $r->getContent(), true);

        return is_array($b) && ($b === [] || ! array_is_list($b)) ? $b : [];
    }
}
