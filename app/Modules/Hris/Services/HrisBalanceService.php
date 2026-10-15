<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisSaldoMutasi;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The leave/PH balance ledger (L2): one append-only row per movement,
 * saldo = SUM(delta) per person per jenis.
 *
 *   earn (+)  a PH worked, a CUTI grant, a manual top-up, a refund.
 *             PH earns carry kedaluwarsa and are their own batch
 *             (batch_id = own id).
 *   use (−)   consuming balance splits into one row per batch chunk,
 *             oldest-expiry first (FIFO, L4).
 *   expire    the daily job zeroes leftover earn batches past kedaluwarsa.
 *
 * Idempotency is UNIQUE(sumber_tipe, sumber_id); usage chunks share the
 * source id with a #n suffix. PH is never paid out on resignation (L5):
 * the balance simply sits until it expires.
 *
 * Every multi-row write runs in one transaction, and consuming locks the
 * person's ledger first, so two approvals at once cannot both spend the
 * same day.
 */
final class HrisBalanceService
{
    public const JENIS = ['PH', 'CUTI'];

    public function __construct(private readonly HrisAuditService $audit) {}

    /** @template T @param callable():T $fn @return T */
    private static function atomic(callable $fn): mixed
    {
        return HrisSaldoMutasi::query()->getConnection()->transaction($fn);
    }

