<?php

namespace App\Modules\Kompas\Services;

use DateTime;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Laporan Dashboard Omset — Harian (PDF, dompdf, A4 portrait).
 *
 * Angka mengikuti Dashboard Omset (laksamana-office-vue `finance/kas`):
 *
 *   gross   = food + bev + lainnya
 *   net     = gross − discount                ← "omset net"
 *   taxSvc  = service_charge + tax
 *   tagihan = net + taxSvc                    ← "Realisasi (tagihan)"
 *   totalItem = qty_food + qty_bev + qty_others
 *   UPT = totalItem ÷ bill
 *   ATV = net ÷ bill            (Rupiah)
 *   APS = traffic ÷ bill
 *   kompo = food + bev + lainnya; rasio tiap bagian = bagian ÷ kompo × 100
 *   self = self_amount − self_disc; selfPct = self ÷ net × 100
 *   lewatKasir = net − self
 *   Rata-rata per hari (mode harian) = tagihan ÷ 1
 *   Rata-rata per hari (bulan) = bulan.tagihan ÷ bulan.hari_terinput
 *
 * Pembagian dengan nol tampil sebagai "—" (bukan 0, bukan error).
 *
 * Contoh pemanggilan dengan data dummy + stream ke browser:
 *
 *   $pdf = LaporanOmset::buatPdfOmsetHarian([
 *       'food' => 11405800, 'bev' => 9952014, 'lainnya' => 345000,
 *       'discount' => 315200, 'tax' => 2070781, 'service_charge' => 1035391,
 *       'traffic' => 284, 'bill' => 141,
 *       'qty_food' => 288, 'qty_bev' => 517, 'qty_others' => 9,
 *       'self_amount' => 3656000, 'self_disc' => 0,
 *       'compliment_total' => 1051100,
 *       'tanggal' => '2026-10-15', 'nama_pencetak' => 'AI Agent',
 *       'waktu_cetak' => '16 Oktober 2026 pukul 22.50',
 *       'bulan' => ['tagihan' => 459184816, 'net' => 405042035, 'hari_terinput' => 15],
 *   ]);
 *   $dompdf = new Dompdf; // di dalam fungsi; untuk stream manual:
 *   return response($pdf, 200, [
 *       'Content-Type' => 'application/pdf',
 *       'Content-Disposition' => 'attachment; filename="Dashboard-Omset-2026-10-15.pdf"',
 *   ]);
 *   // atau langsung: $dompdf->stream('Dashboard-Omset-2026-10-15.pdf', ['Attachment' => true]);
 */
class LaporanOmset
{
    private const HARI = [
        0 => 'Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu',
    ];

    private const HARI_PENDEK = [
        0 => 'Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab',
    ];

    private const BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    private const BULAN_PENDEK = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    // ── format ──

