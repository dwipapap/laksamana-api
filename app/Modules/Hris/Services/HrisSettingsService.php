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
        'awalMenit' => 60,
        'akhirMenit' => 180,
        'toleransiTelat' => 5,
        'tanpaShiftBoleh' => false,
        'wajahWajib' => false,
        // Per-model face thresholds. The mobilefacenet-v1 numbers are
        // placeholders until the M1 calibration measures same-vs-different
        // distances on 5–10 people (TODO(mentor): confirm or replace).
        'wajahAmbang' => [
            'default' => ['metric' => 'cosine', 'ambang' => 0.4],
            'mobilefacenet-v1' => ['metric' => 'cosine', 'ambang' => 0.4],
        ],
        // Extra HR staff by legacy User id (module admins of hris are always HR).
        'hr' => [],
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
            'tanpaShiftBoleh', 'wajahWajib' => $this->cleanBool($v, $k),
            'wajahAmbang' => $this->cleanAmbang($v),
            'hr' => $this->cleanHr($v),
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
