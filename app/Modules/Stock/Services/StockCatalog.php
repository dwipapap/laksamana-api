<?php

namespace App\Modules\Stock\Services;

use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Products (bahan baku) and vendors — maps keyed by NAME. Port of the
 * PRODUCTS / VENDORS / IMPOR sections of lib_stock_mysql.php.
 *
 * Rules kept:
 *  - preserve-if-null: a field the caller did not send (null) keeps its old
 *    value; only an explicit value (even empty) changes it
 *  - rename = delete the old name + upsert the new one, in one transaction;
 *    for products the rename (and a new product) then follows into HPP,
 *    outside the transaction and never failing the save
 *  - imports upsert by name through the same single-row save and delete nothing
 *  - reads normalise every optional field so the frontend never sees undefined
 */
class StockCatalog
{
    public function __construct(private readonly StockHppNames $hpp) {}

    // ─────────────────────────────── vendors ──

    /** pur_hari_normal(): closing days as sorted unique ints 0..6. */
    public static function hari(mixed $v): array
    {
        if (is_string($v)) {
            $v = ($v === '' ? [] : explode(',', $v));
        }
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $h) {
            if (! is_numeric($h)) {
                continue;
            }
            $n = (int) $h;
            if ($n < 0 || $n > 6 || in_array($n, $out, true)) {
                continue;
            }
            $out[] = $n;
        }
        sort($out);

