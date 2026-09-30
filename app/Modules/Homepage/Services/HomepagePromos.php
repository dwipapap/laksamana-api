<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Services;

use App\Modules\Bd\Services\BdState;
use App\Modules\Homepage\Models\HomepageBanner;
use App\Modules\Radar\Services\RadarRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The homepage promo slider: which BD OS promos and uploaded banners appear on
 * the public website.
 *
 * BD owns its promo document (`BdState::setting('promos')`, also read by Radar
 * and Kompas) — this class only reads it through `BdState`, on the legacy
 * connection or on core, and Homepage never writes a single BD row. The switch,
 * the manual order and the uploaded banners live in the greenfield
 * `homepage_banner` table in `core`.
 *
 * One eligibility rule, shared by the public feed, the office list and the
 * toggles (`eligibility()`), from the promo spec decisions 4 & 5: a BD promo is
 * eligible only while it is `running` (RadarRules::promoStatus) AND has a valid
 * poster, an upload only while today (WIB) is inside its optional window and its
 * file exists. A row that stops being eligible simply falls out of the website;
 * it is never deleted.
 */
class HomepagePromos
{
    /** The public clock: periods are WIB dates. */
    public const TZ = 'Asia/Jakarta';

    public const SOURCE_BD = 'bd';

    public const SOURCE_UPLOAD = 'unggah';

    /** A BD poster data URL may decode to at most 1 MB, else it counts as no poster. */
    public const POSTER_MAX_BYTES = 1024 * 1024;

    /** Error reasons of one office row (`alasan`): why it may not appear. */
    public const REASONS = [
        'bd_belum_mulai', 'bd_berakhir', 'bd_dijeda', 'bd_tanpa_poster', 'bd_hilang', 'di_luar_periode',
    ];

    public function __construct(
        private readonly BdState $bd,
        private readonly HomepagePhotos $photos,
    ) {}

    /** Today in WIB — the public clock of the promo window. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::TZ)->toDateString();
    }

    // ─────────────────────────── BD reading ──

    /**
     * The BD `promos` document keyed by promo id, WITHOUT the poster bytes: each
     * promo carries `_poster_ok` (a valid poster decodes) and `_poster_v` (a short
     * hash of the poster, the image URL's `?v=`). A promo without an id is
     * skipped. Throws when BD cannot be read — callers decide what to do.
     *
     * The document holds every poster as a data URL (up to 400 KB each), so the
     * derived index is cached under the database's MD5 of the setting: one small
     * query per request, and any BD save changes the key (never stale).
     *
     * @return array<string,array<string,mixed>>
     */
    public function bdPromos(): array
    {
        $hash = $this->bd->settingHash('promos');
        if ($hash === null) {
            return [];
        }

        return Cache::remember('homepage:bd-promos:'.$hash, 3600, fn (): array => self::index($this->bdPromosFull()));
    }

