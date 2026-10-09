<?php

declare(strict_types=1);

namespace App\Erp\Resep\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Imports\BarangImporter;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\BarangSatuan;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Satuan;
use App\Erp\Resep\Models\KontrolBahan;
use App\Erp\Resep\Models\KontrolBahanBaris;
use App\Erp\Resep\Models\PengaturanHpp;
use App\Erp\Resep\Models\Resep;
use App\Erp\Resep\Models\ResepBaris;
use App\Erp\Resep\Models\ResepHarga;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Resep & HPP (docs/erp/resep-hpp.md "Pemetaan lama → v2") from the
 * legacy HPP tables in Stock. Run after `erp-barang`. Idempotent: recipes are
 * keyed by legacy id, their lines by position.
 *
 * A line whose ingredient matches no Barang or recipe is kept as a note line
 * marked "[tidak dikenal]" (legacy counted it as zero cost and flagged it) and
 * reported; a unit that cannot be converted leaves qty_dasar NULL (rule 3).
 */
final class ResepImporter implements Importer, ReportsIssues
{
    private const LEGACY = 'legacy_stock';

    /** Fixed ratios inside a unit family (rule 4), by lower-case unit name. */
    private const KELUARGA = [
        'gram' => ['massa', 1], 'gr' => ['massa', 1], 'g' => ['massa', 1], 'mg' => ['massa', 0.001],
        'kg' => ['massa', 1000], 'ons' => ['massa', 100],
        'ml' => ['volume', 1], 'cc' => ['volume', 1], 'l' => ['volume', 1000], 'liter' => ['volume', 1000], 'ltr' => ['volume', 1000],
    ];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, Satuan> */
    private array $satuan = [];

    /** @var array<string, Barang> lower-case name => Barang */
    private array $barang = [];

    public function module(): string
    {
        return 'erp-resep';
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
        $this->satuan = [];
        $legacy = DB::connection(self::LEGACY);
        $rows = $legacy->table('hpp_resep')->orderBy('jenis')->orderBy('tipe')->orderBy('nama')->get();
        $count = 0;

        DB::connection('core')->transaction(function () use ($legacy, $rows, &$count): void {
            $this->keluargaSatuan();
            $this->barang = Barang::withTrashed()->get()->keyBy(fn (Barang $b) => mb_strtolower(trim($b->nama)))->all();

            /** @var array<string, Resep> $resep legacy id => row */
            $resep = [];
            foreach ($rows as $r) {
                $resep[(string) $r->id] = $this->importResep($r);
                $count++;
            }
            foreach ($rows as $r) {
                $this->importBaris($resep[(string) $r->id], $r, $resep, $rows);
            }
            $this->tautkanBarang($legacy, $rows, $resep);
            $count += $this->importPengaturan($legacy);
            $count += $this->importKontrolBahan($legacy);
        });

        return $count;
    }

    private function importResep(object $r): Resep
    {
        $seksi = trim((string) $r->seksi);
        $prasmanan = (bool) preg_match('/^PRASMANAN\b/i', $seksi);
        $kategori = $prasmanan ? 'prasmanan' : ((string) $r->tipe === 'base' ? 'base' : 'menu');
        if ($prasmanan) {
            $seksi = trim((string) preg_replace('/^PRASMANAN\b\s*[-–—:]?\s*/iu', '', $seksi)); // seksiTanpaPras
        }
        $yield = (float) $r->yield_qty > 0 ? (float) $r->yield_qty : 1.0;
        $modal = (float) $r->modal_manual;
        $label = "{$r->jenis}/{$r->nama}";

        $resep = Resep::withTrashed()->firstOrNew(['legacy_id' => (string) $r->id]);
        $this->save($resep, [
            'nama' => trim((string) $r->nama),
            'jenis' => (string) $r->jenis === 'drink' ? 'drink' : 'food',
            'kategori' => $kategori,
            'seksi' => $seksi !== '' ? mb_substr($seksi, 0, 96) : null,
            'kode_pos' => trim((string) $r->kode) !== '' ? strtoupper(trim((string) $r->kode)) : null,
            'yield_qty' => $this->dec($yield),
            'yield_satuan_id' => trim((string) $r->yield_unit) !== '' ? $this->satuan((string) $r->yield_unit)->id : null,
            'modal_manual' => $modal > 0 ? (string) round($modal) : null,
            'catatan' => trim((string) $r->catatan) !== '' ? mb_substr(trim((string) $r->catatan), 0, 2000) : null,
            'aktif' => (bool) $r->aktif,
        ]);
        if ($modal > 0 && $modal !== floor($modal)) {
            $this->issue('modal_manual_dibulatkan', "{$label}: {$modal}");
        }

        foreach (['harga_baru' => 'harga_jual'] as $legacyCol => $_) {
            $jual = (float) $r->{$legacyCol};
            $upsize = (float) $r->harga_upsize;
            if ($jual > 0 || $upsize > 0) {
                $harga = ResepHarga::firstOrNew(['resep_id' => $resep->id, 'berlaku_dari' => BarangImporter::BERLAKU_AWAL]);
                $this->save($harga, [
                    'harga_jual' => (string) round($jual),
                    'harga_upsize' => $upsize > 0 ? (string) round($upsize) : null,
                ]);
            }
        }

        return $resep;
    }

