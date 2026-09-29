<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Modules\Menu\Models\MenuItem;
use App\Modules\Menu\Models\MenuKategori;
use App\Modules\Menu\Models\MenuVarian;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Office writes for /api/v1/menu/office — validation, ordering and the
 * optimistic-concurrency rules of docs/api/menu.md §6. Every write stamps
 * `updated_by` from the Sanctum user (passed in by the controller).
 *
 * Throws MenuConflict('version_required'|'version_conflict'|'already_exists'|
 * 'kategori_dipakai') and RuntimeException('validation: …') for 422.
 */
class MenuAdmin
{
    public const JENIS = ['makanan', 'minuman'];

    public function __construct(private readonly MenuCatalog $catalog) {}

    // ─────────────────────────── kategori ──

    /** @return Collection<int,MenuKategori> */
    public function kategoriList()
    {
        return MenuKategori::query()->orderBy('jenis')->orderBy('urutan')->orderBy('nama')->get();
    }

    /** @return array<string,mixed> */
    public function kategoriStore(array $body, ?string $actor): array
    {
        $nama = trim((string) ($body['nama'] ?? ''));
        $jenis = (string) ($body['jenis'] ?? '');
        if ($nama === '') {
            throw new RuntimeException('validation: nama wajib diisi.');
        }
        if (! in_array($jenis, self::JENIS, true)) {
            throw new RuntimeException('validation: jenis harus makanan atau minuman.');
        }
        $slug = trim((string) ($body['slug'] ?? '')) !== '' ? Str::slug((string) $body['slug']) : Str::slug($nama);
        if ($slug === '') {
            throw new RuntimeException('validation: slug tidak bisa dibuat dari nama.');
        }
        if (MenuKategori::query()->where('slug', $slug)->exists()) {
            throw new MenuConflict('already_exists');
        }
        $k = MenuKategori::query()->create([
            'jenis' => $jenis,
            'nama' => $nama,
            'slug' => $slug,
            'urutan' => (int) ($body['urutan'] ?? 0),
            'aktif' => array_key_exists('aktif', $body) ? (bool) $body['aktif'] : true,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        return $this->kategoriRow($k);
    }

    /** @return array<string,mixed> */
    public function kategoriUpdate(string $id, array $body, ?string $actor): array
    {
        $k = $this->findKategori($id);
        $this->guardVersion($k, $body['_base'] ?? null);

        if (array_key_exists('nama', $body)) {
            $nama = trim((string) $body['nama']);
            if ($nama === '') {
                throw new RuntimeException('validation: nama wajib diisi.');
            }
            $k->nama = $nama;
        }
        if (array_key_exists('jenis', $body)) {
            if (! in_array((string) $body['jenis'], self::JENIS, true)) {
                throw new RuntimeException('validation: jenis harus makanan atau minuman.');
            }
            $k->jenis = (string) $body['jenis'];
        }
        if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== '') {
            $slug = Str::slug((string) $body['slug']);
            if ($slug !== $k->slug && MenuKategori::query()->where('slug', $slug)->exists()) {
                throw new MenuConflict('already_exists');
            }
            $k->slug = $slug;
        }
        if (array_key_exists('urutan', $body)) {
            $k->urutan = (int) $body['urutan'];
        }
        if (array_key_exists('aktif', $body)) {
            $k->aktif = (bool) $body['aktif'];
        }
        $k->updated_by = $actor;
        $k->save();

        return $this->kategoriRow($k);
    }

    public function kategoriDestroy(string $id, ?string $base, ?string $actor): void
    {
        $k = $this->findKategori($id);
        $this->guardVersion($k, $base);
        if (MenuItem::query()->where('kategori_id', $k->id)->exists()) {
            throw new MenuConflict('kategori_dipakai');
        }
        $k->updated_by = $actor;
        $k->delete();
    }

    // ─────────────────────────── item ──

    /** Office list: like the public filters, plus `tampil=` and inactive categories. */
    public function itemList(array $f): array
    {
        $q = MenuItem::query()->from('menu_item')
            ->join('menu_kategori as k', 'k.id', '=', 'menu_item.kategori_id')
            ->select('menu_item.*');
        if (($f['jenis'] ?? null) !== null) {
            $q->where('k.jenis', $f['jenis']);
        }
        if (($f['kategori'] ?? null) !== null) {
            $q->where('k.slug', $f['kategori']);
        }
        if (($f['bagian'] ?? null) !== null) {
            $q->where('menu_item.bagian', $f['bagian']);
        }
        if (($f['tampil'] ?? null) !== null) {
            $q->where('menu_item.tampil', (bool) $f['tampil']);
        }
        if (($f['unggulan'] ?? false)) {
            $q->where('menu_item.unggulan', true);
        }
        $text = trim((string) ($f['q'] ?? ''));
        if ($text !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%';
            $q->where(function ($w) use ($like): void {
                $w->where('menu_item.nama', 'like', $like)
                    ->orWhere('menu_item.komponen', 'like', $like)
                    ->orWhere('menu_item.deskripsi', 'like', $like);
            });
        }
        $rows = $q->orderBy('k.urutan')->orderBy('menu_item.urutan')->orderBy('menu_item.nama')->get();

        return array_map(fn (MenuItem $i) => $this->itemRow($i), $rows->all());
    }

    /** @return array<string,mixed> */
    public function itemShow(string $id): array
    {
        return $this->itemRow($this->findItem($id));
    }

    /** @return array<string,mixed> */
    public function itemStore(array $body, ?string $actor): array
    {
        $data = $this->validateItem($body, null);
        $k = $this->resolveKategori($body);
        $this->validateBagian($k, $data['bagian'] ?? null);

        if (MenuItem::query()->where('slug', $data['slug'])->exists()) {
            throw new MenuConflict('already_exists');
        }

        return DB::connection('core')->transaction(function () use ($data, $k, $body, $actor): array {
            $item = MenuItem::query()->create($data + [
                'kategori_id' => $k->id,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
            $this->replaceVarian($item, $body['varian'] ?? [], $actor);

            return $this->itemRow($item->fresh());
        });
    }

    /** @return array<string,mixed> */
    public function itemUpdate(string $id, array $body, ?string $base, ?string $actor): array
    {
        $item = $this->findItem($id);
        $this->guardVersion($item, $base);
        $data = $this->validateItem($body, $item);

        $kategori = array_key_exists('kategori_id', $body) || array_key_exists('kategori', $body)
            ? $this->resolveKategori($body)
            : $item->kategori;
        $bagian = array_key_exists('bagian', $data) ? $data['bagian'] : $item->bagian;
        $this->validateBagian($kategori, $bagian);

        if (isset($data['slug']) && $data['slug'] !== $item->slug
            && MenuItem::query()->where('slug', $data['slug'])->whereKeyNot($item->id)->exists()) {
            throw new MenuConflict('already_exists');
        }

        return DB::connection('core')->transaction(function () use ($item, $data, $kategori, $body, $actor): array {
            $item->fill($data);
            $item->kategori_id = $kategori->id;
            $item->updated_by = $actor;
            $item->save();
            if (array_key_exists('varian', $body)) {
                $this->replaceVarian($item, $body['varian'], $actor);
            }

            return $this->itemRow($item->fresh());
        });
    }

    public function itemDestroy(string $id, ?string $base, ?string $actor): void
    {
        $item = $this->findItem($id);
        $this->guardVersion($item, $base);
        $item->updated_by = $actor;
        $item->delete(); // menu_varian cascades at the database
    }

    /**
     * The two one-field toggles (daily availability, publication). Fast on
     * purpose: they do NOT need If-Match — the last writer wins, a boolean
     * has nothing to merge. They still bump `version`.
     */
    public function toggle(string $id, string $field, mixed $value, ?string $actor): array
    {
        $item = $this->findItem($id);
        $item->{$field} = (bool) $value;
        $item->updated_by = $actor;
        $item->save();

        return $this->itemRow($item->fresh());
    }

    /** Bulk display order: any subset of kategori/item/varian. */
    public function urutan(array $body, ?string $actor): void
    {
        DB::connection('core')->transaction(function () use ($body, $actor): void {
            foreach (['kategori' => MenuKategori::class, 'item' => MenuItem::class, 'varian' => MenuVarian::class] as $key => $class) {
                $rows = $body[$key] ?? null;
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['id'])) {
                        continue;
                    }
                    $model = $class::query()->find($row['id']);
                    if (! $model) {
                        continue;
                    }
                    $model->urutan = (int) ($row['urutan'] ?? 0);
                    $model->updated_by = $actor;
                    $model->save();
                }
            }
        });
    }

