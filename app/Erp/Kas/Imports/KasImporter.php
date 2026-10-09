<?php

declare(strict_types=1);

namespace App\Erp\Kas\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\Dompet;
use App\Erp\Kas\Models\Investor;
use App\Erp\Kas\Models\KasKecil;
use App\Erp\Kas\Models\KategoriKas;
use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Kas\Models\MutasiDompet;
use App\Erp\Kas\Models\Pembayaran;
use App\Erp\Kas\Models\PengembalianModal;
use App\Erp\Kas\Models\RencanaBayar;
use App\Erp\Kas\Models\Setoran;
use App\Erp\Kas\Models\SetoranHari;
use App\Erp\Master\Imports\PencocokUser;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Kas (docs/erp/kas.md "Pemetaan lama → v2") from legacy Finance (Kas
 * Kecil tables, Brankas blob bk_state) and Kompas (rekap_setoran). Run after
 * `account` and `erp-orang`. Idempotent: documents keyed by legacy id, book
 * rows by their document's unique keys.
 */
final class KasImporter implements Importer, ReportsIssues
{
    /** Brankas wallets (legacy BANKS + KAS_K) => [nama, jenis]. */
    public const DOMPET = [
        'bri' => ['BRI', 'bank'], 'mandiri' => ['Mandiri', 'bank'], 'bca' => ['BCA', 'bank'], 'uob' => ['UOB', 'bank'],
        'cash' => ['Cash / Brankas Fisik', 'tunai'],
    ];

    /**
     * Report Daily payment methods (legacy PAYS) => [nama, dompet kode]; NULL =
     * no money lands anywhere (compliment, voucher, errors, member deposit).
     * Wallets follow Brankas MAP_BAWAAN (QR Order, ojol and transfer to UOB).
     */
    public const METODE = [
        'cash' => ['Cash', 'cash'],
        'qris_esb' => ['QRIS POS (QR Order)', 'uob'],
        'edc_bri' => ['EDC BRI', 'bri'], 'qris_bri' => ['QRIS BRI', 'bri'],
        'edc_mandiri' => ['EDC Mandiri', 'mandiri'], 'qris_mandiri' => ['QRIS Mandiri', 'mandiri'],
        'edc_bca' => ['EDC BCA', 'bca'], 'qris_bca' => ['QRIS BCA', 'bca'],
        'transfer_uob' => ['Transfer UOB', 'uob'],
        'gofood' => ['Gofood', 'uob'], 'grabfood' => ['Grabfood', 'uob'], 'tiktokgo' => ['TikTok Go', 'uob'],
        'compliment' => ['Compliment', null], 'voucher' => ['Voucher', null], 'member_dep' => ['Member Deposit', null],
        'error_bar' => ['Error Bar', null], 'error_customer' => ['Error Customer', null], 'error_floor' => ['Error Floor', null],
        'error_kasir' => ['Error Kasir', null], 'error_kitchen' => ['Error Kitchen', null],
    ];

    /** Planning Pembayaran's fixed categories (legacy BAYAR_KAT), merged with kk_kategori. */
    private const BAYAR_KAT = ['Bahan baku', 'Minuman', 'Rokok', 'Gas & Utilitas', 'Jasa & Langganan',
        'Sewa', 'Gaji', 'Pajak', 'Cicilan', 'Bagi hasil', 'Kas kecil', 'Lainnya'];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, Dompet> kode => row */
    private array $dompet = [];

    private ?Lokasi $outlet = null;

    public function __construct(private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-kas';
    }

    public function legacyConnections(): array
    {
        return ['legacy_finance', 'legacy_kompas'];
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
        $fin = DB::connection('legacy_finance');
        $raw = $fin->table('bk_state')->where('id', 1)->value('data');
        $bk = json_decode((string) $raw, true);
        $bk = is_array($bk) ? $bk : [];
        $kp = json_decode((string) DB::connection('legacy_kompas')->table('app_state')->value('data'), true);
        $kp = is_array($kp) ? $kp : [];
        $count = 0;

        DB::connection('core')->transaction(function () use ($fin, $bk, $kp, &$count): void {
            $this->outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
            $count += $this->importDompet($fin, (array) ($bk['setting']['awal'] ?? []));
            $count += $this->importMetode((array) ($bk['setting']['peta'] ?? []));
            $kategori = $this->importKategori($fin);
            $count += count($kategori);
            $count += $this->importKasKecil($fin, $kategori);
            $count += $this->importMutasi((array) ($bk['mutasi'] ?? []));
            $count += $this->importInvestor((array) ($bk['investor'] ?? []));
            $count += $this->importBayar((array) ($bk['bayar'] ?? []), $kategori);
            $count += $this->importSetoran((array) ($kp['rekap_setoran'] ?? []), (array) ($kp['reports'] ?? []));
        });

        return $count;
    }

