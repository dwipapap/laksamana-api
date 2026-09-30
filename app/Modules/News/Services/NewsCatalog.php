<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use App\Modules\News\Models\NewsArtikel;
use App\Modules\News\Models\NewsKategori;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public reads for /api/v1/news — always an allow-list projection (§6.1),
 * never "everything minus secrets". Only `tampil` articles in `aktif`
 * categories are ever returned, newest first (`published_at DESC, id DESC`).
 */
class NewsCatalog
{
    /** The public clock: `date` and `published_at` are rendered in WIB. */
    public const TZ = 'Asia/Jakarta';

    public const PER_PAGE = 9;

    public const MAX_PER_PAGE = 50;

    /** Active categories for the filter chips (§6.2). @return list<array{slug:string,nama:string}> */
    public function kategori(): array
    {
        return NewsKategori::query()->where('aktif', true)
            ->orderBy('urutan')->orderBy('nama')->get()
            ->map(fn (NewsKategori $k) => ['slug' => $k->slug, 'nama' => $k->nama])
            ->all();
    }

    public function kategoriAktifExists(string $slug): bool
    {
        return NewsKategori::query()->where('aktif', true)->where('slug', $slug)->exists();
    }

    /**
     * One page of the listing.
     *
     * @param  array{category?:?string,q?:?string,page?:int|string|null,per_page?:int|string|null}  $f
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,version:string}
     */
    public function list(array $f): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($f['per_page'] ?? self::PER_PAGE)));

        $q = $this->baseQuery();
        self::applyFilters($q, $f);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('news_artikel.published_at')->orderByDesc('news_artikel.id')
            ->forPage($page, $perPage)->with('kategori')->get();

        $items = [];
        $stamps = [];
        foreach ($rows as $a) {
            $items[] = $this->publicRow($a);
            $stamps[] = (string) $a->updated_at;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'version' => $this->version($stamps),
        ];
    }

    /** One public article (with `content`) + its ETag version, or null. @return array{item:array<string,mixed>,version:string}|null */
    public function bySlug(string $slug): ?array
    {
        $a = $this->baseQuery()->where('news_artikel.slug', $slug)->with('kategori')->first();
        if (! $a) {
            return null;
        }

        return [
            'item' => $this->publicRow($a) + [
                'content' => $a->isi,
                'published_at' => self::iso($a->published_at),
            ],
            'version' => $this->version([(string) $a->updated_at]),
        ];
    }

    /** The list shape of §6.1. @return array<string,mixed> */
    public function publicRow(NewsArtikel $a): array
    {
        return [
            'id' => (string) $a->getKey(),
            'slug' => $a->slug,
            'category' => ['slug' => $a->kategori->slug, 'nama' => $a->kategori->nama],
            'title' => $a->judul,
            'excerpt' => $a->ringkasan,
            'date' => $a->published_at?->setTimezone(self::TZ)->format('Y-m-d'),
            'cover' => $a->cover_key ? '/api/v1/news/foto/'.$a->cover_key : null,
            'author' => $a->penulis,
        ];
    }

    /** ISO-8601 in WIB, or null. */
    public static function iso(?DateTimeInterface $t): ?string
    {
        return $t ? CarbonImmutable::instance($t)->setTimezone(self::TZ)->toIso8601String() : null;
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

    /** `category` (slug) and `q` (judul/ringkasan/isi), shared with the office list. */
    public static function applyFilters(Builder $q, array $f): void
    {
        if (($f['category'] ?? null) !== null && $f['category'] !== '') {
            $q->where('k.slug', (string) $f['category']);
        }
        $text = trim((string) ($f['q'] ?? ''));
        if ($text !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%';
            $q->where(function ($w) use ($like): void {
                $w->where('news_artikel.judul', 'like', $like)
                    ->orWhere('news_artikel.ringkasan', 'like', $like)
                    ->orWhere('news_artikel.isi', 'like', $like);
            });
        }
    }

    /** @return Builder<NewsArtikel> */
    private function baseQuery(): Builder
    {
        return NewsArtikel::query()
            ->from('news_artikel')
            ->join('news_kategori as k', 'k.id', '=', 'news_artikel.kategori_id')
            ->where('news_artikel.tampil', true)
            ->where('k.aktif', true)
            ->select('news_artikel.*');
    }
}
