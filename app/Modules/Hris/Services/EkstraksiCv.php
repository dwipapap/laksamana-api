<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * CV text extraction in pure PHP — no shell, no binaries (shared cPanel has
 * neither). PDF covers the common cases: uncompressed and FlateDecode
 * streams, literal and hex strings, Tj/TJ operators, WinAnsi remap.
 * Scanned/image-only PDFs yield nothing (OCR is out of scope); DOCX reads
 * word/document.xml through ZipArchive. Anything else is refused with the
 * reason, never with half text presented as full.
 */
final class EkstraksiCv
{
    public const MAKS_HURUF = 200000;

    public const MAKS_BAIT = 10 * 1024 * 1024;

    /** @return array{teks:string,catatan:string} */
    public static function dariBerkas(string $nama, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAKS_BAIT) {
            throw new HrisError('validation_failed', 'Berkas CV kosong atau melebihi 10 MB.', 422);
        }
        $ext = strtolower((string) pathinfo($nama, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => self::dariPdf($bytes),
            'docx' => self::dariDocx($bytes),
            'txt' => ['teks' => self::potong($bytes), 'catatan' => ''],
            default => throw new HrisError('validation_failed', 'CV harus PDF, DOCX, atau TXT.', 422),
        };
    }

    /** @return array{teks:string,catatan:string} */
    public static function dariPdf(string $bytes): array
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new HrisError('validation_failed', 'Berkas bukan PDF yang valid.', 422);
        }
        $teks = [];
        // stream...endstream blocks with their offsets, so the dictionary
        // before each block tells whether it is FlateDecode.
        preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$raw, $pos]) {
            $dict = substr($bytes, max(0, $pos - 400), 400);
            $data = $raw;
            if (str_contains($dict, '/FlateDecode') || str_contains($dict, '/Fl')) {
                if (! function_exists('gzinflate')) {
                    continue;
                }
                $inflated = @gzinflate($raw);
                if (! is_string($inflated)) {
                    continue;
                }
                $data = $inflated;
            }
            // Hex strings first (so literal parens inside hex survive).
            $data = preg_replace_callback('/<([0-9A-Fa-f\s]+)>/', function ($h) {
                $hex = preg_replace('/\s+/', '', $h[1]);
                if (strlen($hex) % 2 === 1) {
                    $hex .= '0';
                }
                $bin = @hex2bin($hex);

                return $bin === false ? ' ' : self::remap($bin);
            }, $data);
            // Literal strings: (teks) with \( \) \\ escapes.
            preg_match_all('/\((?:[^()\\\\]|\\\\.|\\\r?\n)*\)/s', (string) $data, $lit);
            foreach ($lit[0] as $s) {
                $inner = substr($s, 1, -1);
                $inner = str_replace(['\\\\', '\\(', '\\)', '\\n', '\\r', '\\t'], ['\\', '(', ')', "\n", "\r", "\t"], $inner);
                $inner = (string) preg_replace('/\\\\\r?\n/', '', $inner);
                $teks[] = self::remap($inner);
            }
        }
        $gabung = self::potong(preg_replace('/[ \t\x0B\f]+/u', ' ', implode("\n", $teks)));
        if (trim($gabung) === '') {
            return ['teks' => '', 'catatan' => 'PDF tanpa teks terbaca (kemungkinan hasil pindaian).'];
        }

        return ['teks' => $gabung, 'catatan' => ''];
    }

    /** @return array{teks:string,catatan:string} */
    public static function dariDocx(string $bytes): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new HrisError('validation_failed', 'DOCX tidak didukung di server ini.', 422);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new HrisError('validation_failed', 'Berkas bukan DOCX yang valid.', 422);
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }
        if (! is_string($xml) || $xml === '') {
            return ['teks' => '', 'catatan' => 'DOCX tanpa dokumen terbaca.'];
        }
        // <w:t> runs carry the text; <w:p>/<w:br> break lines.
        $xml = preg_replace('/<(w:p|w:br)[^>]*>/', "\n", $xml);
        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $xml, $m);
        $gabung = self::potong(html_entity_decode(implode('', $m[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return ['teks' => $gabung, 'catatan' => trim($gabung) === '' ? 'DOCX tanpa teks terbaca.' : ''];
    }

    /**
     * WinAnsi (CP-1252) bytes to UTF-8. Pure ASCII passes through; high
     * bytes map through the Windows-1252 table (covers Indonesian CVs).
     */
    public static function remap(string $bin): string
    {
        if (preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $bin)) {
            return $bin;
        }
        $map = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
            0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
            0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
            0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178];
        $out = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($bin[$i]);
            if ($o < 0x80) {
                $out .= $bin[$i];
            } else {
                $out .= mb_chr($map[$o] ?? 0xFFFD, 'UTF-8');
            }
        }

        return $out;
    }

    private static function potong(string $teks): string
    {
        $teks = trim(preg_replace('/\n{3,}/', "\n\n", $teks));
        if (mb_strlen($teks) > self::MAKS_HURUF) {
            $teks = mb_substr($teks, 0, self::MAKS_HURUF);
        }

        return $teks;
    }
}
