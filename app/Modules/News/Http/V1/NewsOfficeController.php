<?php

declare(strict_types=1);

namespace App\Modules\News\Http\V1;

use App\Auth\AccountRepository;
use App\Modules\News\Services\NewsAdmin;
use App\Modules\News\Services\NewsConflict;
use App\Modules\News\Services\NewsPhotos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/news/office — the Berita panel (spec §6, "Office").
 * Sanctum + module:news. Kategori/artikel PATCH and DELETE need `If-Match`
 * (or `?version=`): missing → 428 version_required, stale → 409
 * version_conflict with error.details.current. The `tampil` toggle and
 * `PUT /urutan` are exempt. Every write stamps `updated_by` from the token.
 */
class NewsOfficeController
{
    public function __construct(
        private readonly NewsAdmin $admin,
        private readonly NewsPhotos $photos,
        private readonly AccountRepository $users,
    ) {}

    // ─────────────────────────── state & kategori ──

    public function state(): JsonResponse
    {
        return ApiResponse::ok($this->admin->state());
    }

    public function kategoriIndex(): JsonResponse
    {
        $rows = $this->admin->kategoriList()->map(fn ($k) => $this->admin->kategoriRow($k))->all();

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function kategoriStore(Request $r): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::created($this->admin->kategoriStore($this->body($r), $this->actor($r))));
    }

    public function kategoriUpdate(Request $r, string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->admin->kategoriUpdate($id, $this->body($r), $this->base($r), $this->actor($r))));
    }

    public function kategoriDestroy(Request $r, string $id): JsonResponse
    {
        return $this->guard(function () use ($r, $id) {
            $this->admin->kategoriDestroy($id, $this->base($r));

            return ApiResponse::ok(['deleted' => true, 'id' => $id]);
        });
    }

    // ─────────────────────────── artikel ──

    public function artikelIndex(Request $r): JsonResponse
    {
        $f = $r->validate([
            'category' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'tampil' => ['nullable', 'boolean'],
        ]);
        $rows = $this->admin->artikelList($f);

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function artikelShow(string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->admin->artikelShow($id)));
    }

    public function artikelStore(Request $r): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::created($this->admin->artikelStore($this->body($r), $this->actor($r))));
    }

    public function artikelUpdate(Request $r, string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->admin->artikelUpdate($id, $this->body($r), $this->base($r), $this->actor($r))));
    }

    public function artikelDestroy(Request $r, string $id): JsonResponse
    {
        return $this->guard(function () use ($r, $id) {
            $this->admin->artikelDestroy($id, $this->base($r));

            return ApiResponse::ok(['deleted' => true, 'id' => $id]);
        });
    }

    public function artikelTampil(Request $r, string $id): JsonResponse
    {
        $body = $this->body($r);
        if (! array_key_exists('tampil', $body)) {
            return ApiResponse::error('validation_failed', 'Send {"tampil": true|false}.', 422);
        }

        return $this->guard(fn () => ApiResponse::ok($this->admin->tampil($id, $body['tampil'], $this->actor($r))));
    }

    public function urutan(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            $this->admin->urutan($this->body($r), $this->actor($r));

            return ApiResponse::ok(['saved' => true]);
        });
    }

    // ─────────────────────────── covers ──

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

    public function fotoDestroy(string $key): JsonResponse
    {
        return ApiResponse::ok(['deleted' => $this->photos->delete($key), 'key' => $key]);
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
        } catch (NewsConflict $e) {
            return match ($e->getMessage()) {
                'version_required' => ApiResponse::error('version_required', 'Send the version you read as If-Match (or ?version=).', 428),
                'already_exists' => ApiResponse::error('already_exists', 'Slug ini sudah dipakai.', 409),
                'kategori_dipakai' => ApiResponse::error('kategori_dipakai', 'Kategori masih dipakai oleh artikel.', 409),
                default => ApiResponse::error('version_conflict', 'Data berubah oleh orang lain. Muat ulang lalu simpan lagi.', 409,
                    ['current' => $e->current]),
            };
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            if ($m === 'not_found') {
                return ApiResponse::error('not_found', 'Data tidak ditemukan.', 404);
            }
            if (str_starts_with($m, 'validation:')) {
                return ApiResponse::error('validation_failed', trim(substr($m, 11)), 422);
            }

            throw $e;
        }
    }
}
