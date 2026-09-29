<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\V1;

use App\Modules\Menu\Services\MenuCatalog;
use App\Modules\Menu\Services\MenuPhotos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu reads (docs/api/menu.md §6): no auth, no server-side cache.
 * Public reads send `Cache-Control` and an `ETag`; a matching `If-None-Match`
 * answers 304. Photos stream with a long cache.
 */
class MenuController
{
    private const CACHE = 'public, max-age=60, stale-while-revalidate=300';

    public function __construct(
        private readonly MenuCatalog $catalog,
        private readonly MenuPhotos $photos,
    ) {}

    public function jenis(Request $r): JsonResponse
    {
        return $this->cached(ApiResponse::ok($this->catalog->jenis()), null, $r);
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate([
            'jenis' => ['nullable', 'in:makanan,minuman'],
            'bagian' => ['nullable', 'in:main_course,snack'],
            'kategori' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'unggulan' => ['nullable', 'boolean'],
        ]);

        return $this->list($this->catalog->list($f), $r);
    }

    public function unggulan(Request $r): JsonResponse
    {
        return $this->list($this->catalog->unggulan(), $r);
    }

    public function show(Request $r, string $slug): JsonResponse
    {
        $found = $this->catalog->bySlug($slug);
        if ($found === null) {
            return ApiResponse::error('not_found', 'Menu tidak ditemukan.', 404);
        }

        return $this->cached(ApiResponse::ok($found['item'], ['version' => $found['version']]), $found['version'], $r);
    }

    public function foto(string $key): Response
    {
        return $this->photos->stream($key);
    }

    /** @param array{items:list<array<string,mixed>>,version:string} $out */
    private function list(array $out, Request $r): JsonResponse
    {
        $body = ApiResponse::ok($out['items'], ['total' => count($out['items']), 'version' => $out['version']]);

        return $this->cached($body, $out['version'], $r);
    }

    /** Attach the public cache headers; honour If-None-Match → 304. */
    private function cached(JsonResponse $response, ?string $version = null, ?Request $request = null): JsonResponse
    {
        $etag = '"'.($version ?? sha1((string) $response->getContent())).'"';
        $response->headers->set('Cache-Control', self::CACHE);
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request?->header('If-None-Match');
        if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && trim($ifNoneMatch, ' "W/') === trim($etag, '"')) {
            $response->setStatusCode(304)->setContent('');
        }

        return $response;
    }
}
