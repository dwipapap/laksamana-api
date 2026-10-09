<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Master\Imports\MenulisImpor;
use App\Erp\Master\Imports\PencocokUser;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Tamu\Models\DaftarTunggu;
use App\Erp\Tamu\Models\KategoriReservasi;
use App\Erp\Tamu\Models\Meja;
use App\Erp\Tamu\Models\Reservasi;
use App\Erp\Tamu\Models\ReservasiDp;
use App\Erp\Tamu\Models\ReservasiKedatangan;
use App\Erp\Tamu\Models\ReservasiTindakLanjut;
use App\Erp\Tamu\Models\SumberInfo;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Reservasi (docs/erp/reservasi.md "Pemetaan lama → v2") from the
 * legacy reservasi blobs. Run after `erp-kas` (metode_bayar). Idempotent.
 *
 * Legacy table names are free text, often several tables ("11, 12"); the text
 * is kept in meja_impor and meja_id is set only for an exact single match.
 * Proofs stay where they are: the legacy file key ("@f:…") is kept.
 */
final class ReservasiImporter implements Importer, ReportsIssues
{
    use MenulisImpor;

    private const STATUS = ['Pending' => 'pending', 'Confirmed' => 'confirmed', 'Booking' => 'confirmed', 'Datang' => 'datang',
        'Checked-in' => 'datang', 'Completed' => 'datang', 'Cancelled' => 'cancelled', 'No-show' => 'no_show'];

    /** Legacy DP method labels => metode_bayar kode; others keep their text in metode_impor. */
    private const METODE = ['cash' => 'cash', 'qris bri' => 'qris_bri', 'qris bca' => 'qris_bca', 'qris mandiri' => 'qris_mandiri', 'transfer uob' => 'transfer_uob'];

    /** @var array<string, int> legacy table text => reservations */
    private array $mejaTeks = [];

    /** @var array<string, int> legacy DP method label => instalments */
    private array $metodeTeks = [];

    public function __construct(private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-reservasi';
    }

