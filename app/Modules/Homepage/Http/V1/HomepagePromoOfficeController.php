<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Http\V1;

use App\Auth\AccountRepository;
use App\Modules\Homepage\Services\HomepageConflict;
use App\Modules\Homepage\Services\HomepagePhotos;
use App\Modules\Homepage\Services\HomepagePromos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/homepage/office — the Promo panel (Sanctum + module:homepage).
 *
 * It never writes a BD row: the BD `promos` document is only read through
 * `BdState`, and the switch/order/uploaded banners live in `homepage_banner`.
 * PATCH/DELETE need `If-Match` (or `?version=`): missing → 428
 * version_required, stale → 409 version_conflict with error.details.current.
 * The `tampil` toggles and `PUT /urutan` are exempt. Every write stamps
 * `updated_by` from the token.
 */
class HomepagePromoOfficeController
{
    public function __construct(
        private readonly HomepagePromos $promos,
        private readonly HomepagePhotos $photos,
        private readonly AccountRepository $users,
    ) {}

    /** The whole panel: stored rows + BD candidates, and whether BD answered. */
    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->promos->officeList());
    }

    /** Office preview by row id (`?banner=`) or BD promo id (`?promo=`) — never by key. */
    public function gambar(Request $r): Response
    {
        $banner = trim((string) $r->query('banner', ''));
        $promo = trim((string) $r->query('promo', ''));
        if (($banner === '') === ($promo === '')) {
            return ApiResponse::error('validation_failed', 'Kirim tepat satu dari banner atau promo.', 422);
        }

        $img = $banner !== ''
            ? $this->promos->officeImageByBanner($banner)
            : $this->promos->officeImageByPromo($promo);
        if ($img === null) {
            return ApiResponse::error('not_found', 'Gambar tidak ada.', 404);
        }

        $etag = '"'.sha1($img['data']).'"';
        $cache = ['Cache-Control' => 'public, max-age=86400'];

        return response($img['data'], 200, $cache + [
            'Content-Type' => $img['type'],
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function fotoUpload(Request $r): JsonResponse
    {
        $f = $r->file('file');
        if (! $f) {
            return ApiResponse::error('validation_failed', 'Kirim berkas multipart pada field "file".', 422);
        }
        try {
            $out = $this->photos->save([
                'name' => (string) $f->getClientOriginalName(),
                'tmp' => (string) $f->getRealPath(),
                'size' => (int) $f->getSize(),
                'mime' => $f->getMimeType(),
            ]);
        } catch (RuntimeException $e) {
            return ApiResponse::error('invalid_file', $e->getMessage(), 422);
        }

        return ApiResponse::created($out);
    }

    public function store(Request $r): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::created($this->promos->createUpload($this->body($r), $this->actor($r))));
    }

    public function update(Request $r, string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->promos->update($id, $this->body($r), $this->base($r), $this->actor($r))));
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->promos->destroy($id, $this->base($r))));
    }

    public function tampil(Request $r, string $id): JsonResponse
    {
        $body = $this->body($r);
        if (! array_key_exists('tampil', $body) || ! is_bool($body['tampil'])) {
            return ApiResponse::error('validation_failed', 'Kirim {"tampil": true|false}.', 422);
        }

        return $this->guard(fn () => ApiResponse::ok($this->promos->setTampil($id, $body['tampil'], $this->actor($r))));
    }

    /** Upsert the switch of a BD promo by its BD id (404 when BD has no such promo). */
    public function bdTampil(Request $r, string $promoId): JsonResponse
    {
        $body = $this->body($r);
        if (! array_key_exists('tampil', $body) || ! is_bool($body['tampil'])) {
            return ApiResponse::error('validation_failed', 'Kirim {"tampil": true|false}.', 422);
        }

        return $this->guard(fn () => ApiResponse::ok($this->promos->setBdTampil($promoId, $body['tampil'], $this->actor($r))));
    }

    public function urutan(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            $saved = $this->promos->urutan($this->body($r), $this->actor($r));

            return ApiResponse::ok(['saved' => true, 'count' => $saved]);
        });
    }

    // ─────────────────────────── helpers ──

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

    private function base(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $q = $r->query('version');

        return is_string($q) && $q !== '' ? $q : null;
    }

    /** Translate the service's exceptions into the v1 envelope. */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (HomepageConflict $e) {
            return match ($e->getMessage()) {
                'version_required' => ApiResponse::error('version_required', 'Kirim versi yang kamu baca sebagai If-Match (atau ?version=).', 428),
                default => ApiResponse::error('version_conflict', 'Data berubah oleh orang lain. Muat ulang lalu simpan lagi.', 409,
                    ['current' => $e->current]),
            };
        } catch (RuntimeException $e) {
            $m = $e->getMessage();

            return match (true) {
                $m === 'not_found' => ApiResponse::error('not_found', 'Data tidak ditemukan.', 404),
                $m === 'tidak_eligible' => ApiResponse::error('tidak_eligible', 'Tidak memenuhi syarat tampil di website.', 422),
                $m === 'pakai_saklar' => ApiResponse::error('pakai_saklar', 'Promo BD dimatikan lewat saklar, bukan dihapus.', 422),
                $m === 'bd_tidak_terhubung' => ApiResponse::error('bd_tidak_terhubung', 'BD OS tidak terhubung.', 503),
                str_starts_with($m, 'validation:') => ApiResponse::error('validation_failed', trim(substr($m, 11)), 422),
                default => throw $e,
            };
        }
    }
}
