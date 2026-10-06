<?php

declare(strict_types=1);

namespace App\Erp\Master\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\BarangHarga;
use App\Erp\Master\Models\BarangLokasi;
use App\Erp\Master\Models\BarangSatuan;
use App\Erp\Master\Models\BarangVendor;
use App\Erp\Master\Models\KategoriBarang;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\PihakRekening;
use App\Erp\Master\Models\Satuan;
use App\Erp\Master\Models\Vendor;
use App\Erp\Master\Models\VendorHariTutup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 master Barang from legacy Stock (products, vendors) and HPP
 * (hpp_bahan). Mapping and rules: docs/erp/pembelian-persediaan.md
 * "Pemetaan Barang dari data lama". Idempotent: keyed by legacy_id and the
 * tables' unique keys, so a second run changes nothing.
 *
 * Conflicts a person must decide are not guessed: the column stays empty and
 * the row is listed in issues().
 */
final class BarangImporter implements Importer, ReportsIssues
{
    /** Unit sizes and prices known before v2 start from this date. */
    public const BERLAKU_AWAL = '2000-01-01';

    private const LEGACY = 'legacy_stock';

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, Satuan> lower-case name => row */
    private array $satuan = [];

    /** @var array<string, Vendor> lower-case legacy name => row */
    private array $vendor = [];

    public function module(): string
    {
        return 'erp-barang';
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
        $legacy = DB::connection(self::LEGACY);
        $products = $legacy->table('products')->orderBy('nama')->get(['nama', 'data']);
        $hpp = $legacy->table('hpp_bahan')->get()->keyBy(fn ($r) => mb_strtolower((string) $r->nama));
        $vendors = $legacy->table('vendors')->orderBy('nama')->get(['nama', 'whatsapp', 'data']);

        $count = 0;
        DB::connection($this->targetConnection())->transaction(function () use ($products, $hpp, $vendors, &$count): void {
            $lokasi = $this->lokasi();
            foreach ($vendors as $v) {
                $this->importVendor((string) $v->nama, (string) $v->whatsapp, $this->json($v->data));
                $count++;
            }
            foreach ($products as $p) {
                $this->importBarang((string) $p->nama, $this->json($p->data), $hpp[mb_strtolower((string) $p->nama)] ?? null, $lokasi);
                $count++;
            }
        });

        return $count;
    }

    /** @return array{outlet: Lokasi, ck: Lokasi} */
    private function lokasi(): array
    {
        return [
            'outlet' => Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']),
            'ck' => Lokasi::withTrashed()->firstOrCreate(['kode' => 'ck'], ['nama' => 'Central Kitchen', 'jenis' => 'central_kitchen']),
        ];
    }

    private function importVendor(string $nama, string $whatsapp, object $d): Vendor
    {
        $nama = trim($nama);
        $vendor = Vendor::withTrashed()->where('legacy_id', $nama)->first();
        $telepon = trim($whatsapp !== '' ? $whatsapp : (string) ($d->whatsapp ?? '')) ?: null;
        if (! $vendor) {
            $pihak = Pihak::create(['nama' => $nama, 'telepon' => $telepon]);
            $vendor = Vendor::create(['pihak_id' => $pihak->id, 'legacy_id' => $nama]);
        } else {
            $this->saveIfDirty($vendor->pihak, ['nama' => $nama, 'telepon' => $telepon]);
        }
        $this->saveIfDirty($vendor, ['perlu_jadwal_jemput' => (bool) ($d->perluJadwalJemput ?? false)]);

        $norek = trim((string) ($d->norek ?? ''));
        if ($norek !== '') {
            PihakRekening::withTrashed()->firstOrCreate(
                ['pihak_id' => $vendor->pihak_id, 'bank' => trim((string) ($d->bank ?? '')) ?: '-', 'nomor' => $norek],
                ['atas_nama' => trim((string) ($d->penerima ?? '')) ?: $nama, 'utama' => true],
            );
        }

        $hari = array_values(array_unique(array_filter(
            array_map('intval', is_array($d->tutupHari ?? null) ? $d->tutupHari : []),
            fn (int $h) => $h >= 0 && $h <= 6,
        )));
        VendorHariTutup::where('pihak_id', $vendor->pihak_id)->whereNotIn('hari', $hari ?: [-1])->delete();
        foreach ($hari as $h) {
            VendorHariTutup::firstOrCreate(['pihak_id' => $vendor->pihak_id, 'hari' => $h]);
        }

        return $this->vendor[mb_strtolower($nama)] = $vendor;
    }