    // ─────────────────────────── office shapes ──

    /** Public shape + the office-only fields (§6). @return array<string,mixed> */
    public function itemRow(MenuItem $item): array
    {
        $item->loadMissing('kategori', 'varian');
        $varian = $item->varian->sortBy([['urutan', 'asc'], ['id', 'asc']])->values();
        $public = [
            'id' => (string) $item->id,
            'slug' => $item->slug,
            'nama' => $item->nama,
            'jenis' => $item->kategori->jenis,
            'bagian' => $item->bagian,
            'kategori' => ['slug' => $item->kategori->slug, 'nama' => $item->kategori->nama],
            'deskripsi' => (string) $item->deskripsi,
            'komponen' => (string) $item->komponen,
            'foto_url' => $item->foto_key ? '/api/v1/menu/foto/'.$item->foto_key : null,
            'varian' => $varian->map(fn (MenuVarian $v) => [
                'id' => (string) $v->id, 'label' => (string) $v->label, 'harga' => (int) $v->harga, 'urutan' => (int) $v->urutan,
            ])->all(),
            'harga_mulai' => $varian->isEmpty() ? 0 : (int) $varian->min('harga'),
            'unggulan' => (bool) $item->unggulan,
            'rekomendasi' => (bool) $item->rekomendasi,
            'pedas' => (bool) $item->pedas,
            'vegetarian' => (bool) $item->vegetarian,
            'ramah_anak' => (bool) $item->ramah_anak,
            'tersedia' => (bool) $item->tersedia,
        ];

        return $public + [
            'kategori_id' => (string) $item->kategori_id,
            'tampil' => (bool) $item->tampil,
            'urutan' => (int) $item->urutan,
            'catatan_internal' => (string) $item->catatan_internal,
            'version' => (int) $item->version,
        ];
    }

