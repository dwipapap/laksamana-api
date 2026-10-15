<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPelanggaran;

/**
 * Discipline record (S4), rebuilt in HRIS: pelanggaran/SP per person.
 * Status-driven (AKTIF/DIBATALKAN) — rows are never hard-deleted, so past
 * scores stay explainable. Reads are scoped by Lingkup.
 */
final class HrisDisiplinService
{
    public const TINGKAT = ['RINGAN' => 10, 'SEDANG' => 25, 'BERAT' => 50];

    public function __construct(private readonly HrisAuditService $audit) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public static function assertTingkat(string $t): string
    {
        $t = strtoupper(trim($t));
        if (! isset(self::TINGKAT[$t])) {
            throw new HrisError('validation_failed', 'Tingkat harus RINGAN, SEDANG, atau BERAT.', 422);
        }

        return $t;
    }

    /** @param  array<int,string>|null  $userIds */
    public function list(?array $userIds, string $dari, string $sampai): array
    {
        $dari = PunchRules::tglValid($dari);
        $sampai = PunchRules::tglValid($sampai);
        $q = HrisPelanggaran::query()->orderBy('tanggal')->orderBy('created_at');
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        if ($dari !== '' && $sampai !== '') {
            if ($sampai < $dari) {
                [$dari, $sampai] = [$sampai, $dari];
            }
            $q->whereBetween('tanggal', [$dari, $sampai]);
        }
        $out = [];
        foreach ($q->limit(500)->get() as $r) {
            $out[] = $this->shape($r);
        }

        return $out;
    }

    public function create(array $in, string $by): HrisPelanggaran
    {
        $uid = self::s($in['user_id'] ?? $in['user'] ?? '');
        if ($uid === '') {
            throw new HrisError('validation_failed', 'Pelanggaran butuh user.', 422);
        }
        $tgl = PunchRules::tglValid($in['tanggal'] ?? '');
        if ($tgl === '') {
            throw new HrisError('validation_failed', 'Tanggal harus YYYY-MM-DD.', 422);
        }
        $row = HrisPelanggaran::query()->create([
            'user_id' => $uid, 'tanggal' => $tgl,
            'jenis' => mb_substr(self::s($in['jenis'] ?? ''), 0, 64),
            'tingkat' => self::assertTingkat(self::s($in['tingkat'] ?? 'RINGAN')),
            'sp_ke' => max(0, (int) ($in['sp_ke'] ?? 0)),
            'keterangan' => mb_substr(self::s($in['keterangan'] ?? ''), 0, 255),
            'status' => 'AKTIF', 'dibuat_oleh' => mb_substr($by, 0, 120),
        ]);
        $this->audit->record($by, 'pelanggaran_dibuat', 'hris_pelanggaran', $row->id, ['user_id' => $uid]);

        return $row;
    }

    public function batal(string $id, string $by): HrisPelanggaran
    {
        $row = HrisPelanggaran::query()->find(self::s($id));
        if (! $row) {
            throw new HrisError('not_found', 'Pelanggaran tidak ada.', 404);
        }
        $row->fill(['status' => 'DIBATALKAN'])->save();
        $this->audit->record($by, 'pelanggaran_dibatalkan', 'hris_pelanggaran', $row->id, []);

        return $row->fresh();
    }

    public function shape(HrisPelanggaran $r): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'tanggal' => $r->tanggal->format('Y-m-d'),
            'jenis' => $r->jenis, 'tingkat' => $r->tingkat, 'sp_ke' => (int) $r->sp_ke,
            'keterangan' => $r->keterangan, 'status' => $r->status,
            'dibuat_oleh' => $r->dibuat_oleh, 'berubah' => (int) $r->version,
        ];
    }
}
