<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\V1;

use App\Auth\AccountRepository;
use App\Modules\Menu\Services\MenuAdmin;
use App\Modules\Menu\Services\MenuCatalog;
use App\Modules\Menu\Services\MenuConflict;
use App\Modules\Menu\Services\MenuPhotos;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/menu/office — the Menu panel (docs/api/menu.md §6, "Office").
 * Sanctum + module:menu. PATCH/PUT/DELETE need `If-Match` (or `?version=`):
 * missing → 428 version_required, stale → 409 version_conflict with
 * error.details.current. Every write stamps `updated_by` from the token.
 */
class MenuOfficeController
{
    public function __construct(
        private readonly MenuAdmin $admin,
        private readonly MenuCatalog $catalog,
        private readonly MenuPhotos $photos,
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
        return $this->guard(fn () => ApiResponse::ok($this->admin->kategoriUpdate($id, $this->withBase($r), $this->actor($r))));
    }

    public function kategoriDestroy(Request $r, string $id): JsonResponse
    {
        return $this->guard(function () use ($r, $id) {
            $this->admin->kategoriDestroy($id, $this->base($r), $this->actor($r));

            return ApiResponse::ok(['deleted' => true, 'id' => $id]);
        });
    }

    // ─────────────────────────── item ──

    public function itemIndex(Request $r): JsonResponse
    {
        $f = $r->validate([
            'jenis' => ['nullable', 'in:makanan,minuman'],
            'bagian' => ['nullable', 'in:main_course,snack'],
            'kategori' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'tampil' => ['nullable', 'boolean'],
            'unggulan' => ['nullable', 'boolean'],
        ]);
        $rows = $this->admin->itemList($f);

        return ApiResponse::ok($rows, ['total' => count($rows)]);
    }

    public function itemShow(string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->admin->itemShow($id)));
    }

    public function itemStore(Request $r): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::created($this->admin->itemStore($this->body($r), $this->actor($r))));
    }

    public function itemUpdate(Request $r, string $id): JsonResponse
    {
        return $this->guard(fn () => ApiResponse::ok($this->admin->itemUpdate($id, $this->withBase($r), $this->base($r), $this->actor($r))));
    }

    public function itemDestroy(Request $r, string $id): JsonResponse
    {
        return $this->guard(function () use ($r, $id) {
            $this->admin->itemDestroy($id, $this->base($r), $this->actor($r));

            return ApiResponse::ok(['deleted' => true, 'id' => $id]);
        });
    }

    public function itemTampil(Request $r, string $id): JsonResponse
    {
        return $this->toggle($r, $id, 'tampil');
    }

    public function itemTersedia(Request $r, string $id): JsonResponse
    {
        return $this->toggle($r, $id, 'tersedia');
    }

    private function toggle(Request $r, string $id, string $field): JsonResponse
    {
        $body = $this->body($r);
        if (! array_key_exists($field, $body)) {
            return ApiResponse::error('validation_failed', "Send {\"$field\": true|false}.", 422);
        }

        return $this->guard(fn () => ApiResponse::ok(
            $this->admin->toggle($id, $field, $body[$field], $this->actor($r))
        ));
    }

    public function urutan(Request $r): JsonResponse
    {
        return $this->guard(function () use ($r) {
            $this->admin->urutan($this->body($r), $this->actor($r));

            return ApiResponse::ok(['saved' => true]);
        });
    }

    // ─────────────────────────── photos ──

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

    /** The update body with its resolved base version under a private key. */
    private function withBase(Request $r): array
    {
        return $this->body($r) + ['_base' => $this->base($r)];
    }

    /** Translate the service's exceptions into the v1 envelope. */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (MenuConflict $e) {
            return match ($e->getMessage()) {
                'version_required' => ApiResponse::error('version_required', 'Send the version you read as If-Match (or ?version=).', 428),
                'already_exists' => ApiResponse::error('already_exists', 'Slug ini sudah dipakai.', 409),
                'kategori_dipakai' => ApiResponse::error('kategori_dipakai', 'Kategori masih dipakai oleh item menu.', 409),
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
