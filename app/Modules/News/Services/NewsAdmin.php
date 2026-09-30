<?php

declare(strict_types=1);

namespace App\Modules\News\Services;

use App\Modules\News\Models\NewsArtikel;
use App\Modules\News\Models\NewsKategori;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Office writes for /api/v1/news/office — validation, sanitization of `isi`,
 * the publish toggle and the optimistic-concurrency rules (spec §6). Every
 * write stamps `updated_by` from the Sanctum user (passed in by the controller).
 *
 * Throws NewsConflict('version_required'|'version_conflict'|'already_exists'|
 * 'kategori_dipakai') and RuntimeException('validation: …' | 'not_found').
 */
class NewsAdmin
{
    /** Literal public paths under /news that an article slug would be shadowed by. */
    public const RESERVED_SLUGS = ['kategori', 'office', 'foto'];

    public function __construct(
        private readonly NewsCatalog $catalog,
        private readonly NewsSanitize $sanitize,
    ) {}

    // ─────────────────────────── kategori ──

    /** @return Collection<int,NewsKategori> */
    public function kategoriList()
    {
        return NewsKategori::query()->orderBy('urutan')->orderBy('nama')->get();
    }

    /** @return array<string,mixed> */
    public function kategoriStore(array $body, ?string $actor): array
    {
        $nama = trim((string) ($body['nama'] ?? ''));
        if ($nama === '') {
            throw new RuntimeException('validation: nama wajib diisi.');
        }
        $slug = trim((string) ($body['slug'] ?? '')) !== '' ? Str::slug((string) $body['slug']) : Str::slug($nama);
        if ($slug === '') {
            throw new RuntimeException('validation: slug tidak bisa dibuat dari nama.');
        }
        if (NewsKategori::query()->where('slug', $slug)->exists()) {
            throw new NewsConflict('already_exists');
        }
        $k = NewsKategori::query()->create([
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
    public function kategoriUpdate(string $id, array $body, ?string $base, ?string $actor): array
    {
        $k = $this->findKategori($id);
        $this->guardVersion($k, $base);

        if (array_key_exists('nama', $body)) {
            $nama = trim((string) $body['nama']);
            if ($nama === '') {
                throw new RuntimeException('validation: nama wajib diisi.');
            }
            $k->nama = $nama;
        }
        if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== '') {
            $slug = Str::slug((string) $body['slug']);
            if ($slug === '') {
                throw new RuntimeException('validation: slug tidak sah.');
            }
            if ($slug !== $k->slug && NewsKategori::query()->where('slug', $slug)->exists()) {
                throw new NewsConflict('already_exists');
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

    public function kategoriDestroy(string $id, ?string $base): void
    {
        $k = $this->findKategori($id);
        $this->guardVersion($k, $base);
        if (NewsArtikel::query()->where('kategori_id', $k->id)->exists()) {
            throw new NewsConflict('kategori_dipakai');
        }
        $k->delete();
    }

    // ─────────────────────────── artikel ──

    /**
     * Office list: the public `category`/`q` filters plus `tampil`; also shows
     * drafts and articles of inactive categories. Newest first, drafts without
     * a date on top.
     *
     * @return list<array<string,mixed>>
     */
    public function artikelList(array $f): array
    {
        $q = NewsArtikel::query()->from('news_artikel')
            ->join('news_kategori as k', 'k.id', '=', 'news_artikel.kategori_id')
            ->select('news_artikel.*');
        NewsCatalog::applyFilters($q, $f);
        if (($f['tampil'] ?? null) !== null) {
            $q->where('news_artikel.tampil', (bool) $f['tampil']);
        }
        $rows = $q->orderByRaw('news_artikel.published_at IS NULL DESC')
            ->orderByDesc('news_artikel.published_at')->orderByDesc('news_artikel.id')
            ->with('kategori')->get();

        return array_map(fn (NewsArtikel $a) => $this->artikelRow($a), $rows->all());
    }

    /** @return array<string,mixed> */
    public function artikelShow(string $id): array
    {
        return $this->artikelRow($this->findArtikel($id));
    }

    /** @return array<string,mixed> */
    public function artikelStore(array $body, ?string $actor): array
    {
        $data = $this->validateArtikel($body, null);
        $k = $this->resolveKategori($body);
        if (NewsArtikel::query()->where('slug', $data['slug'])->exists()) {
            throw new NewsConflict('already_exists');
        }
        $data += ['tampil' => false];
        if ($data['tampil'] && empty($data['published_at'])) {
            $data['published_at'] = CarbonImmutable::now('UTC');
        }

        $a = NewsArtikel::query()->create($data + [
            'kategori_id' => $k->id,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        return $this->artikelRow($a->fresh());
    }

    /** @return array<string,mixed> */
    public function artikelUpdate(string $id, array $body, ?string $base, ?string $actor): array
    {
        $a = $this->findArtikel($id);
        $this->guardVersion($a, $base);
        $data = $this->validateArtikel($body, $a);

        if (array_key_exists('kategori_id', $body) || array_key_exists('kategori', $body)) {
            $data['kategori_id'] = $this->resolveKategori($body)->id;
        }
        if (isset($data['slug']) && $data['slug'] !== $a->slug
            && NewsArtikel::query()->where('slug', $data['slug'])->whereKeyNot($a->id)->exists()) {
            throw new NewsConflict('already_exists');
        }

        $a->fill($data);
        if ($a->tampil && $a->published_at === null) {
            $a->published_at = CarbonImmutable::now('UTC');
        }
        $a->updated_by = $actor;
        $a->save();

        return $this->artikelRow($a->fresh());
    }

    public function artikelDestroy(string $id, ?string $base): void
    {
        $a = $this->findArtikel($id);
        $this->guardVersion($a, $base);
        $a->delete();
    }

    /**
     * Publish / unpublish. Fast on purpose: no If-Match (a boolean has nothing
     * to merge). Publishing an article without a date stamps `published_at`.
     */
    public function tampil(string $id, mixed $value, ?string $actor): array
    {
        $a = $this->findArtikel($id);
        $a->tampil = (bool) $value;
        if ($a->tampil && $a->published_at === null) {
            $a->published_at = CarbonImmutable::now('UTC');
        }
        $a->updated_by = $actor;
        $a->save();

        return $this->artikelRow($a->fresh());
    }

    /** Bulk category order: any subset; unknown ids are skipped. */
    public function urutan(array $body, ?string $actor): void
    {
        $rows = $body['kategori'] ?? null;
        if (! is_array($rows)) {
            return;
        }
        DB::connection('core')->transaction(function () use ($rows, $actor): void {
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }
                $k = NewsKategori::query()->find((string) $row['id']);
                if (! $k) {
                    continue;
                }
                $k->urutan = (int) ($row['urutan'] ?? 0);
                $k->updated_by = $actor;
                $k->save();
            }
        });
    }

    /** Every article (drafts included) and every category (inactive included). */
    public function state(): array
    {
        return [
            'kategori' => $this->kategoriList()->map(fn (NewsKategori $k) => $this->kategoriRow($k))->all(),
            'artikel' => $this->artikelList([]),
        ];
    }

    // ─────────────────────────── office shapes ──

    /** Public shape + `content`/`published_at` + the office-only fields. @return array<string,mixed> */
    public function artikelRow(NewsArtikel $a): array
    {
        $a->loadMissing('kategori');

        return $this->catalog->publicRow($a) + [
            'content' => $a->isi,
            'published_at' => NewsCatalog::iso($a->published_at),
            'kategori_id' => (string) $a->kategori_id,
            'cover_key' => $a->cover_key,
            'tampil' => (bool) $a->tampil,
            'version' => (int) $a->version,
            'created_at' => NewsCatalog::iso($a->created_at),
            'updated_at' => NewsCatalog::iso($a->updated_at),
        ];
    }

    /** @return array<string,mixed> */
    public function kategoriRow(NewsKategori $k): array
    {
        return [
            'id' => (string) $k->id,
            'nama' => $k->nama,
            'slug' => $k->slug,
            'urutan' => (int) $k->urutan,
            'aktif' => (bool) $k->aktif,
            'version' => (int) $k->version,
        ];
    }

    // ─────────────────────────── helpers ──

    /** Parse a client datetime; one without an offset is read as WIB. Stored in UTC. */
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException('validation: published_at harus tanggal-waktu yang sah.');
        }
        try {
            return CarbonImmutable::parse($value, NewsCatalog::TZ)->utc();
        } catch (Throwable) {
            throw new RuntimeException('validation: published_at harus tanggal-waktu yang sah.');
        }
    }

    private function findKategori(string $id): NewsKategori
    {
        return NewsKategori::query()->find($id) ?? throw new RuntimeException('not_found');
    }

    private function findArtikel(string $id): NewsArtikel
    {
        return NewsArtikel::query()->find($id) ?? throw new RuntimeException('not_found');
    }

    private function resolveKategori(array $body): NewsKategori
    {
        if (isset($body['kategori_id'])) {
            $k = NewsKategori::query()->find((string) $body['kategori_id']);
        } elseif (isset($body['kategori'])) {
            $k = NewsKategori::query()->where('slug', (string) $body['kategori'])->first();
        } else {
            throw new RuntimeException('validation: kategori_id wajib diisi.');
        }
        if (! $k) {
            throw new RuntimeException('validation: kategori tidak ditemukan.');
        }

        return $k;
    }

    /** The article columns this body sets; `isi` is sanitized here. */
    private function validateArtikel(array $body, ?NewsArtikel $existing): array
    {
        $data = [];
        if (array_key_exists('judul', $body)) {
            $data['judul'] = trim((string) $body['judul']);
            if ($data['judul'] === '') {
                throw new RuntimeException('validation: judul wajib diisi.');
            }
            if (mb_strlen($data['judul']) > 190) {
                throw new RuntimeException('validation: judul maksimal 190 karakter.');
            }
        } elseif ($existing === null) {
            throw new RuntimeException('validation: judul wajib diisi.');
        }
        foreach (['ringkasan', 'cover_key', 'penulis'] as $f) {
            if (array_key_exists($f, $body)) {
                $v = $body[$f] === null ? null : trim((string) $body[$f]);
                $data[$f] = $v === '' ? null : $v;
            }
        }
        if (isset($data['penulis']) && mb_strlen($data['penulis']) > 120) {
            throw new RuntimeException('validation: penulis maksimal 120 karakter.');
        }
        if (isset($data['cover_key']) && ! preg_match('/^[A-Za-z0-9._-]{1,190}$/', $data['cover_key'])) {
            throw new RuntimeException('validation: cover_key tidak sah.');
        }
        if (array_key_exists('isi', $body)) {
            $data['isi'] = $this->sanitize->clean($body['isi'] === null ? null : (string) $body['isi']);
        }
        if (array_key_exists('tampil', $body)) {
            $data['tampil'] = (bool) $body['tampil'];
        }
        if (array_key_exists('published_at', $body)) {
            $data['published_at'] = self::parseDate($body['published_at']);
        }
        if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== '') {
            $data['slug'] = Str::slug((string) $body['slug']);
        } elseif ($existing === null) {
            $data['slug'] = Str::slug($data['judul']);
        }
        if (isset($data['slug'])) {
            if ($data['slug'] === '') {
                throw new RuntimeException('validation: slug tidak bisa dibuat dari judul.');
            }
            $data['slug'] = Str::limit($data['slug'], 190, '');
            if (in_array($data['slug'], self::RESERVED_SLUGS, true)) {
                throw new RuntimeException('validation: slug ini dipakai sistem, pilih slug lain.');
            }
        }

        return $data;
    }

    /** If-Match required on PATCH/DELETE: missing → version_required, stale → version_conflict. */
    private function guardVersion(NewsKategori|NewsArtikel $model, mixed $base): void
    {
        if ($base === null || $base === '' || ! ctype_digit((string) $base)) {
            throw new NewsConflict('version_required');
        }
        if ((int) $base !== (int) $model->version) {
            throw new NewsConflict('version_conflict', $model->version);
        }
    }
}
