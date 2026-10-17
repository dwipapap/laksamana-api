<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPengaturan;

/**
 * The hris_pengaturan key/value store. One row per key (nilai JSON,
 * berlaku_dari for history); reads merge stored rows over DEFAULTS.
 *
 * ⏳-marked decisions are NOT guessed here: anything the mentor has not
 * answered stays a setting with a documented default (TODO(mentor)).
 */
final class HrisSettingsService
{
    public const DEFAULTS = [
        'awalMenit' => 180,
        'akhirMenit' => 180,
        'toleransiTelat' => 5,
        // GPS fix accuracy ceiling: dalam_area needs 1 <= akurasi <= akurasiMaks (A13).
        'akurasiMaks' => 100,
        // PULANG later than target + this queues LUAR_SHIFT (A24).
        'pulangLewatMenit' => 180,
        // Work-duration target in minutes: per-person override, else the
        // shift snapshot (JADWAL first, BAWAAN), else the default (A22).
        // `orang` keys are User ids (validated as id-shaped strings, like `hr`).
        'durasiKerja' => ['default' => 540, 'orang' => []],
        'tanpaShiftBoleh' => false,
        // wajahWajib was removed (A18: the unenrolled always queue
        // WAJAH_KOSONG). PUT still accepts it and ignores it, because the
        // old PWA sends it — see save().
        // Per-model face thresholds. The sface-2021dec-int8 numbers are
        // placeholders until the M1 calibration measures same-vs-different
        // distances on 5–10 people (TODO(mentor): confirm or replace).
        'wajahAmbang' => [
            'default' => ['metric' => 'cosine', 'ambang' => 0.4],
            'sface-2021dec-int8' => ['metric' => 'cosine', 'ambang' => 0.4],
        ],
        // Extra HR staff by legacy User id (module admins of hris are always HR).
        'hr' => [],
        // Annual CUTI quota in days (TODO(mentor): per-contract rule, B7).
        'jatahCuti' => 12,
        // Team KPI input (templates + realisation) stays off until the mentor
        // confirms the items per Divisi (K1); reads always work.
        'kpiInputAktif' => false,
        // Perhatian thresholds (PWA "Perlu perhatian" box, computed on
        // GET /hris/me/today): minutes after shift start without MASUK,
        // and minutes after shift end with MASUK but no PULANG.
        'perhatianTelatMenit' => 15,
        'perhatianPulangMenit' => 60,
    ];

    /** The merged settings map (stored rows over defaults). */
    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach (HrisPengaturan::query()->get(['kunci', 'nilai']) as $row) {
            if (array_key_exists($row->kunci, $out)) {
                $out[$row->kunci] = $row->nilai ?? $out[$row->kunci];
            }
        }