    /** Rupiah: Rp1.234.567 (titik ribuan, tanpa desimal, tanpa spasi). */
    public static function rupiah(float|int $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /** Persen 1 desimal koma desimal: 45,3%. */
    public static function persen(float|int $n): string
    {
        return number_format((float) $n, 1, ',', '.').'%';
    }

    /** Desimal gaya Indonesia 2 angka: 1,25. */
    public static function des2(float|int $n): string
    {
        return number_format((float) $n, 2, ',', '.');
    }

    public static function int(float|int $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** Tanggal gaya id-ID: Kamis, 15 Okt 2026 (bulan pendek, ikut gambar acuan). */
    public static function labelTanggal(string $ymd): string
    {
        $p = explode('-', $ymd);
        if (count($p) !== 3 || ! checkdate((int) $p[1], (int) $p[2], (int) $p[0])) {
            return $ymd;
        }
        $ts = mktime(0, 0, 0, (int) $p[1], (int) $p[2], (int) $p[0]);

        return self::HARI[(int) date('w', $ts)].', '.((int) $p[2]).' '.self::BULAN_PENDEK[(int) $p[1]].' '.$p[0];
    }

    public static function labelBulan(string $ym): string
    {
        $p = explode('-', $ym);

        return (self::BULAN[(int) ($p[1] ?? 0)] ?? $ym).' '.($p[0] ?? '');
    }

    public static function labelSekarang(): string
    {
        $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

        return ((int) $dt->format('j')).' '.self::BULAN[(int) $dt->format('n')].' '.$dt->format('Y').' pukul '.$dt->format('H.i');
    }

    public static function rp(float|int $n): string
    {
        return self::rupiah($n);
    }

    // ── hitung ──

    /**
     * Hitung angka + string tampilan satu hari. Semua teks dari data
     * di-escape; pembagi nol menghasilkan "—".
     */
    public static function hitungHarian(array $data): array
    {
        $n = fn ($k) => KompasState::num($data[$k] ?? 0);
        $food = $n('food');
        $bev = $n('bev');
        $lainnya = $n('lainnya');
        $discount = $n('discount');
        $tax = $n('tax');
        $svc = $n('service_charge');
        $traffic = $n('traffic');
        $bill = $n('bill');
        $self = $n('self_amount') - $n('self_disc');

        $gross = $food + $bev + $lainnya;
        $net = $gross - $discount;
        $taxSvc = $svc + $tax;
        $tagihan = $net + $taxSvc;
        $totalItem = $n('qty_food') + $n('qty_bev') + $n('qty_others');
        $dash = '—';

        return [
            'food' => self::rupiah($food), 'bev' => self::rupiah($bev), 'lainnya' => self::rupiah($lainnya),
            'rFood' => $gross > 0 ? self::persen($food / $gross * 100) : $dash,
            'rBev' => $gross > 0 ? self::persen($bev / $gross * 100) : $dash,
            'rLain' => $gross > 0 ? self::persen($lainnya / $gross * 100) : $dash,
            'kompo' => self::rupiah($gross),
            'qfood' => self::int($n('qty_food')), 'qbev' => self::int($n('qty_bev')),
            'qothers' => self::int($n('qty_others')), 'totalItem' => self::int($totalItem),
            'upt' => $bill > 0 ? self::des2($totalItem / $bill) : $dash,
            'atv' => $bill > 0 ? self::rupiah($net / $bill) : $dash,
            'aps' => $bill > 0 ? self::des2($traffic / $bill) : $dash,
            'bill' => self::int($bill), 'traffic' => self::int($traffic),
            'self' => self::rupiah($self),
            'selfPct' => $net > 0 ? self::persen($self / $net * 100) : $dash,
            'kasir' => self::rupiah($net - $self),
            'svc' => self::rupiah($svc), 'tax' => self::rupiah($tax), 'taxSvc' => self::rupiah($taxSvc),
            'discount' => self::rupiah($discount),
            'comp' => self::rupiah($n('compliment_total')),
            'net' => self::rupiah($net), 'tagihan' => self::rupiah($tagihan),
        ];
    }

    /**
     * Bangun PDF A4 portrait dari data SATU HARI (+ ringkasan bulan).
     * Memulangkan biner PDF (tidak menulis berkas apa pun ke disk).
     */
    public static function buatPdfOmsetHarian(array $data): string
    {
        $h = self::hitungHarian($data);
        $b = $data['bulan'] ?? [];
        $bTagihan = KompasState::num($b['tagihan'] ?? 0);
        $bNet = KompasState::num($b['net'] ?? 0);
        $bHari = KompasState::num($b['hari_terinput'] ?? 0);
        $tanggal = (string) ($data['tanggal'] ?? '');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $logo = '';
        foreach ([public_path('img/logo-laksamana-emas.png')] as $f) {
            if (is_file($f)) {
                $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents($f));
                break;
            }
        }
        $mk = substr($tanggal, 0, 7);

        $html = view('kompas.laporan-omset', [
            'h' => $h,
            'labelTanggal' => self::labelTanggal($tanggal),
            'labelBulan' => self::labelBulan($mk),
            'bulanTagihan' => self::rupiah($bTagihan),
            'bulanNet' => self::rupiah($bNet),
            'bulanHari' => $bHari > 0 ? self::int($bHari).' hari' : 'belum ada data',
            'bulanRata' => $bHari > 0 ? self::rupiah($bTagihan / $bHari) : '—',
            'hariTerinput' => self::semuaNol($data) ? 'belum ada data' : '1 hari',
            'oleh' => $e($data['nama_pencetak'] ?? 'AI Agent'),
            'waktuCetak' => $e($data['waktu_cetak'] ?? self::labelSekarang()),
            'logo' => $logo,
        ])->render();

        $opt = new Options;
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('isRemoteEnabled', false);
        $opt->set('isFontSubsettingEnabled', true);
        $opt->set('dpi', 96);
        $opt->set('defaultMediaType', 'print');
        $dompdf = new Dompdf($opt);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private static function semuaNol(array $data): bool
    {
        foreach (['food', 'bev', 'lainnya', 'discount', 'tax', 'service_charge', 'traffic', 'bill',
            'qty_food', 'qty_bev', 'qty_others', 'self_amount', 'self_disc', 'compliment_total'] as $k) {
            if (KompasState::num($data[$k] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    // ── blob → endpoint ──

    /** Build the report payload. Returns null when there is no daily data at all. */
    public static function build(array $s, ?string $tanggal): ?array
    {
        $daily = array_values(array_filter(
            is_array($s['daily'] ?? null) ? $s['daily'] : [],
            fn ($d) => is_array($d) && isset($d['date'])
        ));
        if ($daily === []) {
            return null;
        }
        $dates = array_column($daily, 'date');
        if ($tanggal === null || $tanggal === '') {
            $tanggal = max($dates);
        }
        $hari = array_values(array_filter($daily, fn ($d) => ($d['date'] ?? null) === $tanggal));
        if ($hari === []) {
            return null;
        }
        $mk = substr($tanggal, 0, 7);
        $bulan = array_values(array_filter($daily, fn ($d) => str_starts_with((string) ($d['date'] ?? ''), $mk)));
        $compliments = array_values(array_filter(
            is_array($s['compliments'] ?? null) ? $s['compliments'] : [],
            fn ($c) => is_array($c) && (string) ($c['date'] ?? '') === $tanggal
        ));

        return [
            'tanggal' => $tanggal,
            'labelTanggal' => self::labelTanggal($tanggal),
            'labelBulan' => self::labelBulan($mk),
            'hari' => self::ringkas($hari, $compliments),
            'bulan' => self::ringkas($bulan, []),
            'bulanHari' => count($bulan),
            'oleh' => 'AI Agent',
            'dicetakPada' => self::labelSekarang(),
        ];
    }

    /** Aggregate one or more day rows into dashboard figures. */
    public static function ringkas(array $rows, array $compliments): array
    {
        $n = fn ($d, $k) => KompasState::num($d[$k] ?? 0);
        $t = ['food' => 0, 'bev' => 0, 'lainnya' => 0, 'discount' => 0, 'service' => 0,
            'tax' => 0, 'traffic' => 0, 'bill' => 0, 'qfood' => 0, 'qbev' => 0, 'qothers' => 0,
            'self' => 0, 'selfAmount' => 0, 'selfDisc' => 0];
        foreach ($rows as $d) {
            $t['food'] += $n($d, 'food');
            $t['bev'] += $n($d, 'bev');
            $t['lainnya'] += $n($d, 'lainnya');
            $t['discount'] += $n($d, 'discount');
            $t['service'] += $n($d, 'service_charge');
            $t['tax'] += $n($d, 'tax');
            $t['traffic'] += $n($d, 'traffic');
            $t['bill'] += $n($d, 'bill');
            $t['qfood'] += $n($d, 'qty_food');
            $t['qbev'] += $n($d, 'qty_bev');
            $t['qothers'] += $n($d, 'qty_others');
            $bd = $d['bd'] ?? null;
            $self = is_array($bd) ? ($bd['self'] ?? null) : null;
            $sa = $n(is_array($self) ? $self : [], 'amount');
            $sd = $n(is_array($self) ? $self : [], 'disc');
            $t['selfAmount'] += $sa;
            $t['selfDisc'] += $sd;
            $t['self'] += $sa - $sd;
        }
        $gross = $t['food'] + $t['bev'] + $t['lainnya'];
        $net = $gross - $t['discount'];
        $taxSvc = $t['service'] + $t['tax'];
        $tagihan = $net + $taxSvc;
        $totalItem = $t['qfood'] + $t['qbev'] + $t['qothers'];
        $comp = 0;
        foreach ($compliments as $c) {
            $comp += KompasState::num($c['nominal'] ?? 0);
        }
        $pct = fn ($a, $b) => $b > 0 ? $a / $b * 100 : 0;

        return [
            'food' => $t['food'], 'bev' => $t['bev'], 'lainnya' => $t['lainnya'],
            'discount' => $t['discount'], 'service' => $t['service'], 'tax' => $t['tax'],
            'svcOnly' => $t['service'], 'taxOnly' => $t['tax'],
            'traffic' => $t['traffic'], 'bill' => $t['bill'],
            'qfood' => $t['qfood'], 'qbev' => $t['qbev'], 'qothers' => $t['qothers'],
            'gross' => $gross, 'net' => $net, 'taxSvc' => $taxSvc, 'tagihan' => $tagihan,
            'totalItem' => $totalItem, 'self' => $t['self'],
            'selfAmount' => $t['selfAmount'], 'selfDisc' => $t['selfDisc'],
            'comp' => $comp,
            'kasir' => $net - $t['self'],
            'avg' => $t['bill'] > 0 ? $net / $t['bill'] : 0,
            'upt' => $t['bill'] > 0 ? $totalItem / $t['bill'] : 0,
            'atv' => $t['bill'] > 0 ? $net / $t['bill'] : 0,
            'aps' => $t['bill'] > 0 ? $t['traffic'] / $t['bill'] : 0,
            'rFood' => $pct($t['food'], $gross), 'rBev' => $pct($t['bev'], $gross), 'rLain' => $pct($t['lainnya'], $gross),
            'selfPct' => $pct($t['self'], $net),
        ];
    }
}
