<?php

namespace App\Modules\Reservasi\Services;

/**
 * A one-page A4 "Bukti Pembayaran DP" for one reservation, written by hand
 * (Helvetica/WinAnsi, no library) the same way TicketPdf writes e-tickets.
 *
 * This is the AUTOMATIC receipt the WhatsApp bot attaches after Xendit
 * confirms the DP. It is NOT the Office's official, signed, numbered kwitansi
 * (that stays Finance's, via Request Kwitansi) — it is a plain proof of
 * payment generated from the reservation row. No phone number is printed.
 */
class KwitansiPdf
{
    private static function ansi(string $s): string
    {
        if (function_exists('iconv')) {
            $x = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($x !== false) {
                return (string) $x;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    private static function str(string $s): string
    {
        return '('.str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], self::ansi($s)).')';
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function cut(string $s, float $size, float $maxWidth): string
    {
        $s = self::ansi($s);
        $max = (int) floor($maxWidth / ($size * 0.52));
        if (strlen($s) <= $max) {
            return $s;
        }

        return rtrim(substr($s, 0, max(1, $max - 1))).'.';
    }

    private static function text(float $x, float $y, float $size, string $font, string $text, string $color = '0 0 0'): string
    {
        return "BT $color rg /$font ".self::n($size).' Tf '
            .self::n($x).' '.self::n($y).' Td '.self::str($text)." Tj ET\n";
    }

    private static function box(float $x, float $y, float $w, float $h, string $color): string
    {
        return "$color rg ".self::n($x).' '.self::n($y).' '.self::n($w).' '.self::n($h)." re f\n";
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $color = '.85 .84 .81', float $width = 1): string
    {
        return "[] 0 d\n$color RG ".self::n($width).' w '
            .self::n($x1).' '.self::n($y1).' m '.self::n($x2).' '.self::n($y2)." l S\n";
    }

    private static function rp($v): string
    {
        return 'Rp '.number_format((float) $v, 0, ',', '.');
    }

    /** @param array<string,mixed> $row the reservation row (decoded `data`) */
    public static function content(array $row, int $now): string
    {
        $dps = is_array($row['dps'] ?? null) ? $row['dps'] : [];
        $total = 0;
        foreach ($dps as $p) {
            $total += (int) (is_array($p) ? ($p['amount'] ?? 0) : 0);
        }
        if ($total === 0 && ($row['dpStatus'] ?? '') === 'Sudah') {
            $total = (int) ($row['dpAmount'] ?? 0);
        }

        $W = 595.28;
        $H = 841.89;
        $L = 40.0;
        $R = $W - 40.0;
        $IW = $R - $L;
        $y = $H - 36.0;
        $s = "q\n";

        // Header band
        $s .= self::box($L, $y - 74, $IW, 74, '.165 .149 .125');
        $s .= self::text($L + 16, $y - 30, 9, 'F2', 'LAKSAMANA MUDA', '.784 .588 .122');
        $s .= self::text($L + 16, $y - 52, 17, 'F2', 'Bukti Pembayaran DP', '1 1 1');
        $s .= self::text($L + 16, $y - 67, 8.5, 'F1', 'Reservasi Meja', '.72 .70 .66');
        $s .= self::text($R - 16 - 210, $y - 30, 8.5, 'F1', 'No. '.substr((string) ($row['id'] ?? '-'), 0, 28), '.72 .70 .66');
        $y -= 74 + 26;

        // Info rows
        $tgl = (string) ($row['date'] ?? $row['tanggal'] ?? '-');
        $jam = (string) ($row['time'] ?? $row['jam'] ?? '-');
        $meja = (string) ($row['tables'] ?? $row['table'] ?? '-');
        $rows = [
            ['Nama', (string) ($row['name'] ?? '-')],
            ['Meja', $meja === '' ? '-' : $meja],
            ['Tanggal', $tgl],
            ['Jam', substr($jam, 0, 5).' WIB'],
            ['Jumlah orang', (string) (int) ($row['pax'] ?? 0).' orang'],
            ['Status', (string) ($row['status'] ?? '-')],
        ];
        foreach ($rows as $r) {
            $s .= self::text($L, $y, 8, 'F1', strtoupper($r[0]), '.60 .58 .52');
            $s .= self::text($L, $y - 14, 11.5, 'F2', self::cut($r[1], 11.5, $IW));
            $y -= 30;
        }

        $y -= 6;
        $s .= self::line($L, $y, $R, $y, '.85 .84 .81', 1);
        $y -= 24;

        // DP summary
        $s .= self::text($L, $y, 9, 'F1', 'RINCIAN DP', '.60 .58 .52');
        $y -= 20;
        if ($dps !== []) {
            foreach ($dps as $p) {
                $amt = (int) (is_array($p) ? ($p['amount'] ?? 0) : 0);
                $mth = (string) (is_array($p) ? ($p['method'] ?? '') : '');
                $s .= self::text($L + 6, $y, 10, 'F1', self::cut($mth === '' ? 'DP' : $mth, 10, $IW - 140));
                $s .= self::text($R - 130, $y, 10, 'F2', self::rp($amt));
                $y -= 18;
            }
        } else {
            $s .= self::text($L + 6, $y, 10, 'F1', 'Belum ada pembayaran tercatat');
            $y -= 18;
        }
        $y -= 6;
        $s .= self::box($L, $y - 6, $IW, 26, '.97 .96 .92');
        $s .= self::text($L + 6, $y, 11, 'F2', 'TOTAL DP');
        $s .= self::text($R - 130, $y, 12, 'F2', self::rp($total));
        $y -= 44;

        // Printed at + note
        $wib = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
        $s .= self::text($L, $y, 8.5, 'F1', 'Dicetak otomatis '.$wib.' WIB', '.60 .58 .52');
        $y -= 14;
        $s .= self::text($L, $y, 8.5, 'F1', 'Dokumen ini bukti pembayaran DP reservasi; kwitansi resmi diterbitkan Finance.', '.60 .58 .52');
        $s .= "Q\n";

        return $s;
    }

    /** @param array<string,mixed> $row the reservation row (decoded `data`) */
    public static function render(array $row, int $now): string
    {
        $s = self::content($row, $now);
        $W = 595.28;
        $H = 841.89;

        // Objects
        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[2] = '<< /Type /Pages /Count 1 /Kids [5 0 R] >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $obj[5] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::n($W).' '.self::n($H).'] '
            .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents 6 0 R >>';
        if (function_exists('gzcompress')) {
            $z = gzcompress($s, 6);
            $obj[6] = '<< /Length '.strlen($z)." /Filter /FlateDecode >>\nstream\n".$z."\nendstream";
        } else {
            $obj[6] = '<< /Length '.strlen($s)." >>\nstream\n".$s.'endstream';
        }

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offset = [];
        ksort($obj);
        foreach ($obj as $id => $body) {
            $offset[$id] = strlen($out);
            $out .= "$id 0 obj\n".$body."\nendobj\n";
        }
        $maxId = max(array_keys($obj));
        $xref = strlen($out);
        $out .= "xref\n0 ".($maxId + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offset[$i] ?? 0);
        }

        return $out."trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    public static function fileName(array $row): string
    {
        $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($row['id'] ?? ''));

        return 'Bukti-DP-'.($id === '' ? 'reservasi' : $id).'.pdf';
    }
}
