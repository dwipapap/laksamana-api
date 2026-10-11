<?php

namespace App\Modules\Reservasi\Services;

/**
 * The DP kwitansi as a one-page A4 PDF, mirroring the Office sheet
 * (laksamana-office-vue/app/utils/kwitansi.ts → kwitansiHtml): the same kop,
 * meta rows, item table, TOTAL DITERIMA, transfer box, "DP DITERIMA" badge,
 * signature block(s) with the stamp/signature images, and the footer note.
 *
 * Written by hand like TicketPdf (Helvetica/WinAnsi, Flate-compressed), plus
 * JPEG image XObjects for the logo, the stamp (cap) and each signature (ttd),
 * decoded with GD. The number is the OFFICIAL one from Finance when the
 * request is issued; otherwise the venue's own placeholder (`KW/YYYYMMDD/XXXX`).
 *
 * The WhatsApp bot attaches this only; it is generated from the reservation
 * row (+ the issued kwitansi when Finance approved it).
 */
class KwitansiPdf
{
    /** @var list<array{name:string,w:int,h:int,data:string}> */
    private static array $images = [];

    private const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const PT = 'PT. LAKSAMANA MUDA BERSATU';

    private const ALAMAT1 = 'Jl. Soekarno - Hatta No. 39, Labuh Baru Tim, Kec. Payung Sekaki,';

    private const ALAMAT2 = 'Kota Pekanbaru, Riau 28282';

    private const BANK = 'UOB | PT. Laksamana Muda Bersatu';

    private const REK = '319-303-3588';

    // ── primitives ───────────────────────────────────────────────────────────

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

    /** Rough Helvetica width (0.52em average) for right alignment / truncation. */
    private static function w(string $s, float $size): float
    {
        return strlen(self::ansi($s)) * $size * 0.52;
    }

    private static function cut(string $s, float $size, float $maxWidth): string
    {
        $s = self::ansi($s);
        $max = (int) floor($maxWidth / ($size * 0.52));
        if ($max < 1) {
            return '';
        }

        return strlen($s) <= $max ? $s : rtrim(substr($s, 0, max(1, $max - 1))).'.';
    }

    private static function text(float $x, float $y, float $size, string $font, string $text, string $color = '0 0 0', float $spacing = 0): string
    {
        return "BT $color rg /$font ".self::n($size).' Tf '
            .($spacing > 0 ? self::n($spacing).' Tc ' : '')
            .self::n($x).' '.self::n($y).' Td '.self::str($text)." Tj ET\n";
    }

    private static function textRight(float $xRight, float $y, float $size, string $font, string $text, string $color = '0 0 0'): string
    {
        return self::text($xRight - self::w($text, $size), $y, $size, $font, $text, $color);
    }

    private static function box(float $x, float $y, float $w, float $h, string $color): string
    {
        return "$color rg ".self::n($x).' '.self::n($y).' '.self::n($w).' '.self::n($h)." re f\n";
    }

    private static function rect(float $x, float $y, float $w, float $h, string $color, float $line = 0.6): string
    {
        return "$color RG ".self::n($line)." w [] 0 d ".self::n($x).' '.self::n($y).' '.self::n($w).' '.self::n($h)." re S\n";
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $color = '.85 .84 .81', float $width = 1): string
    {
        return "[] 0 d\n$color RG ".self::n($width).' w '
            .self::n($x1).' '.self::n($y1).' m '.self::n($x2).' '.self::n($y2)." l S\n";
    }

