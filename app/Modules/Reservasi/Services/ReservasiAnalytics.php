<?php

namespace App\Modules\Reservasi\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Compact reads for Office Vue (api#311).
 *
 * The Analitik screen and KompasBri pulled the whole reservation table (tens
 * of thousands of rows, megabytes) and aggregated in the browser because no
 * compact endpoint existed. The rules below are twins of the client they
 * replace, kept in the same order:
 *   analytics <-> performaPeriode() + ringkasKeseluruhan() (app/utils/analitik.ts)
 *   dps       <-> the muatDp flattening (KompasBri.vue) + dpsBulan() (app/utils/cocok-bri.ts)
 * (laksamana-office deploy/reservasi/index.html). READ-ONLY: not one write.
 */
final class ReservasiAnalytics
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(private readonly ReservasiRecords $records) {}

    /**
     * Period performance + whole-range summary for the Analitik screen.
     *
     * harian: rows with date == $date (YYYY-MM-DD). bulanan: rows with date
     * in month $date (YYYY-MM). The summary always covers the whole table,
     * like the client computing ringkasKeseluruhan() over its full list.
     * `label` echoes the input: the client formats it for display
     * (tglPendek / month name); every number is computed here.
     *
     * @return array{periode: array, ringkasan: array}
     */
    public function period(string $mode, string $date): array
    {
        if ($mode === 'bulanan') {
            $rows = array_values(array_filter(
                $this->records->list(null, null),
                fn ($r) => str_starts_with((string) ($r['date'] ?? ''), $date)
            ));
        } else {
            $rows = $this->records->list($date, $date);
        }

        return [
            'periode' => self::performa($rows, $date, $mode === 'bulanan'),
            'ringkasan' => self::ringkas($this->records->list(null, null)),
        ];
    }

    /**
     * Every DP instalment in range, flattened exactly like the old muatDp.
     *
     * The range picks the transfer date OR the reservation date, and
     * keeps instalments without a transfer date (twin of dpsBulan(): OCR
     * could not read the date on many proofs, and dropping them would make
     * them unpickable). Without bounds every instalment counts. Shape = DpBri
     * (KompasBri.vue), `proofData` travelling as stored (`@f:` refs stay refs).
     *
     * @return list<array>
     */
    public function dps(?string $tfFrom, ?string $tfTo): array
    {
        $out = [];
        foreach ($this->records->list(null, null) as $r) {
            $resTgl = (string) ($r['date'] ?? '');
            $dps = isset($r['dps']) && is_array($r['dps']) ? array_values($r['dps']) : [];
            foreach ($dps as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $tfTgl = (string) ($p['tfDate'] ?? '');
                if ($tfTgl === '' || self::inRange($tfTgl, $tfFrom, $tfTo)
                    || ($resTgl !== '' && self::inRange($resTgl, $tfFrom, $tfTo))) {
                    $out[] = self::dpBaris($r, $p);
                }
            }
        }

        return $out;
    }

    // ─────────────────────────── twins ──

    /** Twin of the DpBri flattening in KompasBri.vue (`dps`). */
    private static function dpBaris(array $r, array $p): array
    {
        $tf = self::num($p['tfAmount'] ?? null);
        $n = $tf > 0 ? $tf : self::num($p['amount'] ?? null);

        return [
            'resId' => (string) ($r['id'] ?? ''),
            'dpId' => (string) ($p['id'] ?? ''),
            'nama' => (string) ($r['name'] ?? ''),
            'resTgl' => (string) ($r['date'] ?? ''),
            'tfTgl' => (string) ($p['tfDate'] ?? ''),
            'tfJam' => (string) ($p['tfTime'] ?? ''),
            'bank' => (string) ($p['tfBank'] ?? ''),
            'metode' => (string) ($p['method'] ?? ''),
            'status' => (string) ($p['tfStatus'] ?? ''),
            'nominal' => (int) round($n),
            'diketik' => (int) round(self::num($p['amount'] ?? null)),
            'bukti' => (string) ($p['proofData'] ?? ''),
            'buktiNama' => (string) ($p['proofName'] ?? ''),
        ];
    }

    private static function inRange(string $tgl, ?string $from, ?string $to): bool
    {
        if ($tgl === '') {
            return false;
        }
        if ($from !== null && $from !== '' && $tgl < $from) {
            return false;
        }
        if ($to !== null && $to !== '' && $tgl > $to) {
            return false;
        }

        return true;
    }

    /** Twin of performaPeriode() (app/utils/analitik.ts:44). */
    private static function performa(array $baris, string $label, bool $bulanan): array
    {
        $dipesan = array_values(array_filter($baris, fn ($r) => ($r['source'] ?? null) !== 'Walk-in'));
        $walkin = array_values(array_filter($baris, fn ($r) => ($r['source'] ?? null) === 'Walk-in'));
        $datang = array_values(array_filter($baris, fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'Datang'));
        $dipesanDatang = array_values(array_filter($dipesan, fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'Datang'));
        $noShow = array_values(array_filter($dipesan, fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'No-show'));
        $batal = array_values(array_filter($dipesan, fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'Cancelled'));
        $diharapkan = count($dipesanDatang) + count($noShow);

        $tepatData = [];
        foreach ($dipesanDatang as $r) {
            $l = self::terlambat($r);
            if ($l !== null) {
                $tepatData[] = $l;
            }
        }
        $tepat = count(array_filter($tepatData, fn ($l) => $l <= 10));
        $telatList = array_values(array_filter($tepatData, fn ($l) => $l > 10));

        $durasi = [];
        foreach ($baris as $r) {
            $d = self::durasiDuduk($r);
            if ($d !== null) {
                $durasi[] = $d;
            }
        }
        $masihDuduk = count(array_filter($baris,
            fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'Datang'
                && ! empty($r['checkinAt']) && empty($r['leftAt'])));

        $paxB = 0;
        $paxA = 0;
        foreach ($datang as $r) {
            $paxB += self::paxPesan($r);
            $paxA += self::paxDatang($r);
        }

        $perHari = [];
        if ($bulanan) {
            foreach ($baris as $r) {
                $d = (string) ($r['date'] ?? '');
                $e = $perHari[$d] ?? ['total' => 0, 'hadir' => 0, 'pax' => 0];
                $e['total']++;
                if (self::normStatus((string) ($r['status'] ?? '')) === 'Datang') {
                    $e['hadir']++;
                    $e['pax'] += self::paxDatang($r);
                }
                $perHari[$d] = $e;
            }
            ksort($perHari);
        }
        $trenHarian = [];
        foreach ($perHari as $tgl => $v) {
            $trenHarian[] = ['tgl' => $tgl, 'total' => $v['total'], 'hadir' => $v['hadir'], 'pax' => $v['pax']];
        }
        $tersibuk = null;
        foreach ($trenHarian as $t) {
            if ($tersibuk === null || $t['total'] > $tersibuk['total']) {
                $tersibuk = $t;
            }
        }

        $telatRows = [];
        foreach ($dipesanDatang as $r) {
            $l = self::terlambat($r);
            if ($l !== null && $l > 10) {
                $telatRows[] = ['r' => $r, 'l' => $l];
            }
        }
        usort($telatRows, fn ($a, $b) => $b['l'] <=> $a['l']);
        $palingTelat = array_map(fn ($x) => [
            'nama' => (string) ($x['r']['name'] ?? ''),
            'telat' => $x['l'],
            'booking' => (string) ($x['r']['time'] ?? ''),
            'tgl' => (string) ($x['r']['date'] ?? ''),
            'datang' => (int) ($x['r']['checkinAt'] ?? 0),
        ], array_slice($telatRows, 0, 5));

        $dudukRows = [];
        foreach ($baris as $r) {
            $d = self::durasiDuduk($r);
            if ($d !== null) {
                $dudukRows[] = ['r' => $r, 'd' => $d];
            }
        }
        usort($dudukRows, fn ($a, $b) => $b['d'] <=> $a['d']);
        $dudukTerlama = array_map(fn ($x) => [
            'nama' => (string) ($x['r']['name'] ?? ''),
            'meja' => (string) ($x['r']['table'] ?? '') !== '' ? (string) $x['r']['table'] : '-',
            'durasi' => $x['d'],
        ], array_slice($dudukRows, 0, 5));

        return [
            'label' => $label,
            'reservasi' => count($dipesan),
            'walkin' => count($walkin),
            'hariAktif' => count(array_unique(array_map(fn ($r) => (string) ($r['date'] ?? ''), $baris))),
            'hadir' => count($dipesanDatang),
            'diharapkan' => $diharapkan,
            'kehadiran' => $diharapkan ? (int) round(count($dipesanDatang) / $diharapkan * 100) : 0,
            'tepat' => $tepat,
            'telat' => count($telatList),
            'telatRata' => count($telatList) ? (int) round(array_sum($telatList) / count($telatList)) : 0,
            'tepatPersen' => count($tepatData) ? (int) round($tepat / count($tepatData) * 100) : 0,
            'durasiRata' => count($durasi) ? (int) round(array_sum($durasi) / count($durasi)) : null,
            'durasiN' => count($durasi),
            'masihDuduk' => $masihDuduk,
            'paxPesan' => $paxB,
            'paxDatang' => $paxA,
            'paxRealisasi' => $paxB ? (int) round($paxA / $paxB * 100) : 0,
            'noShow' => count($noShow),
            'batal' => count($batal),
            'tersibuk' => $tersibuk === null ? null : ['tgl' => $tersibuk['tgl'], 'total' => $tersibuk['total']],
            'trenHarian' => $trenHarian,
            'palingTelat' => $palingTelat,
            'dudukTerlama' => $dudukTerlama,
        ];
    }

    /** Twin of ringkasKeseluruhan() (app/utils/analitik.ts:137). */
    private static function ringkas(array $baris): array
    {
        $perSumber = [];
        $perPic = [];
        $perJam = [];
        $perHari = [0, 0, 0, 0, 0, 0, 0];
        $perBulan = [];

        foreach ($baris as $r) {
            $src = (string) ($r['source'] ?? '');
            if ($src !== '') {
                $perSumber[$src] = ($perSumber[$src] ?? 0) + 1;
            }
            $pic = trim((string) ($r['picName'] ?? ''));
            if ($pic === '') {
                $pic = (($r['picType'] ?? null) === 'Marketing') ? 'Marketing' : 'Host/Captain';
            }
            $e = $perPic[$pic] ?? ['total' => 0, 'hadir' => 0];
            $e['total']++;
            if (self::normStatus((string) ($r['status'] ?? '')) === 'Datang') {
                $e['hadir']++;
            }
            $perPic[$pic] = $e;
            // Peak hour and peak day count PAX, never rows.
            $jam = explode(':', (string) ($r['time'] ?? '0'))[0] ?? '0';
            $jam = $jam !== '' ? $jam : '0';
            $perJam[$jam] = ($perJam[$jam] ?? 0) + self::paxPesan($r);
            $d = self::dayOfWeek((string) ($r['date'] ?? ''));
            if ($d !== null) {
                $perHari[$d] += self::paxPesan($r);
            }
            $m = substr((string) ($r['date'] ?? ''), 0, 7);
            if ($m !== '') {
                $perBulan[$m] = ($perBulan[$m] ?? 0) + 1;
            }
        }

        $dinilai = array_values(array_filter($baris,
            fn ($r) => in_array(self::normStatus((string) ($r['status'] ?? '')), ['Datang', 'No-show'], true)));
        $noShow = count(array_filter($baris,
            fn ($r) => self::normStatus((string) ($r['status'] ?? '')) === 'No-show'));
        $totalPax = 0;
        foreach ($baris as $r) {
            $totalPax += self::paxPesan($r);
        }
        $n = count($baris);

        $sumber = [];
        foreach ($perSumber as $label => $c) {
            $sumber[] = ['label' => $label, 'n' => $c];
        }
        usort($sumber, fn ($a, $b) => $b['n'] <=> $a['n']);
        $pic = [];
        foreach ($perPic as $nama => $v) {
            $pic[] = ['nama' => $nama, 'total' => $v['total'],
                'hadirPersen' => $v['total'] ? (int) round($v['hadir'] / $v['total'] * 100) : 0];
        }
        usort($pic, fn ($a, $b) => $b['total'] <=> $a['total']);
        $jamList = [];
        foreach ($perJam as $jam => $pax) {
            $jamList[] = ['jam' => (string) $jam, 'pax' => $pax];
        }
        usort($jamList, fn ($a, $b) => strcmp($a['jam'], $b['jam']));
        $hariList = [];
        foreach (['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'] as $i => $hari) {
            $hariList[] = ['hari' => $hari, 'pax' => $perHari[$i]];
        }
        $bulanList = [];
        foreach ($perBulan as $bulan => $c) {
            $bulanList[] = ['bulan' => $bulan, 'n' => $c];
        }
        usort($bulanList, fn ($a, $b) => strcmp($a['bulan'], $b['bulan']));

        $phones = [];
        foreach ($baris as $r) {
            $phones[] = ReservasiGuests::normPhone($r['phone'] ?? '');
        }

        return [
            'total' => $n,
            'totalPax' => $totalPax,
            'rataPax' => $n ? number_format($totalPax / $n, 1, '.', '') : '0',
            'noShow' => $noShow,
            'dinilai' => count($dinilai),
            'rasioNoShow' => count($dinilai) ? (int) round($noShow / count($dinilai) * 100) : 0,
            'unik' => count(array_unique($phones)),
            'perSumber' => $sumber,
            'perPic' => $pic,
            'perJam' => $jamList,
            'perHari' => $hariList,
            'perBulan' => $bulanList,
        ];
    }

    private static function normStatus(string $s): string
    {
        return ReservasiBrowse::normStatus($s);
    }

    private static function paxPesan(array $r): int
    {
        return (int) self::num($r['pax'] ?? 0);
    }

    /** Twin of arrivedPax() with the client's `|| paxPesan` fallback (app/utils/kunci-meja.ts). */
    private static function paxDatang(array $r): int
    {
        $arr = isset($r['arrivals']) && is_array($r['arrivals']) ? $r['arrivals'] : [];
        $a = 0;
        foreach ($arr as $x) {
            $a += (int) self::num(is_array($x) ? ($x['pax'] ?? 0) : 0);
        }
        if ($arr !== []) {
            return $a;
        }
        if (isset($r['actualPax']) && $r['actualPax'] !== null) {
            return (int) self::num($r['actualPax']);
        }

        return self::paxPesan($r);
    }

    /** Twin of keterlambatanDatang() (app/utils/riwayat.ts); wall clock in Asia/Jakarta. */
    private static function terlambat(array $r): ?int
    {
        if (empty($r['checkinAt']) || empty($r['date']) || empty($r['time'])) {
            return null;
        }
        $sched = self::wall((string) $r['date'], (string) $r['time']);
        if ($sched === null) {
            return null;
        }

        return (int) round(((int) $r['checkinAt'] - $sched * 1000) / 60000);
    }

    /** Twin of durasiDuduk() (app/utils/riwayat.ts). */
    private static function durasiDuduk(array $r): ?int
    {
        if (empty($r['checkinAt']) || empty($r['leftAt'])) {
            return null;
        }

        return max(0, (int) round(((int) $r['leftAt'] - (int) $r['checkinAt']) / 60000));
    }

    /** Twin of `new Date(`${date}T${time}:00`)` evaluated in Asia/Jakarta. */
    private static function wall(string $date, string $time): ?int
    {
        try {
            $dt = new DateTimeImmutable("{$date}T{$time}:00", new DateTimeZone(self::TZ));
        } catch (\Throwable) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /** Twin of `new Date(`${date}T00:00:00`).getDay()` evaluated in Asia/Jakarta. */
    private static function dayOfWeek(string $date): ?int
    {
        if ($date === '') {
            return null;
        }
        try {
            return (int) (new DateTimeImmutable("{$date}T00:00:00", new DateTimeZone(self::TZ)))->format('w');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Twin of rsv_js_num(): numeric or 0. */
    private static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0;
    }
}
