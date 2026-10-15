<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPenilaianAtasan;
use App\Modules\Hris\Models\HrisSkor;
use App\Modules\Hris\Models\HrisSkorFormula;
use App\Modules\Jadwal\Services\JadwalService;

/**
 * People Score (S1–S3, S5): monthly feedback and a promotion basis — never a
 * bonus basis. The formula (components + weights) is versioned
 * effective-from; a locked monthly snapshot keeps its formula version, so
 * later weight edits never rewrite history. kelengkapan < 60 means the
 * person is not ranked ("data kurang").
 */
final class HrisScoreService
{
    public const DEFAULT = [
        'kehadiran' => 25, 'ketepatan' => 15, 'kpi_tim' => 20,
        'pelatihan' => 10, 'disiplin' => 15, 'penilaian' => 15,
    ];

    /** @var array<string,SkorKomponen> */
    private array $komponen;

    public function __construct(
        KomponenKehadiran $kehadiran,
        KomponenKetepatan $ketepatan,
        KomponenKpiTim $kpiTim,
        KomponenPelatihan $pelatihan,
        KomponenDisiplin $disiplin,
        KomponenPenilaian $penilaian,
        private readonly HrisAuditService $audit,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
    ) {
        $this->komponen = [
            'kehadiran' => $kehadiran, 'ketepatan' => $ketepatan, 'kpi_tim' => $kpiTim,
            'pelatihan' => $pelatihan, 'disiplin' => $disiplin, 'penilaian' => $penilaian,
        ];
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** Active formula for the month, or the starting weights (versi 0). */
    public function formulaUntuk(string $bulan): array
    {
        $f = HrisSkorFormula::query()->where('berlaku_dari', '<=', $bulan.'-28')
            ->orderByDesc('berlaku_dari')->orderByDesc('versi')->first();
        if (! $f) {
            return ['versi' => 0, 'berlaku_dari' => null, 'komponen' => self::DEFAULT];
        }

        return ['versi' => (int) $f->versi, 'berlaku_dari' => $f->berlaku_dari->format('Y-m-d'),
            'komponen' => $f->komponen, 'berubah' => (int) $f->version];
    }

    /** @return array<int,array{versi:int,berlaku_dari:string,komponen:array}> */
    public function riwayatFormula(): array
    {
        $out = [];
        foreach (HrisSkorFormula::query()->orderByDesc('versi')->get() as $f) {
            $out[] = ['versi' => (int) $f->versi, 'berlaku_dari' => $f->berlaku_dari->format('Y-m-d'),
                'komponen' => $f->komponen];
        }

        return $out;
    }

    /**
     * Save a new formula version. Keys must be known components, weights ≥ 0
     * with a positive total. Takes effect from berlaku_dari; locked snapshots
     * keep their old version.
     */
    public function saveFormula(array $komponen, string $berlakuDari, string $by): array
    {
        if (! is_array($komponen) || $komponen === []) {
            throw new HrisError('validation_failed', 'Formula butuh komponen dan bobot.', 422);
        }
        $bersih = [];
        $total = 0.0;
        foreach ($komponen as $k => $bobot) {
            $k = self::s($k);
            if (! isset($this->komponen[$k])) {
                throw new HrisError('validation_failed', "Komponen tidak dikenal: {$k}.", 422);
            }
            if (! is_numeric($bobot) || (float) $bobot < 0) {
                throw new HrisError('validation_failed', "Bobot {$k} harus angka ≥ 0.", 422);
            }
            $bersih[$k] = (float) $bobot;
            $total += (float) $bobot;
        }
        if ($total <= 0) {
            throw new HrisError('validation_failed', 'Total bobot harus > 0.', 422);
        }
        $dari = PunchRules::tglValid($berlakuDari);
        if ($dari === '') {
            throw new HrisError('validation_failed', 'Berlaku-dari harus YYYY-MM-DD.', 422);
        }
        $versi = (int) (HrisSkorFormula::query()->max('versi') ?? 0) + 1;
        $f = HrisSkorFormula::query()->create(['versi' => $versi, 'berlaku_dari' => $dari, 'komponen' => $bersih]);
        $this->audit->record($by, 'formula_disimpan', 'hris_skor_formula', (string) $versi, $bersih);

        return ['versi' => $versi, 'berlaku_dari' => $dari, 'komponen' => $bersih, 'id' => $f->id];
    }

    /**
     * Compute (or recompute while unlocked) one person's month.
     *
     * @return array{skor:float,kelengkapan:float,diranking:bool,komponen:array,formula_versi:int}
     */
    public function hitung(string $userId, string $bulan): array
    {
        $bulan = HrisMealService::bulanValid($bulan);
        if ($bulan === '' || self::s($userId) === '') {
            throw new HrisError('validation_failed', 'Hitung butuh user dan bulan YYYY-MM.', 422);
        }
        $lama = HrisSkor::query()->where('user_id', $userId)->where('bulan', $bulan)->first();
        if ($lama && $lama->terkunci) {
            throw new HrisError('terkunci', 'Skor bulan ini sudah dikunci.', 409);
        }
        $formula = $this->formulaUntuk($bulan);
        $bobot = $formula['komponen'];
        $totalBobot = array_sum($bobot);
        $komponen = [];
        $skorSum = 0.0;
        $kelSum = 0.0;
        foreach ($bobot as $k => $b) {
            $h = isset($this->komponen[$k]) ? $this->komponen[$k]->hitung($userId, $bulan)
                : ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['tidak_dikenal' => true]];
            $komponen[$k] = ['bobot' => $b] + $h;
            $skorSum += $b * (float) $h['skor'];
            $kelSum += $b * (float) $h['kelengkapan'];
        }
        $skor = $totalBobot > 0 ? round($skorSum / $totalBobot, 2) : 0.0;
        $kel = $totalBobot > 0 ? round($kelSum / $totalBobot, 2) : 0.0;
        $data = ['bulan' => $bulan, 'user_id' => $userId, 'formula_versi' => $formula['versi'],
            'komponen' => $komponen, 'skor' => $skor, 'kelengkapan' => $kel, 'terkunci' => false];
        if ($lama) {
            $lama->fill($data)->save();
            $row = $lama->fresh();
        } else {
            $row = HrisSkor::query()->create($data);
        }

        return $this->shape($row) + ['diranking' => $kel >= 60];
    }