    /** Lock one person's ledger for one jenis until the transaction ends. */
    private static function lockLedger(string $userId, string $jenis): void
    {
        HrisSaldoMutasi::query()->where('user_id', $userId)->where('jenis', $jenis)
            ->lockForUpdate()->pluck('id');
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public static function assertJenis(string $jenis): string
    {
        $jenis = strtoupper(trim($jenis));
        if (! in_array($jenis, self::JENIS, true)) {
            throw new HrisError('validation_failed', 'Jenis saldo harus PH atau CUTI.', 422);
        }

        return $jenis;
    }

    /** Whole-day saldo (earned − used − expired, always exact). */
    public function saldo(string $userId, string $jenis): int
    {
        $jenis = self::assertJenis($jenis);

        return (int) HrisSaldoMutasi::query()->where('user_id', $userId)->where('jenis', $jenis)->sum('delta');
    }

    /** Remainder of one earn batch. */
    public function sisaBatch(string $batchId): int
    {
        return (int) HrisSaldoMutasi::query()->where('batch_id', $batchId)->sum('delta');
    }

    /**
     * Earn batches with remainder, oldest-expiry first (NULL expiry last).
     *
     * @return array<int,array{id:string,sisa:int,kedaluwarsa:string|null,tanggal:string}>
     */
    public function batchFIFO(string $userId, string $jenis): array
    {
        $jenis = self::assertJenis($jenis);
        $earns = HrisSaldoMutasi::query()->where('user_id', $userId)->where('jenis', $jenis)
            ->whereColumn('batch_id', 'id')
            ->orderByRaw('kedaluwarsa IS NULL, kedaluwarsa ASC')->orderBy('tanggal')->orderBy('id')
            ->get();
        $out = [];
        foreach ($earns as $e) {
            $sisa = $this->sisaBatch($e->id);
            if ($sisa > 0) {
                $out[] = ['id' => $e->id, 'sisa' => $sisa,
                    'kedaluwarsa' => $e->kedaluwarsa?->format('Y-m-d'),
                    'tanggal' => $e->tanggal->format('Y-m-d')];
            }
        }

        return $out;
    }

    /**
     * Add balance. Idempotent per (sumber_tipe, sumber_id): a repeated call
     * returns the existing rows instead of earning twice.
     */
    public function earn(string $userId, string $jenis, int $jumlah, string $sumberTipe,
        string $sumberId, string $tanggal, ?string $kedaluwarsa, string $catatan = ''): array
    {
        $jenis = self::assertJenis($jenis);
        $userId = self::s($userId);
        if ($userId === '' || $jumlah < 1) {
            throw new HrisError('validation_failed', 'Penambahan saldo butuh user dan jumlah ≥ 1.', 422);
        }
        $tanggal = PunchRules::tglValid($tanggal);
        if ($tanggal === '') {
            throw new HrisError('validation_failed', 'Tanggal mutasi harus YYYY-MM-DD.', 422);
        }
        if ($kedaluwarsa !== null && PunchRules::tglValid($kedaluwarsa) === '') {
            throw new HrisError('validation_failed', 'Tanggal hangus harus YYYY-MM-DD.', 422);
        }
        $ada = HrisSaldoMutasi::query()->where('sumber_tipe', $sumberTipe)->where('sumber_id', $sumberId)->get();
        if ($ada->isNotEmpty()) {
            return $ada->all();
        }
        try {
            // Row + its batch_id in one step: a half-written earn (no
            // batch_id) would be invisible to FIFO yet count in saldo().
            $row = self::atomic(function () use ($userId, $jenis, $jumlah, $sumberTipe, $sumberId, $tanggal, $kedaluwarsa, $catatan) {
                $row = HrisSaldoMutasi::query()->create([
                    'user_id' => $userId, 'user_ulid' => null, 'jenis' => $jenis, 'delta' => $jumlah,
                    'sumber_tipe' => mb_substr($sumberTipe, 0, 32), 'sumber_id' => mb_substr($sumberId, 0, 96),
                    'tanggal' => $tanggal, 'kedaluwarsa' => $kedaluwarsa,
                    'catatan' => mb_substr(self::s($catatan), 0, 255),
                ]);
                $row->fill(['batch_id' => $row->id])->save();

                return $row;
            });
        } catch (QueryException) {
            // Lost a race with another earner: return the winner's rows.
            return HrisSaldoMutasi::query()->where('sumber_tipe', $sumberTipe)->where('sumber_id', $sumberId)->get()->all();
        }

        return [$row->fresh()];
    }

    /**
     * Consume balance FIFO (oldest-expiry first). Idempotent per
     * (sumber_tipe, sumber_id): chunks share the source id with a #n suffix.
     *
     * @return array<int,HrisSaldoMutasi> the usage rows
     */
    public function gunakan(string $userId, string $jenis, int $jumlah, string $sumberTipe,
        string $sumberId, string $catatan = ''): array
    {
        $jenis = self::assertJenis($jenis);
        $userId = self::s($userId);
        if ($userId === '' || $jumlah < 1) {
            throw new HrisError('validation_failed', 'Pemakaian saldo butuh user dan jumlah ≥ 1.', 422);
        }
        $prefix = mb_substr($sumberId, 0, 90);

        return self::atomic(function () use ($userId, $jenis, $jumlah, $sumberTipe, $prefix, $catatan): array {
            // Lock first, then re-read: a concurrent spender waits here and
            // then sees this one's rows (idempotent hit or a smaller saldo).
            self::lockLedger($userId, $jenis);
            $ada = HrisSaldoMutasi::query()->where('sumber_tipe', $sumberTipe)
                ->where('sumber_id', 'like', $prefix.'%')->get();
            if ($ada->isNotEmpty()) {
                return $ada->all();
            }
            $batches = $this->batchFIFO($userId, $jenis);
            $total = array_sum(array_column($batches, 'sisa'));
            if ($total < $jumlah) {
                throw new HrisError('saldo_kurang', "Saldo {$jenis} kurang: sisa {$total}, butuh {$jumlah}.", 422,
                    ['saldo' => $total, 'butuh' => $jumlah]);
            }
            $today = PunchRules::tglWib(PunchRules::ms());
            $out = [];
            $sisa = $jumlah;
            $n = 0;
            foreach ($batches as $b) {
                if ($sisa <= 0) {
                    break;
                }
                $ambil = min($b['sisa'], $sisa);
                $out[] = HrisSaldoMutasi::query()->create([
                    'user_id' => $userId, 'user_ulid' => null, 'jenis' => $jenis, 'delta' => -$ambil,
                    'sumber_tipe' => mb_substr($sumberTipe, 0, 32),
                    'sumber_id' => $prefix.($n === 0 ? '' : '#'.$n),
                    'batch_id' => $b['id'], 'tanggal' => $today, 'kedaluwarsa' => null,
                    'catatan' => mb_substr(self::s($catatan), 0, 255),
                ]);
                $sisa -= $ambil;
                $n++;
            }

            return $out;
        });
    }

    /** HR manual adjustment (positive = earn batch, negative = FIFO consume). */
    public function adjust(string $by, string $userId, string $jenis, int $delta, string $catatan): array
    {
        $jenis = self::assertJenis($jenis);
        $userId = self::s($userId);
        $catatan = self::s($catatan);
        if ($userId === '' || $delta === 0) {
            throw new HrisError('validation_failed', 'Penyesuaian butuh user dan delta ≠ 0.', 422);
        }
        if ($catatan === '') {
            throw new HrisError('validation_failed', 'Penyesuaian saldo wajib disertai alasan.', 422);
        }
        $today = PunchRules::tglWib(PunchRules::ms());
        $key = 'ADJ-'.strtolower((string) Str::ulid());
        if ($delta > 0) {
            $rows = $this->earn($userId, $jenis, $delta, 'PENYESUAIAN', $key, $today,
                $jenis === 'PH' ? self::tambahBulan($today, 3) : null, $catatan);
        } else {
            $rows = $this->gunakan($userId, $jenis, -$delta, 'PENYESUAIAN', $key, $catatan);
        }
        $this->audit->record($by, 'saldo_disesuaikan', 'hris_saldo_mutasi', $userId,
            ['jenis' => $jenis, 'delta' => $delta]);

        return $rows;
    }

    /**
     * Give back what one source consumed (approved request cancelled, or a
     * cell write that failed after deduction): new earn rows mirroring each
     * usage chunk. Idempotent per (PENGEMBALIAN, source): a repeated call
     * returns the existing refund rows.
     *
     * @return array<int,HrisSaldoMutasi>
     */
    public function kembalikan(string $sumberTipe, string $sumberId, string $catatan): array
    {
        $prefix = mb_substr(trim($sumberId), 0, 90);
        $pakai = HrisSaldoMutasi::query()->where('sumber_tipe', $sumberTipe)
            ->where('sumber_id', 'like', $prefix.'%')->where('delta', '<', 0)
            ->orderBy('sumber_id')->get();
        if ($pakai->isEmpty()) {
            return [];
        }
        $u0 = $pakai->first();

        return self::atomic(function () use ($pakai, $prefix, $catatan, $u0): array {
            self::lockLedger((string) $u0->user_id, (string) $u0->jenis);
            $ada = HrisSaldoMutasi::query()->where('sumber_tipe', 'PENGEMBALIAN')
                ->where('sumber_id', 'like', $prefix.'%')->get();
            if ($ada->isNotEmpty()) {
                return $ada->all();
            }
            $today = PunchRules::tglWib(PunchRules::ms());
            $out = [];
            $n = 0;
            foreach ($pakai as $u) {
                $batch = $u->batch_id ? HrisSaldoMutasi::query()->find($u->batch_id) : null;
                $hangus = ($batch && $batch->kedaluwarsa) ? $batch->kedaluwarsa->format('Y-m-d') : null;
                if ($u->jenis === 'PH' && ($hangus === null || $hangus < $today)) {
                    // The original batch is gone or past: return usable balance.
                    $hangus = self::tambahBulan($today, 3);
                }
                $row = HrisSaldoMutasi::query()->create([
                    'user_id' => $u->user_id, 'user_ulid' => null, 'jenis' => $u->jenis, 'delta' => -$u->delta,
                    'sumber_tipe' => 'PENGEMBALIAN', 'sumber_id' => $prefix.($n === 0 ? '' : '#k'.$n),
                    'tanggal' => $today, 'kedaluwarsa' => $u->jenis === 'PH' ? $hangus : null,
                    'catatan' => mb_substr(trim($catatan), 0, 255),
                ]);
                $row->fill(['batch_id' => $row->id])->save();
                $out[] = $row->fresh();
                $n++;
            }

            return $out;
        });
    }

    /**
     * Expire leftover PH batches past kedaluwarsa (L4). Idempotent per batch.
     *
     * @return array{hangus:int,baris:int}
     */
    public function hanguskan(string $today): array
    {
        $today = PunchRules::tglValid($today);
        $hangus = 0;
        $baris = 0;
        $jatuh = HrisSaldoMutasi::query()->where('jenis', 'PH')
            ->whereColumn('batch_id', 'id')
            ->whereNotNull('kedaluwarsa')->where('kedaluwarsa', '<', $today)->get();
        foreach ($jatuh as $e) {
            $sisa = $this->sisaBatch($e->id);
            if ($sisa <= 0) {
                continue;
            }
            $ada = HrisSaldoMutasi::query()->where('sumber_tipe', 'KEDALUWARSA')
                ->where('sumber_id', $e->id)->exists();
            if ($ada) {
                continue;
            }
            try {
                HrisSaldoMutasi::query()->create([
                    'user_id' => $e->user_id, 'user_ulid' => null, 'jenis' => 'PH', 'delta' => -$sisa,
                    'sumber_tipe' => 'KEDALUWARSA', 'sumber_id' => $e->id, 'batch_id' => $e->id,
                    'tanggal' => $today, 'kedaluwarsa' => null,
                    'catatan' => 'Hangus '.($e->kedaluwarsa?->format('Y-m-d') ?? ''),
                ]);
                $hangus += $sisa;
                $baris++;
            } catch (QueryException) {
                // Lost a race with another run; the winner's row stands.
            }
        }

        return ['hangus' => $hangus, 'baris' => $baris];
    }

    /**
     * The crew-facing summary: balances, the nearest PH expiry, and the
     * annual CUTI quota (manual grants for now — TODO(mentor) per contract).
     */
    public function ringkas(string $userId, int $jatahCuti): array
    {
        $today = PunchRules::tglWib(PunchRules::ms());
        $ambang = self::tambahBulan($today, 1); // placeholder; B4 reminds at 14 days
        $dekat = [];
        foreach ($this->batchFIFO($userId, 'PH') as $b) {
            if ($b['kedaluwarsa'] !== null && $b['kedaluwarsa'] <= $ambang) {
                $dekat[] = $b;
            }
        }

        return [
            'PH' => ['saldo' => $this->saldo($userId, 'PH'), 'hangus_dekat' => $dekat],
            'CUTI' => ['saldo' => $this->saldo($userId, 'CUTI'), 'jatah_tahunan' => $jatahCuti],
        ];
    }

    /**
     * Ledger history, newest first — the console "riwayat mutasi" (C2).
     * Scoping happens in the controller; this only reads one person's rows.
     *
     * @return array<int,array{id:string,tanggal:string,jenis:string,delta:int,sumber:string,catatan:string,kedaluwarsa:string|null}>
     */
    public function riwayat(string $userId, int $batas = 50): array
    {
        $out = [];
        foreach (HrisSaldoMutasi::query()->where('user_id', self::s($userId))
            ->orderByDesc('tanggal')->orderByDesc('id')->limit(max(1, min(200, $batas)))->get() as $m) {
            $out[] = ['id' => $m->id, 'tanggal' => $m->tanggal->format('Y-m-d'),
                'jenis' => $m->jenis, 'delta' => (int) $m->delta,
                'sumber' => trim($m->sumber_tipe.' '.$m->sumber_id),
                'catatan' => (string) $m->catatan,
                'kedaluwarsa' => $m->kedaluwarsa?->format('Y-m-d')];
        }

        return $out;
    }

    public static function tambahBulan(string $ymd, int $n): string
    {
        return Carbon::parse($ymd, 'Asia/Jakarta')->addMonthsNoOverflow($n)->format('Y-m-d');
    }
}
