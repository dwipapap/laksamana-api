<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Http\V1;

use App\Modules\Homepage\Services\HomepageEvents;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public homepage event feed: the "Malam" section of the website. No auth.
 * Reads send `Cache-Control` and an `ETag`; a matching `If-None-Match` answers
 * 304, exactly like NewsController. Only `tampil = true` AND eligible events
 * are ever returned, in start order.
 */
class HomepageController
{
    private const CACHE = 'public, max-age=60, stale-while-revalidate=300';

    public function __construct(private readonly HomepageEvents $events) {}

    public function index(Request $r): JsonResponse
    {
        $items = $this->events->publicList();
        $version = substr(sha1((string) json_encode($items)), 0, 16);

        return $this->cached(ApiResponse::ok($items, ['version' => $version]), $version, $r);
    }

    public function poster(string $id): Response
    {
        $found = $this->events->posterPublic($id);
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

    /** Attach the public cache headers; honour If-None-Match → 304. */
    private function cached(JsonResponse $response, ?string $version, Request $request): JsonResponse
    {
        $etag = '"'.($version ?? sha1((string) $response->getContent())).'"';
        $response->headers->set('Cache-Control', self::CACHE);
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request->header('If-None-Match');
        if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && trim($ifNoneMatch, ' "W/') === trim($etag, '"')) {
            $response->setStatusCode(304)->setContent('');
        }

        return $response;
    }
}