    /** @param array<string, mixed> $awal legacy setting.awal (opening balance per wallet) */
    private function importDompet($fin, array $awal): int
    {
        $urutan = 0;
        foreach (self::DOMPET as $kode => [$nama, $jenis]) {
            $this->dompet[$kode] = $this->upsert(Dompet::withTrashed()->firstOrNew(['kode' => $kode]), [
                'nama' => $nama, 'jenis' => $jenis, 'lokasi_id' => $this->outlet->id,
                'bank' => $jenis === 'bank' ? $nama : null,
                'saldo_awal' => (string) round((float) ($awal[$kode] ?? 0)),
                'urutan' => $urutan += 10,
            ]);
        }
        foreach ($fin->table('kk_pos')->orderBy('urut')->get() as $p) {
            $kode = 'kk_'.$p->id;
            $this->dompet[$kode] = $this->upsert(Dompet::withTrashed()->firstOrNew(['kode' => $kode]), [
                'legacy_id' => (string) $p->id, 'nama' => trim((string) $p->nama), 'jenis' => 'kas_kecil',
                'lokasi_id' => $this->outlet->id, 'urutan' => 100 + (int) $p->urut, 'aktif' => (bool) $p->aktif,
            ]);
        }

        return count($this->dompet);
    }

    /** @param array<string, mixed> $peta legacy setting.peta (Rekap group => wallet), overriding the defaults */
    private function importMetode(array $peta): int
    {
        $grup = ['qris_esb' => 'qr_order', 'transfer_uob' => 'transfer']; // Rekap group of a Report Daily key
        $urutan = 0;
        foreach (self::METODE as $kode => [$nama, $bawaan]) {
            $override = $peta[$grup[$kode] ?? $kode] ?? null;
            $dompetKode = $override !== null ? (string) $override : $bawaan;
            if ($override !== null && $override !== '' && ! isset($this->dompet[$dompetKode])) {
                $this->issue('metode_ke_dompet_tidak_dikenal', "{$kode}: {$dompetKode}");
                $dompetKode = null;
            }
            $this->upsert(MetodeBayar::withTrashed()->firstOrNew(['kode' => $kode]), [
                'nama' => $nama, 'dompet_id' => $dompetKode ? $this->dompet[$dompetKode]->id : null, 'urutan' => $urutan += 10,
            ]);
        }

        return count(self::METODE);
    }