    /**
     * @param  array<string, Resep>  $resep
     * @param  iterable<object>  $rows
     */
    private function importBaris(Resep $resep, object $r, array $resep_, iterable $rows): void
    {
        $bahan = json_decode((string) $r->bahan, true);
        $bahan = is_array($bahan) ? array_values($bahan) : [];
        $label = "{$r->jenis}/{$r->nama}";
        $urutan = 0;

        foreach ($bahan as $b) {
            if (! is_array($b)) {
                continue;
            }
            $urutan++;
            $nilai = ['barang_id' => null, 'sub_resep_id' => null, 'catatan' => null,
                'qty_input' => null, 'satuan_input_id' => null, 'qty_dasar' => null];

            $nama = trim((string) ($b['nama'] ?? ''));
            if ($nama === '') { // a cooking-step line
                $nilai['catatan'] = mb_substr(trim((string) ($b['catatan'] ?? '')), 0, 190) ?: '-';
                $this->baris($resep, $urutan, $nilai);

                continue;
            }

            $qty = (float) ($b['qty'] ?? 0);
            $unit = trim((string) ($b['satuan'] ?? ''));
            $sub = ($b['ref'] ?? '') === 'resep' ? $this->cariResep($nama, (string) $r->jenis, $resep_, $rows, (string) $r->id) : null;
            $barang = $sub ? null : ($this->barang[mb_strtolower($nama)] ?? null);
            if (! $sub && ! $barang && ($b['ref'] ?? '') !== 'resep') {
                // legacy also falls back to a recipe of the same name when the Barang is a shadow row
                $sub = $this->cariResep($nama, (string) $r->jenis, $resep_, $rows, (string) $r->id);
            }

            if ((! $sub && ! $barang) || $qty <= 0) {
                $alasan = (! $sub && ! $barang) ? 'bahan_tidak_dikenal' : 'qty_nol_atau_negatif';
                $this->issue($alasan, "{$label}: {$nama} {$qty} {$unit}");
                $nilai['catatan'] = mb_substr("[tidak dikenal] {$nama} {$qty} {$unit}", 0, 190);
                $this->baris($resep, $urutan, $nilai);

                continue;
            }

            // The unit the line was entered in; legacy may leave it empty = the target unit.
            $target = $sub ? $sub->yield_satuan_id : $barang->satuan_dasar_id;
            $satuan = $unit !== '' ? $this->satuan($unit) : ($target ? Satuan::find($target) : null);
            if (! $satuan) {
                $this->issue('satuan_kosong', "{$label}: {$nama}");
                $nilai['catatan'] = mb_substr("[tanpa satuan] {$nama} {$qty}", 0, 190);
                $this->baris($resep, $urutan, $nilai);

                continue;
            }
            $dasar = $this->keDasar($qty, $satuan, $target, $barang);
            if ($dasar === null) {
                $this->issue('satuan_tidak_bisa_dikonversi', "{$label}: {$nama} {$qty} {$satuan->nama}");
            }
            $nilai['barang_id'] = $barang?->id;
            $nilai['sub_resep_id'] = $sub?->id;
            $nilai['qty_input'] = $this->dec($qty);
            $nilai['satuan_input_id'] = $satuan->id;
            $nilai['qty_dasar'] = $dasar === null ? null : $this->dec($dasar);
            if ($sub && $sub->id === $resep->id) {
                $this->issue('resep_memakai_dirinya', $label);
                $nilai = array_merge($nilai, ['sub_resep_id' => null, 'qty_input' => null, 'satuan_input_id' => null,
                    'qty_dasar' => null, 'catatan' => mb_substr("[siklus] {$nama}", 0, 190)]);
            }
            $this->baris($resep, $urutan, $nilai);
        }
        ResepBaris::where('resep_id', $resep->id)->where('urutan', '>', $urutan)->delete();
    }

