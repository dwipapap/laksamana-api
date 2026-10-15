<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Server-side PII redaction (R3): NIK, phone, email and street addresses
 * are masked BEFORE anything leaves for the AI screener (photos never
 * leave at all — only text is sent). HR keeps reading the originals.
 * Pure and unit-tested; returns the redacted text plus what was found
 * (kinds + counts only, never the values).
 */
final class RedaksiPii
{
    /** @return array{teks:string,temuan:array<string,int>} */
    public static function redact(string $teks): array
    {
        $temuan = [];
        $hitung = function (string $kind, string $pattern, string $ganti, string $subjek) use (&$temuan): string {
            $n = 0;
            $out = preg_replace_callback($pattern, function ($m) use (&$n, $ganti) {
                $n++;

                return $ganti;
            }, $subjek);
            if ($n > 0) {
                $temuan[$kind] = $n;
            }

            return $out ?? $subjek;
        };

        // Email first (it contains digits and dots other patterns would eat).
        $teks = $hitung('email', '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[EMAIL]', $teks);
        // Indonesian phones: 08… or +62… with separators, 9–14 digits total.
        $teks = preg_replace_callback(
            '/(?:\+?62|0)[\s.\-]*8[\d\s.\-]{7,13}/',
            function ($m) use (&$temuan): string {
                $digit = preg_replace('/\D/', '', $m[0]);
                if (strlen($digit) < 9 || strlen($digit) > 14) {
                    return $m[0];
                }
                $temuan['telepon'] = ($temuan['telepon'] ?? 0) + 1;

                return '[TELEPON]';
            }, $teks);
        // NIK: exactly 16 digits standing alone.
        $teks = $hitung('nik', '/(?<!\d)\d{16}(?!\d)/', '[NIK]', (string) $teks);
        // Street addresses: a marker word plus the rest of the segment.
        $teks = $hitung('alamat',
            '/\b(Jl\.?|Jalan|Gg\.?|Gang|RT|RW)\b[^.\n]{0,80}/iu', '[ALAMAT]', (string) $teks);

        return ['teks' => $teks, 'temuan' => $temuan];
    }
}
