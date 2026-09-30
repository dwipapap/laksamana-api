<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Http\V1;

use App\Modules\Homepage\Services\HomepagePromos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public homepage promo feed: the slider of the website. No auth. Reads send
 * `Cache-Control` and an `ETag`; a matching `If-None-Match` answers 304, exactly
 * like the event feed. Only switched-on AND eligible rows are ever returned —
 * and their image only by row id, never by key or data URL.
 */
class HomepagePromoController
{
    private const CACHE = 'public, max-age=60, stale-while-revalidate=300';

    public function __construct(private readonly HomepagePromos $promos) {}

    public function index(Request $r): JsonResponse
    {
        $items = $this->promos->publicList();
        $version = substr(sha1((string) json_encode($items)), 0, 16);

        return $this->cached(ApiResponse::ok($items, ['version' => $version]), $version, $r);
    }

    public function gambar(Request $r, string $id): Response
    {
        $img = $this->promos->publicImage($id);
        if ($img === null) {
            return ApiResponse::error('not_found', 'Gambar tidak ada.', 404);
        }

        return self::image($img, $r);
    }

    /** Binary response with a content ETag; a matching If-None-Match answers 304. */
    private static function image(array $img, Request $r): Response
    {
        $etag = '"'.sha1($img['data']).'"';
        $cache = ['Cache-Control' => 'public, max-age=86400'];
        $ifNoneMatch = $r->header('If-None-Match');
        if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && trim($ifNoneMatch, ' "W/') === trim($etag, '"')) {
            return response('', 304, $cache + ['ETag' => $etag]);
        }

        return response($img['data'], 200, $cache + [
            'Content-Type' => $img['type'],
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
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
