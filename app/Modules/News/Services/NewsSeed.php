<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use App\Modules\News\Models\NewsArtikel;
use App\Modules\News\Models\NewsKategori;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one-time load from the committed `database/seeders/data/news.json`
 * (spec §8). Idempotent by slug; `--fresh` deletes every news row first. An
 * unknown kategori slug aborts the whole run before anything is written.
 * `published_at` without an offset is read as WIB.
 */
class NewsSeed
{
    public function __construct(private readonly NewsSanitize $sanitize) {}

    /** @return array{kategori_baru:int,kategori_ubah:int,artikel_baru:int,artikel_ubah:int} */
    public function run(bool $fresh): array
    {
        $file = database_path('seeders/data/news.json');
        if (! is_file($file)) {
            throw new RuntimeException("Berkas data berita tidak ditemukan: $file");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            throw new RuntimeException('news.json bukan JSON yang sah.');
        }
        $kategori = $data['kategori'] ?? [];
        $artikel = $data['artikel'] ?? [];
        if (! is_array($kategori) || ! is_array($artikel)) {
            throw new RuntimeException('news.json harus punya array "kategori" dan "artikel".');
        }
        $this->validate($kategori, $artikel);

        return DB::connection('core')->transaction(function () use ($fresh, $kategori, $artikel): array {
            if ($fresh) {
                NewsArtikel::query()->delete();
                NewsKategori::query()->delete();
            }
            $r = ['kategori_baru' => 0, 'kategori_ubah' => 0, 'artikel_baru' => 0, 'artikel_ubah' => 0];
            foreach ($kategori as $row) {
                $slug = Str::slug((string) $row['slug']);
                $attrs = [
                    'nama' => (string) ($row['nama'] ?? $slug),
                    'urutan' => (int) ($row['urutan'] ?? 0),
                    'aktif' => (bool) ($row['aktif'] ?? true),
                ];
                $k = NewsKategori::query()->where('slug', $slug)->first();
                if ($k) {
                    $k->fill($attrs);
                    if ($k->isDirty()) {
                        $k->save();
                        $r['kategori_ubah']++;
                    }
                } else {
                    NewsKategori::query()->create($attrs + ['slug' => $slug]);
                    $r['kategori_baru']++;
                }
            }
            foreach ($artikel as $row) {
                $slug = (string) $row['slug'];
                $k = NewsKategori::query()->where('slug', (string) $row['kategori'])->firstOrFail();
                $tampil = (bool) ($row['tampil'] ?? false);
                $published = NewsAdmin::parseDate($row['published_at'] ?? null);
                $attrs = [
                    'kategori_id' => $k->id,
                    'judul' => (string) ($row['judul'] ?? $slug),
                    'ringkasan' => isset($row['ringkasan']) ? (string) $row['ringkasan'] : null,
                    'isi' => $this->sanitize->clean(isset($row['isi']) ? (string) $row['isi'] : null),
                    'cover_key' => isset($row['cover_key']) ? (string) $row['cover_key'] : null,
                    'penulis' => isset($row['penulis']) ? (string) $row['penulis'] : null,
                    'tampil' => $tampil,
                ];
                $a = NewsArtikel::query()->where('slug', $slug)->first();
                if ($a) {
                    $a->fill($attrs);
                    // Compare instants, not objects, so a re-run changes nothing.
                    if ($published?->getTimestamp() !== $a->published_at?->getTimestamp()) {
                        $a->published_at = $published;
                    }
                    if ($a->isDirty()) {
                        $a->save();
                        $r['artikel_ubah']++;
                    }
                } else {
                    NewsArtikel::query()->create($attrs + [
                        'slug' => $slug,
                        'published_at' => $published ?? ($tampil ? now('UTC') : null),
                    ]);
                    $r['artikel_baru']++;
                }
            }

            return $r;
        });
    }

    /** Fail before writing when a slug repeats or an article names an unknown category. */
    private function validate(array $kategori, array $artikel): void
    {
        $known = [];
        foreach ($kategori as $row) {
            if (! is_array($row) || ! isset($row['slug'])) {
                throw new RuntimeException('Setiap kategori butuh "slug".');
            }
            $slug = Str::slug((string) $row['slug']);
            if (isset($known[$slug])) {
                throw new RuntimeException("Slug kategori ganda: $slug");
            }
            $known[$slug] = true;
        }
        $seen = [];
        foreach ($artikel as $row) {
            if (! is_array($row) || ! isset($row['slug'], $row['kategori'], $row['judul'])) {
                throw new RuntimeException('Setiap artikel butuh "slug", "judul" dan "kategori".');
            }
            $slug = (string) $row['slug'];
            if (! isset($known[(string) $row['kategori']])) {
                throw new RuntimeException("Kategori tidak dikenal untuk artikel $slug: {$row['kategori']}");
            }
            if (isset($seen[$slug])) {
                throw new RuntimeException("Slug artikel ganda: $slug");
            }
            $seen[$slug] = true;
            NewsAdmin::parseDate($row['published_at'] ?? null);
        }
    }
}
