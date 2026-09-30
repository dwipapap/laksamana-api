<?php

declare(strict_types=1);

namespace App\Modules\News\Http\V1;

use App\Modules\News\Services\NewsCatalog;
use App\Modules\News\Services\NewsPhotos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public news reads (spec §6): no auth, no server-side cache. Reads send
 * `Cache-Control` and an `ETag`; a matching `If-None-Match` answers 304.
 * Covers stream with a long cache.
 */
class NewsController
{
    private const CACHE = 'public, max-age=60, stale-while-revalidate=300';

    public function __construct(
        private readonly NewsCatalog $catalog,
        private readonly NewsPhotos $photos,
    ) {}

    public function kategori(Request $r): JsonResponse
    {
        return $this->cached(ApiResponse::ok($this->catalog->kategori()), null, $r);
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate([
            'category' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.NewsCatalog::MAX_PER_PAGE],
        ]);
        if (($f['category'] ?? null) !== null && ! $this->catalog->kategoriAktifExists($f['category'])) {
            return ApiResponse::error('validation_failed', 'Kategori tidak dikenal.', 422, ['category' => ['Kategori tidak dikenal.']]);
        }

        $out = $this->catalog->list($f);
        $body = ApiResponse::ok($out['items'], [
            'total' => $out['total'],
            'page' => $out['page'],
            'per_page' => $out['per_page'],
            'version' => $out['version'],
        ]);

        // The page and filters are part of the ETag: two pages can share their newest stamp.
        return $this->cached($body, $out['version'].'-'.substr(sha1((string) $r->getQueryString()), 0, 8), $r);
    }

    public function show(Request $r, string $slug): JsonResponse
    {
        $found = $this->catalog->bySlug($slug);
        if ($found === null) {
            return ApiResponse::error('not_found', 'Berita tidak ditemukan.', 404);
        }

        return $this->cached(ApiResponse::ok($found['item'], ['version' => $found['version']]), $found['version'], $r);
    }

    public function foto(string $key): Response
    {
        return $this->photos->stream($key);
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
