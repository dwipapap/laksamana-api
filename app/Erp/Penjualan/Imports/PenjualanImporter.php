<?php

declare(strict_types=1);

namespace App\Erp\Penjualan\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Kas\Models\SetoranHari;
use App\Erp\Master\Imports\BarangImporter;
use App\Erp\Master\Imports\PencocokUser;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Penjualan\Models\Bon;
use App\Erp\Penjualan\Models\Compliment;
use App\Erp\Penjualan\Models\LaporanKasir;
use App\Erp\Penjualan\Models\LaporanKasirBayar;
use App\Erp\Penjualan\Models\OmsetHarian;
use App\Erp\Penjualan\Models\OmsetPorsi;
use App\Erp\Penjualan\Models\PengaturanPenjualan;
use App\Erp\Penjualan\Models\TargetOmset;
use App\Erp\Penjualan\Models\TargetOmsetPic;
use App\Erp\Penjualan\Models\VoidItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Penjualan Harian (docs/erp/penjualan-harian.md "Pemetaan lama → v2")
 * from the legacy Kompas blob (app_state) and its void tables. Run after
 * `erp-kas` (metode_bayar, dompet, setoran). Breakdown lines taken from an
 * Acara, Reservasi VIP or Event point at it once those are imported: run this
 * again after `erp-acara`, `erp-reservasi`, `erp-event`. Idempotent.
 *
 * Takings that reach a wallet become arus_kas `omset` rows: a bank method's
 * typed "aktual masuk", else its actual less the old MDR; cash its actual —
 * the Brankas rule (kas.md rule 2).
 */
final class PenjualanImporter implements Importer, ReportsIssues
{
    /** Rekap group keys (aktual, esb, mdrManual) that differ from the Report Daily method keys. */
    private const GRUP_KE_METODE = ['qr_order' => 'qris_esb', 'transfer' => 'transfer_uob'];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, MetodeBayar> */
    private array $metode = [];

    /** @var array<string, array<string, string>> divi => legacy employee id => Office id */
    private array $pegawai = [];

    private ?Lokasi $outlet = null;

    public function __construct(private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-penjualan';
    }