    /** @param array{outlet: Lokasi, ck: Lokasi} $lokasi */
    private function importBarang(string $nama, object $d, ?object $hpp, array $lokasi): void
    {
        $nama = trim($nama);
        $label = $nama;

        // Satuan Dasar: Stock first, HPP fills a gap; two different answers wait for a person.
        $dasarStock = trim((string) ($d->satuanDasar ?? ''));
        $dasarHpp = trim((string) ($hpp->satuan ?? ''));
        $dasar = $dasarStock !== '' ? $dasarStock : $dasarHpp;
        if ($dasarStock !== '' && $dasarHpp !== '' && mb_strtolower($dasarStock) !== mb_strtolower($dasarHpp)) {
            $this->issue('satuan_dasar_beda', "{$label}: Stock {$dasarStock}, HPP {$dasarHpp}");
            $dasar = '';
        } elseif ($dasar === '') {
            $this->issue('satuan_dasar_kosong', $label);
        }
        $satuanDasar = $dasar !== '' ? $this->satuan($dasar) : null;

        $kategori = trim((string) ($d->kategori ?? ''));
        $sumber = match ((string) ($d->sumber ?? '')) {
            'ck' => 'ck',
            'both' => 'keduanya',
            default => 'vendor',
        };

        $barang = Barang::withTrashed()->firstOrNew(['legacy_id' => $nama]);
        $this->saveIfDirty($barang, [
            'nama' => $nama,
            'satuan_dasar_id' => $satuanDasar?->id,
            'kategori_id' => $kategori !== '' ? KategoriBarang::withTrashed()->firstOrCreate(['nama' => $kategori])->id : null,
            'sumber' => $sumber,
            'aktif' => ($d->aktif ?? true) !== false,
            // HPP "Perlu ada di Purchasing?" (water, own ice: no); not known = yes, as legacy
            'dipesan' => ! $hpp || ! property_exists($hpp, 'di_purchasing') || (bool) $hpp->di_purchasing,
        ]);

        $this->importSatuan($barang, $d, $satuanDasar);
        $this->importVendorBarang($barang, $d, $hpp, $label);
        $this->syncLokasi($barang, match (true) {
            $sumber === 'keduanya', $sumber === 'ck' && ! empty($d->diOutlet) => [$lokasi['outlet'], $lokasi['ck']],
            $sumber === 'ck' => [$lokasi['ck']],
            default => [$lokasi['outlet']],
        });
        $this->importHarga($barang, $hpp, $label);
    }

    private function importSatuan(Barang $barang, object $d, ?Satuan $dasar): void
    {
        /** @var array<string, float|null> $ukuran lower-case unit => size in Satuan Dasar (null = unknown) */
        $ukuran = [];
        $nama = [];
        $add = function (string $s, ?float $n) use (&$ukuran, &$nama): void {
            $s = trim($s);
            if ($s === '') {
                return;
            }
            $k = mb_strtolower($s);
            $nama[$k] ??= $s;
            if ($n !== null || ! array_key_exists($k, $ukuran)) {
                $ukuran[$k] = $n ?? ($ukuran[$k] ?? null);
            }
        };

        foreach (is_array($d->satuan ?? null) ? $d->satuan : [] as $s) {
            $add((string) $s, null);
        }
        if ((float) ($d->packIsi ?? 0) > 0) {
            $add('Pack', (float) $d->packIsi);
        }
        foreach ((array) ($d->isi ?? []) as $s => $n) {
            if ((float) $n > 0) {
                $add((string) $s, (float) $n); // isi wins over packIsi, as in legacy
            }
        }
        if ($dasar) {
            $add($dasar->nama, 1.0);
        }

        foreach ($ukuran as $k => $n) {
            if ($dasar === null) {
                $n = null; // a size relative to an unknown Satuan Dasar means nothing yet
            }
            $row = BarangSatuan::firstOrNew([
                'barang_id' => $barang->id,
                'satuan_id' => $this->satuan($nama[$k])->id,
                'berlaku_dari' => self::BERLAKU_AWAL,
            ]);
            $this->saveIfDirty($row, ['ukuran' => $n]);
        }
    }