    /** Decode a data URI (or raw bytes) to a JPEG for a PDF image XObject. */
    private static function image(string $src): ?array
    {
        $src = trim($src);
        if ($src === '') {
            return null;
        }
        $bytes = $src;
        if (str_starts_with($src, 'data:')) {
            $comma = strpos($src, ',');
            if ($comma === false) {
                return null;
            }
            $meta = substr($src, 5, $comma - 5);
            $payload = substr($src, $comma + 1);
            $bytes = str_contains($meta, ';base64') ? (base64_decode($payload, true) ?: '') : rawurldecode($payload);
        }
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $gd = @imagecreatefromstring($bytes);
        if (! $gd) {
            return null;
        }
        $w = imagesx($gd);
        $h = imagesy($gd);
        if ($w < 1 || $h < 1 || $w > 4000 || $h > 4000) {
            imagedestroy($gd);

            return null;
        }
        // Flatten on white (logo/stamp have transparency) and re-encode as JPEG.
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $gd, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($flat, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($gd);
        imagedestroy($flat);

        $name = 'Im'.(count(self::$images) + 1);
        self::$images[] = ['name' => $name, 'w' => $w, 'h' => $h, 'data' => $jpeg];

        return ['name' => $name, 'w' => $w, 'h' => $h];
    }

    private static function imageAt(?array $img, float $x, float $y, float $w, float $h): string
    {
        if ($img === null) {
            return '';
        }

        return "q\n".self::n($w)." 0 0 ".self::n($h).' '.self::n($x).' '.self::n($y)." cm\n/".$img['name']." Do\nQ\n";
    }

    // ── data helpers ─────────────────────────────────────────────────────────

    private static function rp(float $n): string
    {
        return 'Rp. '.number_format($n, 0, ',', '.');
    }

    private static function tglId(string $ymd, bool $withYear = true): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return $ymd === '' ? '-' : $ymd;
        }
        $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        $hari = self::HARI[(int) date('w', $ts)];
        $bln = self::BULAN[(int) $m[2] - 1];

