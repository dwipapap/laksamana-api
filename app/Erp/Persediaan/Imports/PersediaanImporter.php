<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\BarangSatuan;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Satuan;
use App\Erp\Persediaan\Models\KirimanCk;
use App\Erp\Persediaan\Models\KirimanCkBaris;
use App\Erp\Persediaan\Models\MutasiStok;
use App\Erp\Persediaan\Models\Pemakaian;
use App\Erp\Persediaan\Models\PemakaianBaris;
use App\Erp\Persediaan\Models\PenyesuaianStok;
use App\Erp\Persediaan\Models\PenyesuaianStokBaris;
use App\Erp\Persediaan\Models\PesananBahan;
use App\Erp\Persediaan\Models\PesananBahanBaris;
use App\Erp\Persediaan\Models\ProduksiCk;
use App\Erp\Persediaan\Models\ProduksiCkBaris;
use App\Erp\Persediaan\Models\SerahTerima;
use App\Erp\Persediaan\Models\SerahTerimaBaris;
use App\Erp\Persediaan\Models\Waste;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ERP v2 stock documents from legacy Stock: orders -> pesanan_bahan (+ baris),
 * ck_stock -> mutasi_stok with its origin document, serah_terima,
 * usage_events -> pemakaian, waste. Needs `core:import erp-barang` first.
 * Mapping: docs/erp/pembelian-persediaan.md "Pemetaan tabel lama → v2".
 *
 * Idempotent: documents keyed by legacy_id; multi-line documents rebuild their
 * lines only when the legacy lines changed. Legacy text that matches no row
 * goes to *_impor columns; anything else a person must decide is in issues().
 */
final class PersediaanImporter implements Importer, ReportsIssues
{
    private const LEGACY = 'legacy_stock';

    private const WIB = 'Asia/Jakarta';

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, Barang> */
    private array $barang = [];

    /** @var array<string, Satuan> */
    private array $satuan = [];

    /** @var array<string, string|null> lower-case name => user id */
    private array $user = [];

    /** @var array<string, string> divisi kode => id */
    private array $divisi = [];

    private Lokasi $outlet;

    private Lokasi $ck;

    public function module(): string
    {
        return 'erp-persediaan';
    }

    public function legacyConnections(): array
    {
        return [self::LEGACY];
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
        $this->outlet = Lokasi::where('kode', 'outlet')->first()
            ?? throw new RuntimeException('Run core:import erp-barang first: Lokasi outlet is missing.');
        $this->ck = Lokasi::where('kode', 'ck')->firstOrFail();
        foreach (Barang::withTrashed()->get() as $b) {
            $this->barang[mb_strtolower((string) ($b->legacy_id ?? $b->nama))] = $b;
        }
        $this->divisi = DB::connection('core')->table('divisi')->pluck('id', 'kode')->all();
        foreach (DB::connection('core')->table('user')->get(['id', 'nama']) as $u) {
            $this->user[mb_strtolower(trim((string) $u->nama))] = $u->id;
        }

        $legacy = DB::connection(self::LEGACY);
        $count = 0;
        DB::connection('core')->transaction(function () use ($legacy, &$count): void {
            $count += $this->importOrders($legacy->table('orders')->orderBy('waktu')->orderBy('row_index')->get());
            $count += $this->importCkStock($legacy->table('ck_stock')->orderBy('waktu')->get());
            $count += $this->importSerahTerima($legacy->table('serah_terima')->orderBy('waktu')->get());
            $count += $this->importPemakaian($legacy->table('usage_events')->orderBy('waktu')->get());
            $count += $this->importWaste($legacy->table('waste')->orderBy('waktu')->get());
        });

        return $count;
    }

