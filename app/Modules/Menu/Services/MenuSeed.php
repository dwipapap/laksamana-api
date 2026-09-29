<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Modules\Menu\Models\MenuItem;
use App\Modules\Menu\Models\MenuKategori;
use App\Modules\Menu\Models\MenuVarian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one-time catalogue load from the committed `database/seeders/data/menu.json`
 * (§8). Idempotent by slug; `--fresh` deletes every menu row first. An unknown
 * kategori slug aborts the whole run before anything is written.
 *
 * Prices in the JSON are integer IDR; the seeder does NOT multiply (whoever
 * fills the file converts "45" in the PDF to 45000).
 */
class MenuSeed
{
    public function __construct(private readonly MenuCatalog $catalog) {}

    /** @return array{kategori_baru:int,kategori_ubah:int,item_baru:int,item_ubah:int,varian:int} */
    public function run(bool $fresh): array
    {
        $file = database_path('seeders/data/menu.json');
        if (! is_file($file)) {
            throw new RuntimeException("Berkas data menu tidak ditemukan: $file");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            throw new RuntimeException('menu.json bukan JSON yang sah.');
        }
        $kategori = $data['kategori'] ?? [];
        $item = $data['item'] ?? [];
        if (! is_array($kategori) || ! is_array($item)) {
            throw new RuntimeException('menu.json harus punya array "kategori" dan "item".');
        }

        $kategoriBySlug = $this->validate($kategori, $item);

        return DB::connection('core')->transaction(function () use ($fresh, $kategori, $item, $kategoriBySlug): array {
            if ($fresh) {
                MenuVarian::query()->delete();
                MenuItem::query()->delete();
                MenuKategori::query()->delete();
            }
            $r = ['kategori_baru' => 0, 'kategori_ubah' => 0, 'item_baru' => 0, 'item_ubah' => 0, 'varian' => 0];
            foreach ($kategori as $row) {
                $slug = (string) $row['slug'];
                $k = MenuKategori::query()->where('slug', $slug)->first();
                $attrs = [
                    'jenis' => (string) $row['jenis'],
                    'nama' => (string) ($row['nama'] ?? $slug),
                    'urutan' => (int) ($row['urutan'] ?? 0),
                    'aktif' => (bool) ($row['aktif'] ?? true),
                ];
                if ($k) {
                    $k->fill($attrs);
                    if ($k->isDirty()) {
                        $k->save();
                        $r['kategori_ubah']++;
                    }
                } else {
                    MenuKategori::query()->create($attrs + ['slug' => $slug]);
                    $r['kategori_baru']++;
                }
            }
            foreach ($item as $row) {
                $slug = (string) $row['slug'];
                $kategori = MenuKategori::query()->where('slug', $kategoriBySlug[$slug])->firstOrFail();
                $i = MenuItem::query()->where('slug', $slug)->first();
                $attrs = [
                    'kategori_id' => $kategori->id,
                    'bagian' => $row['bagian'] ?? null,
                    'nama' => (string) ($row['nama'] ?? $slug),
                    'deskripsi' => (string) ($row['deskripsi'] ?? ''),
                    'komponen' => (string) ($row['komponen'] ?? ''),
                    'unggulan' => (bool) ($row['unggulan'] ?? false),
                    'rekomendasi' => (bool) ($row['rekomendasi'] ?? false),
                    'pedas' => (bool) ($row['pedas'] ?? false),
                    'vegetarian' => (bool) ($row['vegetarian'] ?? false),
                    'ramah_anak' => (bool) ($row['ramah_anak'] ?? false),
                    'tampil' => (bool) ($row['tampil'] ?? true),
                    'tersedia' => (bool) ($row['tersedia'] ?? true),
                    'urutan' => (int) ($row['urutan'] ?? 0),
                ];
                if ($i) {
                    $i->fill($attrs);
                    if ($i->isDirty()) {
                        $i->save();
                        $r['item_ubah']++;
                    }
                } else {
                    $i = MenuItem::query()->create($attrs + ['slug' => $slug]);
                    $r['item_baru']++;
                }
                $r['varian'] += $this->syncVarian($i, $row['varian'] ?? []);
            }

            return $r;
        });
    }

    /** Upsert variants by (label, harga): reuse an existing row, else create. */
    private function syncVarian(MenuItem $item, array $rows): int
    {
        $wanted = [];
        foreach ($rows as $n => $row) {
            $wanted[] = [
                'label' => (string) ($row['label'] ?? ''),
                'harga' => (int) ($row['harga'] ?? 0),
                'urutan' => (int) ($row['urutan'] ?? $n),
            ];
        }
        $existing = MenuVarian::query()->where('item_id', $item->id)->get();
        $count = 0;
        foreach ($wanted as $w) {
            $match = $existing->first(fn (MenuVarian $v) => (string) $v->label === $w['label'] && (int) $v->harga === $w['harga']);
            if ($match) {
                if ((int) $match->urutan !== $w['urutan']) {
                    $match->urutan = $w['urutan'];
                    $match->save();
                }
                $count++;

                continue;
            }
            MenuVarian::query()->create($w + ['item_id' => $item->id]);
            $count++;
        }

        return $count;
    }

    /**
     * Fail the whole run before writing when a category slug is unknown or a
     * slug repeats. @return array<string,string> item slug => kategori slug
     */
    private function validate(array $kategori, array $item): array
    {
        $known = [];
        foreach ($kategori as $row) {
            if (! is_array($row) || ! isset($row['slug'], $row['jenis'])) {
                throw new RuntimeException('Setiap kategori butuh "jenis" dan "slug".');
            }
            if (! in_array((string) $row['jenis'], MenuAdmin::JENIS, true)) {
                throw new RuntimeException('jenis kategori harus makanan atau minuman: '.(string) $row['slug']);
            }
            $slug = Str::slug((string) $row['slug']);
            if (isset($known[$slug])) {
                throw new RuntimeException("Slug kategori ganda: $slug");
            }
            $known[$slug] = $slug;
        }
        $map = [];
        foreach ($item as $row) {
            if (! is_array($row) || ! isset($row['slug'], $row['kategori'])) {
                throw new RuntimeException('Setiap item butuh "slug" dan "kategori".');
            }
            $slug = (string) $row['slug'];
            $kat = (string) $row['kategori'];
            if (! isset($known[$kat])) {
                throw new RuntimeException("Kategori tidak dikenal untuk item $slug: $kat");
            }
            if (isset($map[$slug])) {
                throw new RuntimeException("Slug item ganda: $slug");
            }
            $map[$slug] = $kat;
        }

        return $map;
    }
}
