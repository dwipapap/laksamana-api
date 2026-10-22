<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisPengumuman;
use App\Modules\Hris\Models\HrisPengumumanDivisi;
use App\Modules\Hris\Models\HrisPengumumanGrant;
use Illuminate\Support\Facades\DB;

final class HrisPengumumanService
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly HrisLingkup $lingkup,
        private readonly HrisDivisiService $divisi,
        private readonly HrisAuditService $audit,
    ) {}

    /** Published announcements visible to the current employee. */
    public function untuk(array $me): array
    {
        $ids = $this->divisiIds($me);
        $q = HrisPengumuman::query()->where('status', 'published')->whereNull('deleted_at')
            ->where(function ($q) use ($ids) {
                $q->where('audience_all', true);
                if ($ids !== []) {
                    $q->orWhereIn('id', HrisPengumumanDivisi::query()->select('pengumuman_id')->whereIn('divisi_id', $ids));
                }
            });

        return $q->orderByDesc('published_at')->limit(100)->get()->map(fn (HrisPengumuman $p) => $this->shape($p))->all();
    }

    public function bolehKelola(array $me): bool
    {
        return $this->isAdmin($me) || $this->lingkup->isHr($me)
            || ($this->divisi->petaKepala()[$this->uid($me)] ?? []) !== []
            || HrisPengumumanGrant::query()->where('user_id', $this->uid($me))->where('active', true)->exists();
    }

    /** Writer view contains own drafts and own still-permitted published items. */
    public function kelola(array $me): array
    {
        $this->assertWriter($me);
        if ($this->isAdmin($me) || $this->lingkup->isHr($me)) {
            return HrisPengumuman::query()->whereNull('deleted_at')->orderByDesc('updated_at')->limit(200)
                ->get()->map(fn (HrisPengumuman $p) => $this->shape($p))->all();
        }
        $scope = $this->scopeDivisions($me);
        $own = HrisPengumuman::query()->where('author_id', $this->uid($me))->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereIn('status', ['draft', 'archived']);
            });

        if ($scope === null) {
            $own->orWhere(function ($q) use ($me) {
                $q->where('author_id', $this->uid($me))->where('status', 'published')->whereNull('deleted_at');
            });
        } elseif ($scope !== []) {
            $own->orWhere(function ($q) use ($me, $scope) {
                $q->where('author_id', $this->uid($me))->where('status', 'published')->whereNull('deleted_at')
                    ->whereIn('id', HrisPengumumanDivisi::query()->select('pengumuman_id')->whereIn('divisi_id', $scope));
            });
        }

        return $own->orderByDesc('updated_at')->limit(200)->get()
            ->filter(fn (HrisPengumuman $p) => $this->audienceWithinWriterScope($me, $p))
            ->map(fn (HrisPengumuman $p) => $this->shape($p))->values()->all();
    }

    public function show(array $me, string $id, bool $management = false): array
    {
        $p = HrisPengumuman::query()->whereNull('deleted_at')->find($id);
        if ($management) {
            $this->assertWriter($me);
        }
        if (! $p || ! $this->canRead($me, $p, $management)) {
            throw new HrisError('not_found', 'Pengumuman tidak ada.', 404);
        }

        return $this->shape($p, true);
    }

    public function create(array $me, array $in): array
    {
        $this->assertWriter($me);
        $audience = $this->audience($me, $in);
        $p = DB::connection('core')->transaction(function () use ($me, $in, $audience) {
            $p = HrisPengumuman::query()->create([
                'author_id' => $this->uid($me),
                'author_name' => mb_substr(trim((string) ($me['name'] ?? $me['nama'] ?? '')), 0, 120),
                'judul' => trim((string) $in['judul']),
                'isi' => trim((string) $in['isi']),
                'audience_all' => $audience['all'],
                'status' => 'draft',
                'created_by' => $this->ulid($me),
                'updated_by' => $this->ulid($me),
            ]);
            $this->replaceAudience($p, $audience['ids'], $me);
            $this->audit->record($this->uid($me), 'pengumuman_dibuat', 'hris_pengumuman', $p->id, ['status' => 'draft']);

            return $p;
        });

        return $this->shape($p, true);
    }

    public function update(array $me, string $id, array $in): array
    {
        $audience = $this->audience($me, $in);
        $p = DB::connection('core')->transaction(function () use ($me, $id, $in, $audience) {
            $p = $this->forManage($me, $id, lock: true);
            $this->assertVersion($p, $in);
            if ($p->status === 'archived') {
                throw new HrisError('conflict', 'Pengumuman yang sudah diarsipkan tidak bisa diubah.', 409);
            }
            $p->judul = trim((string) $in['judul']);
            $p->isi = trim((string) $in['isi']);
            $p->audience_all = $audience['all'];
            $p->version = (int) $p->version + 1;
            $p->updated_by = $this->ulid($me);
            $p->save();
            $this->replaceAudience($p, $audience['ids'], $me);
            $this->audit->record($this->uid($me), 'pengumuman_diubah', 'hris_pengumuman', $p->id, ['version' => $p->version]);

            return $p->fresh();
        });

        return $this->shape($p, true);
    }

    public function publish(array $me, string $id, array $in): array
    {
        $p = DB::connection('core')->transaction(function () use ($me, $id, $in) {
            $p = $this->forManage($me, $id, lock: true);
            $this->assertVersion($p, $in);
            if ($p->status !== 'draft') {
                throw new HrisError('conflict', 'Hanya draf yang bisa diterbitkan.', 409);
            }
            $this->assertAudienceStillAllowed($me, $p);
            $p->status = 'published';
            $p->published_at = now();
            $p->updated_by = $this->ulid($me);
            $p->save();
            $this->audit->record($this->uid($me), 'pengumuman_diterbitkan', 'hris_pengumuman', $p->id, ['audience_all' => (bool) $p->audience_all]);

            return $p->fresh();
        });

        return $this->shape($p, true);
    }

    public function archive(array $me, string $id, array $in): array
    {
        $p = DB::connection('core')->transaction(function () use ($me, $id, $in) {
            $p = $this->forManage($me, $id, lock: true);
            $this->assertVersion($p, $in);
            $p->status = 'archived';
            $p->updated_by = $this->ulid($me);
            $p->save();
            $this->audit->record($this->uid($me), 'pengumuman_diarsipkan', 'hris_pengumuman', $p->id, []);

            return $p->fresh();
        });

        return $this->shape($p, true);
    }

    /** Admin-only grant options, with names for accessible pickers. */
    public function options(array $me): array
    {
        if ($this->isAdmin($me)) {
            $people = HrisKaryawan::query()->whereNull('tanggal_keluar')->orderBy('user_id')->get()->map(function ($k) {
                $u = $this->access->userById((string) $k->user_id);

                return ['id' => (string) $k->user_id, 'nama' => (string) ($u['name'] ?? $u['nama'] ?? $k->user_id)];
            })->all();
            $divisions = HrisDivisi::query()->where('aktif', true)->orderBy('urutan')->orderBy('nama')
                ->get(['id', 'nama'])->map(fn ($d) => ['id' => (string) $d->id, 'nama' => (string) $d->nama])->all();

            return ['bolehAturHak' => true, 'pengguna' => $people, 'global' => true, 'divisi' => $divisions,
                'grant' => HrisPengumumanGrant::query()->where('active', true)->get()->map(fn ($g) => [
                    'user_id' => (string) $g->user_id, 'nama' => $this->name((string) $g->user_id),
                    'semua_divisi' => (bool) $g->all_divisions, 'divisi_ids' => $g->division_ids ?? [],
                ])->all()];
        }

        $this->assertWriter($me);
        $scope = $this->scopeDivisions($me);

        return ['bolehAturHak' => false, 'bisaKelola' => true, 'global' => $scope === null,
            'divisi' => HrisDivisi::query()->where('aktif', true)->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
                ->orderBy('urutan')->orderBy('nama')->get(['id', 'nama'])->map(fn ($d) => ['id' => (string) $d->id, 'nama' => (string) $d->nama])->all()];
    }

    public function setGrant(array $me, string $userId, array $in): array
    {
        $this->assertAdmin($me);
        $all = (bool) ($in['semua_divisi'] ?? false);
        $ids = array_values(array_unique(array_map('strval', $in['divisi_ids'] ?? [])));
        if ($all && $ids !== []) {
            throw new HrisError('validation_failed', 'Pilih semua divisi atau divisi tertentu.', 422);
        }
        if (! $all && $ids === []) {
            throw new HrisError('validation_failed', 'Pilih sedikitnya satu divisi.', 422);
        }
        if (! HrisKaryawan::query()->where('user_id', $userId)->whereNull('tanggal_keluar')->exists()) {
            throw new HrisError('validation_failed', 'Penerima hak harus karyawan aktif.', 422);
        }
        $valid = HrisDivisi::query()->where('aktif', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($valid) !== count($ids)) {
            throw new HrisError('validation_failed', 'Satu atau beberapa divisi tidak tersedia.', 422);
        }
        $g = HrisPengumumanGrant::query()->firstOrNew(['user_id' => $userId]);
        $g->fill(['all_divisions' => $all, 'division_ids' => $ids, 'active' => true, 'granted_by' => $this->uid($me),
            'updated_by' => $this->ulid($me)]);
        if (! $g->exists) {
            $g->created_by = $this->ulid($me);
        }
        $g->save();
        $this->audit->record($this->uid($me), 'hak_pengumuman_diberikan', 'hris_pengumuman_grant', $g->id, ['user_id' => $userId]);

        return ['user_id' => $userId, 'nama' => $this->name($userId), 'semua_divisi' => $all, 'divisi_ids' => $ids];
    }

    public function revokeGrant(array $me, string $userId): void
    {
        $this->assertAdmin($me);
        $g = HrisPengumumanGrant::query()->where('user_id', $userId)->where('active', true)->first();
        if (! $g) {
            throw new HrisError('not_found', 'Hak pengumuman tidak ada.', 404);
        }
        $g->active = false;
        $g->updated_by = $this->ulid($me);
        $g->save();
        $this->audit->record($this->uid($me), 'hak_pengumuman_dicabut', 'hris_pengumuman_grant', $g->id, ['user_id' => $userId]);
    }

    private function forManage(array $me, string $id, bool $lock = false): HrisPengumuman
    {
        $this->assertWriter($me);
        $query = HrisPengumuman::query()->whereNull('deleted_at')->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $p = $query->first();
        if (! $p || ! $this->canRead($me, $p, true)) {
            throw new HrisError('not_found', 'Pengumuman tidak ada.', 404);
        }

        return $p;
    }

    private function canRead(array $me, HrisPengumuman $p, bool $management): bool
    {
        if (! $management) {
            return $p->status === 'published' && $this->audienceMatches($me, $p);
        }
        if ($this->isAdmin($me) || $this->lingkup->isHr($me)) {
            return true;
        }
        if ($management) {
            if ($p->author_id !== $this->uid($me)) {
                return false;
            }

            return $this->audienceWithinWriterScope($me, $p);
        }

        return false;
    }

    private function audienceMatches(array $me, HrisPengumuman $p): bool
    {
        if ($p->audience_all) {
            return true;
        }
        $own = $this->divisiIds($me);
        $targets = HrisPengumumanDivisi::query()->where('pengumuman_id', $p->id)->pluck('divisi_id')->map(fn ($id) => (string) $id)->all();

        return array_intersect($own, $targets) !== [];
    }

    private function audienceWithinWriterScope(array $me, HrisPengumuman $p): bool
    {
        $scope = $this->scopeDivisions($me);
        if ($p->audience_all) {
            return $scope === null;
        }
        if ($scope === null) {
            return true;
        }
        $targets = HrisPengumumanDivisi::query()->where('pengumuman_id', $p->id)->pluck('divisi_id')->map(fn ($id) => (string) $id)->all();

        return $targets !== [] && array_diff($targets, $scope) === [];
    }

    private function audience(array $me, array $in): array
    {
        $all = (bool) ($in['semua'] ?? false);
        $ids = array_values(array_unique(array_map('strval', $in['divisi_ids'] ?? [])));
        if ($all === ($ids !== [])) {
            throw new HrisError('validation_failed', 'Pilih semua kru atau sedikitnya satu divisi.', 422);
        }
        $allowed = $this->scopeDivisions($me);
        if ($all && $allowed !== null) {
            throw new HrisError('forbidden', 'Anda hanya dapat memilih divisi yang menjadi lingkup hak.', 403);
        }
        $valid = HrisDivisi::query()->where('aktif', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($valid) !== count($ids)) {
            throw new HrisError('validation_failed', 'Satu atau beberapa divisi tidak tersedia.', 422);
        }
        if ($allowed !== null && array_diff($ids, $allowed) !== []) {
            throw new HrisError('forbidden', 'Anda hanya dapat mengumumkan ke divisi dalam lingkup hak.', 403);
        }

        return ['all' => $all, 'ids' => $ids];
    }

    private function assertAudienceStillAllowed(array $me, HrisPengumuman $p): void
    {
        $ids = HrisPengumumanDivisi::query()->where('pengumuman_id', $p->id)->pluck('divisi_id')->map(fn ($id) => (string) $id)->all();
        $allowed = $this->scopeDivisions($me);
        if (! $p->audience_all && count(HrisDivisi::query()->where('aktif', true)->whereIn('id', $ids)->pluck('id')) !== count($ids)) {
            throw new HrisError('conflict', 'Divisi penerima sudah tidak aktif. Ubah draf sebelum diterbitkan.', 409);
        }
        if (($p->audience_all && $allowed !== null) || ($allowed !== null && array_diff($ids, $allowed) !== [])) {
            throw new HrisError('forbidden', 'Lingkup pengumuman sudah tidak sesuai hak Anda.', 403);
        }
    }

    private function replaceAudience(HrisPengumuman $p, array $ids, array $me): void
    {
        HrisPengumumanDivisi::query()->where('pengumuman_id', $p->id)->delete();
        foreach ($ids as $id) {
            HrisPengumumanDivisi::query()->create([
                'pengumuman_id' => $p->id, 'divisi_id' => $id, 'created_by' => $this->ulid($me), 'updated_by' => $this->ulid($me),
            ]);
        }
    }

    private function scopeDivisions(array $me): ?array
    {
        if ($this->isAdmin($me) || $this->lingkup->isHr($me)) {
            return null;
        }
        $heads = $this->divisi->petaKepala()[$this->uid($me)] ?? [];
        if ($heads !== []) {
            return array_values(array_unique(array_map('strval', $heads)));
        }
        $grant = HrisPengumumanGrant::query()->where('user_id', $this->uid($me))->where('active', true)->first();
        if (! $grant) {
            return [];
        }

        return $grant->all_divisions ? null : array_values(array_unique(array_map('strval', $grant->division_ids ?? [])));
    }

    private function divisiIds(array $me): array
    {
        $id = $this->uid($me);
        $divisi = HrisKaryawan::query()->where('user_id', $id)->whereNull('tanggal_keluar')->value('divisi_id');

        return $divisi ? [(string) $divisi] : [];
    }

    private function assertWriter(array $me): void
    {
        if ($this->isAdmin($me) || $this->lingkup->isHr($me) || ($this->divisi->petaKepala()[$this->uid($me)] ?? []) !== []
            || HrisPengumumanGrant::query()->where('user_id', $this->uid($me))->where('active', true)->exists()) {
            return;
        }
        throw new HrisError('forbidden', 'Anda tidak memiliki hak mengelola pengumuman.', 403);
    }

    private function assertAdmin(array $me): void
    {
        if (! $this->isAdmin($me)) {
            throw new HrisError('forbidden', 'Hak pengumuman hanya bisa diatur admin HRIS.', 403);
        }
    }

    private function isAdmin(array $me): bool
    {
        $id = $this->uid($me);

        return $id !== '' && $this->access->isModuleAdmin($id, 'hris');
    }

    private function uid(array $me): string
    {
        return trim((string) ($me['id'] ?? ''));
    }

    private function ulid(array $me): ?string
    {
        return isset($me['ulid']) ? (string) $me['ulid'] : null;
    }

    private function name(string $id): string
    {
        $u = $this->access->userById($id);

        return (string) ($u['name'] ?? $u['nama'] ?? $id);
    }

    private function shape(HrisPengumuman $p, bool $detail = false): array
    {
        $ids = HrisPengumumanDivisi::query()->where('pengumuman_id', $p->id)->pluck('divisi_id')->map(fn ($id) => (string) $id)->all();
        $names = HrisDivisi::query()->whereIn('id', $ids)->pluck('nama', 'id')->all();
        $out = ['id' => (string) $p->id, 'judul' => (string) $p->judul, 'cuplikan' => mb_substr((string) $p->isi, 0, 180),
            'status' => (string) $p->status, 'semua' => (bool) $p->audience_all,
            'divisi_ids' => $ids, 'divisi_nama' => array_values(array_map(fn ($id) => (string) ($names[$id] ?? ''), $ids)),
            'author_id' => (string) $p->author_id, 'author_name' => (string) $p->author_name,
            'created_at' => $p->created_at?->toIso8601String(), 'published_at' => $p->published_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
            'berubah' => (int) $p->version];
        if ($detail) {
            $out['isi'] = (string) $p->isi;
        }

        return $out;
    }

    private function assertVersion(HrisPengumuman $p, array $in): void
    {
        if ((int) ($in['berubah'] ?? -1) !== (int) $p->version) {
            throw new HrisError('conflict', 'Pengumuman sudah berubah. Muat ulang dulu.', 409);
        }
    }
}