    /** @param array<string, mixed> $nilai */
    private function baris(Resep $resep, int $urutan, array $nilai): void
    {
        $this->save(ResepBaris::firstOrNew(['resep_id' => $resep->id, 'urutan' => $urutan]), $nilai);
    }

    /**
     * A sub-recipe by name: a base of the same jenis first, then any recipe of
     * the same jenis, then any jenis (legacy cariResep looks in its own jenis),
     * never the recipe itself.
     *
     * @param  array<string, Resep>  $resep
     * @param  iterable<object>  $rows
     */
    private function cariResep(string $nama, string $jenis, array $resep, iterable $rows, string $kecuali = ''): ?Resep
    {
        $n = mb_strtolower($nama);
        $kandidat = [];
        foreach ($rows as $r) {
            // never the recipe itself: a prasmanan dish named like the menu it uses
            if ((string) $r->id !== $kecuali && mb_strtolower(trim((string) $r->nama)) === $n) {
                $kandidat[] = $r;
            }
        }
        usort($kandidat, fn ($a, $b) => [(string) $a->jenis !== $jenis, (string) $a->tipe !== 'base']
            <=> [(string) $b->jenis !== $jenis, (string) $b->tipe !== 'base']);

        return $kandidat ? $resep[(string) $kandidat[0]->id] : null;
    }

    /** Quantity in the target unit: same unit, unit family, then the Barang's own sizes. */
    private function keDasar(float $qty, Satuan $dari, ?string $target, ?Barang $barang): ?float
    {
        if (! $target) {
            return null;
        }
        if ($dari->id === $target) {
            return $qty;
        }
        $ke = Satuan::find($target);
        if ($ke && $dari->keluarga && $dari->keluarga === $ke->keluarga) {
            return $qty * (float) $dari->faktor / (float) $ke->faktor;
        }
        if ($barang) {
            $ukuran = BarangSatuan::where('barang_id', $barang->id)->where('satuan_id', $dari->id)
                ->orderByDesc('berlaku_dari')->value('ukuran');
            if ($ukuran !== null && (float) $ukuran > 0) {
                return $qty * (float) $ukuran;
            }
        }

        return null;
    }

    /**
     * The Barang a Central Kitchen base produces: legacy hpp_resep.di_purchasing,
     * or hpp_bahan.sisi_harga = 'resep' (price taken from the recipe of that name).
     *
     * @param  iterable<object>  $rows
     * @param  array<string, Resep>  $resep
     */
    private function tautkanBarang($legacy, iterable $rows, array $resep): void
    {
        $nama = [];
        foreach ($rows as $r) {
            if ((int) ($r->di_purchasing ?? 0) === 1) {
                $nama[mb_strtolower(trim((string) $r->nama))] = true;
            }
        }
        foreach ($legacy->table('hpp_bahan')->where('sisi_harga', 'resep')->pluck('nama') as $n) {
            $nama[mb_strtolower(trim((string) $n))] = true;
        }
        foreach (array_keys($nama) as $n) {
            $barang = $this->barang[$n] ?? null;
            $r = $barang ? $this->cariResep($n, 'food', $resep, $rows) : null;
            if (! $barang || ! $r) {
                $this->issue('barang_hasil_resep_tidak_ditemukan', $n);

                continue;
            }
            $lain = Resep::withTrashed()->where('barang_id', $barang->id)->where('id', '!=', $r->id)->first();
            if ($lain) {
                $this->issue('barang_sudah_dihasilkan_resep_lain', "{$n}: {$lain->nama}");

                continue;
            }
            $this->save($r, ['barang_id' => $barang->id]);
        }
    }