    /** Lock a month: every computed row becomes immutable (S3). */
    public function kunci(string $bulan, string $by): array
    {
        $bulan = HrisMealService::bulanValid($bulan);
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        $n = HrisSkor::query()->where('bulan', $bulan)->where('terkunci', false)->update(['terkunci' => true]);
        $this->audit->record($by, 'skor_dikunci', 'hris_skor', $bulan, ['baris' => $n]);

        return ['bulan' => $bulan, 'terkunci' => $n];
    }

    /** @param  array<int,string>|null  $userIds */
    public function list(?array $userIds, string $bulan): array
    {
        $bulan = HrisMealService::bulanValid($bulan);
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        $q = HrisSkor::query()->where('bulan', $bulan)->orderByDesc('skor');
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $out = [];
        foreach ($q->limit(500)->get() as $r) {
            $out[] = $this->shape($r) + ['diranking' => (float) $r->kelengkapan >= 60];
        }

        return ['bulan' => $bulan, 'formula' => $this->formulaUntuk($bulan), 'skor' => $out];
    }

    public function shape(HrisSkor $r): array
    {
        return [
            'user_id' => $r->user_id, 'bulan' => $r->bulan,
            'formula_versi' => (int) $r->formula_versi, 'komponen' => $r->komponen ?? [],
            'skor' => (float) $r->skor, 'kelengkapan' => (float) $r->kelengkapan,
            'terkunci' => (bool) $r->terkunci, 'berubah' => (int) $r->version,
        ];
    }

    // ---------------------------------------------------------------- ratings

    /**
     * The head rates one member (1–5 per aspect); HR may rate anyone.
     *
     * @param  array{id:string,name:string}  $me
     */
    public function nilaiBawahan(array $me, string $userId, string $bulan, array $aspek): HrisPenilaianAtasan
    {
        $userId = self::s($userId);
        $bulan = HrisMealService::bulanValid($bulan);
        if ($userId === '' || $bulan === '') {
            throw new HrisError('validation_failed', 'Penilaian butuh user dan bulan YYYY-MM.', 422);
        }
        if (self::s($me['id'] ?? '') === $userId) {
            throw new HrisError('forbidden', 'Tidak bisa menilai diri sendiri.', 403);
        }
        if (! $this->lingkup->isHr($me)) {
            try {
                $div = $this->jadwal->divisiUser($userId);
            } catch (\Throwable) {
                throw new HrisError('forbidden', 'Hanya Kepala Divisi atau HR yang bisa menilai.', 403);
            }
            if (! $this->lingkup->isHead($me) || ! in_array($div, $this->lingkup->headDivisi($me), true)) {
                throw new HrisError('forbidden', 'Hanya Kepala Divisi yang bersangkutan atau HR yang bisa menilai.', 403);
            }
        }
        if (! is_array($aspek) || count($aspek) < 1 || count($aspek) > 6) {
            throw new HrisError('validation_failed', 'Penilaian butuh 1–6 aspek skala 1–5.', 422);
        }
        $bersih = [];
        foreach ($aspek as $k => $v) {
            $k = mb_substr(self::s($k), 0, 64);
            if ($k === '' || ! is_numeric($v) || (int) $v < 1 || (int) $v > 5) {
                throw new HrisError('validation_failed', 'Setiap aspek wajib skala 1–5.', 422);
            }
            $bersih[$k] = (int) $v;
        }
        $row = HrisPenilaianAtasan::query()->updateOrCreate(
            ['bulan' => $bulan, 'user_id' => $userId],
            ['penilai_id' => self::s($me['id'] ?? ''), 'aspek' => $bersih]);
        $this->audit->record(self::s($me['name'] ?? ''), 'bawahan_dinilai', 'hris_penilaian_atasan', $userId,
            ['bulan' => $bulan]);

        return $row;
    }
}
