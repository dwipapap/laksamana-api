<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisRosterEvent;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;
use App\Support\JsonDoc;

/**
 * The roster in HRIS (J6–J7, ADR-0012): the monthly grid per shift Divisi,
 * cell writes, the warnings, the recap and "Jadwal Saya". Cells stay in
 * Jadwal's storage and are written through JadwalService, so the old panel
 * shows the same roster during the parallel run.
 *
 * Who writes: HR any shift Divisi (and Nonshift crew), a Kepala Divisi only
 * the crew of the Divisi they head. The legacy "anyone while no head is
 * configured" rule does not apply here: HRIS checks every row itself and
 * then writes with jadwal admin authority.
 */
final class HrisRosterService
{
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly HrisShiftService $shift,
        private readonly HrisAuditService $audit,
        private readonly HrisRosterEventService $event,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** @return array{0:string,1:string} first and last day of YYYY-MM */
    private static function rentang(string $bulan): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }

        return [$bulan.'-01', date('Y-m-t', strtotime($bulan.'-01'))];
    }

    /** @return array<int,array{user_id:string,nama:string,jabatan:?string}> active crew of one Divisi */
    public function kru(string $divisi): array
    {
        $jabatan = HrisKaryawan::query()->with('jabatan')->get()
            ->mapWithKeys(fn (HrisKaryawan $k) => [$k->user_id => $k->jabatan?->nama])->all();
        $out = [];
        foreach ($this->jadwal->rosterWithDivisi() as $r) {
            $r = (array) $r;
            if ((string) ($r['divisi'] ?? '') !== $divisi || (int) ($r['active'] ?? 1) !== 1) {
                continue;
            }
            $uid = (string) ($r['id'] ?? '');
            $out[] = ['user_id' => $uid, 'nama' => (string) ($r['name'] ?? $uid), 'jabatan' => $jabatan[$uid] ?? null];
        }
        usort($out, fn ($a, $b) => strcmp(mb_strtolower($a['nama']), mb_strtolower($b['nama'])));

        return $out;
    }

    /** The monthly grid of one shift Divisi. */
    public function bulan(string $bulan, string $divisi): array
    {
        [$dari, $sampai] = self::rentang($bulan);
        $kru = $this->kru($divisi);
        $ids = array_flip(array_column($kru, 'user_id'));
        $sel = array_values(array_filter($this->jadwal->readAll($dari, $sampai)['sel'],
            fn ($c) => isset($ids[(string) $c['u']])));
        $dipakai = array_flip(array_map(fn ($c) => (string) $c['t'], $sel));
        $shift = array_values(array_filter($this->shift->list(), fn ($x) => isset($dipakai[$x['kode']])
            || ($x['aktif'] && ($x['divisi'] === [] || in_array($divisi, $x['divisi'], true)))));
        $hari = [];
        for ($d = $dari; $d <= $sampai; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
            $hari[] = $d;
        }

        return ['bulan' => $bulan, 'divisi' => $divisi, 'hari' => $hari, 'kru' => $kru,
            'sel' => $sel, 'shift' => $shift, 'aturan' => $this->aturan()];
    }

    /**
     * Write cells. $bolehDivisi = the shift Divisi the caller may edit, null
     * for HR (every Divisi and Nonshift crew).
     *
     * @param  array<int,string>|null  $bolehDivisi
     * @return array{isi:int,hapus:int,peringatan:array<int,string>}
     */
    public function simpan(?array $bolehDivisi, array $isi, array $hapus, string $by): array
    {
        $defs = [];
        foreach ($this->shift->list() as $x) {
            $defs[$x['kode']] = $x;
        }
        $rows = [];
        $hapusRows = [];
        $orang = [];
        foreach ($isi as $c) {
            $c = is_array($c) ? $c : [];
            [$uid, $tgl] = $this->assertBaris($c, $bolehDivisi);
            $t = self::s($c['t'] ?? '');
            $def = $defs[$t] ?? throw new HrisError('validation_failed', "Shift {$t} tidak dikenal.", 422);
            if (! $def['aktif']) {
                throw new HrisError('validation_failed', "Shift {$def['nama']} sudah nonaktif.", 422);
            }
            $div = $this->jadwal->divisiUser($uid);
            if ($def['divisi'] !== [] && ! in_array($div, $def['divisi'], true)) {
                throw new HrisError('validation_failed', "Shift {$def['nama']} bukan untuk Divisi {$div}.", 422);
            }
            // A block shift takes its hours from the master (J4).
            $berblok = count($def['blok']) >= 2;
            $rows[] = ['u' => $uid, 'd' => $tgl, 't' => $t,
                'm' => $berblok ? '' : self::jam($c['m'] ?? ''), 's' => $berblok ? '' : self::jam($c['s'] ?? ''),
                'n' => mb_substr(self::s($c['n'] ?? ''), 0, 120)];
            $orang[$uid][] = $tgl;
        }
        foreach ($hapus as $c) {
            $c = is_array($c) ? $c : [];
            [$uid, $tgl] = $this->assertBaris($c, $bolehDivisi);
            $hapusRows[] = ['u' => $uid, 'd' => $tgl];
            $orang[$uid][] = $tgl;
        }
        if ($rows === [] && $hapusRows === []) {
            return ['isi' => 0, 'hapus' => 0, 'peringatan' => []];
        }
        $kunci = $this->kunciTersentuh($rows, $hapusRows);
        $lama = $this->selUntuk($kunci);
        $r = $this->jadwal->saveCells($rows, $hapusRows, 'HRIS:'.$by,
            ['id' => '', 'name' => $by, 'adminModules' => ['jadwal']]);
        $this->audit->record($by, 'roster_disimpan', 'jadwal_sel', '', ['isi' => count($rows), 'hapus' => count($hapusRows)]);
        $this->catatEvent($kunci, $lama, $by);

        return ['isi' => (int) ($r['isi'] ?? count($rows)), 'hapus' => (int) ($r['hapus'] ?? count($hapusRows)),
            'peringatan' => $this->peringatan($orang, $defs)];
    }

    /** @return array<string,array<string,mixed>> key `u|d` => row (+hapus flag) */
    private function kunciTersentuh(array $rows, array $hapusRows): array
    {
        $kunci = [];
        foreach ($rows as $c) {
            $kunci[(string) ($c['u'] ?? '').'|'.(string) ($c['d'] ?? '')] = $c;
        }
        foreach ($hapusRows as $c) {
            $kunci[(string) ($c['u'] ?? '').'|'.(string) ($c['d'] ?? '')] = $c + ['hapus' => true];
        }

        return $kunci;
    }

    /** Current cells for the touched keys, read BEFORE the write. */
    private function selUntuk(array $kunci): array
    {
        $perOrang = [];
        foreach (array_keys($kunci) as $k) {
            [$uid, $d] = explode('|', $k, 2) + [null, null];
            if ($uid) {
                $perOrang[$uid][] = $d;
            }
        }
        $out = [];
        foreach ($perOrang as $uid => $tgl) {
            sort($tgl);
            foreach ($this->jadwal->shiftRange($uid, $tgl[0], $tgl[count($tgl) - 1])['rows'] ?? [] as $c) {
                $c = (array) $c;
                $out[(string) $c['u'].'|'.(string) $c['d']] = $c;
            }
        }

        return $out;
    }

    /** One roster event per cell the manual/batch/clear write changed. */
    private function catatEvent(array $kunci, array $lama, string $by): void
    {
        if ($kunci === []) {
            return;
        }
        $uids = [];
        foreach (array_keys($kunci) as $k) {
            [$uid] = explode('|', $k, 2) + [null];
            if ($uid) {
                $uids[$uid] = true;
            }
        }
        $divisi = [];
        foreach (HrisKaryawan::query()->with('divisi')->whereIn('user_id', array_keys($uids))->get() as $k) {
            $divisi[(string) $k->user_id] = $k->divisi;
        }
        foreach ($kunci as $k => $c) {
            [$uid, $tgl] = explode('|', $k, 2) + [null, null];
            $sebelum = $lama[$k] ?? null;
            $sesudah = ! empty($c['hapus']) ? null : ['kode' => (string) ($c['t'] ?? ''),
                'm' => self::s($c['m'] ?? ''), 's' => self::s($c['s'] ?? ''), 'n' => self::s($c['n'] ?? '')];
            if ($this->selSama($sebelum, $sesudah)) {
                continue;
            }
            $this->event->catat(HrisRosterEvent::EDIT_MANUAL, $by, [
                'user_id' => (string) $uid,
                'divisi_kode' => ($divisi[(string) $uid] ?? null)?->kode,
                'divisi_nama' => ($divisi[(string) $uid] ?? null)?->nama,
                'tanggal_kerja' => (string) $tgl,
                'sel_lama' => $sebelum === null ? null : $this->potretSel($sebelum),
                'sel_baru' => $sesudah === null ? null : $this->potretSel($sesudah),
            ]);
        }
    }

    private static function selSama(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) ($a['t'] ?? '') === (string) ($b['kode'] ?? '')
            && self::s($a['m'] ?? '') === self::s($b['m'] ?? '')
            && self::s($a['s'] ?? '') === self::s($b['s'] ?? '');
    }

    private static function potretSel(array $c): array
    {
        return ['kode' => (string) ($c['t'] ?? $c['kode'] ?? ''), 'm' => self::s($c['m'] ?? ''),
            's' => self::s($c['s'] ?? ''), 'n' => self::s($c['n'] ?? '')];
    }

    /** @return array{0:string,1:string} [userId, date] after the Divisi check */
    private function assertBaris(array $c, ?array $bolehDivisi): array
    {
        $uid = self::s($c['u'] ?? '');
        $tgl = PunchRules::tglValid($c['d'] ?? '');
        if ($uid === '' || $tgl === '') {
            throw new HrisError('validation_failed', 'Setiap sel butuh u dan d (YYYY-MM-DD).', 422);
        }
        if ($bolehDivisi !== null) {
            $div = $this->jadwal->divisiUser($uid);
            if ($div === Divisi::NONSHIFT || ! in_array($div, $bolehDivisi, true)) {
                throw new HrisError('forbidden', 'Jadwal kru ini hanya bisa diatur HR atau Kepala Divisinya.', 403);
            }
        }

        return [$uid, $tgl];
    }

    /**
     * J7 warnings (never a block): more than maksBeruntun working days in a
     * row, or less than jedaMin hours between two shifts, around the dates
     * just written.
     *
     * @param  array<string,array<int,string>>  $orang  userId => touched dates
     * @param  array<string,array<string,mixed>>  $defs
     * @return array<int,string>
     */
    private function peringatan(array $orang, array $defs): array
    {
        $aturan = $this->aturan();
        $maks = $aturan['maksBeruntun'];
        $out = [];
        foreach ($orang as $uid => $tgl) {
            sort($tgl);
            $dari = date('Y-m-d', strtotime($tgl[0].' -'.$maks.' days'));
            $sampai = date('Y-m-d', strtotime(end($tgl).' +'.$maks.' days'));
            $sel = [];
            foreach ($this->jadwal->shiftRange($uid, $dari, $sampai)['rows'] as $r) {
                $sel[(string) $r['d']] = $r;
            }
            $nama = $uid;
            foreach ($this->jadwal->rosterWithDivisi() as $x) {
                $x = (array) $x;
                if ((string) ($x['id'] ?? '') === $uid) {
                    $nama = (string) ($x['name'] ?? $uid);
                    break;
                }
            }
            $beruntun = 0;
            $terpanjang = 0;
            $jedaMin = null;
            for ($d = $dari; $d <= $sampai; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
                $c = $sel[$d] ?? null;
                $kerja = $c !== null && empty($c['libur']);
                $beruntun = $kerja ? $beruntun + 1 : 0;
                $terpanjang = max($terpanjang, $beruntun);
                $besok = $sel[date('Y-m-d', strtotime($d.' +1 day'))] ?? null;
                if ($kerja && $besok !== null && empty($besok['libur'])) {
                    $jeda = self::jedaJam($c, $besok);
                    if ($jeda !== null && ($jedaMin === null || $jeda < $jedaMin)) {
                        $jedaMin = $jeda;
                    }
                }
            }
            if ($terpanjang > $maks) {
                $out[] = "{$nama}: {$terpanjang} hari masuk beruntun tanpa libur (batas {$maks}).";
            }
            if ($jedaMin !== null && $jedaMin < $aturan['jedaMin']) {
                $out[] = "{$nama}: jeda antar shift hanya {$jedaMin} jam (batas {$aturan['jedaMin']}).";
            }
        }

        return $out;
    }

    /** Hours between the end of one day's shift and the start of the next day's. */
    private static function jedaJam(array $hariIni, array $besok): ?float
    {
        $m1 = PunchRules::jamKeMenit($hariIni['m'] ?? '');
        $s1 = PunchRules::jamKeMenit($hariIni['s'] ?? '');
        $m2 = PunchRules::jamKeMenit($besok['m'] ?? '');
        if ($m1 < 0 || $s1 < 0 || $m2 < 0) {
            return null;
        }
        $selesai = $s1 <= $m1 ? $s1 + 1440 : $s1;

        return round((1440 + $m2 - $selesai) / 60, 1);
    }

    /** @return array{maksBeruntun:int,jedaMin:int} */
    public function aturan(): array
    {
        $b = JsonDoc::toArray($this->jadwal->setting());

        return ['maksBeruntun' => (int) ($b['maksBeruntun'] ?? 0) > 0 ? (int) $b['maksBeruntun'] : 6,
            'jedaMin' => (int) ($b['jedaMin'] ?? 0) > 0 ? (int) $b['jedaMin'] : 10];
    }

    public function simpanAturan(array $in, string $by): array
    {
        $b = JsonDoc::toArray($this->jadwal->setting());
        foreach (['maksBeruntun' => [1, 31], 'jedaMin' => [0, 24]] as $k => [$min, $max]) {
            if (! array_key_exists($k, $in)) {
                continue;
            }
            if (! is_numeric($in[$k]) || (int) $in[$k] < $min || (int) $in[$k] > $max) {
                throw new HrisError('validation_failed', "{$k} harus {$min}–{$max}.", 422);
            }
            $b[$k] = (int) $in[$k];
        }
        $this->jadwal->saveSetting($b, 'HRIS:'.$by);
        $this->audit->record($by, 'aturan_roster_disimpan', 'jadwal_setting', '', $in);

        return $this->aturan();
    }

    /** J6 recap: per crew member, shift counts, working and off days, empty days. */
    public function rekap(string $bulan, string $divisi): array
    {
        $g = $this->bulan($bulan, $divisi);
        $defs = [];
        foreach ($g['shift'] as $x) {
            $defs[$x['kode']] = $x;
        }
        $per = [];
        foreach ($g['sel'] as $c) {
            $per[(string) $c['u']][(string) $c['d']] = (string) $c['t'];
        }
        $out = [];
        foreach ($g['kru'] as $k) {
            $sel = $per[$k['user_id']] ?? [];
            $hitung = array_count_values($sel);
            $kerja = 0;
            foreach ($sel as $t) {
                if (! ($defs[$t]['libur'] ?? false)) {
                    $kerja++;
                }
            }
            $out[] = $k + ['per_shift' => $hitung, 'hari_kerja' => $kerja,
                'hari_libur' => count($sel) - $kerja, 'kosong' => count($g['hari']) - count($sel)];
        }

        return ['bulan' => $bulan, 'divisi' => $divisi, 'shift' => $g['shift'], 'kru' => $out];
    }

    /** "Jadwal Saya": the caller's own cells with their shift's name, colour and blocks. */
    public function jadwalSaya(string $userId, string $dari, string $sampai): array
    {
        $dari = PunchRules::tglValid($dari);
        $sampai = PunchRules::tglValid($sampai);
        if ($dari === '' || $sampai === '' || $sampai < $dari || strtotime($sampai) - strtotime($dari) > 62 * 86400) {
            throw new HrisError('validation_failed', 'Rentang dari–sampai harus YYYY-MM-DD, paling panjang 62 hari.', 422);
        }
        $defs = [];
        foreach ($this->shift->list() as $x) {
            $defs[$x['kode']] = $x;
        }
        $out = [];
        foreach ($this->jadwal->shiftRange($userId, $dari, $sampai)['rows'] as $r) {
            $d = $defs[(string) $r['t']] ?? null;
            $out[] = ['tanggal' => (string) $r['d'], 'kode' => (string) $r['t'],
                'nama' => $d['nama'] ?? (string) $r['t'], 'warna' => $d['warna'] ?? '',
                'mulai' => (string) $r['m'], 'selesai' => (string) $r['s'],
                'blok' => count($d['blok'] ?? []) >= 2 ? $d['blok'] : [],
                'libur' => ! empty($r['libur'])];
        }
        usort($out, fn ($a, $b) => strcmp($a['tanggal'], $b['tanggal']));

        return $out;
    }

    private static function jam(mixed $v): string
    {
        $v = self::s($v);
        if ($v !== '' && PunchRules::jamKeMenit($v) < 0) {
            throw new HrisError('validation_failed', 'Jam harus HH:MM.', 422);
        }

        return $v;
    }
}