    /** @return array<string,mixed> */
    public function kategoriRow(MenuKategori $k): array
    {
        return [
            'id' => (string) $k->id,
            'jenis' => $k->jenis,
            'nama' => $k->nama,
            'slug' => $k->slug,
            'urutan' => (int) $k->urutan,
            'aktif' => (bool) $k->aktif,
            'version' => (int) $k->version,
        ];
    }

    /** Every item (incl. tampil=false), internal fields, variants and all categories. */
    public function state(): array
    {
        return [
            'kategori' => $this->kategoriList()->map(fn (MenuKategori $k) => $this->kategoriRow($k))->all(),
            'item' => $this->itemList([]),
            'bagian' => $this->catalog->bagian(),
        ];
    }

    // ─────────────────────────── helpers ──

    private function findKategori(string $id): MenuKategori
    {
        return MenuKategori::query()->find($id) ?? throw new RuntimeException('not_found');
    }

    private function findItem(string $id): MenuItem
    {
        return MenuItem::query()->find($id) ?? throw new RuntimeException('not_found');
    }

    private function resolveKategori(array $body): MenuKategori
    {
        if (isset($body['kategori_id'])) {
            $k = MenuKategori::query()->find((string) $body['kategori_id']);
        } elseif (isset($body['kategori'])) {
            $k = MenuKategori::query()->where('slug', (string) $body['kategori'])->first();
        } else {
            throw new RuntimeException('validation: kategori_id wajib diisi.');
        }
        if (! $k) {
            throw new RuntimeException('validation: kategori tidak ditemukan.');
        }

        return $k;
    }