    /** @param iterable<object> $rows */
    private function importOrders(iterable $rows): int
    {
        $ckRefs = DB::connection(self::LEGACY)->table('ck_stock')->where('sebab', 'pengajuan')
            ->where('ref', '<>', '')->pluck('ref')->flip()->all();
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r->batch_id !== '' ? $r->batch_id : $r->nomor_order][] = $r;
        }

        foreach ($groups as $key => $lines) {
            $first = $lines[0];
            if (count(array_unique(array_map(fn ($r) => $r->pic, $lines))) > 1) {
                $this->issue('pesanan_pic_berbeda', "{$key}: dipakai {$first->pic}");
            }
            [$divisiId, $divisiImpor] = $this->divisi((string) $first->tim);
            [$userId, $userImpor] = $this->user((string) $first->pic);
            $doc = PesananBahan::firstOrNew(['legacy_id' => $key]);
            $this->save($doc, [
                'nomor' => $key,
                'lokasi_id' => $this->outlet->id,
                'tanggal_bisnis' => $this->wib($first->waktu)->toDateString(),
                'nama' => trim((string) $first->batch_name) ?: null,
                'divisi_id' => $divisiId,
                'divisi_impor' => $divisiImpor,
                'diajukan_oleh' => $userId,
                'diajukan_oleh_impor' => $userImpor,
            ], $this->wib($first->waktu));

            foreach ($lines as $r) {
                $d = $this->json($r->data);
                $barang = $this->barangFor((string) $r->item, (string) $r->nomor_order);
                if ((float) $r->qty <= 0) {
                    $this->issue('qty_nol_dilewati', "{$r->nomor_order}: {$r->item}");

                    continue;
                }
                $satuan = $this->satuanFor((string) $r->unit, $barang);
                $terima = trim((string) ($d->tglTerima ?? ''));
                $this->save(PesananBahanBaris::firstOrNew(['legacy_id' => $r->nomor_order]), [
                    'pesanan_bahan_id' => $doc->id,
                    'barang_id' => $barang->id,
                    'qty_input' => $this->dec((float) $r->qty),
                    'satuan_input_id' => $satuan->id,
                    'qty_dasar' => $this->qtyDasar($barang, $satuan, (float) $r->qty),
                    'sumber' => isset($ckRefs[$r->nomor_order]) ? 'ck' : 'vendor',
                    // legacy never recorded the vendor of an order; it is not invented here
                    'vendor_id' => null,
                    'status' => strcasecmp((string) $r->kedatangan, 'Datang') === 0 ? 'datang' : 'diajukan',
                    'tanggal_butuh' => $this->date($r->tgl_datang),
                    'tanggal_jemput' => $this->date($d->tglJemput ?? null),
                    'diterima_at' => $terima !== '' && $this->date($terima) ? $this->wib($this->date($terima).' 00:00:00') : null,
                    'catatan' => $this->text($d->catatan ?? $d->note ?? null),
                    'catatan_terima' => $this->text($d->catatanTerima ?? null),
                    // legacy did not record when a row was archived; its request time is the best known bound
                    'diarsipkan_at' => strcasecmp((string) $r->status, 'Arsip') === 0 ? $this->wib($r->waktu) : null,
                ], $this->wib($r->waktu));
            }
        }

        return count($groups);
    }

    /** @param iterable<object> $rows */
    private function importCkStock(iterable $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            if ((float) $r->qty <= 0) {
                $this->issue('qty_nol_dilewati', "ck_stock {$r->id}");

                continue;
            }
            $barang = $this->barangFor((string) $r->item, (string) $r->id);
            $at = $this->wib($r->waktu);
            $tanggal = $this->date($r->tanggal) ?? $at->toDateString();
            $catatan = $this->text($this->json($r->data)->catatan ?? null);
            $qtyInput = (float) $r->qty_input > 0 ? (float) $r->qty_input : (float) $r->qty;
            $satuan = $this->satuanFor((string) $r->unit_input, $barang);
            $line = ['barang_id' => $barang->id, 'qty_input' => $this->dec($qtyInput), 'satuan_input_id' => $satuan->id, 'qty_dasar' => $this->dec((float) $r->qty)];
            $origin = [];

            switch ($r->sebab) {
                case 'pengajuan':
                    $baris = PesananBahanBaris::where('legacy_id', $r->ref)->first();
                    if (! $baris) {
                        $this->issue('mutasi_tanpa_pesanan', "{$r->id}: ref {$r->ref}");

                        continue 2;
                    }
                    $origin = ['pesanan_bahan_baris_id' => $baris->id];
                    break;
                case 'kiriman':
                    $doc = $this->doc(KirimanCk::class, $r->id, $this->outlet, $tanggal, $catatan, $at, ['ke_lokasi_id' => $this->ck->id]);
                    $origin = ['kiriman_ck_baris_id' => $this->oneLine(KirimanCkBaris::class, 'kiriman_ck_id', $doc, $line, $at)->id];
                    break;
                case 'produksi':
                    $doc = $this->doc(ProduksiCk::class, $r->id, $this->ck, $tanggal, $catatan, $at);
                    $origin = ['produksi_ck_baris_id' => $this->oneLine(ProduksiCkBaris::class, 'produksi_ck_id', $doc, $line, $at)->id];
                    break;
                case 'penyesuaian':
                    $doc = $this->doc(PenyesuaianStok::class, $r->id, $this->ck, $tanggal, $catatan, $at, ['status' => 'disahkan', 'disahkan_at' => $at]);
                    $sign = $r->arah === 'keluar' ? -1 : 1;
                    $origin = ['penyesuaian_stok_baris_id' => $this->oneLine(PenyesuaianStokBaris::class, 'penyesuaian_stok_id', $doc, [
                        'barang_id' => $barang->id, 'qty_dasar' => $this->dec($sign * (float) $r->qty), 'alasan' => 'impor', 'catatan' => $catatan,
                    ], $at)->id];
                    break;
                case 'rusak':
                    $waste = $this->doc(Waste::class, $r->id, $this->ck, $tanggal, $catatan, $at, $line + ['sebab' => 'rusak']);
                    $origin = ['waste_id' => $waste->id];
                    break;
                default:
                    $this->issue('sebab_tak_dikenal', "{$r->id}: {$r->sebab}");

                    continue 2;
            }

            $this->save(MutasiStok::firstOrNew(['legacy_id' => $r->id]), [
                'lokasi_id' => $this->ck->id,
                'barang_id' => $barang->id,
                'arah' => $r->arah === 'keluar' ? 'keluar' : 'masuk',
                'qty_dasar' => $this->dec((float) $r->qty),
                'tanggal_bisnis' => $tanggal,
                'sebab' => $r->sebab === 'rusak' ? 'waste' : $r->sebab,
            ] + $origin, $at);
        }

        return $n;
    }

    /** @param iterable<object> $rows */
    private function importSerahTerima(iterable $rows): int
    {
        $n = 0;
        $foto = 0;
        foreach ($rows as $r) {
            $n++;
            $foto += (string) ($r->foto ?? '') !== '' ? 1 : 0;
            $d = $this->json($r->data);
            $at = $this->wib($r->waktu);
            [$divisiId, $divisiImpor] = $this->divisi((string) $r->tujuan);
            [$userId, $userImpor] = $this->user((string) $r->penerima);
            $doc = $this->doc(SerahTerima::class, $r->id, $this->outlet, $this->date($r->tanggal) ?? $at->toDateString(), $this->text($d->catatan ?? null), $at, [
                'tujuan_divisi_id' => $divisiId, 'tujuan_impor' => $divisiImpor,
                'penerima_id' => $userId, 'penerima_impor' => $userImpor,
            ]);
            $this->syncLines(SerahTerimaBaris::class, 'serah_terima_id', $doc, $this->items($d, $r->id), $at);
        }
        if ($foto > 0) {
            $this->issue('foto_belum_dipindah', "serah_terima: {$foto} foto masih di database lama");
        }

        return $n;
    }

    /** @param iterable<object> $rows */
    private function importPemakaian(iterable $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            $d = $this->json($r->data);
            $at = $this->wib($r->waktu);
            $jenis = match (mb_strtolower(trim((string) $r->jenis))) {
                'rnd' => 'rnd',
                'prasmanan' => 'prasmanan',
                default => null,
            };
            if ($jenis === null) {
                $this->issue('jenis_pemakaian_tak_dikenal', "{$r->id}: {$r->jenis}");

                continue;
            }
            $doc = $this->doc(Pemakaian::class, $r->id, $this->outlet, $this->date($r->tanggal) ?? $at->toDateString(), $this->text($d->catatan ?? null), $at, [
                'jenis' => $jenis,
                'nama_acara' => trim((string) $r->nama_event) ?: null,
                'status' => strcasecmp((string) $r->status, 'Selesai') === 0 ? 'selesai' : 'draf',
            ]);
            $this->syncLines(PemakaianBaris::class, 'pemakaian_id', $doc, $this->items($d, $r->id), $at);
        }

        return $n;
    }

    /** @param iterable<object> $rows */
    private function importWaste(iterable $rows): int
    {
        $n = 0;
        $foto = 0;
        foreach ($rows as $r) {
            $n++;
            $foto += (string) ($r->foto ?? '') !== '' ? 1 : 0;
            if ((float) $r->qty <= 0) {
                $this->issue('qty_nol_dilewati', "waste {$r->id}");

                continue;
            }
            $at = $this->wib($r->waktu);
            $barang = $this->barangFor((string) $r->item, (string) $r->id);
            $satuan = $this->satuanFor((string) $r->unit, $barang);
            $sebab = match (mb_strtolower(trim((string) $r->sebab))) {
                'kadaluarsa', 'kedaluwarsa' => 'kadaluarsa',
                'rusak' => 'rusak',
                'sisa produksi' => 'sisa_produksi',
                default => 'lainnya',
            };
            $this->doc(Waste::class, $r->id, $this->outlet, $this->date($r->tanggal) ?? $at->toDateString(), $this->text($this->json($r->data)->catatan ?? null), $at, [
                'barang_id' => $barang->id,
                'qty_input' => $this->dec((float) $r->qty),
                'satuan_input_id' => $satuan->id,
                'qty_dasar' => $this->qtyDasar($barang, $satuan, (float) $r->qty),
                'sebab' => $sebab,
            ]);
        }
        if ($foto > 0) {
            $this->issue('foto_belum_dipindah', "waste: {$foto} foto masih di database lama");
        }

        return $n;
    }

    /**
     * A document header keyed by its legacy id (also its nomor).
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $extra
     */
    private function doc(string $class, string $legacyId, Lokasi $lokasi, string $tanggal, ?string $catatan, Carbon $at, array $extra = []): Model
    {
        $doc = $class::firstOrNew(['legacy_id' => $legacyId]);
        $this->save($doc, ['nomor' => $legacyId, 'lokasi_id' => $lokasi->id, 'tanggal_bisnis' => $tanggal, 'catatan' => $catatan] + $extra, $at);

        return $doc;
    }

    /**
     * The single line of a document made from one legacy ledger row.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $values
     */
    private function oneLine(string $class, string $fk, Model $doc, array $values, Carbon $at): Model
    {
        $line = $class::firstOrNew([$fk => $doc->getKey()]);
        $this->save($line, $values, $at);

        return $line;
    }

    /**
     * Rebuild a document's lines only when they differ from the legacy items,
     * so a second import leaves them (and their versions) untouched.
     *
     * @param  class-string<Model>  $class
     * @param  list<array<string, mixed>>  $items
     */
    private function syncLines(string $class, string $fk, Model $doc, array $items, Carbon $at): void
    {
        $sig = fn (iterable $rows) => collect($rows)
            ->map(fn ($r) => implode('|', [$r['barang_id'], $this->dec((float) $r['qty_input']), $r['satuan_input_id']]))
            ->sort()->values()->all();
        $existing = $class::where($fk, $doc->getKey())->get()->map(fn ($m) => $m->only(['barang_id', 'qty_input', 'satuan_input_id']));
        if ($sig($existing) === $sig($items)) {
            return;
        }
        $class::where($fk, $doc->getKey())->delete();
        foreach ($items as $item) {
            $this->save(new $class, [$fk => $doc->getKey()] + $item, $at);
        }
    }

    /** @return list<array<string, mixed>> */
    private function items(object $d, string $docId): array
    {
        $out = [];
        foreach (is_array($d->items ?? null) ? $d->items : [] as $it) {
            $qty = (float) ($it->qty ?? 0);
            if ($qty <= 0 || trim((string) ($it->item ?? '')) === '') {
                $this->issue('qty_nol_dilewati', "{$docId}: ".($it->item ?? '?'));

                continue;
            }
            $barang = $this->barangFor((string) $it->item, $docId);
            $satuan = $this->satuanFor((string) ($it->unit ?? ''), $barang);
            $out[] = [
                'barang_id' => $barang->id,
                'qty_input' => $this->dec($qty),
                'satuan_input_id' => $satuan->id,
                'qty_dasar' => $this->qtyDasar($barang, $satuan, $qty),
            ];
        }

        return $out;
    }

    /** A legacy item name; an unknown one becomes an inactive Barang so no history is lost. */
    private function barangFor(string $nama, string $where): Barang
    {
        $nama = trim($nama);
        $k = mb_strtolower($nama);
        if (! isset($this->barang[$k])) {
            $this->issue('barang_lama_dibuat_nonaktif', "{$nama} (pertama di {$where})");
            $this->barang[$k] = Barang::create(['legacy_id' => $nama, 'nama' => $nama, 'aktif' => false]);
        }

        return $this->barang[$k];
    }

    private function satuanFor(string $nama, Barang $barang): Satuan
    {
        $nama = trim($nama);
        if ($nama === '') {
            if ($barang->satuan_dasar_id) {
                return Satuan::findOrFail($barang->satuan_dasar_id);
            }
            $this->issue('satuan_kosong', $barang->nama);
            $nama = 'Pcs';
        }

        return $this->satuan[mb_strtolower($nama)] ??= Satuan::withTrashed()->where('nama', $nama)->first()
            ?? Satuan::create(['nama' => $nama]);
    }

    private function qtyDasar(Barang $barang, Satuan $satuan, float $qty): ?string
    {
        if ($barang->satuan_dasar_id === $satuan->id) {
            return $this->dec($qty);
        }
        $ukuran = BarangSatuan::where('barang_id', $barang->id)->where('satuan_id', $satuan->id)
            ->whereNotNull('ukuran')->orderByDesc('berlaku_dari')->value('ukuran');
        if ($ukuran === null) {
            $this->issue('qty_dasar_tidak_diketahui', "{$barang->nama} dalam {$satuan->nama}");

            return null;
        }

        return $this->dec($qty * (float) $ukuran);
    }

    /** @return array{0: ?string, 1: ?string} [divisi id, unmatched legacy text] */
    private function divisi(string $tim): array
    {
        $tim = trim($tim);
        if ($tim === '') {
            return [null, null];
        }
        $kode = mb_strtolower(trim(explode(',', $tim)[0]));

        return isset($this->divisi[$kode]) ? [$this->divisi[$kode], null] : [null, mb_substr($tim, 0, 40)];
    }

    /** @return array{0: ?string, 1: ?string} [user id, unmatched legacy name] */
    private function user(string $nama): array
    {
        $nama = trim($nama);
        if ($nama === '') {
            return [null, null];
        }
        $id = $this->user[mb_strtolower($nama)] ?? null;

        return [$id, $id ? null : mb_substr($nama, 0, 120)];
    }

    /** @param array<string, mixed> $values */
    private function save(Model $row, array $values, Carbon $at): void
    {
        $row->fill($values);
        if ($row->exists && ! $row->isDirty()) {
            return;
        }
        if (! $row->exists) {
            $row->created_at = $at;
        }
        $row->save();
    }

    private function wib(mixed $value): Carbon
    {
        $s = trim((string) $value);

        return ($s === '' ? Carbon::now(self::WIB) : Carbon::parse($s, self::WIB))->utc();
    }

    private function date(mixed $value): ?string
    {
        $s = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
    }

    private function dec(float $n): string
    {
        return number_format($n, 4, '.', '');
    }

    private function text(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : mb_substr($s, 0, 500);
    }

    private function json(?string $raw): object
    {
        $d = json_decode((string) $raw);

        return is_object($d) ? $d : (object) [];
    }

    private function issue(string $kind, string $line): void
    {
        if (! in_array($line, $this->issues[$kind] ?? [], true)) {
            $this->issues[$kind][] = $line;
        }
    }
}