    public function legacyConnections(): array
    {
        return ['legacy_reservasi'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function import(): int
    {
        $this->issues = $this->mejaTeks = $this->metodeTeks = [];
        $this->users->reset();
        $legacy = DB::connection('legacy_reservasi');
        $master = $this->json($legacy->table('settings')->where('k', 'master')->value('v'));
        $count = 0;

        DB::connection('core')->transaction(function () use ($legacy, $master, &$count): void {
            $outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
            $meja = $this->importMeja($outlet, (array) ($master['layouts'] ?? []));
            $kategori = $this->daftar(KategoriReservasi::class, (array) ($master['categories'] ?? []));
            $sumber = $this->daftar(SumberInfo::class, (array) ($master['infoSources'] ?? []));
            $metode = MetodeBayar::withTrashed()->pluck('id', 'kode')->all();
            $count += count($meja);

            foreach ($legacy->table('reservations')->orderBy('id')->cursor() as $r) {
                $this->importReservasi($r, $this->json($r->data), $outlet, $meja, $kategori, $sumber, $metode);
                $count++;
            }
            $count += $this->importTunggu((array) ($master['waitlist'] ?? []), $outlet, $meja);
        });
        if ($this->mejaTeks) {
            $this->issue('meja_teks_tidak_cocok', count($this->mejaTeks).' teks meja berbeda di '.array_sum($this->mejaTeks)
                .' reservasi tidak cocok dengan satu kode meja di denah; teksnya disimpan di meja_impor');
        }
        foreach ($this->metodeTeks as $label => $n) {
            $this->issue('metode_dp_tanpa_padanan', "{$label}: {$n} cicilan (teks disimpan di metode_impor)");
        }

        return $count;
    }

    /**
     * Every table of every floor plan; the first plan that draws a table gives its position.
     *
     * @param  array<string, array<string, mixed>>  $layouts
     * @return array<string, Meja> lower-case kode => row
     */
    private function importMeja(Lokasi $outlet, array $layouts): array
    {
        $tables = [];
        foreach ($layouts as $nama => $versi) {
            // one plan is {name, fixed, tables}; tolerate a list of plans too
            foreach (isset($versi['tables']) ? [$versi] : (array) $versi as $l) {
                foreach ((array) ($l['tables'] ?? []) as $t) {
                    $kode = trim((string) ($t['id'] ?? ''));
                    if ($kode === '') {
                        continue;
                    }
                    $tables[mb_strtolower($kode)] ??= $t + ['_denah' => (string) $nama];
                    $cap = (int) ($t['cap'] ?? 0);
                    if ($cap > (int) ($tables[mb_strtolower($kode)]['cap'] ?? 0)) {
                        $tables[mb_strtolower($kode)]['cap'] = $cap;
                    }
                }
            }
        }
        $out = [];
        foreach ($tables as $k => $t) {
            $out[$k] = $this->upsert(Meja::withTrashed()->firstOrNew(['lokasi_id' => $outlet->id, 'kode' => mb_substr((string) $t['id'], 0, 16)]), [
                'kapasitas' => max(1, (int) ($t['cap'] ?? 1)),
                'zona' => $this->text($t['zone'] ?? null, 32),
                'tata_letak' => array_intersect_key($t, array_flip(['x', 'y', 'w', 'h', 'shape'])) + ['denah' => $t['_denah']],
            ]);
        }

        return $out;
    }

    /**
     * @param  class-string<KategoriReservasi|SumberInfo>  $model
     * @param  list<string>  $names
     * @return array<string, string> lower-case name => id
     */
    private function daftar(string $model, array $names): array
    {
        $out = [];
        foreach (array_values($names) as $i => $nama) {
            $nama = trim((string) $nama);
            if ($nama !== '') {
                $out[mb_strtolower($nama)] = $this->upsert($model::withTrashed()->firstOrNew(['nama' => $nama]), ['urutan' => $i])->id;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, Meja>  $meja
     * @param  array<string, string>  $kategori
     * @param  array<string, string>  $sumber
     * @param  array<string, string>  $metode
     */
    private function importReservasi(object $r, array $d, Lokasi $outlet, array $meja, array &$kategori, array &$sumber, array $metode): void
    {
        $id = (string) $r->id;
        $status = self::STATUS[(string) ($d['status'] ?? $r->status)] ?? null;
        if (! $status) {
            $this->issue('status_tidak_dikenal', "{$id}: ".($d['status'] ?? ''));
            $status = 'pending';
        }
        $tglText = trim((string) ($d['table'] ?? ''));
        $mejaRow = $meja[mb_strtolower($tglText)] ?? null;
        if ($tglText !== '' && ! $mejaRow) {
            $this->mejaTeks[$tglText] = ($this->mejaTeks[$tglText] ?? 0) + 1;
        }
        $kat = $this->pilih($kategori, KategoriReservasi::class, $d['category'] ?? null);
        $src = $this->pilih($sumber, SumberInfo::class, $d['source'] ?? null);
        $picNama = trim((string) ($d['picName'] ?? $r->pic_name));
        $pic = $picNama !== '' ? $this->users->cocok(null, $picNama) : null;
        $oleh = trim((string) ($d['createdBy'] ?? ''));
        $olehId = $oleh !== '' ? $this->users->cocok(null, $oleh) : null;

        $res = $this->upsert(Reservasi::firstOrNew(['legacy_id' => $id]), [
            'nomor' => mb_substr('RSV-'.$id, 0, 40), 'lokasi_id' => $outlet->id,
            'tanggal_bisnis' => $this->tanggal($d['date'] ?? $r->tanggal), 'jam' => $this->jam($d['time'] ?? $r->jam),
            'nama_tamu' => $this->text($d['name'] ?? $r->name, 120) ?? '-', 'telepon' => $this->text($d['phone'] ?? $r->phone, 32),
            'pax' => max(1, (int) ($d['pax'] ?? $r->pax)),
            'meja_id' => $mejaRow?->id, 'meja_impor' => $this->text($tglText, 120), 'berbagi_meja' => ! empty($d['sharing']),
            'kategori_reservasi_id' => $kat, 'sumber_info_id' => $src,
            'pic_tipe' => $this->text($d['picType'] ?? null, 32), 'pic_id' => $pic, 'pic_impor' => $pic ? null : $this->text($picNama, 120),
            'member' => ! empty($d['member']), 'nomor_member' => $this->text($d['memberNo'] ?? null, 40), 'vip' => ! empty($d['vip']),
            'permintaan_makanan' => $this->text($d['foodReq'] ?? null, 500), 'permintaan_minuman' => $this->text($d['drinkReq'] ?? null, 500),
            'catatan' => $this->text($d['notes'] ?? null, 65000), 'status' => $status,
            'alasan_batal' => $this->text($d['cancelReason'] ?? null, 255),
            'pax_aktual' => max(0, (int) ($d['actualPax'] ?? 0)),
            'checkin_at' => $this->waktu($d['checkinAt'] ?? null), 'pulang_at' => $this->waktu($d['leftAt'] ?? null),
            'ditutup_otomatis' => ! empty($d['autoClosed']),
            'dokumen_key' => $this->text($d['docReqData'] ?? null, 190),
            'created_by' => $olehId, 'dicatat_oleh_impor' => $olehId ? null : $this->text($oleh, 120),
        ]);

        $this->anak(ReservasiKedatangan::class, $res, (array) ($d['arrivals'] ?? []), function (array $a, int $i) use ($id) {
            $at = $this->waktu($a['ts'] ?? null);
            if (! $at || (int) ($a['pax'] ?? 0) <= 0) {
                $this->issue('kedatangan_tidak_lengkap', "{$id} #{$i}");

                return null;
            }
            [$u, $impor] = $this->oleh($a['by'] ?? null);

            return ['datang_at' => $at, 'pax' => (int) $a['pax'], 'oleh' => $u, 'oleh_impor' => $impor];
        }, 'datang_at');
        $this->anak(ReservasiTindakLanjut::class, $res, (array) ($d['followups'] ?? []), function (array $f, int $i) {
            $at = $this->waktu($f['ts'] ?? null);
            if (! $at) {
                return null;
            }
            [$u, $impor] = $this->oleh($f['by'] ?? null);

            return ['dicatat_at' => $at, 'catatan' => (string) ($f['note'] ?? '-'), 'oleh' => $u, 'oleh_impor' => $impor];
        }, 'dicatat_at');

        $dps = array_values((array) ($d['dps'] ?? []));
        if ($dps === [] && ($d['dpStatus'] ?? '') === 'Sudah' && ((float) ($d['dpAmount'] ?? 0) > 0 || ($d['dpProofData'] ?? '') !== '')) {
            $dps = [['amount' => $d['dpAmount'] ?? 0, 'method' => $d['dpMethod'] ?? '', 'proofData' => $d['dpProofData'] ?? '',
                'tfDate' => $d['tfDate'] ?? '', 'tfTime' => $d['tfTime'] ?? '', 'tfBank' => $d['tfBank'] ?? '', 'tfName' => $d['tfName'] ?? '',
                'tfAmount' => $d['tfAmount'] ?? 0, 'tfStatus' => $d['tfStatus'] ?? '', 'tfOcrAt' => $d['tfOcrAt'] ?? 0]]; // legacy ensureDps
        }
        foreach ($dps as $i => $p) {
            $p = (array) $p;
            $label = trim((string) ($p['method'] ?? ''));
            $kode = self::METODE[mb_strtolower($label)] ?? null;
            if ($label !== '' && ! $kode) {
                $this->metodeTeks[$label] = ($this->metodeTeks[$label] ?? 0) + 1;
            }
            $verif = ['verified' => 'terverifikasi', 'rejected' => 'ditolak'][(string) ($p['tfStatus'] ?? '')] ?? 'belum';
            $kapan = $verif === 'belum' ? null : ($this->waktu($p['tfAt'] ?? null) ?? $this->waktu($p['at'] ?? null));
            if ($verif !== 'belum' && ! $kapan) {
                $this->issue('verifikasi_tanpa_waktu', "{$id} #".($i + 1));
                $kapan = ($this->tanggal($d['date'] ?? null) ?? '2000-01-01').' 00:00:00';
            }
            [$verifOleh] = $this->oleh($p['tfBy'] ?? null);
            $tfAmount = (float) ($p['tfAmount'] ?? 0);
            $this->upsert(ReservasiDp::firstOrNew(['reservasi_id' => $res->id, 'urutan' => $i + 1]), [
                'nominal' => $this->rp(max(0, (float) ($p['amount'] ?? 0))),
                'metode_bayar_id' => $kode ? ($metode[$kode] ?? null) : null, 'metode_impor' => $kode ? null : $this->text($label, 60),
                'bukti_key' => $this->text($p['proofData'] ?? null, 190),
                'transfer_tanggal' => $this->tanggal($p['tfDate'] ?? null), 'transfer_jam' => $this->jam($p['tfTime'] ?? null),
                'transfer_bank' => $this->text($p['tfBank'] ?? null, 60), 'transfer_nama' => $this->text($p['tfName'] ?? null, 120),
                'transfer_nominal' => $tfAmount > 0 ? $this->rp($tfAmount) : null, 'ocr_at' => $this->waktu($p['tfOcrAt'] ?? null),
                'verifikasi' => $verif, 'diverifikasi_at' => $kapan, 'diverifikasi_oleh' => $verif === 'belum' ? null : $verifOleh,
            ]);
        }
        ReservasiDp::where('reservasi_id', $res->id)->where('urutan', '>', count($dps))->delete();
    }

    /**
     * Child rows keyed by their time, kept in sync (rows no longer in legacy are removed).
     *
     * @param  class-string<ReservasiKedatangan|ReservasiTindakLanjut>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private function anak(string $model, Reservasi $res, array $rows, callable $map, string $kunci): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $v = $map((array) $row, $i + 1);
            if ($v === null) {
                continue;
            }
            $keep[] = $this->upsert($model::firstOrNew(['reservasi_id' => $res->id, $kunci => $v[$kunci]]), $v)->id;
        }
        $model::where('reservasi_id', $res->id)->whereNotIn('id', $keep ?: ['-'])->delete();
    }

    /**
     * @param  array<string, string>  $daftar
     * @param  class-string<KategoriReservasi|SumberInfo>  $model
     */
    private function pilih(array &$daftar, string $model, mixed $nama): ?string
    {
        $nama = trim((string) $nama);
        if ($nama === '') {
            return null;
        }

        return $daftar[mb_strtolower($nama)] ??= $this->upsert($model::withTrashed()->firstOrNew(['nama' => $nama]), ['aktif' => false])->id;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, Meja>  $meja
     */
    private function importTunggu(array $rows, Lokasi $outlet, array $meja): int
    {
        $n = 0;
        foreach ($rows as $w) {
            $res = ($w['resId'] ?? '') !== '' ? Reservasi::where('legacy_id', (string) $w['resId'])->value('id') : null;
            $duduk = ($w['status'] ?? '') === 'seated' && $res;
            $this->upsert(DaftarTunggu::firstOrNew(['legacy_id' => (string) ($w['id'] ?? '')]), [
                'lokasi_id' => $outlet->id, 'tanggal_bisnis' => $this->tanggal($w['date'] ?? $w['addedAt'] ?? null) ?? now()->toDateString(),
                'nama_tamu' => $this->text($w['name'] ?? null, 120) ?? '-', 'telepon' => $this->text($w['phone'] ?? null, 32),
                'pax' => max(1, (int) ($w['pax'] ?? 1)), 'catatan' => $this->text($w['note'] ?? null, 255),
                'status' => $duduk ? 'duduk' : (($w['status'] ?? '') === 'waiting' ? 'menunggu' : 'batal'),
                'masuk_at' => $this->waktu($w['addedAt'] ?? null) ?? now('UTC')->format('Y-m-d H:i:s'),
                'duduk_at' => $duduk ? $this->waktu($w['seatedAt'] ?? null) : null, 'reservasi_id' => $duduk ? $res : null,
            ]);
            $n++;
        }

        return $n;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function oleh(mixed $nama): array
    {
        $nama = trim((string) $nama);
        $id = $nama === '' ? null : $this->users->cocok($nama, $nama);

        return [$id, $id || $nama === '' ? null : mb_substr($nama, 0, 120)];
    }
}
