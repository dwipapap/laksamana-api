<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Face matching for the NEW on-device model (A2). Its descriptors are not
 * comparable with face-api.js or the legacy 0.45 threshold, so HRIS matches
 * with its own logic: euclidean or cosine distance per model, with a
 * per-model threshold from the settings (wajahAmbang).
 *
 * One person holds up to 3 descriptors (W2: straight, slightly left,
 * slightly right). The stored `descriptor` column is an array of arrays; a
 * flat legacy array reads as one descriptor (no migration). The score is
 * the SMALLEST distance over the set.
 *
 * Descriptors of different models are never compared. The default threshold
 * follows the OpenCV SFace reference (similarity >= 0.363, distance <=
 * 0.637); HR may still override it per model in Kelola → Pengaturan (W4).
 */
final class FaceMatcher
{
    /** Built-in threshold when neither the model nor a default is stored. */
    public const AMBANG_BAWAAN = 0.637;

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
     * Match a probe descriptor against a stored descriptor or set (W2).
     *
     * @param  array|null  $stored  decoded stored descriptor: a flat legacy
     *                              array (= one descriptor) or an array of
     *                              arrays (null = never enrolled)
     * @param  mixed  $probe  probe descriptor from the request
     * @param  array{metric:string,ambang:float}  $ambang
     * @return array{skor:float|null,ok:bool,terdaftar:bool,metric:string}
     */
    public static function match(?array $stored, mixed $probe, array $ambang): array
    {
        $metric = ($ambang['metric'] ?? 'cosine') === 'euclidean' ? 'euclidean' : 'cosine';
        $set = self::storedSet($stored);
        if ($set === null) {
            return ['skor' => null, 'ok' => false, 'terdaftar' => false, 'metric' => $metric];
        }
        if (! is_array($probe) || count($probe) < 32) {
            return ['skor' => null, 'ok' => false, 'terdaftar' => true, 'metric' => $metric];
        }
        foreach ($probe as $v) {
            if (! is_numeric($v)) {
                return ['skor' => null, 'ok' => false, 'terdaftar' => true, 'metric' => $metric];
            }
        }
        $probe = array_values($probe);
        $skor = null;
        foreach ($set as $satu) {
            if (count($probe) !== count($satu)) {
                continue;
            }
            $jarak = $metric === 'euclidean'
                ? self::euclidean($satu, $probe)
                : self::cosineDistance($satu, $probe);
            $skor = $skor === null ? $jarak : min($skor, $jarak);
        }
        if ($skor === null) {
            return ['skor' => null, 'ok' => false, 'terdaftar' => true, 'metric' => $metric];
        }

        return ['skor' => round($skor, 4), 'ok' => $skor <= (float) $ambang['ambang'], 'terdaftar' => true, 'metric' => $metric];
    }

    /**
     * Normalize a stored descriptor to a set (W2): a flat array of numbers
     * reads as one descriptor, an array of arrays as several. Null when
     * nothing usable is stored (never enrolled).
     *
     * @return array<int,array<int,float>>|null
     */
    public static function storedSet(?array $stored): ?array
    {
        if (! is_array($stored) || $stored === []) {
            return null;
        }
        $values = array_values($stored);
        if (isset($values[0]) && is_array($values[0])) {
            $set = [];
            foreach ($values as $satu) {
                if (! is_array($satu)) {
                    return null;
                }
                $satu = array_values($satu);
                if (count($satu) < 32) {
                    return null;
                }
                $set[] = $satu;
            }

            return $set === [] ? null : $set;
        }
        if (count($values) < 32) {
            return null;
        }

        return [$values];
    }

    /** Threshold for one model from the wajahAmbang settings map. */
    public static function ambangUntuk(string $model, array $peta): array
    {
        $d = $peta[$model] ?? $peta['default'] ?? [];
        if (! is_array($d)) {
            $d = [];
        }
        $metric = ($d['metric'] ?? 'cosine') === 'euclidean' ? 'euclidean' : 'cosine';
        $ambang = (float) ($d['ambang'] ?? self::AMBANG_BAWAAN);
        if ($ambang <= 0) {
            $ambang = self::AMBANG_BAWAAN;
        }

        return ['metric' => $metric, 'ambang' => $ambang];
    }
}