        return array_values($out);
    }

    public function vendors(): stdClass
    {
        $out = [];
        foreach (StockSupport::db()->select(StockSupport::q('SELECT `nama`, `data` FROM {vendors} ORDER BY `nama`')) as $r) {
            $v = json_decode((string) $r->data);
            if (! is_object($v)) {
                $v = (object) ['whatsapp' => ''];
            }
            $v->perluJadwalJemput = isset($v->perluJadwalJemput) ? (bool) $v->perluJadwalJemput : false;
            $v->tutupHari = self::hari($v->tutupHari ?? null);
            $v->penerima = isset($v->penerima) ? StockSupport::str($v->penerima) : '';
            $v->bank = isset($v->bank) ? StockSupport::str($v->bank) : '';
            $v->norek = isset($v->norek) ? StockSupport::str($v->norek) : '';
            $out[$r->nama] = $v;
        }

        return (object) $out;
    }

    /** pur_vendor_simpan(). */
    public function saveVendor(mixed $nama, mixed $telp, mixed $namaLama = '', mixed $perluJadwalJemput = null,
        mixed $tutupHari = null, mixed $penerima = null, mixed $bank = null, mixed $norek = null): array
    {
        $nama = trim(StockSupport::str($nama));
        if ($nama === '') {
            return ['status' => 'error', 'message' => 'nama vendor kosong'];
        }
        $db = StockSupport::db();
        if ($perluJadwalJemput === null || $tutupHari === null || $penerima === null || $bank === null || $norek === null) {
            $row = $db->selectOne(StockSupport::q('SELECT `data` FROM {vendors} WHERE `nama` = ?'), [$namaLama !== '' ? $namaLama : $nama]);
            $pjLama = false;
            $thLama = [];
            $peLama = $bkLama = $noLama = '';
            if ($row) {
                $lama = json_decode((string) $row->data);
                if (is_object($lama)) {
                    if (isset($lama->perluJadwalJemput)) {
                        $pjLama = (bool) $lama->perluJadwalJemput;
                    }
                    if (isset($lama->tutupHari)) {
                        $thLama = self::hari($lama->tutupHari);
                    }
                    if (isset($lama->penerima)) {
                        $peLama = StockSupport::str($lama->penerima);
                    }
                    if (isset($lama->bank)) {
                        $bkLama = StockSupport::str($lama->bank);
                    }
                    if (isset($lama->norek)) {
                        $noLama = StockSupport::str($lama->norek);
                    }
                }
            }
            $perluJadwalJemput ??= $pjLama;
            $tutupHari ??= $thLama;
            $penerima ??= $peLama;
            $bank ??= $bkLama;
            $norek ??= $noLama;
        }

        $rec = (object) ['whatsapp' => StockSupport::str($telp), 'perluJadwalJemput' => (bool) $perluJadwalJemput,
            'tutupHari' => self::hari($tutupHari),
            'penerima' => trim(StockSupport::str($penerima)), 'bank' => trim(StockSupport::str($bank)),
            'norek' => trim(StockSupport::str($norek))];

        $db->beginTransaction();
        try {
            $namaLama = trim(StockSupport::str($namaLama));
            if ($namaLama !== '' && $namaLama !== $nama) {
                // products pointing at the old vendor are left alone on purpose (free text)
                $db->delete(StockSupport::q('DELETE FROM {vendors} WHERE `nama` = ?'), [$namaLama]);
            }
            $db->statement(StockSupport::q('INSERT INTO {vendors} (`nama`,`whatsapp`,`data`) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE `whatsapp`=VALUES(`whatsapp`), `data`=VALUES(`data`)'),
                [$nama, StockSupport::str($telp), StockSupport::enc($rec)]);
            $db->commit();

            return ['status' => 'success'];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteVendor(mixed $nama): array
    {
        return ['status' => 'success', 'deleted' => StockSupport::db()->delete(StockSupport::q('DELETE FROM {vendors} WHERE `nama` = ?'), [StockSupport::str($nama)])];
    }

    // ─────────────────────────────── products ──

    /** pur_area_normal(): list of areas, case-insensitive unique, first spelling kept. */
    public static function area(mixed $v): array
    {
        if (is_string($v)) {
            $v = ($v === '' ? [] : explode(',', $v));
        }
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $a) {
            $a = trim(StockSupport::str($a));
            if ($a === '') {
                continue;
            }
            foreach ($out as $x) {
                if (strcasecmp($x, $a) === 0) {
                    continue 2;
                }
            }
            $out[] = $a;
        }

        return array_values($out);
    }

    /** pur_isi_normal(): {unit: base units per unit}, positive numbers only. */
    public static function isi(mixed $v): stdClass
    {
        if (is_string($v)) {
            $d = json_decode($v);
            if ($d !== null) {
                $v = $d;
            }
        }
        if (is_object($v)) {
            $v = (array) $v;
        }
        if (! is_array($v)) {
            return new stdClass;
        }
        $out = [];
        foreach ($v as $sat => $n) {
            $sat = trim((string) $sat);
            if ($sat === '') {
                continue;
            }
            $n = is_array($n) ? (float) (bool) $n : (float) $n;
            if (! ($n > 0)) {
                continue;
            }
            foreach ($out as $k => $_) {
                if (strcasecmp((string) $k, $sat) === 0) {
                    continue 2;
                }
            }
            $out[$sat] = $n;
        }

        return (object) $out;
    }

    public function products(): stdClass
    {
        $out = [];
        foreach (StockSupport::db()->select(StockSupport::q('SELECT `nama`, `data` FROM {products} ORDER BY `nama`')) as $r) {
            $out[$r->nama] = self::normaliseProduct(json_decode((string) $r->data));
        }

        return (object) $out;
    }

    public static function normaliseProduct(mixed $p): stdClass
    {
        if (! is_object($p)) {
            $p = (object) ['utama' => '', 'cadangan' => []];
        }
        if (! isset($p->cadangan) || ! is_array($p->cadangan)) {
            $p->cadangan = [];
        }
        if (! isset($p->satuan) || ! is_array($p->satuan)) {
            $p->satuan = [];
        }
        if (! isset($p->kategori) || ! is_string($p->kategori)) {
            $p->kategori = '';
        }
        $p->area = self::area($p->area ?? null);
        $p->aktif = isset($p->aktif) ? (bool) $p->aktif : true;
        if (! isset($p->satuanDasar) || ! is_string($p->satuanDasar)) {
            $p->satuanDasar = '';
        }
        $p->satuanDasar = trim($p->satuanDasar);
        $p->isi = self::isi($p->isi ?? null);
        if (! isset($p->sumber) || ! is_string($p->sumber)) {
            $p->sumber = '';
        }
        $p->packIsi = isset($p->packIsi) ? (float) $p->packIsi : 0;
        if (! isset($p->packSatuan) || ! is_string($p->packSatuan)) {
            $p->packSatuan = '';
        }
        $p->diOutlet = ! empty($p->diOutlet);

        return $p;
    }

    /**
     * pur_product_simpan(). Positional like legacy (items.php and hpp.php call
     * it that way); every optional argument is preserve-if-null.
     */
    public function saveProduct(mixed $nama, mixed $utama, mixed $cadangan, mixed $namaLama = '', mixed $satuan = null,
        mixed $kategori = null, mixed $area = null, mixed $caraBeli = null, mixed $sumber = null, mixed $packIsi = null,
        mixed $packSatuan = null, mixed $diOutlet = null, mixed $satuanDasar = null, mixed $isi = null, mixed $aktif = null): array
    {
        $nama = trim(StockSupport::str($nama));
        if ($nama === '') {
            return ['status' => 'error', 'message' => 'nama produk kosong'];
        }
        if (is_string($cadangan)) {
            $cadangan = array_values(array_filter(array_map('trim', explode(',', $cadangan)), fn ($s) => $s !== ''));
        }
        if (! is_array($cadangan)) {
            $cadangan = [];
        }
        $cadangan = array_values(array_map(fn ($s) => StockSupport::str($s), $cadangan));

        $db = StockSupport::db();
        $satuanLama = [];
        $kategoriLama = '';
        $areaLama = [];
        $caraBeliLama = '';
        $sumberLama = '';
        $packIsiLama = 0;
        $packSatuanLama = '';
        $diOutletLama = false;
        $satuanDasarLama = '';
        $aktifLama = true;
        $isiLama = new stdClass;
        if ($satuan === null || $kategori === null || $area === null || $caraBeli === null || $sumber === null
            || $packIsi === null || $packSatuan === null || $diOutlet === null || $satuanDasar === null
            || $isi === null || $aktif === null) {
            $row = $db->selectOne(StockSupport::q('SELECT `data` FROM {products} WHERE `nama` = ?'), [$namaLama !== '' ? $namaLama : $nama]);
            $lama = $row ? json_decode((string) $row->data) : null;
            if (is_object($lama)) {
                if (isset($lama->satuan) && is_array($lama->satuan)) {
                    $satuanLama = $lama->satuan;
                }
                if (isset($lama->kategori) && is_string($lama->kategori)) {
                    $kategoriLama = $lama->kategori;
                }
                if (isset($lama->area)) {
                    $areaLama = self::area($lama->area);
                }
                if (isset($lama->caraBeli) && is_string($lama->caraBeli)) {
                    $caraBeliLama = $lama->caraBeli;
                }
                if (isset($lama->sumber) && is_string($lama->sumber)) {
                    $sumberLama = $lama->sumber;
                }
                if (isset($lama->packIsi)) {
                    $packIsiLama = (float) $lama->packIsi;
                }
                if (isset($lama->packSatuan) && is_string($lama->packSatuan)) {
                    $packSatuanLama = $lama->packSatuan;
                }
                if (isset($lama->diOutlet)) {
                    $diOutletLama = (bool) $lama->diOutlet;
                }
                if (isset($lama->satuanDasar) && is_string($lama->satuanDasar)) {
                    $satuanDasarLama = $lama->satuanDasar;
                }
                if (isset($lama->isi)) {
                    $isiLama = self::isi($lama->isi);
                }
                if (isset($lama->aktif)) {
                    $aktifLama = (bool) $lama->aktif;
                }
            }
        }
        $satuan ??= $satuanLama;
        $kategori ??= $kategoriLama;
        $area ??= $areaLama;
        $caraBeli ??= $caraBeliLama;
        $sumber ??= $sumberLama;
        $packIsi ??= $packIsiLama;
        $packSatuan ??= $packSatuanLama;
        $diOutlet ??= $diOutletLama;
        $satuanDasar ??= $satuanDasarLama;
        $isi ??= $isiLama;
        $aktif ??= $aktifLama;

        if (is_string($satuan)) {
            $satuan = array_values(array_filter(array_map('trim', explode(',', $satuan)), fn ($s) => $s !== ''));
        }
        if (! is_array($satuan)) {
            $satuan = [];
        }
        $satuan = array_values(array_unique(array_filter(array_map(fn ($s) => trim(StockSupport::str($s)), $satuan), fn ($s) => $s !== '')));

        $kategori = trim(StockSupport::str($kategori));
        $area = self::area($area);
        $caraBeli = strtolower(trim(StockSupport::str($caraBeli)));
        if ($caraBeli !== 'online' && $caraBeli !== 'jemput') {
            $caraBeli = '';
        }
        $sumber = strtolower(trim(StockSupport::str($sumber)));
        if ($sumber !== 'ck' && $sumber !== 'both') {
            $sumber = '';
        }
        $adaDiCK = ($sumber === 'ck' || $sumber === 'both');

        $packIsi = (float) (is_array($packIsi) ? (bool) $packIsi : $packIsi);
        $packSatuan = trim(StockSupport::str($packSatuan));
        $diOutlet = (bool) $diOutlet;
        if ($sumber === 'both') {
            $diOutlet = true;
        }
        if (! $adaDiCK) {
            $packIsi = 0;
            $packSatuan = '';
            $diOutlet = false;
        }
        if ($packIsi < 0) {
            $packIsi = 0;
        }

        // Pure CK goods are forced to [Pack, base unit]; 'both' only gets them added.
        if ($sumber === 'ck' && $packSatuan !== '') {
            $satuan = $packIsi > 0 ? ['Pack', $packSatuan] : [$packSatuan];
        } elseif ($sumber === 'both' && $packSatuan !== '' && $satuan) {
            foreach ($packIsi > 0 ? ['Pack', $packSatuan] : [$packSatuan] as $w) {
                if (! in_array($w, $satuan, true)) {
                    $satuan[] = $w;
                }
            }
        }

        $satuanDasar = trim(StockSupport::str($satuanDasar));
        $isi = self::isi($isi);
        if ($satuanDasar !== '') {
            foreach ((array) $isi as $k => $_) {
                if (strcasecmp((string) $k, $satuanDasar) === 0) {
                    unset($isi->$k);
                }
            }
        } else {
            $isi = new stdClass;
        }
        if ($satuan) {
            $tambah = array_keys((array) $isi);
            if ($satuanDasar !== '') {
                $tambah[] = $satuanDasar;
            }
            foreach ($tambah as $w) {
                foreach ($satuan as $s) {
                    if (strcasecmp((string) $s, (string) $w) === 0) {
                        continue 2;
                    }
                }
                $satuan[] = $w;
            }
        }

        $rec = (object) ['utama' => StockSupport::str($utama), 'cadangan' => $cadangan, 'satuan' => $satuan,
            'kategori' => $kategori, 'area' => $area, 'caraBeli' => $caraBeli,
            'sumber' => $sumber, 'packIsi' => $packIsi, 'packSatuan' => $packSatuan,
            'diOutlet' => $diOutlet, 'satuanDasar' => $satuanDasar, 'isi' => $isi,
            'aktif' => (bool) $aktif];

        $db->beginTransaction();
        try {
            $namaLama = trim(StockSupport::str($namaLama));
            if ($namaLama !== '' && $namaLama !== $nama) {
                $db->delete(StockSupport::q('DELETE FROM {products} WHERE `nama` = ?'), [$namaLama]);
            }
            $db->statement(StockSupport::q('INSERT INTO {products} (`nama`,`utama`,`data`) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE `utama`=VALUES(`utama`), `data`=VALUES(`data`)'),
                [$nama, StockSupport::str($utama), StockSupport::enc($rec)]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Outside the transaction: HPP following a rename must never undo it.
        $laporHpp = null;
        $lahirHpp = false;
        try {
            if ($namaLama !== '' && $namaLama !== $nama) {
                $laporHpp = $this->hpp->followRename($namaLama, $nama);
            }
            $lahirHpp = $this->hpp->followAdd($nama, $satuan);
        } catch (Throwable $e) {
            Log::warning('[stock/items] sinkron HPP gagal: '.$e->getMessage());
        }
        $out = ['status' => 'success'];
        if ($laporHpp && ($laporHpp['bahan'] || $laporHpp['resep'] || $laporHpp['lewat'])) {
            $out['hpp'] = $laporHpp;
        }
        if ($lahirHpp) {
            $out['hppBaru'] = true;
        }

        return $out;
    }

    public function deleteProduct(mixed $nama): array
    {
        return ['status' => 'success', 'deleted' => StockSupport::db()->delete(StockSupport::q('DELETE FROM {products} WHERE `nama` = ?'), [StockSupport::str($nama)])];
    }

    // ─────────────────────────────── imports ──

    /** @return array<string,true> lower(trim(nama)) of every row of $table */
    private function existing(string $table): array
    {
        $ada = [];
        foreach (StockSupport::db()->select(StockSupport::q("SELECT `nama` FROM {{$table}}")) as $r) {
            $ada[mb_strtolower(trim((string) $r->nama))] = true;
        }

        return $ada;
    }

    public function importVendors(mixed $rows): array
    {
        if (! is_array($rows)) {
            return ['status' => 'error', 'message' => 'rows bukan array'];
        }

        return $this->import('vendors', $rows, fn ($nm, $r) => $this->saveVendor($nm, $r->whatsapp ?? '', '',
            $r->perluJadwalJemput ?? null, $r->tutupHari ?? null, $r->penerima ?? null, $r->bank ?? null, $r->norek ?? null));
    }

    public function importProducts(mixed $rows): array
    {
        if (! is_array($rows)) {
            return ['status' => 'error', 'message' => 'rows bukan array'];
        }

        // namaLama stays empty: an import never renames (a different name is a new product)
        return $this->import('products', $rows, fn ($nm, $r) => $this->saveProduct($nm, $r->utama ?? '', $r->cadangan ?? [], '',
            $r->satuan ?? null, $r->kategori ?? null, $r->area ?? null, $r->caraBeli ?? null, $r->sumber ?? null,
            $r->packIsi ?? null, $r->packSatuan ?? null, $r->diOutlet ?? null, $r->satuanDasar ?? null,
            $r->isi ?? null, $r->aktif ?? null));
    }

    private function import(string $table, array $rows, \Closure $save): array
    {
        $ada = $this->existing($table);
        $baru = $ubah = $lewat = 0;
        $galat = [];
        foreach ($rows as $r) {
            if (! is_object($r)) {
                $lewat++;

                continue;
            }
            $nm = trim(StockSupport::str($r->nama ?? ''));
            if ($nm === '') {
                $lewat++;

                continue;
            }
            $k = mb_strtolower($nm);
            $sebelum = isset($ada[$k]);
            $res = $save($nm, $r);
            if (is_array($res) && ($res['status'] ?? '') === 'error') {
                $galat[] = $nm;

                continue;
            }
            if ($sebelum) {
                $ubah++;
            } else {
                $baru++;
                $ada[$k] = true;
            }
        }

        return ['status' => 'success', 'baru' => $baru, 'diubah' => $ubah, 'dilewati' => $lewat, 'galat' => $galat];
    }
}
