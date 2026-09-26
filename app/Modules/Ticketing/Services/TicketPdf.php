<?php

namespace App\Modules\Ticketing\Services;

use Exception;

/**
 * The e-ticket PDF: a faithful port of legacy ticketing-mysql/lib_pdf.php, and
 * byte-identical to it (tests/Unit/TicketingQrPdfTest.php).
 *
 * A hand-written minimal PDF writer: Helvetica/WinAnsi only, no images or
 * embedded fonts, and the QR drawn as vector boxes (sharp at any zoom, small
 * file). Two tickets per A4 page, cut line between them.
 */
class TicketPdf
{
    /** WinAnsi (CP1252): unmappable characters are transliterated, not dropped. */
    private static function ansi($s): string
    {
        $s = (string) $s;
        if (function_exists('iconv')) {
            $x = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($x !== false) {
                $s = $x;
            }
        } else {
            $s = preg_replace('/[^\x20-\x7E]/', '', $s);
        }

        return $s;
    }

    private static function str($s): string
    {
        return '('.str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], self::ansi($s)).')';
    }

    private static function n($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    /** Cut a title that does not fit, using an average Helvetica width of 0.52em. */
    private static function cut($s, $size, $maxWidth): string
    {
        $s = self::ansi($s);
        $max = (int) floor($maxWidth / ($size * 0.52));
        if (strlen($s) <= $max) {
            return $s;
        }

        return rtrim(substr($s, 0, max(1, $max - 1))).'.';
    }

    private static function text($x, $y, $size, $font, $text, $color = '0 0 0'): string
    {
        return "BT $color rg /$font ".self::n($size).' Tf '
            .self::n($x).' '.self::n($y).' Td '.self::str($text)." Tj ET\n";
    }

    private static function box($x, $y, $w, $h, $color): string
    {
        return "$color rg ".self::n($x).' '.self::n($y).' '.self::n($w).' '.self::n($h)." re f\n";
    }

    private static function line($x1, $y1, $x2, $y2, $color = '.85 .84 .81', $width = 1, $dashed = false): string
    {
        return ($dashed ? "[3 3] 0 d\n" : "[] 0 d\n")
            ."$color RG ".self::n($width).' w '
            .self::n($x1).' '.self::n($y1).' m '.self::n($x2).' '.self::n($y2)." l S\n";
    }

    /** QR as boxes; horizontally adjacent modules merge into one box (no hairline gaps). */
    public static function qr(string $text, $x, $y, $size): string
    {
        $m = QrCode::matrix($text);
        $N = count($m);
        $quiet = 4;
        $px = $size / ($N + $quiet * 2);
        $out = self::box($x, $y, $size, $size, '1 1 1');
        $out .= "0 0 0 rg\n";
        for ($r = 0; $r < $N; $r++) {
            $c = 0;
            while ($c < $N) {
                if (! $m[$r][$c]) {
                    $c++;

                    continue;
                }
                $start = $c;
                while ($c < $N && $m[$r][$c]) {
                    $c++;
                }
                // matrix row 0 is drawn at the TOP: the PDF y axis goes up
                $gy = $y + $size - ($r + $quiet + 1) * $px;
                $gx = $x + ($start + $quiet) * $px;
                $out .= self::n($gx).' '.self::n($gy).' '.self::n(($c - $start) * $px).' '.self::n($px)." re\n";
            }
        }

        return $out."f\n";
    }

    /** One ticket in a half-A4 slot; $y0 is the slot's bottom. */
    private static function slot($y0, $height, ?array $ev, array $o, array $t, int $no, int $of): string
    {
        $L = 36;
        $R = 559;
        $W = $R - $L;
        $top = $y0 + $height;
        $s = '';

        $hT = 62;
        $s .= self::box($L, $top - 30 - $hT, $W, $hT, '.165 .149 .125');
        $s .= self::text($L + 16, $top - 30 - 22, 8, 'F2', 'LAKSAMANA MUDA', '.784 .588 .122');
        $s .= self::text($L + 16, $top - 30 - 44, 15, 'F2',
            self::cut($ev && isset($ev['title']) ? $ev['title'] : 'Event Laksamana Muda', 15, $W - 32), '1 1 1');
        $s .= self::text($R - 16 - 52, $top - 30 - 22, 8, 'F1', 'E-TICKET '.$no.'/'.$of, '.75 .73 .70');

        $qrSize = 132;
        $qrX = $R - 16 - $qrSize;
        $qrY = $top - 30 - $hT - 14 - $qrSize;
        $s .= self::qr((string) ($t['qr_token'] ?? ''), $qrX, $qrY, $qrSize);
        $s .= self::text($qrX, $qrY - 12, 7, 'F1', 'Tunjukkan QR ini di pintu masuk', '.55 .53 .48');

        $ky = $top - 30 - $hT - 26;
        $rows = [];
        $rows[] = ['Nomor Tiket', $t['ticket_number'] ?? '-'];
        $rows[] = ['Kategori', $t['tier'] ?? '-'];

        $pt = (int) ($t['pax_total'] ?? 1);
        $kind = $t['kind'] ?? ($pt > 1 ? 'table' : 'seat');
        if ($kind === 'general') {
            $place = 'Tanpa nomor tempat'.($pt > 1 ? ' - tiket '.(int) ($t['pax_no'] ?? 0).' dari '.$pt : '');
        } else {
            $place = ($kind === 'table' ? 'Meja ' : '').($t['seat_label'] ?? '-')
                .($pt > 1 ? ' - tamu '.(int) ($t['pax_no'] ?? 0).' dari '.$pt : '');
        }
        $rows[] = ['Tempat', $place];
        if ($ev && ! empty($ev['start_datetime'])) {
            $rows[] = ['Waktu', str_replace('T', ' ', substr((string) $ev['start_datetime'], 0, 16)).' WIB'];
        }
        if ($ev && ! empty($ev['venue'])) {
            $rows[] = ['Venue', $ev['venue']];
        }
        $rows[] = ['Pemesan', $o['buyer_name'] ?? '-'];
        $rows[] = ['Kode Pesanan', $o['payment_ref'] ?? '-'];

        $infoWidth = $qrX - $L - 26;
        foreach ($rows as $b) {
            $s .= self::text($L + 16, $ky, 7.5, 'F1', strtoupper($b[0]), '.58 .56 .50');
            $s .= self::text($L + 16, $ky - 13, 11, 'F2', self::cut($b[1], 11, $infoWidth));
            $ky -= 31;
        }

        return $s.self::line($L, $y0 + 6, $R, $y0 + 6, '.80 .78 .74', 1, true);
    }

    /**
     * The whole PDF as a binary string (legacy pdf_eticket). $max caps the
     * tickets for the mail attachment size; the rest stay on the e-ticket page.
     */
    public static function eticket(array $o, array $tickets, ?array $ev = null, int $max = 30): string
    {
        $tickets = array_values($tickets);
        if ($max > 0 && count($tickets) > $max) {
            $tickets = array_slice($tickets, 0, $max);
        }
        $n = count($tickets);
        if (! $n) {
            throw new Exception('Tidak ada tiket untuk dicetak.');
        }

        $Wh = 595.28;
        $Hh = 841.89;
        $pages = [];
        for ($i = 0; $i < $n; $i += 2) {
            $c = "q\n".self::slot($Hh / 2, $Hh / 2, $ev, $o, $tickets[$i], $i + 1, $n);
            if (isset($tickets[$i + 1])) {
                $c .= self::slot(0, $Hh / 2, $ev, $o, $tickets[$i + 1], $i + 2, $n);
            }
            $pages[] = $c."Q\n";
        }

        // objects first, xref offsets computed from what was actually written
        $obj = [];
        $count = count($pages);
        $firstPage = 5; // 1 catalog, 2 pages, 3-4 fonts
        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = ($firstPage + $i * 2).' 0 R';
        }
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[2] = "<< /Type /Pages /Count $count /Kids [".implode(' ', $kids).'] >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        for ($i = 0; $i < $count; $i++) {
            $idP = $firstPage + $i * 2;
            $idC = $idP + 1;
            $obj[$idP] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::n($Wh).' '.self::n($Hh).'] '
                ."/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $idC 0 R >>";
            $c = $pages[$i];
            if (function_exists('gzcompress')) {
                $z = gzcompress($c, 6);
                $obj[$idC] = '<< /Length '.strlen($z)." /Filter /FlateDecode >>\nstream\n".$z."\nendstream";
            } else {
                $obj[$idC] = '<< /Length '.strlen($c)." >>\nstream\n".$c.'endstream';
            }
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

    /** Attachment file name: safe characters only (legacy pdf_nama_eticket). */
    public static function fileName(array $o): string
    {
        $ref = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($o['payment_ref'] ?? 'tiket'));

        return 'E-Ticket-'.($ref === '' ? 'tiket' : $ref).'.pdf';
    }
}