        return $hari.', '.(int) $m[3].' '.$bln.($withYear ? ' '.$m[1] : '');
    }

    private static function tampilHp(string $p): string
    {
        $x = preg_replace('/\D/', '', $p) ?? '';
        if ($x === '') {
            return '';
        }

        return str_starts_with($x, '62') ? '0'.substr($x, 2) : $x;
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $inv */
    public static function data(array $row, ?array $inv): array
    {
        $dps = is_array($row['dps'] ?? null) ? $row['dps'] : [];
        $total = 0;
        $metode = [];
        foreach ($dps as $p) {
            if (! is_array($p)) {
                continue;
            }
            $total += (int) ($p['amount'] ?? 0);
            $m = trim((string) ($p['method'] ?? ''));
            if ($m !== '' && ! in_array($m, $metode, true)) {
                $metode[] = $m;
            }
        }
        if ($total === 0 && ($row['dpStatus'] ?? '') === 'Sudah') {
            $total = (int) ($row['dpAmount'] ?? 0);
        }
        $no = is_array($inv) && ! empty($inv['no']) ? (string) $inv['no'] : '';
        $resmi = $no !== '';
        if (! $resmi) {
            $no = 'KW/'.str_replace('-', '', (string) ($row['date'] ?? '')).'/'.strtoupper(substr((string) ($row['id'] ?? ''), -4));
        }
        $tgl = (string) ($row['date'] ?? $row['tanggal'] ?? '');
        $jam = substr((string) ($row['time'] ?? $row['jam'] ?? ''), 0, 5);
        $meja = (string) ($row['tables'] ?? $row['table'] ?? '');
        $penanda = (is_array($inv) && is_array($inv['penanda'] ?? null) && $inv['penanda'] !== [])
            ? $inv['penanda']
            : [['nama' => '(Wandi Pranata)', 'jabatan' => 'Finance', 'ttd' => '']];

        return [
            'no' => $no,
            'resmi' => $resmi,
            'nama' => (string) ($row['name'] ?? '-'),
            'hp' => self::tampilHp((string) ($row['phone'] ?? '')),
            'metode' => $metode !== [] ? implode(', ', $metode) : (string) ($row['dpMethod'] ?? '-'),
            'tanggalTerbit' => self::tglId(date('Y-m-d')),
            'keterangan' => 'Reservasi '.((string) ($row['category'] ?? '')) ?: 'Reservasi Tamu Umum',
            'subKeterangan' => trim(self::tglId($tgl).' · '.($jam === '' ? '-' : $jam).' WIB'.($meja !== '' ? ' · Meja '.$meja : '')),
            'pax' => (string) (int) ($row['pax'] ?? 0),
            'total' => $total,
            'penanda' => $penanda,
            'cap' => is_array($inv) ? (string) ($inv['cap'] ?? '') : '',
        ];
    }

    /** @param array<string,mixed> $d @return array{content:string,images:list<array>} */
    public static function build(array $d, ?string $logoBytes, int $now): array
    {
        self::$images = [];
        $logo = self::image((string) ($logoBytes ?? ''));
        $cap = $d['cap'] !== '' ? self::image($d['cap']) : null;
        $ttd = [];
        foreach ((array) $d['penanda'] as $p) {
            $ttd[] = (is_array($p) && ! empty($p['ttd'])) ? self::image((string) $p['ttd']) : null;
        }

        $L = 34.0;
        $R = 561.28;
        $IW = $R - $L;
        $y = 806.0;
        $s = "q\n";

        // ── Kop ──
        $s .= self::imageAt($logo, $L, $y - 56, 56, 56);
        $s .= self::text($L + 68, $y - 16, 14, 'F2', self::PT);
        $s .= self::text($L + 68, $y - 30, 8.2, 'F1', self::ALAMAT1, '.36 .34 .30');
        $s .= self::text($L + 68, $y - 40, 8.2, 'F1', self::ALAMAT2, '.36 .34 .30');
        $s .= self::textRight($R, $y - 18, 20, 'F2', 'KWITANSI', '0 0 0', 2);
        $s .= self::textRight($R, $y - 32, 8.5, 'F1', 'PEMBAYARAN DP', '.36 .34 .30');
        $s .= self::box($L, $y - 56, $IW, 2.2, '.788 .635 .153');
        $y -= 78;

        // ── Meta (two columns) ──
        $row2 = function (float $yy, string $k, string $v, float $x, float $fontSize = 9.5) {
            return self::text($x, $yy, $fontSize, 'F1', $k, '.36 .34 .30')
                .self::text($x + 78, $yy, $fontSize + 0.5, 'F2', $v);
        };
        $yl = $y;
        $yr = $y;
        $s .= $row2($yl, 'Diterima dari', strtoupper(self::cut((string) $d['nama'], 10, 190)), $L);
        $yl -= 15;
        if ($d['hp'] !== '') {
            $s .= $row2($yl, 'No. HP', $d['hp'], $L);
            $yl -= 15;
        }
        $s .= $row2($yl, 'Untuk', 'Down Payment (DP) Reservasi', $L);
        $lblNo = $d['resmi'] ? 'No. Invoice' : 'No. Kwitansi';
        $s .= $row2($yr, $lblNo, self::cut((string) $d['no'], 10, 150), 330.0);
        $yr -= 15;
        $s .= $row2($yr, 'Tanggal Terbit', $d['tanggalTerbit'], 330.0);
        $yr -= 15;
        $s .= $row2($yr, 'Metode Bayar', self::cut((string) $d['metode'], 10, 150), 330.0);
        $y = min($yl, $yr) - 14;

        // ── Item table ──
        $th = 22.0;
        $rw = 34.0;
        $colNo = $L;
        $colKet = $L + 34;
        $colPax = $R - 190;
        $colJml = $R - 120;
        $s .= self::box($L, $y - $th, $IW, $th, '.957 .933 .878');
        $s .= self::rect($L, $y - $th, $IW, $th, '.851 .804 .698', 0.6);
        $s .= self::text($colNo + 10, $y - 15, 8.5, 'F2', 'NO');
        $s .= self::text($colKet + 8, $y - 15, 8.5, 'F2', 'KETERANGAN');
        $s .= self::textRight($colPax + 40, $y - 15, 8.5, 'F2', 'PAX');
        $s .= self::textRight($colJml + 110, $y - 15, 8.5, 'F2', 'JUMLAH');
        $y -= $th;
        $top = $y;
        $y -= $rw;
        $s .= self::text($colNo + 12, $top - 22, 9.5, 'F1', '1');
        $s .= self::text($colKet + 8, $top - 16, 9.5, 'F2', self::cut((string) $d['keterangan'], 9.5, $colPax - $colKet - 20));
        $s .= self::text($colKet + 8, $top - 28, 8.2, 'F1', self::cut((string) $d['subKeterangan'], 8.2, $colPax - $colKet - 20), '.36 .34 .30');
        $s .= self::textRight($colPax + 40, $top - 22, 9.5, 'F1', (string) $d['pax']);
        $s .= self::textRight($colJml + 110, $top - 22, 9.5, 'F1', self::rp((float) $d['total']));
        $s .= self::rect($L, $y, $IW, $top - $y, '.851 .804 .698', 0.6);
        // Total row
        $tot = 30.0;
        $s .= self::box($L, $y - $tot, $IW, $tot, '.980 .965 .925');
        $s .= self::rect($L, $y - $tot, $IW, $tot, '.851 .804 .698', 0.6);
        $s .= self::textRight($colPax + 40, $y - 20, 10, 'F2', 'TOTAL DITERIMA');
        $s .= self::textRight($colJml + 110, $y - 20, 10, 'F2', self::rp((float) $d['total']));
        $y -= $tot + 26;

        // ── Transfer box + badge ──
        $BW = 250.0;
        $BH = 46.0;
        $s .= self::rect($L, $y - $BH, $BW, $BH, '.851 .804 .698', 0.8);
        $s .= self::text($L + 10, $y - 15, 8.5, 'F2', 'Pembayaran dapat ditransfer ke:');
        $s .= self::text($L + 10, $y - 27, 8.5, 'F1', self::BANK);
        $s .= self::text($L + 10, $y - 39, 9, 'F2', self::REK);
        // badge
        $s .= self::box($L, $y - $BH - 30, 118, 24, '.945 .855 .702');
        $s .= self::text($L + 20, $y - $BH - 22, 10, 'F2', 'DP DITERIMA', '.545 .396 .078');

        // ── Signature block(s) ──
        $sx = $R;
        $s .= self::textRight($sx, $y - 6, 9.5, 'F1', 'Hormat Kami,');
        $slotW = 150.0;
        $n = count($ttd);
        for ($i = 0; $i < $n; $i++) {
            $x0 = $sx - ($n - $i) * $slotW;
            $s .= self::imageAt($i === 0 ? $cap : null, $x0 + 20, $y - 74, 78, 78);
            $s .= self::imageAt($ttd[$i], $x0 + 32, $y - 70, 64, 40);
            $p = (array) $d['penanda'][$i];
            $nm = (string) ($p['nama'] ?? '');
            $s .= self::textRight($x0 + $slotW - 8, $y - 92, 9.5, 'F2', $nm);
            $s .= self::line($x0 + 20, $y - 88, $x0 + $slotW - 16, $y - 88, '.11 .10 .08', 0.7);
            $s .= self::textRight($x0 + $slotW - 8, $y - 104, 8.2, 'F1', (string) ($p['jabatan'] ?? ''), '.36 .34 .30');
        }

        // ── Footer note ──
        $y -= 128;
        $s .= self::line($L, $y + 12, $R, $y + 12, '.851 .804 .698', 0.6);
        $s .= self::text($L, $y - 2, 8, 'F1', 'DP ini akan memotong total tagihan saat tamu datang. Mohon simpan kwitansi ini sebagai bukti pembayaran.', '.36 .34 .30');
        $s .= self::text($L, $y - 13, 8, 'F1', 'Dokumen ini dicetak dari sistem Laksamana Muda dan sah tanpa tanda tangan basah.', '.36 .34 .30');
        $s .= "Q\n";

        return ['content' => $s, 'images' => self::$images];
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $inv */
    public static function render(array $row, ?array $inv, int $now): string
    {
        $logoPath = __DIR__.'/../Assets/logo.png';
        $logo = is_file($logoPath) ? (string) file_get_contents($logoPath) : '';
        $built = self::build(self::data($row, $inv), $logo, $now);
        $s = $built['content'];

        $W = 595.28;
        $H = 841.89;
        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[2] = '<< /Type /Pages /Count 1 /Kids [5 0 R] >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $imgRefs = '';
        $id = 7;
        foreach ($built['images'] as $im) {
            $obj[$id] = '<< /Type /XObject /Subtype /Image /Width '.$im['w'].' /Height '.$im['h']
                .' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($im['data'])." >>\nstream\n".$im['data']."\nendstream";
            $imgRefs .= '/'.$im['name'].' '.$id.' 0 R ';
            $id++;
        }
        $obj[5] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::n($W).' '.self::n($H).'] '
            .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >>'.($imgRefs !== '' ? ' /XObject << '.$imgRefs.'>>' : '').' >> /Contents 6 0 R >>';
        if (function_exists('gzcompress')) {
            $z = gzcompress($s, 6);
            $obj[6] = '<< /Length '.strlen($z)." /Filter /FlateDecode >>\nstream\n".$z."\nendstream";
        } else {
            $obj[6] = '<< /Length '.strlen($s)." >>\nstream\n".$s.'endstream';
        }

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offset = [];
        ksort($obj);
        foreach ($obj as $oid => $body) {
            $offset[$oid] = strlen($out);
            $out .= "$oid 0 obj\n".$body."\nendobj\n";
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

        return 'Kwitansi-'.($id === '' ? 'reservasi' : $id).'.pdf';
    }
}