    public function legacyConnections(): array
    {
        return ['legacy_kompas'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function issues(): array
    {
        return $this->issues;
    }

    public function import(): int
    {
        $this->issues = [];
        $this->users->reset();
        $legacy = DB::connection('legacy_kompas');
        $kp = json_decode((string) $legacy->table('app_state')->value('data'), true);
        $kp = is_array($kp) ? $kp : [];
        $count = 0;

        DB::connection('core')->transaction(function () use ($legacy, $kp, &$count): void {
            $this->outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
            $this->metode = MetodeBayar::withTrashed()->get()->keyBy('kode')->all();
            if ($this->metode === []) {
                throw new \RuntimeException('Run core:import erp-kas first: metode_bayar is empty.');
            }
            foreach ((array) ($kp['employees'] ?? []) as $divi => $list) {
                foreach ((array) $list as $e) {
                    $this->pegawai[(string) $divi][(string) ($e['id'] ?? '')] = (string) ($e['officeUserId'] ?? '');
                }
            }
            $count += $this->importOmset((array) ($kp['daily'] ?? []));
            $count += $this->importLaporan((array) ($kp['reports'] ?? []));
            $count += $this->importCompliment((array) ($kp['compliments'] ?? []));
            $count += $this->importBon((array) ($kp['piutang'] ?? []));
            $count += $this->importVoid($legacy);
            $count += $this->importTarget((array) ($kp['settings'] ?? []), (array) ($kp['employees'] ?? []));
        });

        return $count;
    }

    /** @param list<array<string, mixed>> $rows legacy daily[] */
    private function importOmset(array $rows): int
    {
        $n = 0;
        foreach ($rows as $d) {
            $tgl = $this->tanggal($d['date'] ?? null);
            if (! $tgl) {
                $this->issue('omset_tanggal_tidak_sah', (string) ($d['date'] ?? '?'));

                continue;
            }
            $h = $this->upsert(OmsetHarian::firstOrNew(['lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl]), [
                'legacy_id' => $tgl,
                'food' => $this->rp($d['food'] ?? 0), 'bev' => $this->rp($d['bev'] ?? 0), 'lainnya' => $this->rp($d['lainnya'] ?? 0),
                'diskon' => $this->rp($d['discount'] ?? 0), 'service' => $this->rp($d['service_charge'] ?? 0), 'pajak' => $this->rp($d['tax'] ?? 0),
                'jumlah_bill' => (int) ($d['bill'] ?? 0), 'traffic' => (int) ($d['traffic'] ?? 0),
                'qty_food' => (int) ($d['qty_food'] ?? 0), 'qty_bev' => (int) ($d['qty_bev'] ?? 0), 'qty_lainnya' => (int) ($d['qty_others'] ?? 0),
                'breakdown_valid' => ! empty($d['bdValid']),
            ]);

            $bd = (array) ($d['bd'] ?? []);
            $baris = [];
            foreach (['marketing', 'event'] as $sumber) {
                foreach ((array) ($bd[$sumber] ?? []) as $r) {
                    $baris[] = $this->porsiAcara($sumber, (array) $r, $tgl);
                }
            }
            foreach ((array) ($bd['kasir'] ?? []) as $r) {
                $r = (array) $r;
                if ((float) ($r['amount'] ?? 0) == 0 && (float) ($r['disc'] ?? 0) == 0 && empty($r['off'])) {
                    continue; // an empty row the screen draws for every cashier
                }
                [$pic, $impor] = $this->pegawaiKe('kasir', (string) ($r['kasirId'] ?? ''), "{$tgl} kasir");
                $baris[] = ['sumber' => 'kasir', 'pic_id' => $pic, 'pic_impor' => $impor,
                    'nominal' => $this->rp($r['amount'] ?? 0), 'diskon' => $this->rp($r['disc'] ?? 0), 'libur' => ! empty($r['off'])];
            }
            $self = (array) ($bd['self'] ?? []);
            if ((float) ($self['amount'] ?? 0) != 0 || (float) ($self['disc'] ?? 0) != 0) {
                $baris[] = ['sumber' => 'walk_in', 'nominal' => $this->rp($self['amount'] ?? 0), 'diskon' => $this->rp($self['disc'] ?? 0)];
            }

            $kosong = ['pic_id' => null, 'pic_impor' => null, 'keterangan' => null, 'shift' => null, 'libur' => false,
                'sumber_acara_id' => null, 'sumber_reservasi_id' => null, 'sumber_event_id' => null, 'sumber_impor' => null,
                'nominal' => '0', 'pajak' => '0', 'service' => '0', 'diskon' => '0'];
            foreach ($baris as $i => $b) {
                $this->upsert(OmsetPorsi::firstOrNew(['omset_harian_id' => $h->id, 'urutan' => $i + 1]), $b + $kosong);
            }
            OmsetPorsi::where('omset_harian_id', $h->id)->where('urutan', '>', count($baris))->delete();
            $n++;
        }

        return $n;
    }

    /**
     * A Marketing or Event breakdown line: PIC from Kompas' own staff list, its
     * source (legacy srcId "mkt:<id>" Acara, "vip:<id>" Reservasi, "evt:<id>" Event).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function porsiAcara(string $sumber, array $r, string $tgl): array
    {
        [$pic, $impor] = $this->pegawaiKe($sumber, (string) ($r['picId'] ?? ''), "{$tgl} {$sumber}");
        $src = trim((string) ($r['srcId'] ?? ''));
        [$jenis, $id] = array_pad(explode(':', $src, 2), 2, '');
        $kolom = ['mkt' => ['sumber_acara_id', 'acara'], 'vip' => ['sumber_reservasi_id', 'reservasi'], 'evt' => ['sumber_event_id', 'event']][$jenis] ?? null;
        $ref = $kolom && $id !== '' ? DB::connection('core')->table($kolom[1])->where('legacy_id', $id)->value('id') : null;

        return [
            'sumber' => $sumber, 'pic_id' => $pic, 'pic_impor' => $impor,
            'keterangan' => $this->text($r['eventName'] ?? null, 190), 'shift' => $this->text(is_array($r['shift'] ?? null) ? implode(',', $r['shift']) : ($r['shift'] ?? null), 16), // legacy: a list of shifts
            'nominal' => $this->rp($r['amount'] ?? 0), 'pajak' => $this->rp($r['tax'] ?? 0), 'service' => $this->rp($r['service'] ?? 0),
            'sumber_acara_id' => $kolom && $kolom[0] === 'sumber_acara_id' ? $ref : null,
            'sumber_reservasi_id' => $kolom && $kolom[0] === 'sumber_reservasi_id' ? $ref : null,
            'sumber_event_id' => $kolom && $kolom[0] === 'sumber_event_id' ? $ref : null,
            'sumber_impor' => $src !== '' && ! $ref ? mb_substr($src, 0, 64) : null,
        ];
    }

    /** @return array{0: ?string, 1: ?string} Kompas staff id => [user id, legacy name/id when unmatched] */
    private function pegawaiKe(string $divi, string $id, string $label): array
    {
        if ($id === '') {
            return [null, null];
        }
        $office = $this->pegawai[$divi][$id] ?? '';
        $user = $office !== '' ? $this->users->id($office) : null;
        if (! $user) {
            $this->issue('pic_kompas_tanpa_user', "{$label}: {$id}");
        }

        return [$user, $user ? null : $id];
    }

    /** @param array<string, array<string, mixed>> $reports legacy reports{tgl} */
    private function importLaporan(array $reports): int
    {
        $n = 0;
        ksort($reports);
        foreach ($reports as $tgl => $r) {
            $tgl = $this->tanggal($tgl);
            if (! $tgl || ! is_array($r)) {
                continue;
            }
            [$oleh, $olehImpor] = $this->oleh($r['by'] ?? null);
            $lap = $this->upsert(LaporanKasir::firstOrNew(['lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl]), [
                'legacy_id' => $tgl,
                'dikirim_at' => ! empty($r['submitted']) && ! empty($r['at']) ? $this->waktu($r['at']) : null,
                'dikirim_oleh' => ! empty($r['submitted']) ? $oleh : null,
                'dikirim_oleh_impor' => ! empty($r['submitted']) ? $olehImpor : null,
            ]);

            $pay = (array) ($r['pay'] ?? []);
            $aktual = $this->perMetode((array) ($r['aktual'] ?? []), $tgl);
            $esb = $this->perMetode((array) ($r['esb'] ?? []), $tgl);
            $mdrManual = $this->perMetode((array) ($r['mdrManual'] ?? []), $tgl);
            $mdrLama = $this->perMetode((array) ($r['mdr'] ?? []), $tgl);
            $kodes = array_unique([...array_keys($pay), ...array_keys($aktual), ...array_keys(array_filter($esb)), ...array_keys($mdrManual)]);
            $keep = [];
            foreach ($kodes as $kode) {
                $metode = $this->metode[$kode] ?? null;
                if (! $metode) {
                    $this->issue('metode_tidak_dikenal', "{$tgl}: {$kode}");

                    continue;
                }
                $p = (array) ($pay[$kode] ?? []);
                $pos = $this->rp($p['pos'] ?? 0);
                $act = $this->rp($p['actual'] ?? 0);
                $masuk = array_key_exists($kode, $aktual) ? $this->rp($aktual[$kode])
                    : (array_key_exists($kode, $mdrLama) ? (string) max(0, (int) $act - (int) $this->rp($mdrLama[$kode])) : null);
                if ($pos === '0' && $act === '0' && $masuk === null && empty($esb[$kode]) && ! array_key_exists($kode, $mdrManual)) {
                    continue; // nothing recorded for this method that day
                }
                $baris = $this->upsert(LaporanKasirBayar::firstOrNew(['laporan_kasir_id' => $lap->id, 'metode_bayar_id' => $metode->id]), [
                    'nominal_pos' => $pos, 'nominal_aktual' => $act, 'aktual_masuk' => $masuk,
                    'mdr_manual' => array_key_exists($kode, $mdrManual) ? (string) max(0, (int) $this->rp($mdrManual[$kode])) : null,
                    'sudah_input_pos' => ! empty($esb[$kode]),
                ]);
                $keep[] = $baris->id;

                // takings reaching a wallet (Brankas aktGrup): bank = aktual masuk else actual less old MDR; cash = actual
                $uang = $metode->dompet_id ? (int) ($masuk ?? $act) : 0;
                $arus = ArusKas::where('laporan_kasir_bayar_id', $baris->id)->first();
                if ($uang > 0) {
                    $this->upsert($arus ?? new ArusKas(['laporan_kasir_bayar_id' => $baris->id]), [
                        'dompet_id' => $metode->dompet_id, 'arah' => 'masuk', 'sebab' => 'omset', 'nominal' => (string) $uang, 'tanggal_bisnis' => $tgl,
                    ]);
                } else {
                    $arus?->delete();
                }
            }
            $lama = LaporanKasirBayar::where('laporan_kasir_id', $lap->id)->whereNotIn('id', $keep ?: ['-'])->pluck('id');
            ArusKas::whereIn('laporan_kasir_bayar_id', $lama)->delete();
            LaporanKasirBayar::whereIn('id', $lama)->delete();

            SetoranHari::where('tanggal_bisnis', $tgl)->whereNull('laporan_kasir_id')->update(['laporan_kasir_id' => $lap->id]);
            $n++;
        }

        return $n;
    }

    /**
     * Rekap group keys => Report Daily method keys. The pre-12-Aug-2026 per-bank
     * keys (bri, mandiri, bca: EDC and QRIS together) cannot be split and are reported.
     *
     * @param  array<string, mixed>  $byGroup
     * @return array<string, mixed>
     */
    private function perMetode(array $byGroup, string $tgl): array
    {
        $out = [];
        foreach ($byGroup as $k => $v) {
            $k = (string) $k;
            if (in_array($k, ['bri', 'mandiri', 'bca', 'error'], true)) {
                $this->issue('kelompok_lama_tidak_bisa_dipecah', "{$tgl}: {$k}");

                continue;
            }
            $out[self::GRUP_KE_METODE[$k] ?? $k] = $v;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows legacy compliments[] */
    private function importCompliment(array $rows): int
    {
        $n = 0;
        $divisi = DB::connection('core')->table('divisi')->pluck('id', 'kode')->all();
        foreach ($rows as $c) {
            $tgl = $this->tanggal($c['date'] ?? null);
            if (! $tgl) {
                $this->issue('compliment_tanggal_tidak_sah', (string) ($c['id'] ?? '?'));

                continue;
            }
            $pic = $this->users->cocok((string) ($c['picOfficeId'] ?? ''), (string) ($c['picName'] ?? ''));
            $pemberi = $this->users->cocok((string) ($c['pemberiOfficeId'] ?? ''), (string) ($c['pemberiName'] ?? ''));
            [$oleh, $olehImpor] = $this->oleh($c['by'] ?? null);
            $div = ['kasir' => 'cashier'][(string) ($c['picDiv'] ?? '')] ?? (string) ($c['picDiv'] ?? '');
            $this->upsert(Compliment::firstOrNew(['legacy_id' => (string) $c['id']]), [
                'nomor' => 'CP-'.$c['id'], 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl,
                'nama_tamu' => $this->text($c['nama'] ?? null, 120), 'alasan' => $this->text($c['alasan'] ?? null, 255),
                'nominal' => $this->rp($c['nominal'] ?? 0),
                'subtotal' => isset($c['subTotal']) ? $this->rp($c['subTotal']) : null,
                'pajak' => isset($c['tax']) ? $this->rp($c['tax']) : null,
                'service' => isset($c['service']) ? $this->rp($c['service']) : null,
                'pic_id' => $pic, 'pic_impor' => $pic ? null : $this->text($c['picName'] ?? null, 120),
                'divisi_id' => $divisi[$div] ?? null,
                'pemberi_id' => $pemberi, 'pemberi_impor' => $pemberi ? null : $this->text($c['pemberiName'] ?? null, 120),
                'created_by' => $oleh, 'dicatat_oleh_impor' => $olehImpor,
            ]);
            $n++;
        }

        return $n;
    }

    /** @param list<array<string, mixed>> $rows legacy piutang[] */
    private function importBon(array $rows): int
    {
        $n = 0;
        foreach ($rows as $b) {
            $tgl = $this->tanggal($b['date'] ?? null);
            if (! $tgl) {
                $this->issue('bon_tanggal_tidak_sah', (string) ($b['id'] ?? '?'));

                continue;
            }
            $lunas = ($b['status'] ?? '') === 'lunas';
            $lunasTgl = $lunas ? $this->tanggal($b['lunasDate'] ?? null) : null;
            $lunasMetode = $lunas ? ($this->metode[(string) ($b['lunasPayKey'] ?? '')] ?? null) : null;
            if ($lunas && (! $lunasTgl || ! $lunasMetode)) {
                $this->issue('bon_lunas_tidak_lengkap', (string) $b['id']);
                $lunas = false;
            }
            $pic = $this->users->cocok((string) ($b['picOfficeId'] ?? ''), (string) ($b['picName'] ?? ''));
            [$lunasOleh, $lunasImpor] = $this->oleh($b['lunasBy'] ?? null);
            [$oleh, $olehImpor] = $this->oleh($b['by'] ?? null);
            $tipe = in_array($b['tipe'] ?? null, ['tamu', 'staff', 'owner'], true) ? $b['tipe'] : null;
            $this->upsert(Bon::firstOrNew(['legacy_id' => (string) $b['id']]), [
                'nomor' => 'BN-'.$b['id'], 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl,
                'nama_tamu' => $this->text($b['nama'] ?? null, 120) ?? '-', 'tipe' => $tipe,
                'nomor_bill' => $this->text($b['bill'] ?? null, 60), 'nominal' => $this->rp($b['nominal'] ?? 0),
                'metode_bayar_id' => ($this->metode[(string) ($b['payKey'] ?? '')] ?? null)?->id,
                'pic_id' => $pic, 'pic_impor' => $pic ? null : $this->text($b['picName'] ?? null, 120),
                'status' => $lunas ? 'lunas' : 'belum', 'lunas_tanggal' => $lunas ? $lunasTgl : null,
                'lunas_metode_bayar_id' => $lunas ? $lunasMetode->id : null,
                'lunas_at' => $lunas && ! empty($b['lunasAt']) ? $this->waktu($b['lunasAt']) : null,
                'lunas_oleh' => $lunas ? $lunasOleh : null, 'lunas_oleh_impor' => $lunas ? $lunasImpor : null,
                'created_by' => $oleh, 'dicatat_oleh_impor' => $olehImpor,
            ]);
            $n++;
        }

        return $n;
    }

    private function importVoid($legacy): int
    {
        $n = 0;
        $kolom = DB::connection('legacy_kompas')->getSchemaBuilder()->getColumnListing('void_log');
        foreach ($legacy->table('void_log')->orderBy('tgl')->get() as $v) {
            if ((int) $v->nominal <= 0) {
                $this->issue('void_nominal_nol', (string) $v->id);

                continue;
            }
            $rinci = in_array('subtotal', $kolom, true) && ((int) $v->subtotal > 0 || (int) $v->service > 0 || (int) $v->tax > 0);
            $penginput = trim((string) ($v->penginput ?? ''));
            $salah = trim((string) ($v->salah ?? ''));
            $pi = $this->users->cocok(null, $penginput);
            $sa = $this->users->cocok(null, $salah);
            [$oleh, $olehImpor] = $this->oleh($v->oleh_id ?: $v->oleh);
            $batal = (int) $v->batal_at > 0;
            $this->upsert(VoidItem::firstOrNew(['legacy_id' => (string) $v->id]), [
                'nomor' => 'VD-'.$v->id, 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => (string) $v->tgl,
                'nomor_bill' => $this->text($v->bill, 60), 'item' => $this->text($v->item, 200) ?? '-',
                'subtotal' => $rinci ? (string) $v->subtotal : null, 'service' => $rinci ? (string) $v->service : null,
                'pajak' => $rinci ? (string) $v->tax : null, 'total' => (string) (int) $v->nominal,
                'penginput_id' => $pi, 'penginput_impor' => $pi ? null : ($penginput ?: null),
                'salah_id' => $sa, 'salah_impor' => $sa ? null : ($salah ?: null),
                'alasan' => $v->alasan, 'created_by' => $oleh, 'dicatat_oleh_impor' => $olehImpor,
                'dibatalkan_at' => $batal ? Carbon::createFromTimestampMs((int) $v->batal_at, 'UTC')->format('Y-m-d H:i:s') : null,
                'alasan_batal' => $batal ? $this->text($v->batal_alasan, 255) : null,
            ]);
            $n++;
        }
        $setting = $legacy->table('void_setting')->where('id', 1)->first();
        $this->upsert(PengaturanPenjualan::firstOrNew(['berlaku_dari' => BarangImporter::BERLAKU_AWAL]), [
            'pajak_persen' => number_format((float) ($setting->tax_persen ?? 10), 3, '.', ''),
            'service_persen' => number_format((float) ($setting->service_persen ?? 5), 3, '.', ''),
        ]);

        return $n + 1;
    }

    /**
     * @param  array<string, mixed>  $settings  legacy settings (company target, working days)
     * @param  array<string, list<array<string, mixed>>>  $employees  legacy employees{divi}[] with target
     */
    private function importTarget(array $settings, array $employees): int
    {
        $hari = (int) ($settings['workingDaysPerMonth'] ?? 0);
        $t = $this->upsert(TargetOmset::firstOrNew(['lokasi_id' => $this->outlet->id, 'berlaku_dari' => BarangImporter::BERLAKU_AWAL]), [
            'nominal_bulanan' => $this->rp($settings['companyMonthlyTarget'] ?? 0),
            'pakai_hari_kerja' => ! empty($settings['useWorkingDays']),
            'hari_kerja_per_bulan' => $hari >= 1 && $hari <= 31 ? $hari : null,
        ]);
        $n = 1;
        foreach (['marketing' => 'marketing', 'kasir' => 'kasir', 'event' => 'event'] as $divi => $peran) {
            foreach ((array) ($employees[$divi] ?? []) as $e) {
                $target = (int) round((float) ($e['target'] ?? 0));
                $user = $this->users->id((string) ($e['officeUserId'] ?? ''));
                if ($target <= 0) {
                    continue;
                }
                if (! $user) {
                    $this->issue('target_pic_tanpa_user', "{$divi}: ".($e['name'] ?? $e['id'] ?? '?'));

                    continue;
                }
                $this->upsert(TargetOmsetPic::firstOrNew(['target_omset_id' => $t->id, 'user_id' => $user, 'peran' => $peran]), ['nominal' => (string) $target]);
                $n++;
            }
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

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function upsert(Model $row, array $values): Model
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    private function rp(mixed $v): string
    {
        return (string) (int) round((float) $v);
    }

    private function tanggal(mixed $v): ?string
    {
        $v = substr(trim((string) $v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    /** Legacy instants are epoch milliseconds or ISO strings; stored in UTC. */
    private function waktu(mixed $v): ?string
    {
        if (is_numeric($v) && (float) $v > 0) {
            return Carbon::createFromTimestampMs((int) $v, 'UTC')->format('Y-m-d H:i:s');
        }
        try {
            return $v ? Carbon::parse((string) $v)->utc()->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