    private function validateBagian(MenuKategori $k, ?string $bagian): void
    {
        if ($k->jenis === 'makanan' && ($bagian === null || $bagian === '')) {
            throw new RuntimeException('validation: bagian wajib untuk makanan.');
        }
        if ($k->jenis === 'minuman' && $bagian !== null && $bagian !== '') {
            throw new RuntimeException('validation: bagian harus kosong untuk minuman.');
        }
    }

    /** Fields the item table accepts; `jenis` is never stored on the item. */
    private function validateItem(array $body, ?MenuItem $existing): array
    {
        $data = [];
        foreach (['nama', 'deskripsi', 'komponen', 'foto_key', 'catatan_internal'] as $f) {
            if (array_key_exists($f, $body)) {
                $data[$f] = $body[$f] === null ? null : (string) $body[$f];
            }
        }
        if (array_key_exists('nama', $data) && trim((string) $data['nama']) === '') {
            throw new RuntimeException('validation: nama wajib diisi.');
        }
        if ($existing === null && ! array_key_exists('nama', $data)) {
            throw new RuntimeException('validation: nama wajib diisi.');
        }
        foreach (['unggulan', 'rekomendasi', 'pedas', 'vegetarian', 'ramah_anak', 'tampil', 'tersedia'] as $f) {
            if (array_key_exists($f, $body)) {
                $data[$f] = (bool) $body[$f];
            }
        }
        if (array_key_exists('urutan', $body)) {
            $data['urutan'] = (int) $body['urutan'];
        }
        if (array_key_exists('bagian', $body)) {
            $bagian = $body['bagian'] === null || $body['bagian'] === '' ? null : (string) $body['bagian'];
            if ($bagian !== null && ! array_key_exists($bagian, MenuCatalog::BAGIAN)) {
                throw new RuntimeException('validation: bagian harus main_course atau snack.');
            }
            $data['bagian'] = $bagian;
        }
        if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== '') {
            $data['slug'] = Str::slug((string) $body['slug']);
        } elseif (array_key_exists('nama', $body)) {
            $data['slug'] = Str::slug((string) $body['nama']);
        }
        if (isset($data['slug']) && $data['slug'] === '') {
            throw new RuntimeException('validation: slug tidak bisa dibuat dari nama.');
        }

        return $data;
    }

    /** A `varian` array REPLACES the whole set (§6). */
    private function replaceVarian(MenuItem $item, mixed $rows, ?string $actor): void
    {
        if (! is_array($rows)) {
            throw new RuntimeException('validation: varian harus berupa array.');
        }
        MenuVarian::query()->where('item_id', $item->id)->delete();
        $urutan = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('validation: varian harus berupa array objek.');
            }
            $harga = $row['harga'] ?? 0;
            if (! is_numeric($harga) || (float) $harga < 0) {
                throw new RuntimeException('validation: harga harus bilangan >= 0.');
            }
            MenuVarian::query()->create([
                'item_id' => $item->id,
                'label' => (string) ($row['label'] ?? ''),
                'harga' => (int) $harga,
                'urutan' => (int) ($row['urutan'] ?? $urutan++),
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
        }
    }

    /** If-Match required on PATCH/PUT/DELETE: missing → version_required, stale → version_conflict. */
    private function guardVersion(MenuKategori|MenuItem $model, mixed $base): void
    {
        if ($base === null || $base === '' || ! ctype_digit((string) $base)) {
            throw new MenuConflict('version_required');
        }
        if ((int) $base !== (int) $model->version) {
            throw new MenuConflict('version_conflict', $model->version);
        }
    }
}