    /** @return array<string, KategoriKas> lower-case name => row (and 'id:<legacy>' => row) */
    private function importKategori($fin): array
    {
        $out = [];
        foreach ($fin->table('kk_kategori')->orderBy('urut')->get() as $k) {
            $row = KategoriKas::withTrashed()->where('legacy_id', (string) $k->id)->first()
                ?? KategoriKas::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower(trim((string) $k->nama))])->first()
                ?? new KategoriKas;
            $this->upsert($row, ['legacy_id' => (string) $k->id, 'nama' => trim((string) $k->nama), 'urutan' => (int) $k->urut, 'aktif' => (bool) $k->aktif]);
            $out[mb_strtolower($row->nama)] = $out['id:'.$k->id] = $row;
        }
        foreach (self::BAYAR_KAT as $i => $nama) {
            $out[mb_strtolower($nama)] ??= $this->upsert(
                KategoriKas::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first() ?? new KategoriKas,
                ['nama' => $nama, 'urutan' => 500 + $i],
            );
        }

        return $out;
    }

    /** @param array<string, KategoriKas> $kategori */
    private function importKasKecil($fin, array $kategori): int
    {
        $n = 0;
        $pos = $fin->table('kk_trx_pos')->get()->groupBy('trx_id');
        foreach ($fin->table('kk_trx')->orderBy('id')->get() as $t) {
            $oleh = trim((string) $t->dibuat_oleh);
            $userId = $this->users->cocok(null, $oleh);
            $kk = $this->upsert(KasKecil::firstOrNew(['legacy_id' => (string) $t->id]), [
                'nomor' => 'KK-'.$t->id, 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => (string) $t->tgl,
                'keterangan' => trim((string) $t->keterangan) ?: '-',
                'kategori_kas_id' => $t->kategori_id ? ($kategori['id:'.$t->kategori_id] ?? null)?->id : null,
                'sudah_dibukukan' => (bool) $t->input, 'ada_bon' => (bool) $t->bon,
                'created_by' => $userId, 'dicatat_oleh_impor' => $userId || $oleh === '' ? null : $oleh,
            ]);
            foreach ($pos[$t->id] ?? [] as $p) {
                $dompet = $this->dompet['kk_'.$p->pos_id] ?? null;
                foreach (['masuk' => (int) $p->debet, 'keluar' => (int) $p->kredit] as $arah => $nominal) {
                    if ($nominal <= 0 || ! $dompet) {
                        continue;
                    }
                    $this->upsert(ArusKas::firstOrNew(['kas_kecil_id' => $kk->id, 'dompet_id' => $dompet->id, 'arah' => $arah]), [
                        'sebab' => 'kas_kecil', 'nominal' => (string) $nominal, 'tanggal_bisnis' => (string) $t->tgl,
                    ]);
                }
            }
            $n++;
        }

        return $n;
    }

    /** @param list<array<string, mixed>> $rows legacy bk_state.mutasi */
    private function importMutasi(array $rows): int
    {
        $n = 0;
        foreach ($rows as $m) {
            $jenis = (string) ($m['jenis'] ?? 'pindah');
            $dari = $jenis === 'masuk' ? null : ($this->dompet[(string) ($m['dari'] ?? '')] ?? null);
            $ke = $jenis === 'keluar' ? null : ($this->dompet[(string) ($m['ke'] ?? '')] ?? null);
            $nominal = (int) round((float) ($m['nominal'] ?? 0));
            $tgl = $this->tanggal($m['tgl'] ?? null);
            if (! in_array($jenis, ['pindah', 'masuk', 'keluar'], true) || $nominal <= 0 || ! $tgl
                || ($jenis !== 'masuk' && ! $dari) || ($jenis !== 'keluar' && ! $ke) || ($jenis === 'pindah' && $dari === $ke)) {
                $this->issue('mutasi_tidak_lengkap', (string) ($m['id'] ?? '?'));

                continue;
            }
            [$userId, $impor] = $this->oleh($m['by'] ?? null);
            $md = $this->upsert(MutasiDompet::firstOrNew(['legacy_id' => (string) $m['id']]), [
                'nomor' => 'MD-'.$m['id'], 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl,
                'jenis' => $jenis, 'dari_dompet_id' => $dari?->id, 'ke_dompet_id' => $ke?->id, 'nominal' => (string) $nominal,
                'keterangan' => $this->text($m['ket'] ?? null, 255), 'created_by' => $userId, 'dicatat_oleh_impor' => $impor,
            ]);
            foreach (['keluar' => $dari, 'masuk' => $ke] as $arah => $dompet) {
                if ($dompet) {
                    $this->upsert(ArusKas::firstOrNew(['mutasi_dompet_id' => $md->id, 'arah' => $arah]), [
                        'dompet_id' => $dompet->id, 'sebab' => 'mutasi_dompet', 'nominal' => (string) $nominal, 'tanggal_bisnis' => $tgl,
                    ]);
                }
            }
            $n++;
        }

        return $n;
    }

    /** @param list<array<string, mixed>> $rows legacy bk_state.investor */
    private function importInvestor(array $rows): int
    {
        $n = 0;
        foreach ($rows as $i) {
            $legacyId = (string) ($i['id'] ?? '');
            $inv = Investor::withTrashed()->where('legacy_id', $legacyId)->first();
            $pihak = $inv ? Pihak::withTrashed()->findOrFail($inv->pihak_id) : new Pihak;
            $this->upsert($pihak, ['nama' => trim((string) ($i['name'] ?? '')) ?: '-']);
            $inv ??= new Investor(['pihak_id' => $pihak->id]);
            $own = (float) ($i['ownership'] ?? 0);
            $this->upsert($inv, [
                'legacy_id' => $legacyId, 'modal' => (string) round((float) ($i['capital'] ?? 0)),
                'kepemilikan' => $own > 0 ? number_format($own, 2, '.', '') : null,
                'target_kembali' => $this->tanggal($i['targetDate'] ?? null),
            ]);
            foreach (array_values((array) ($i['returns'] ?? [])) as $k => $r) {
                $tgl = $this->tanggal($r['date'] ?? null);
                $nominal = (int) round((float) ($r['amount'] ?? 0));
                if (! $tgl || $nominal <= 0) {
                    $this->issue('pengembalian_modal_tidak_lengkap', "{$pihak->nama} #".($k + 1));

                    continue;
                }
                $dompet = $this->dompet[(string) ($r['dari'] ?? '')] ?? null;
                if (! $dompet) {
                    $this->issue('pengembalian_modal_tanpa_dompet', "{$pihak->nama} {$tgl} {$nominal}");
                }
                [$userId, $impor] = $this->oleh($r['by'] ?? null);
                $pm = $this->upsert(PengembalianModal::firstOrNew(['legacy_id' => "{$legacyId}#{$k}"]), [
                    'nomor' => "PM-{$legacyId}-".($k + 1), 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl,
                    'investor_id' => $inv->pihak_id, 'dompet_id' => $dompet?->id, 'nominal' => (string) $nominal,
                    'created_by' => $userId, 'dicatat_oleh_impor' => $impor,
                ]);
                if ($dompet) {
                    $this->upsert(ArusKas::firstOrNew(['pengembalian_modal_id' => $pm->id]), [
                        'dompet_id' => $dompet->id, 'arah' => 'keluar', 'sebab' => 'pengembalian_modal',
                        'nominal' => (string) $nominal, 'tanggal_bisnis' => $tgl,
                    ]);
                }
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  legacy bk_state.bayar (Planning Pembayaran)
     * @param  array<string, KategoriKas>  $kategori
     */
    private function importBayar(array $rows, array $kategori): int
    {
        $n = 0;
        foreach ($rows as $p) {
            $batch = $this->tanggal($p['batch'] ?? ($p['dueDate'] ?? null));
            $nominal = (int) round((float) ($p['amount'] ?? 0));
            $dompet = $this->dompet[(string) ($p['dari'] ?? 'cash')] ?? null;
            if (! $batch || $nominal <= 0 || ! $dompet) {
                $this->issue('pembayaran_tidak_lengkap', (string) ($p['id'] ?? '?'));

                continue;
            }
            $sheet = RencanaBayar::firstOrCreate(['lokasi_id' => $this->outlet->id, 'tanggal_bayar' => $batch]);
            $vendor = trim((string) ($p['vendor'] ?? ''));
            $pihakId = $vendor !== '' ? Pihak::whereRaw('LOWER(nama) = ?', [mb_strtolower($vendor)])->whereHas('vendor')->value('id') : null;
            $paid = ($p['status'] ?? '') === 'paid';
            $bukti = is_array($p['bukti'] ?? null) && ! empty($p['bukti']['ok']) ? $p['bukti'] : null;
            [$buktiUser, $buktiImpor] = $this->oleh($bukti['by'] ?? null);
            $row = $this->upsert(Pembayaran::firstOrNew(['legacy_id' => (string) $p['id']]), [
                'rencana_bayar_id' => $sheet->id, 'keterangan' => trim((string) ($p['name'] ?? '')) ?: '-',
                'kategori_kas_id' => ($kategori[mb_strtolower(trim((string) ($p['cat'] ?? '')))] ?? null)?->id,
                'pihak_id' => $pihakId, 'pihak_impor' => $pihakId || $vendor === '' ? null : mb_substr($vendor, 0, 190),
                'dompet_id' => $dompet->id, 'nominal' => (string) $nominal, 'jatuh_tempo' => $this->tanggal($p['dueDate'] ?? null),
                'catatan' => $this->text($p['note'] ?? null, 255),
                'status' => $paid ? 'dibayar' : 'dijadwalkan', 'dibayar_at' => $paid ? $batch.' 00:00:00' : null,
                'bukti_at' => $bukti ? ($this->tanggal($bukti['at'] ?? null) ?? $batch).' 00:00:00' : null,
                'bukti_oleh' => $buktiUser, 'bukti_oleh_impor' => $buktiImpor,
            ]);
            if ($vendor !== '' && ! $pihakId) {
                $this->issue('pembayaran_vendor_tidak_dikenal', "{$p['id']}: {$vendor}");
            }
            if ($paid) {
                $this->upsert(ArusKas::firstOrNew(['pembayaran_id' => $row->id]), [
                    'dompet_id' => $dompet->id, 'arah' => 'keluar', 'sebab' => 'pembayaran', 'nominal' => (string) $nominal, 'tanggal_bisnis' => $batch,
                ]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  legacy Kompas rekap_setoran
     * @param  array<string, mixed>  $reports  legacy Kompas reports (cash actual per day)
     */
    private function importSetoran(array $rows, array $reports): int
    {
        $n = 0;
        $cash = $this->dompet['cash'];
        foreach ($rows as $s) {
            $tgl = $this->tanggal($s['tgl'] ?? null);
            $nominal = (int) round((float) ($s['nominal'] ?? 0));
            if (! $tgl || $nominal <= 0) {
                $this->issue('setoran_tidak_lengkap', (string) ($s['id'] ?? '?'));

                continue;
            }
            $tujuan = trim((string) ($s['tujuan'] ?? ''));
            $ke = null;
            foreach (['bri', 'mandiri', 'bca', 'uob'] as $bank) { // legacy bankDariTujuan: bank name inside the text
                if ($tujuan !== '' && str_contains(mb_strtolower($tujuan), $bank)) {
                    $ke = $this->dompet[$bank];
                    break;
                }
            }
            if (! $ke) {
                $this->issue('setoran_tujuan_tidak_dikenal', "{$s['id']}: ".($tujuan ?: '(kosong)'));
            }
            [$userId, $impor] = $this->oleh($s['by'] ?? null);
            $st = $this->upsert(Setoran::firstOrNew(['legacy_id' => (string) $s['id']]), [
                'nomor' => 'ST-'.$s['id'], 'lokasi_id' => $this->outlet->id, 'tanggal_bisnis' => $tgl,
                'dari_dompet_id' => $cash->id, 'ke_dompet_id' => $ke?->id, 'tujuan_impor' => $ke ? null : ($tujuan !== '' ? mb_substr($tujuan, 0, 80) : null),
                'nominal' => (string) $nominal, 'catatan' => $this->text($s['catatan'] ?? null, 200),
                'created_by' => $userId, 'dicatat_oleh_impor' => $impor,
            ]);

            // per day: the typed partial amount, else that day's cash actual (legacy kp_setor_masuk)
            $jumlah = (array) ($s['jumlah'] ?? []);
            $hari = array_values(array_filter(array_map(fn ($h) => $this->tanggal($h), (array) ($s['hari'] ?? []))));
            $per = [];
            foreach ($hari as $h) {
                $per[$h] = (int) round((float) ($jumlah[$h] ?? ($reports[$h]['pay']['cash']['actual'] ?? 0)));
            }
            if (count($hari) === 1 && array_sum($per) !== $nominal) {
                $per[$hari[0]] = $nominal; // one day: the stored total is the day's amount
            }
            if (array_sum($per) !== $nominal) {
                $this->issue('setoran_per_hari_beda_total', "{$s['id']}: total {$nominal}, per hari ".array_sum($per));
            }
            foreach ($per as $h => $v) {
                if ($v > 0) {
                    $this->upsert(SetoranHari::firstOrNew(['setoran_id' => $st->id, 'tanggal_bisnis' => $h]), ['nominal' => (string) $v]);
                }
            }
            SetoranHari::where('setoran_id', $st->id)->whereNotIn('tanggal_bisnis', array_keys(array_filter($per)) ?: ['1900-01-01'])->delete();

            $this->upsert(ArusKas::firstOrNew(['setoran_id' => $st->id, 'arah' => 'keluar']), [
                'dompet_id' => $cash->id, 'sebab' => 'setoran', 'nominal' => (string) $nominal, 'tanggal_bisnis' => $tgl,
            ]);
            if ($ke) {
                $this->upsert(ArusKas::firstOrNew(['setoran_id' => $st->id, 'arah' => 'masuk']), [
                    'dompet_id' => $ke->id, 'sebab' => 'setoran', 'nominal' => (string) $nominal, 'tanggal_bisnis' => $tgl,
                ]);
            }
            $n++;
        }

        return $n;
    }

    /** @return array{0: ?string, 1: ?string} [user id, legacy name when it matched no User] */
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

    private function tanggal(mixed $v): ?string
    {
        $v = substr(trim((string) $v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
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