    private function importPengaturan($legacy): int
    {
        $raw = $legacy->table('hpp_setting')->where('id', 1)->value('data');
        $d = json_decode((string) $raw, true);
        $d = is_array($d) ? $d : [];
        $row = PengaturanHpp::firstOrNew(['berlaku_dari' => BarangImporter::BERLAKU_AWAL]);
        // defaults are the legacy ones (hpp_setting_baca)
        $this->save($row, [
            'target_food' => $this->dec((float) ($d['targetFood'] ?? 0.33)),
            'target_drink' => $this->dec((float) ($d['targetDrink'] ?? 0.33)),
            'spare' => $this->dec((float) ($d['buffer'] ?? 0.05)),
            'lampu_kuning' => number_format((float) ($d['lampuKuning'] ?? 3), 2, '.', ''),
            'lampu_merah' => number_format((float) ($d['lampuMerah'] ?? 8), 2, '.', ''),
        ]);

        return 1;
    }

    private function importKontrolBahan($legacy): int
    {
        $bulanan = $legacy->table('hpp_bulan')->orderBy('bulan')->get();
        if ($bulanan->isEmpty() && $legacy->table('hpp_pakai')->count() === 0) {
            return 0;
        }
        $outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
        $n = 0;
        $bulanSemua = $bulanan->pluck('bulan')->merge($legacy->table('hpp_pakai')->distinct()->pluck('bulan'))->unique();
        foreach ($bulanSemua as $bulan) {
            if (! preg_match('/^\d{4}-\d{2}$/', (string) $bulan)) {
                $this->issue('bulan_tidak_sah', (string) $bulan);

                continue;
            }
            $meta = $bulanan->firstWhere('bulan', $bulan);
            $kb = KontrolBahan::firstOrNew(['legacy_id' => (string) $bulan]);
            $this->save($kb, [
                'lokasi_id' => $outlet->id,
                'bulan' => "{$bulan}-01",
                'penjualan' => (string) round((float) ($meta->penjualan ?? 0)),
                'catatan' => trim((string) ($meta->catatan ?? '')) ?: null,
            ]);
            foreach ($legacy->table('hpp_pakai')->where('bulan', $bulan)->get() as $p) {
                $barang = $this->barang[mb_strtolower(trim((string) $p->bahan))] ?? null;
                if (! $barang) {
                    $this->issue('kontrol_bahan_barang_tidak_dikenal', "{$bulan}: {$p->bahan}");

                    continue;
                }
                $this->save(KontrolBahanBaris::firstOrNew(['kontrol_bahan_id' => $kb->id, 'barang_id' => $barang->id]), [
                    'stok_awal' => $this->dec((float) $p->sa), 'belanja' => $this->dec((float) $p->beli),
                    'pakai_resep' => $this->dec((float) $p->resep), 'spoil' => $this->dec((float) $p->spoil),
                    'team' => $this->dec((float) $p->team), 'rnd' => $this->dec((float) $p->rnd),
                    'compliment' => $this->dec((float) $p->comp), 'stok_akhir' => $this->dec((float) $p->opname),
                ]);
            }
            $n++;
        }

        return $n;
    }

    /** Units of a known family get their fixed ratio (rule 4), once. */
    private function keluargaSatuan(): void
    {
        foreach (Satuan::withTrashed()->get() as $s) {
            $this->tandaiKeluarga($s);
        }
    }

    private function tandaiKeluarga(Satuan $s): void
    {
        $k = self::KELUARGA[mb_strtolower(trim($s->nama))] ?? null;
        if ($k && $s->keluarga === null) {
            $this->save($s, ['keluarga' => $k[0], 'faktor' => $this->dec((float) $k[1])]);
        }
    }

    private function satuan(string $nama): Satuan
    {
        $k = mb_strtolower(trim($nama));
        if (! isset($this->satuan[$k])) {
            $s = Satuan::withTrashed()->where('nama', trim($nama))->first() ?? Satuan::create(['nama' => trim($nama)]);
            $this->tandaiKeluarga($s);
            $this->satuan[$k] = $s;
        }

        return $this->satuan[$k];
    }

    /** @param array<string, mixed> $values */
    private function save(Model $row, array $values): void
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }
    }

    private function dec(float $v): string
    {
        return number_format($v, 4, '.', '');
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