        return $out;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $fallback;
    }

    /**
     * Save a partial map. Unknown keys are refused (a typo must not create a
     * dead setting); out-of-range values are refused with the legacy tone.
     */
    public function save(array $input): array
    {
        $bersih = [];
        foreach ($input as $k => $v) {
            if ($k === 'wajahWajib') {
                // Removed setting (A18): the old PWA still sends it, so it
                // is accepted and ignored instead of refused as unknown.
                continue;
            }
            if (! array_key_exists($k, self::DEFAULTS)) {
                throw new HrisError('validation_failed', "Pengaturan tidak dikenal: {$k}.", 422);
            }
            $bersih[$k] = $this->clean($k, $v);
        }
        $today = PunchRules::tglWib(PunchRules::ms());
        foreach ($bersih as $k => $v) {
            HrisPengaturan::query()->updateOrCreate(['kunci' => $k], ['nilai' => $v, 'berlaku_dari' => $today]);
        }

        return $this->all();
    }

    private function clean(string $k, mixed $v): mixed
    {
        $int = fn (mixed $x, int $min, int $max, string $nama): int => $this->cleanInt($x, $min, $max, $nama);

        return match ($k) {
            'awalMenit' => $int($v, 0, 720, $k),
            'akhirMenit' => $int($v, 0, 720, $k),
            'toleransiTelat' => $int($v, 0, 120, $k),
            'akurasiMaks' => $int($v, 10, 1000, $k),
            'pulangLewatMenit' => $int($v, 0, 720, $k),
            'durasiKerja' => $this->cleanDurasi($v),
            'tanpaShiftBoleh', 'kpiInputAktif' => $this->cleanBool($v, $k),
            'wajahAmbang' => $this->cleanAmbang($v),
            'hr' => $this->cleanHr($v),
            'jatahCuti' => $int($v, 0, 30, $k),
            'perhatianTelatMenit' => $int($v, 5, 240, $k),
            'perhatianPulangMenit' => $int($v, 5, 720, $k),
        };
    }

    private function cleanInt(mixed $v, int $min, int $max, string $nama): int
    {
        if (! is_numeric($v) || (int) $v < $min || (int) $v > $max) {
            throw new HrisError('validation_failed', "Pengaturan {$nama} harus angka {$min}–{$max}.", 422);
        }

        return (int) $v;
    }

    private function cleanBool(mixed $v, string $nama): bool
    {
        if (! is_bool($v)) {
            throw new HrisError('validation_failed', "Pengaturan {$nama} harus boolean.", 422);
        }

        return $v;
    }

    /** @return array{default:int,orang:array<string,int>} */
    private function cleanDurasi(mixed $v): array
    {
        if (! is_array($v)) {
            throw new HrisError('validation_failed', 'Pengaturan durasiKerja harus objek {default, orang}.', 422);
        }
        $def = $v['default'] ?? 540;
        if (! is_numeric($def) || (int) $def < 60 || (int) $def > 1440) {
            throw new HrisError('validation_failed', 'Pengaturan durasiKerja.default harus angka 60–1440.', 422);
        }
        $orang = $v['orang'] ?? [];
        if (! is_array($orang)) {
            throw new HrisError('validation_failed', 'Pengaturan durasiKerja.orang harus objek id User ke menit.', 422);
        }
        $out = [];
        foreach ($orang as $id => $m) {
            $id = trim((string) $id);
            if ($id === '' || strlen($id) > 64) {
                throw new HrisError('validation_failed', 'Pengaturan durasiKerja.orang memakai id User yang valid.', 422);
            }
            if (! is_numeric($m) || (int) $m < 60 || (int) $m > 1440) {
                throw new HrisError('validation_failed', "Pengaturan durasiKerja.orang[{$id}] harus angka 60–1440.", 422);
            }
            $out[$id] = (int) $m;
        }

        return ['default' => (int) $def, 'orang' => $out];
    }

    /** @return array<string,array{metric:string,ambang:float}> */
    private function cleanAmbang(mixed $v): array
    {
        if (! is_array($v)) {
            throw new HrisError('validation_failed', 'Pengaturan wajahAmbang harus objek per model.', 422);
        }
        $out = [];
        foreach ($v as $model => $d) {
            $model = trim((string) $model);
            if ($model === '' || ! is_array($d)) {
                throw new HrisError('validation_failed', 'Pengaturan wajahAmbang harus objek per model.', 422);
            }
            $metric = ($d['metric'] ?? 'cosine') === 'euclidean' ? 'euclidean' : 'cosine';
            $ambang = (float) ($d['ambang'] ?? -1);
            if ($ambang <= 0 || $ambang > 4) {
                throw new HrisError('validation_failed', "Ambang wajah model {$model} harus > 0 dan ≤ 4.", 422);
            }
            $out[$model] = ['metric' => $metric, 'ambang' => $ambang];
        }
        if ($out === []) {
            throw new HrisError('validation_failed', 'Pengaturan wajahAmbang tidak boleh kosong.', 422);
        }

        return $out;
    }

    /** @return array<int,string> */
    private function cleanHr(mixed $v): array
    {
        if (! is_array($v)) {
            throw new HrisError('validation_failed', 'Pengaturan hr harus daftar id User.', 422);
        }
        $out = [];
        foreach ($v as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ! in_array($id, $out, true)) {
                $out[] = mb_substr($id, 0, 64);
            }
        }

        return $out;
    }
}