    /**
     * The full BD `promos` document keyed by promo id, posters included. Only
     * the image routes need it. Throws when BD cannot be read.
     *
     * @return array<string,array<string,mixed>>
     */
    public function bdPromosFull(): array
    {
        $doc = $this->bd->setting('promos', []);
        $out = [];
        foreach (is_array($doc) ? $doc : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = trim((string) ($p['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = $p;
        }

        return $out;
    }

    /**
     * Drop the poster bytes, keep whether it is valid and its version hash.
     *
     * @param  array<string,array<string,mixed>>  $full
     * @return array<string,array<string,mixed>>
     */
    public static function index(array $full): array
    {
        $out = [];
        foreach ($full as $id => $p) {
            $poster = (string) ($p['poster'] ?? '');
            unset($p['poster']);
            $p['_poster_ok'] = self::posterBytes(['poster' => $poster]) !== null;
            $p['_poster_v'] = $poster === '' ? '' : substr(sha1($poster), 0, 8);
            $out[$id] = $p;
        }

        return $out;
    }

    /** Whether a promo (full or indexed) has a valid poster. */
    public static function hasPoster(array $p): bool
    {
        return array_key_exists('_poster_ok', $p) ? (bool) $p['_poster_ok'] : self::posterBytes($p) !== null;
    }

    /** The poster bytes of one BD promo, read from the full document; null on any failure. @return array{data:string,type:string}|null */
    private function bdPoster(string $promoId): ?array
    {
        try {
            $p = $this->bdPromosFull()[$promoId] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $p === null ? null : self::posterBytes($p);
    }

    /**
     * The `?v=` of a row's image URL: it changes whenever the image does, so the
     * day-long image cache never shows a replaced picture.
     */
    private function imageVersion(HomepageBanner $row, array $bdPromos): string
    {
        if ($row->sumber === self::SOURCE_UPLOAD) {
            return substr(sha1((string) ($row->gambar_key ?? '')), 0, 8);
        }

        return (string) ($bdPromos[(string) ($row->promo_id ?? '')]['_poster_v'] ?? '');
    }

    /**
     * BD read that never throws: `[promos, failed]`. The public feed keeps
     * serving uploaded banners when BD is down; the office list flags `bd_gagal`.
     *
     * @return array{0:array<string,array<string,mixed>>,1:bool}
     */
    public function bdPromosSafe(): array
    {
        try {
            return [$this->bdPromos(), false];
        } catch (Throwable $e) {
            report($e);

            return [[], true];
        }
    }

    /**
     * Decode a BD poster data URL. Only `data:image/(jpeg|png|webp);base64,`
     * with valid base64 and at most 1 MB decoded counts; anything else is
     * treated as "no poster" and the raw value is never rendered.
     *
     * @return array{data:string,type:string}|null
     */
    public static function posterBytes(array $promo): ?array
    {
        $poster = (string) ($promo['poster'] ?? '');
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $poster, $m)) {
            return null;
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bin === false || $bin === '' || strlen($bin) > self::POSTER_MAX_BYTES) {
            return null;
        }

        return ['data' => $bin, 'type' => 'image/'.$m[1]];
    }

    // ─────────────────────────── eligibility ──

    /**
     * Decisions 4 & 5: may this row appear at all today? Returns
     * `[eligible, reason]`; `reason` is null when eligible and one of
     * `self::REASONS` otherwise (null too for an upload whose file vanished —
     * there is nothing to show yet no period reason applies).
     *
     * @return array{0:bool,1:?string}
     */
    public function eligibility(HomepageBanner $row, array $bdPromos, ?string $today = null): array
    {
        $today ??= self::today();

        if ($row->sumber === self::SOURCE_UPLOAD) {
            $key = (string) ($row->gambar_key ?? '');
            if ($key === '' || ! $this->photos->exists($key)) {
                return [false, null];
            }
            $mulai = $row->mulai === null ? null : (string) $row->mulai;
            $selesai = $row->selesai === null ? null : (string) $row->selesai;
            $inside = ($mulai === null || $today >= $mulai) && ($selesai === null || $today <= $selesai);

            return $inside ? [true, null] : [false, 'di_luar_periode'];
        }

        $p = $bdPromos[(string) ($row->promo_id ?? '')] ?? null;
        if ($p === null) {
            return [false, 'bd_hilang'];
        }
        $reason = match (RadarRules::promoStatus($p, $today)) {
            'paused' => 'bd_dijeda',
            'upcoming' => 'bd_belum_mulai',
            'ended' => 'bd_berakhir',
            default => null,
        };
        if ($reason !== null) {
            return [false, $reason];
        }

        return self::hasPoster($p) ? [true, null] : [false, 'bd_tanpa_poster'];
    }

    // ─────────────────────────── public feed ──

    /**
     * Every row with `tampil = true` AND eligible, in manual order (`urutan`,
     * then the older row first). BD failure never blocks the uploads.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        $rows = HomepageBanner::query()
            ->where('tampil', true)
            ->orderBy('urutan')->orderBy('created_at')->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $today = self::today();
        [$bd] = $this->bdPromosSafe();

        $items = [];
        foreach ($rows as $row) {
            if (! $this->eligibility($row, $bd, $today)[0]) {
                continue;
            }
            $items[] = $this->publicRow($row, $bd);
        }

        return $items;
    }

    /** The allow-list projection of §Public Promo (never "everything minus secrets"). @return array<string,mixed> */
    public function publicRow(HomepageBanner $row, array $bdPromos): array
    {
        return [
            'id' => (string) $row->id,
            'sumber' => (string) $row->sumber,
            'image' => '/api/v1/homepage/promos/'.$row->id.'/gambar?v='.$this->imageVersion($row, $bdPromos),
            'alt' => $this->alt($row, $bdPromos),
            'href' => $row->href === null || $row->href === '' ? null : (string) $row->href,
        ];
    }

    /**
     * The bytes of one banner image for the PUBLIC route: only a switched-on,
     * eligible row, and only by row id — a client-supplied key or data URL is
     * never accepted.
     *
     * @return array{data:string,type:string}|null
     */
    public function publicImage(string $id): ?array
    {
        $row = HomepageBanner::query()->find($id);
        if ($row === null || ! $row->tampil) {
            return null;
        }
        [$bd] = $this->bdPromosSafe();
        if (! $this->eligibility($row, $bd)[0]) {
            return null;
        }

        return $this->imageOf($row);
    }

    // ─────────────────────────── office list ──

    /**
     * The office list: every `homepage_banner` row (any status) in manual
     * order, then BD promos with a poster that are running/upcoming and have no
     * row yet (candidates, `id: null`). Running candidates first, then the
     * nearest start.
     *
     * @return array{items:list<array<string,mixed>>,bd_gagal:bool}
     */
    public function officeList(): array
    {
        [$bd, $bdFailed] = $this->bdPromosSafe();
        $today = self::today();

        $rows = HomepageBanner::query()
            ->orderBy('urutan')->orderBy('created_at')->orderBy('id')
            ->get();

        $used = [];
        $items = [];
        foreach ($rows as $row) {
            if ($row->sumber === self::SOURCE_BD) {
                $used[(string) ($row->promo_id ?? '')] = true;
            }
            $items[] = $this->officeRow($row, $bd, $today);
        }

        $candidates = [];
        foreach ($bd as $promoId => $p) {
            if (isset($used[$promoId])) {
                continue;
            }
            $status = RadarRules::promoStatus($p, $today);
            if ($status !== 'running' && $status !== 'upcoming') {
                continue;
            }
            if (! self::hasPoster($p)) {
                continue;
            }
            $candidates[] = $this->candidateRow($promoId, $p, $status, $today);
        }
        usort($candidates, static function (array $a, array $b): int {
            $rank = static fn (array $r): int => $r['alasan'] === null ? 0 : 1;
            $mulai = static fn (array $r): string => (string) ($r['bd']['mulai'] ?? '9999-12-31');

            return [$rank($a), $mulai($a), (string) ($a['bd']['nama'] ?? '')]
                <=> [$rank($b), $mulai($b), (string) ($b['bd']['nama'] ?? '')];
        });

        return ['items' => array_merge($items, $candidates), 'bd_gagal' => $bdFailed];
    }

    /** One office row for a stored banner. @return array<string,mixed> */
    public function officeRow(HomepageBanner $row, array $bdPromos, ?string $today = null): array
    {
        $today ??= self::today();
        [$eligible, $alasan] = $this->eligibility($row, $bdPromos, $today);
        $promo = $row->sumber === self::SOURCE_BD
            ? ($bdPromos[(string) ($row->promo_id ?? '')] ?? null)
            : null;

        return [
            'id' => (string) $row->id,
            'sumber' => (string) $row->sumber,
            'promo_id' => $row->promo_id,
            'alt' => $row->alt,
            'href' => $row->href,
            'mulai' => $row->mulai,
            'selesai' => $row->selesai,
            'tampil' => (bool) $row->tampil,
            'urutan' => (int) $row->urutan,
            'version' => (int) $row->version,
            'eligible' => $eligible,
            'alasan' => $alasan,
            'gambar' => $this->hasImage($row, $bdPromos)
                ? '/api/v1/homepage/office/promos/gambar?banner='.$row->id.'&v='.$this->imageVersion($row, $bdPromos)
                : null,
            'bd' => $promo === null ? null : self::bdInfo($promo, $today),
        ];
    }

    /** A BD promo that has no row yet: a candidate to switch on. @return array<string,mixed> */
    private function candidateRow(string $promoId, array $p, string $status, string $today): array
    {
        return [
            'id' => null,
            'sumber' => self::SOURCE_BD,
            'promo_id' => $promoId,
            'alt' => null,
            'href' => null,
            'mulai' => null,
            'selesai' => null,
            'tampil' => false,
            'urutan' => 0,
            'version' => 0,
            'eligible' => $status === 'running',
            'alasan' => $status === 'running' ? null : 'bd_belum_mulai',
            'gambar' => '/api/v1/homepage/office/promos/gambar?promo='.$promoId.'&v='.($p['_poster_v'] ?? ''),
            'bd' => self::bdInfo($p, $today, $status),
        ];
    }

    /** The BD facts the Office screen may see — never kode/partner/kuota/lmPIC/ketentuan/outlet. */
    public static function bdInfo(array $p, string $today, ?string $status = null): array
    {
        return [
            'nama' => (string) ($p['nama'] ?? ''),
            'tipe' => (string) ($p['tipe'] ?? ''),
            'kategori' => (string) ($p['kategori'] ?? ''),
            'benefit' => (string) ($p['benefit'] ?? ''),
            'mulai' => (string) ($p['mulai'] ?? ''),
            'selesai' => (string) ($p['selesai'] ?? ''),
            'status' => $status ?? RadarRules::promoStatus($p, $today),
        ];
    }

    // ─────────────────────────── office images ──

    /** Office preview of any stored row, by row id only (even a switched-off row). */
    public function officeImageByBanner(string $id): ?array
    {
        $row = HomepageBanner::query()->find($id);
        if ($row === null) {
            return null;
        }

        return $this->imageOf($row);
    }

    /** Office preview of any BD promo with a poster, by promo id only. */
    public function officeImageByPromo(string $promoId): ?array
    {
        return $this->bdPoster($promoId);
    }

    /** @return array{data:string,type:string}|null */
    private function imageOf(HomepageBanner $row): ?array
    {
        if ($row->sumber === self::SOURCE_UPLOAD) {
            return $this->photos->read((string) ($row->gambar_key ?? ''));
        }

        return $this->bdPoster((string) ($row->promo_id ?? ''));
    }

    /**
     * Whether the row has an image, WITHOUT reading the file bytes — the office
     * list only needs to know if a preview URL makes sense.
     */
    private function hasImage(HomepageBanner $row, array $bdPromos): bool
    {
        if ($row->sumber === self::SOURCE_UPLOAD) {
            return $this->photos->exists((string) ($row->gambar_key ?? ''));
        }
        $p = $bdPromos[(string) ($row->promo_id ?? '')] ?? null;

        return $p !== null && self::hasPoster($p);
    }

    /** Remove a stored file unless another banner row still points at the same key. */
    private function unlinkIfUnused(string $key, string $exceptId): bool
    {
        $used = HomepageBanner::query()
            ->where('gambar_key', $key)->where('id', '!=', $exceptId)->exists();

        return $used ? false : $this->photos->delete($key);
    }

    // ─────────────────────────── office writes ──

    /**
     * Toggle one stored row. Switching OFF is always allowed; switching ON
     * requires the row to be eligible right now. Returns the office row.
     *
     * @return array<string,mixed>
     */
    public function setTampil(string $id, bool $tampil, ?string $actor): array
    {
        $row = HomepageBanner::query()->find($id);
        if ($row === null) {
            throw new RuntimeException('not_found');
        }
        [$bd] = $this->bdPromosSafe();

        if ($tampil && ! $this->eligibility($row, $bd)[0]) {
            throw new RuntimeException('tidak_eligible');
        }

        $row->tampil = $tampil;
        $row->updated_by = $actor;
        $row->save();

        return $this->officeRow($row, $bd);
    }

    /**
     * Upsert the switch of one BD promo (`PATCH …/bd/{promoId}/tampil`):
     * the row is created with the next `urutan` the first time it is touched.
     *
     * @return array<string,mixed>
     */
    public function setBdTampil(string $promoId, bool $tampil, ?string $actor): array
    {
        try {
            $bd = $this->bdPromos();
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('bd_tidak_terhubung');
        }

        $p = $bd[$promoId] ?? null;
        if ($p === null) {
            throw new RuntimeException('not_found');
        }
        if ($tampil) {
            $status = RadarRules::promoStatus($p, self::today());
            if ($status !== 'running' || ! self::hasPoster($p)) {
                throw new RuntimeException('tidak_eligible');
            }
        }

        $row = HomepageBanner::query()
            ->where('sumber', self::SOURCE_BD)->where('promo_id', $promoId)->first();
        if ($row === null) {
            $row = new HomepageBanner([
                'sumber' => self::SOURCE_BD,
                'promo_id' => $promoId,
                'tampil' => $tampil,
                'urutan' => $this->nextUrutan(),
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
        } else {
            $row->tampil = $tampil;
            $row->updated_by = $actor;
        }
        $row->save();

        return $this->officeRow($row, $bd);
    }

    /** Create an `unggah` banner (201). @return array<string,mixed> */
    public function createUpload(array $body, ?string $actor): array
    {
        $key = trim((string) ($body['gambar_key'] ?? ''));
        if (! $this->photos->exists($key)) {
            throw new RuntimeException('validation: gambar_key bukan berkas yang ada di folder homepage.');
        }
        $alt = $this->textOrNull($body['alt'] ?? null, 'alt', 190);
        $href = $this->normalizeHref($body['href'] ?? null);
        $mulai = $this->dateOrNull($body['mulai'] ?? null, 'mulai');
        $selesai = $this->dateOrNull($body['selesai'] ?? null, 'selesai');
        $tampil = $this->boolOr($body['tampil'] ?? false, 'tampil');
        if ($mulai !== null && $selesai !== null && $mulai > $selesai) {
            throw new RuntimeException('validation: mulai tidak boleh melewati selesai.');
        }

        $row = new HomepageBanner([
            'sumber' => self::SOURCE_UPLOAD,
            'gambar_key' => $key,
            'alt' => $alt,
            'href' => $href,
            'mulai' => $mulai,
            'selesai' => $selesai,
            'tampil' => $tampil,
            'urutan' => $this->nextUrutan(),
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        if ($tampil && ! $this->eligibility($row, [])[0]) {
            throw new RuntimeException('tidak_eligible');
        }
        $row->save();

        return $this->officeRow($row, []);
    }

    /**
     * PATCH one row (If-Match required): `alt, href, mulai, selesai, gambar_key`
     * for an upload; `alt, href` for a BD row. `tampil` is the toggle endpoint.
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $body, ?string $base, ?string $actor): array
    {
        $row = HomepageBanner::query()->find($id);
        if ($row === null) {
            throw new RuntimeException('not_found');
        }
        $this->guardVersion($row, $base);

        if ($row->sumber === self::SOURCE_UPLOAD) {
            if (array_key_exists('gambar_key', $body)) {
                $key = trim((string) $body['gambar_key']);
                if (! $this->photos->exists($key)) {
                    throw new RuntimeException('validation: gambar_key bukan berkas yang ada di folder homepage.');
                }
                $oldKey = (string) ($row->gambar_key ?? '');
                $row->gambar_key = $key;
            }
            if (array_key_exists('mulai', $body)) {
                $row->mulai = $this->dateOrNull($body['mulai'], 'mulai');
            }
            if (array_key_exists('selesai', $body)) {
                $row->selesai = $this->dateOrNull($body['selesai'], 'selesai');
            }
            if ($row->mulai !== null && $row->selesai !== null && (string) $row->mulai > (string) $row->selesai) {
                throw new RuntimeException('validation: mulai tidak boleh melewati selesai.');
            }
        }
        if (array_key_exists('alt', $body)) {
            $row->alt = $this->textOrNull($body['alt'], 'alt', 190);
        }
        if (array_key_exists('href', $body)) {
            $row->href = $this->normalizeHref($body['href']);
        }

        $row->updated_by = $actor;
        $row->save();

        if (isset($oldKey) && $oldKey !== '' && $oldKey !== (string) $row->gambar_key) {
            $this->unlinkIfUnused($oldKey, (string) $row->id);
        }

        [$bd] = $this->bdPromosSafe();

        return $this->officeRow($row, $bd);
    }

    /**
     * DELETE one upload banner (If-Match required) and its file. A BD row is
     * BD's data and is only switched off → 422 `pakai_saklar`.
     *
     * @return array{deleted:bool,id:string}
     */
    public function destroy(string $id, ?string $base): array
    {
        $row = HomepageBanner::query()->find($id);
        if ($row === null) {
            throw new RuntimeException('not_found');
        }
        $this->guardVersion($row, $base);
        if ($row->sumber !== self::SOURCE_UPLOAD) {
            throw new RuntimeException('pakai_saklar');
        }

        $key = (string) ($row->gambar_key ?? '');
        $row->delete();
        if ($key !== '') {
            $this->unlinkIfUnused($key, $id);
        }

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Bulk order (`PUT …/urutan`): a subset is fine, an unknown id is skipped,
     * no If-Match (the order is a preference, last writer wins).
     */
    public function urutan(array $body, ?string $actor): int
    {
        $items = $body['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('validation: items harus berupa daftar {id, urutan}.');
        }

        $saved = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $row = HomepageBanner::query()->find($id);
            if ($row === null) {
                continue;
            }
            $row->urutan = (int) ($item['urutan'] ?? 0);
            $row->updated_by = $actor;
            $row->save();
            $saved++;
        }

        return $saved;
    }

    // ─────────────────────────── helpers ──

    /** The next manual position: after every existing row, in tens. */
    private function nextUrutan(): int
    {
        return ((int) HomepageBanner::query()->max('urutan')) + 10;
    }

    private function alt(HomepageBanner $row, array $bdPromos): string
    {
        $alt = trim((string) ($row->alt ?? ''));
        if ($alt !== '') {
            return $alt;
        }
        if ($row->sumber === self::SOURCE_BD) {
            return trim((string) ($bdPromos[(string) ($row->promo_id ?? '')]['nama'] ?? ''));
        }

        return '';
    }

    /** If-Match required on PATCH/DELETE: missing → version_required, stale → version_conflict. */
    private function guardVersion(HomepageBanner $row, ?string $base): void
    {
        if ($base === null || $base === '' || ! ctype_digit($base)) {
            throw new HomepageConflict('version_required');
        }
        if ((int) $base !== (int) $row->version) {
            throw new HomepageConflict('version_conflict', (int) $row->version);
        }
    }

    private function textOrNull(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            throw new RuntimeException("validation: $field tidak valid.");
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            throw new RuntimeException("validation: $field maksimal $max karakter.");
        }

        return $s;
    }

    private function normalizeHref(mixed $value): ?string
    {
        $s = $this->textOrNull($value, 'href', 500);
        if ($s === null) {
            return null;
        }
        $ok = str_starts_with($s, 'https://')
            || (str_starts_with($s, '/') && ! str_starts_with($s, '//') && ! str_contains($s, '\\'));
        if (! $ok) {
            throw new RuntimeException('validation: href harus https:// atau path relatif yang diawali /.');
        }

        return $s;
    }

    private function dateOrNull(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException("validation: $field harus format YYYY-MM-DD.");
        }
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        $d = CarbonImmutable::createFromFormat('Y-m-d', $s);
        if ($d === false || $d->toDateString() !== $s) {
            throw new RuntimeException("validation: $field harus format YYYY-MM-DD.");
        }

        return $s;
    }

    private function boolOr(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException("validation: $field harus true atau false.");
        }

        return $value;
    }
}
