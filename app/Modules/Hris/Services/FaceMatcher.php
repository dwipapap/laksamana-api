<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Face matching for the NEW on-device model (A2). Its descriptors are not
 * comparable with face-api.js or the legacy 0.45 threshold, so HRIS matches
 * with its own logic: euclidean or cosine distance per model, with a
 * per-model threshold from the settings (wajahAmbang).
 *
 * Descriptors of different models are never compared. The starting
 * thresholds are placeholders for the M1 calibration (5–10 people,
 * same-vs-different distances) — see TODO(mentor) in docs/modules/hris.md.
 */
final class FaceMatcher
{
    /** Euclidean distance between two float vectors. */
    public static function euclidean(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $d = (float) $a[$i] - (float) $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /** Cosine distance (1 − cosine similarity) between two float vectors. */
    public static function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        if ($na <= 0 || $nb <= 0) {
            return 1.0;
        }

        return 1 - $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Match a probe descriptor against a stored one.
     *
     * @param  array|null  $stored  decoded stored descriptor (null = never enrolled)
     * @param  mixed  $probe  probe descriptor from the request
     * @param  array{metric:string,ambang:float}  $ambang
     * @return array{skor:float|null,ok:bool,terdaftar:bool,metric:string}
     */
    public static function match(?array $stored, mixed $probe, array $ambang): array
    {
        $metric = ($ambang['metric'] ?? 'cosine') === 'euclidean' ? 'euclidean' : 'cosine';
        if (! is_array($stored) || count($stored) < 32) {
            return ['skor' => null, 'ok' => false, 'terdaftar' => false, 'metric' => $metric];
        }
        if (! is_array($probe) || count($probe) !== count($stored)) {
            return ['skor' => null, 'ok' => false, 'terdaftar' => true, 'metric' => $metric];
        }
        foreach ($probe as $v) {
            if (! is_numeric($v)) {
                return ['skor' => null, 'ok' => false, 'terdaftar' => true, 'metric' => $metric];
            }
        }
        $skor = $metric === 'euclidean'
            ? self::euclidean(array_values($stored), array_values($probe))
            : self::cosineDistance(array_values($stored), array_values($probe));

        return ['skor' => round($skor, 4), 'ok' => $skor <= (float) $ambang['ambang'], 'terdaftar' => true, 'metric' => $metric];
    }

    /** Threshold for one model from the wajahAmbang settings map. */
    public static function ambangUntuk(string $model, array $peta): array
    {
        $d = $peta[$model] ?? $peta['default'] ?? [];
        if (! is_array($d)) {
            $d = [];
        }
        $metric = ($d['metric'] ?? 'cosine') === 'euclidean' ? 'euclidean' : 'cosine';
        $ambang = (float) ($d['ambang'] ?? 0.4);
        if ($ambang <= 0) {
            $ambang = 0.4;
        }

        return ['metric' => $metric, 'ambang' => $ambang];
    }
}