    private function importVendorBarang(Barang $barang, object $d, ?object $hpp, string $label): void
    {
        $names = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            [(string) ($d->utama ?? ''), ...(is_array($d->cadangan ?? null) ? $d->cadangan : [])],
        )));
        $hppVendor = trim((string) ($hpp->vendor ?? ''));
        if ($hppVendor !== '') {
            if ($names === []) {
                $names = [$hppVendor];
            } elseif (mb_strtolower($names[0]) !== mb_strtolower($hppVendor)) {
                $this->issue('vendor_beda', "{$label}: Stock {$names[0]}, HPP {$hppVendor}");
            }
        }

        $keep = [];
        foreach (array_values(array_unique($names)) as $i => $n) {
            $vendor = $this->vendor[mb_strtolower($n)] ?? null;
            if (! $vendor) {
                $this->issue('vendor_dibuat_dari_nama', "{$label}: {$n}");
                $vendor = $this->importVendor($n, '', (object) []);
            }
            $keep[] = $vendor->pihak_id;
            $row = BarangVendor::firstOrNew(['barang_id' => $barang->id, 'pihak_id' => $vendor->pihak_id]);
            if ($row->urutan !== $i) {
                // free the slot first: (barang_id, urutan) is unique
                BarangVendor::where('barang_id', $barang->id)->where('urutan', $i)->where('pihak_id', '!=', $vendor->pihak_id)->delete();
            }
            $this->saveIfDirty($row, ['urutan' => $i]);
        }
        BarangVendor::where('barang_id', $barang->id)->whereNotIn('pihak_id', $keep ?: ['-'])->delete();
    }

    /** @param list<Lokasi> $lokasi */
    private function syncLokasi(Barang $barang, array $lokasi): void
    {
        $ids = array_map(fn (Lokasi $l) => $l->id, $lokasi);
        BarangLokasi::where('barang_id', $barang->id)->whereNotIn('lokasi_id', $ids)->delete();
        foreach ($ids as $id) {
            BarangLokasi::firstOrCreate(['barang_id' => $barang->id, 'lokasi_id' => $id]);
        }
    }

    private function importHarga(Barang $barang, ?object $hpp, string $label): void
    {
        if (! $hpp || (float) $hpp->qty_beli <= 0) {
            return;
        }
        $harga = (float) $hpp->harga_beli;
        if ($harga !== floor($harga)) {
            $this->issue('harga_beli_dibulatkan', "{$label}: {$harga}");
        }
        $vendor = $this->vendor[mb_strtolower(trim((string) $hpp->vendor))] ?? null;
        $row = BarangHarga::firstOrNew([
            'barang_id' => $barang->id,
            'berlaku_dari' => Carbon::createFromTimestampMs((int) $hpp->updated_at, 'Asia/Jakarta')->toDateString(),
        ]);
        $this->saveIfDirty($row, [
            'pihak_id' => $vendor?->pihak_id,
            'qty_beli' => number_format((float) $hpp->qty_beli, 4, '.', ''),
            'harga_beli' => (string) round($harga),
        ]);
    }

    private function satuan(string $nama): Satuan
    {
        $k = mb_strtolower(trim($nama));

        return $this->satuan[$k] ??= Satuan::withTrashed()->where('nama', trim($nama))->first()
            ?? Satuan::create(['nama' => trim($nama)]);
    }

    /** @param array<string, mixed> $values */
    private function saveIfDirty(Model $row, array $values): void
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }
    }

    private function json(?string $raw): object
    {
        $d = json_decode((string) $raw);

        return is_object($d) ? $d : (object) [];
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
