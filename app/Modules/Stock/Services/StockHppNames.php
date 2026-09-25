<?php

namespace App\Modules\Stock\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port of lib_hpp_nama.php: a product renamed or added in Purchasing follows
 * into HPP & Resep (hpp_bahan, hpp_pakai, hpp_resep.bahan), server-side, so
 * every caller of the product save carries HPP along.
 *
 * Never throws to its caller: a rename in Purchasing is already valid and
 * must not fail because HPP could not follow. A target name already used by
 * another HPP row is skipped (merging two priced ingredients is a human call).
 * Table presence is checked read-only through information_schema (the HPP
 * tables are created by the HPP backend, not here).
 */
class StockHppNames
{
    /** @var array<string,bool> */
    private array $tables = [];

    public function tableExists(string $name): bool
    {
        if (! array_key_exists($name, $this->tables)) {
            try {
                $this->tables[$name] = (bool) (int) StockSupport::db()->selectOne(
                    'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name])->c;
            } catch (Throwable $e) {
                Log::warning('[stock/hpp-nama] cek tabel '.$name.' gagal: '.$e->getMessage());
                $this->tables[$name] = false;
            }
        }

        return $this->tables[$name];
    }

    /** hpp_ikut_tambah(): a new product gets a zero-priced hpp_bahan row; existing rows are never touched. */
    public function followAdd(string $nama, mixed $satuan = []): bool
    {
        $nama = trim($nama);
        if ($nama === '' || ! $this->tableExists('hpp_bahan')) {
            return false;
        }
        try {
            $db = StockSupport::db();
            if ((int) $db->selectOne('SELECT COUNT(*) AS c FROM hpp_bahan WHERE nama = ?', [$nama])->c) {
                return false;
            }
            $sat = (is_array($satuan) && count($satuan)) ? mb_substr(trim(StockSupport::str($satuan[0])), 0, 32) : '';
            $db->insert("INSERT INTO hpp_bahan (nama,satuan,qty_beli,harga_beli,vendor,produk,kategori,catatan,updated_at,updated_by)
                         VALUES (?,?,0,0,'','','',?,?,'purchasing')",
                [$nama, $sat, 'Dibuat otomatis dari Purchasing — harga belum diisi.', (int) (microtime(true) * 1000)]);

            return true;
        } catch (Throwable $e) {
            Log::warning('[stock/hpp-nama] tambah: '.$e->getMessage());

            return false;
        }
    }

    /** hpp_ikut_ganti_nama(): report {bahan, resep, pakai, lewat}. */
    public function followRename(string $lama, string $baru): array
    {
        $lama = trim($lama);
        $baru = trim($baru);
        $out = ['bahan' => 0, 'resep' => 0, 'pakai' => 0, 'lewat' => ''];
        if ($lama === '' || $baru === '' || $lama === $baru || ! $this->tableExists('hpp_bahan')) {
            return $out;
        }
        $db = StockSupport::db();
        try {
            if (! (int) $db->selectOne('SELECT COUNT(*) AS c FROM hpp_bahan WHERE nama = ?', [$lama])->c) {
                return $out;
            }
            if ((int) $db->selectOne('SELECT COUNT(*) AS c FROM hpp_bahan WHERE nama = ?', [$baru])->c) {
                $out['lewat'] = 'nama "'.$baru.'" sudah dipakai bahan HPP lain';

                return $out;
            }
            $db->update('UPDATE hpp_bahan SET nama = ? WHERE nama = ?', [$baru, $lama]);
            $out['bahan'] = 1;
            $db->update('UPDATE hpp_bahan SET produk = ? WHERE produk = ?', [$baru, $lama]);

            if ($this->tableExists('hpp_pakai')) {
                foreach ($db->select('SELECT bulan FROM hpp_pakai WHERE bahan = ?', [$lama]) as $r) {
                    if ((int) $db->selectOne('SELECT COUNT(*) AS c FROM hpp_pakai WHERE bahan = ? AND bulan = ?', [$baru, $r->bulan])->c) {
                        continue; // that month already has the new name: never overwrite someone's count
                    }
                    $db->update('UPDATE hpp_pakai SET bahan = ? WHERE bahan = ? AND bulan = ?', [$baru, $lama, $r->bulan]);
                    $out['pakai']++;
                }
            }

            if ($this->tableExists('hpp_resep')) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $lama).'%';
                foreach ($db->select('SELECT id, bahan FROM hpp_resep WHERE bahan LIKE ?', [$like]) as $r) {
                    $baris = json_decode((string) $r->bahan, true);
                    if (! is_array($baris)) {
                        continue;
                    }
                    $ubah = false;
                    foreach ($baris as &$ln) {
                        if (isset($ln['nama']) && $ln['nama'] === $lama) {
                            $ln['nama'] = $baru;
                            $ubah = true;
                        }
                    }
                    unset($ln);
                    if ($ubah) {
                        $db->update('UPDATE hpp_resep SET bahan = ? WHERE id = ?', [json_encode($baris, JSON_UNESCAPED_UNICODE), $r->id]);
                        $out['resep']++;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('[stock/hpp-nama] '.$e->getMessage());
            $out['lewat'] = 'kesalahan server saat menyesuaikan HPP';
        }

        return $out;
    }
}
