<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Modules\Menu\Models\MenuItem;
use App\Modules\Menu\Models\MenuKategori;
use App\Modules\Menu\Models\MenuVarian;
use Illuminate\Support\Facades\DB;

/**
 * Public reads for /api/v1/menu — always an allow-list projection (§6.1),
 * never "everything minus secrets". Only `tampil` items in `aktif`
 * categories are ever returned; `tersedia` is a public field.
 */
class MenuCatalog
{
    /** The two Bagian values; a two-value enum that never changes (§3, decision #7). */
    public const BAGIAN = [
        'main_course' => 'Main Course',
        'snack' => 'Snack',
    ];

    /**
     * The tab/filter tree (§6.2). Every active category is listed, even one
     * with no items yet — the panel creates categories before items.
     *
     * @return array<string,mixed>
     */
    public function jenis(): array
    {
        $out = [
            'makanan' => ['bagian' => $this->bagian(), 'kategori' => []],
            'minuman' => ['kategori' => []],
        ];
        foreach ($this->kategoriRows() as $k) {
            $out[$k->jenis]['kategori'][] = ['slug' => $k->slug, 'nama' => $k->nama];
        }

        return $out;
    }

    /** @return list<array{kunci:string,nama:string}> */
    public function bagian(): array
    {
        // ponytail: `bagian` is a column until its labels need editing from the panel;
        // promote it to a table then (docs/db/menu.md).
        return array_map(
            fn (string $k, string $n) => ['kunci' => $k, 'nama' => $n],
            array_keys(self::BAGIAN),
            array_values(self::BAGIAN),
        );
    }

    /**
     * Filtered item list. @return array{items: list<array<string,mixed>>, version: string}
     */
    public function list(array $filters): array
    {
        $q = $this->baseQuery();
        $this->applyFilters($q, $filters);
        $rows = $q->orderBy('k.urutan')->orderBy('menu_item.urutan')->orderBy('menu_item.nama')->get();

        return $this->project($rows);
    }

    /** One public item by slug + its ETag version, or null. @return array{item:array<string,mixed>,version:string}|null */
    public function bySlug(string $slug): ?array
    {
        $out = $this->project($this->baseQuery()->where('menu_item.slug', $slug)->get());

        return isset($out['items'][0]) ? ['item' => $out['items'][0], 'version' => $out['version']] : null;
    }

    /** The homepage cards: `unggulan = true`, default order. @return array{items: list<array<string,mixed>>, version: string} */
    public function unggulan(): array
    {
        return $this->list(['unggulan' => true]);
    }

    /**
     * The public shape of §6.1, one query for the rows and one for the
     * variants (no N+1). @return array{items: list<array<string,mixed>>, version: string}
     */
    private function project($rows): array
    {
        $ids = array_map(fn (MenuItem $i) => (string) $i->getKey(), $rows->all());
        $varian = $this->varianByItem($ids);

        $items = [];
        $stamps = [];
        foreach ($rows as $i) {
            $vars = $varian[(string) $i->getKey()] ?? [];
            $items[] = [
                'id' => (string) $i->getKey(),
                'slug' => $i->slug,
                'nama' => $i->nama,
                'jenis' => $i->kategori->jenis,
                'bagian' => $i->bagian,
                'kategori' => ['slug' => $i->kategori->slug, 'nama' => $i->kategori->nama],
                'deskripsi' => (string) $i->deskripsi,
                'komponen' => (string) $i->komponen,
                'foto_url' => $i->foto_key ? '/api/v1/menu/foto/'.$i->foto_key : null,
                'varian' => $vars,
                'harga_mulai' => $vars === [] ? 0 : min(array_column($vars, 'harga')),
                'unggulan' => (bool) $i->unggulan,
                'rekomendasi' => (bool) $i->rekomendasi,
                'pedas' => (bool) $i->pedas,
                'vegetarian' => (bool) $i->vegetarian,
                'ramah_anak' => (bool) $i->ramah_anak,
                'tersedia' => (bool) $i->tersedia,
            ];
            $stamps[] = (string) $i->updated_at;
        }

        return ['items' => $items, 'version' => $this->version($stamps)];
    }

    /** The ETag value: a hash of the newest `updated_at` in the returned set. */
    public function version(array $stamps): string
    {
        if ($stamps === []) {
            return '0';
        }
        sort($stamps);

        return substr(sha1(end($stamps)), 0, 16);
    }

    /** @return list<array{label:string,harga:int}> */
    private function varianByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $out = [];
        $rows = MenuVarian::query()->whereIn('item_id', $itemIds)
            ->orderBy('urutan')->orderBy('id')->get();
        foreach ($rows as $v) {
            $out[(string) $v->item_id][] = ['label' => (string) $v->label, 'harga' => (int) $v->harga];
        }

        return $out;
    }

    private function baseQuery()
    {
        return MenuItem::query()
            ->from('menu_item')
            ->join('menu_kategori as k', 'k.id', '=', 'menu_item.kategori_id')
            ->where('menu_item.tampil', true)
            ->where('k.aktif', true)
            ->select('menu_item.*');
    }

    private function applyFilters($q, array $f): void
    {
        if (($f['jenis'] ?? null) !== null) {
            $q->where('k.jenis', $f['jenis']);
        }
        if (array_key_exists('bagian', $f) && $f['bagian'] !== null) {
            $q->where('menu_item.bagian', $f['bagian']);
        }
        if (($f['kategori'] ?? null) !== null) {
            $q->where('k.slug', $f['kategori']);
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
    }

    /** Active categories in display order. @return \Illuminate\Support\Collection<int,MenuKategori> */
    private function kategoriRows()
    {
        return MenuKategori::query()->where('aktif', true)
            ->orderBy('jenis')->orderBy('urutan')->orderBy('nama')->get();
    }

    /** Items referenced by an ACTIVE category (used by the office list filter). */
    public static function kategoriIdsBySlug(string $slug): ?string
    {
        return DB::connection('core')->table('menu_kategori')->where('slug', $slug)->value('id');
    }
}
