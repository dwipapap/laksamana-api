<?php

namespace App\Modules\Kompas\Services;

use DateTime;
use DateTimeZone;

/**
 * Laporan Omset Harian (PDF) — the same figures as Dashboard Omset
 * (laksamana-office-vue `finance/kas` → bulanan.vue), computed server-side:
 *
 *   gross = food + bev + lainnya
 *   net = gross - discount
 *   taxSvc = service_charge + tax
 *   tagihan = net + taxSvc (what the guest paid)
 *
 * `tanggal` null/empty = the latest date present in `daily` (so a nightly
 * run still reports a back-filled day, e.g. the 15th filled on the 16th).
 */
class LaporanOmset
{
    private const HARI = [0 => 'Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    private const BULAN = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    private const BULAN_PANJANG = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public static function labelTanggal(string $ymd): string
    {
        $p = explode('-', $ymd);
        if (count($p) !== 3 || ! checkdate((int) $p[1], (int) $p[2], (int) $p[0])) {
            return $ymd;
        }
        $ts = mktime(0, 0, 0, (int) $p[1], (int) $p[2], (int) $p[0]);

        return self::HARI[(int) date('w', $ts)].', '.((int) $p[2]).' '.self::BULAN[(int) $p[1]].' '.$p[0];
    }

    public static function labelBulan(string $ym): string
    {
        $p = explode('-', $ym);

        return (self::BULAN_PANJANG[(int) ($p[1] ?? 0)] ?? $ym).' '.($p[0] ?? '');
    }

    public static function rp(float|int $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

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

    public static function labelSekarang(): string
    {
        $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

        return ((int) $dt->format('j')).' '.self::BULAN_PANJANG[(int) $dt->format('n')].' '.$dt->format('Y').' pukul '.$dt->format('H.i');
    }

    /** Aggregate one or more day rows into dashboard figures. */
    public static function ringkas(array $rows, array $compliments): array
    {
        $n = fn ($d, $k) => KompasState::num($d[$k] ?? 0);
        $t = ['food' => 0, 'bev' => 0, 'lainnya' => 0, 'discount' => 0, 'service' => 0,
            'tax' => 0, 'traffic' => 0, 'bill' => 0, 'qfood' => 0, 'qbev' => 0, 'qothers' => 0, 'self' => 0];
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
            $t['self'] += $n(is_array($self) ? $self : [], 'amount') - $n(is_array($self) ? $self : [], 'disc');
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
            'totalItem' => $totalItem, 'self' => $t['self'], 'comp' => $comp,
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
