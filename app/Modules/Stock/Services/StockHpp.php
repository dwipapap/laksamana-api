<?php

namespace App\Modules\Stock\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * HPP & Resep (hpp.php): ingredient prices (`hpp_bahan`), recipes (`hpp_resep`,
 * lines as JSON in `bahan`, a line may point at another recipe), monthly usage
 * (`hpp_pakai` + `hpp_bulan`) and settings (`hpp_setting`, row id 1).
 *
 * Rules kept from legacy:
 *  - one row per save (upsert by nama / id), never a whole-state save;
 *  - costs are NOT computed here (the screen computes them, cascading); only
 *    `per1` = harga_beli / qty_beli is derived on read;
 *  - renaming an ingredient patches every recipe line and moves monthly usage
 *    (months that already hold the new name are skipped);
 *  - a new ingredient with the Purchasing switch on (default) is registered as
 *    a product; a recipe with di_purchasing becomes a Central Kitchen product;
 *  - the imports never delete (except `impor` with `timpa`, the one-time move
 *    from Excel) and never rename; one bad row does not stop the others.
 * The legacy runtime DDL (hpp_pastikan_*) is not ported: tables and columns
 * exist live. Errors thrown here are answered by the compat route as
 * 500 "kesalahan server: <message>", like legacy.
 */
class StockHpp
{
    public function __construct(
        private readonly StockCatalog $catalog,
        private readonly StockHppNames $names,
    ) {}

    // ─────────────────────────────── helpers (hpp_num / hpp_txt / hpp_ms) ──

    public static function num(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', StockSupport::str($v));

        return ($s === '' || $s === '-') ? 0.0 : (float) $s;
    }

    public static function txt(mixed $v, int $n): string
    {
        return mb_substr(trim(StockSupport::str($v)), 0, $n);
    }

    public static function ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** `isset($d->k) ? $d->k : $default` on anything (non-objects have no fields). */
    private static function f(mixed $d, string $k, mixed $default = ''): mixed
    {
        return is_object($d) && isset($d->$k) ? $d->$k : $default;
    }

    private static function db()
    {
        return StockSupport::db();
    }

    /** Recipe lines whose `nama` is $from point at $to; returns how many recipes changed. */
    private function relinkRecipes(string $from, string $to): int
    {
        $n = 0;
        foreach (self::db()->select(StockSupport::q('SELECT {id} AS `id`,bahan FROM {hpp_resep}')) as $r) {
            $baris = json_decode((string) $r->bahan, true);
            if (! is_array($baris)) {
                continue;
            }
            $ubah = false;
            foreach ($baris as &$b) {
                if (isset($b['nama']) && $b['nama'] === $from) {
                    $b['nama'] = $to;
                    $ubah = true;
                }
            }
            unset($b);
            if ($ubah) {
                self::db()->update(StockSupport::q('UPDATE {hpp_resep} SET bahan=? WHERE {id}=?'), [json_encode($baris, JSON_UNESCAPED_UNICODE), $r->id]);
                $n++;
            }
        }

        return $n;
    }

    /** Monthly usage rows of $from move to $to, except months that already hold $to. */
    private function moveUsage(string $from, string $to): void
    {
        $db = self::db();
        foreach ($db->select(StockSupport::q('SELECT bulan FROM {hpp_pakai} WHERE bahan=?'), [$from]) as $m) {
            if ((int) $db->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {hpp_pakai} WHERE bahan=? AND bulan=?'), [$to, $m->bulan])->c) {
                continue;
            }
            $db->update(StockSupport::q('UPDATE {hpp_pakai} SET bahan=? WHERE bahan=? AND bulan=?'), [$to, $from, $m->bulan]);
        }
    }

    // ─────────────────────────────── reads ──

    /** hpp_setting_baca(): stored values over the defaults of the original HPP file. */
    public function settings(): array
    {
        $row = self::db()->selectOne(StockSupport::q('SELECT data FROM {hpp_setting} WHERE {id}=1'));
        $d = $row ? json_decode((string) $row->data, true) : null;
        if (! is_array($d)) {
            $d = [];
        }
        foreach (['targetFood' => 0.33, 'targetDrink' => 0.33, 'buffer' => 0.05, 'lampuKuning' => 3.0, 'lampuMerah' => 8.0] as $k => $v) {
            if (! isset($d[$k])) {
                $d[$k] = $v;
            }
        }

        return $d;
    }

    public static function ingredientRow(stdClass $r): array
    {
        $b = (array) $r;
        $b['qty_beli'] = (float) $b['qty_beli'];
        $b['harga_beli'] = (float) $b['harga_beli'];
        $b['per1'] = $b['qty_beli'] > 0 ? $b['harga_beli'] / $b['qty_beli'] : 0;
        $b['di_purchasing'] = isset($b['di_purchasing']) ? (int) $b['di_purchasing'] : 1;
        $s = isset($b['sisi_harga']) ? strtolower(trim((string) $b['sisi_harga'])) : '';
        $b['sisi_harga'] = ($s === 'beli' || $s === 'resep') ? $s : '';

        return $b;
    }

    public static function recipeRow(stdClass $r): array
    {
        $x = (array) $r;
        foreach (['yield_qty', 'harga_lama', 'harga_baru', 'harga_upsize', 'modal_manual'] as $k) {
            $x[$k] = (float) $x[$k];
        }
        $x['aktif'] = (int) $x['aktif'];
        $x['di_purchasing'] = isset($x['di_purchasing']) ? (int) $x['di_purchasing'] : 0;
        $j = json_decode((string) $x['bahan'], true);
        $x['bahan'] = is_array($j) ? $j : [];

        return $x;
    }

    /** hpp_ambil(): the flat payload of ?action=all (no `ok` key). */
    public function all(): array
    {
        return [
            'bahan' => array_map(self::ingredientRow(...), self::db()->select(StockSupport::q('SELECT {*hpp_bahan} FROM {hpp_bahan} ORDER BY nama'))),
            'resep' => array_map(self::recipeRow(...), self::db()->select(StockSupport::q('SELECT {*hpp_resep} FROM {hpp_resep} ORDER BY jenis, tipe, nama'))),
            'setting' => $this->settings(),
            'ts' => gmdate('c'),
        ];
    }

    /** hpp_pakai_ambil(): one month of usage + that month's sales, and the list of months. */
    public function usage(string $bulan): array
    {
        $baris = [];
        foreach (self::db()->select(StockSupport::q('SELECT {*hpp_pakai} FROM {hpp_pakai} WHERE bulan=? ORDER BY bahan'), [$bulan]) as $r) {
            $r = (array) $r;
            foreach (['sa', 'beli', 'resep', 'spoil', 'team', 'rnd', 'comp', 'opname'] as $k) {
                $r[$k] = (float) $r[$k];
            }
            $baris[] = $r;
        }
        $meta = self::db()->selectOne(StockSupport::q('SELECT {*hpp_bulan} FROM {hpp_bulan} WHERE bulan=?'), [$bulan]);

        return [
            'bulan' => $bulan, 'baris' => $baris,
            'penjualan' => $meta ? (float) $meta->penjualan : 0,
            'catatan' => $meta ? $meta->catatan : '',
            'daftarBulan' => array_column(self::db()->select(StockSupport::q('SELECT bulan FROM {hpp_bulan} ORDER BY bulan DESC')), 'bulan'),
        ];
    }

    // ─────────────────────────────── writes ──

    /** hpp_pakai_simpan(). */
    public function saveUsage(mixed $d, mixed $by): array
    {
        $bulan = trim(StockSupport::str(self::f($d, 'bulan')));
        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            throw new RuntimeException('Bulan tidak sah (YYYY-MM)');
        }
        $ms = self::ms();
        $ub = self::txt($by, 120);
        $n = 0;
        $baris = self::f($d, 'baris', null);
        if (is_array($baris)) {
            foreach ($baris as $r) {
                if (is_object($r)) {
                    $r = (array) $r;
                }
                $v = fn (string $k) => self::num(is_array($r) && isset($r[$k]) ? $r[$k] : 0);
                $nama = self::txt(is_array($r) && isset($r['bahan']) ? $r['bahan'] : '', 190);
                if ($nama === '') {
                    continue;
                }
                self::db()->statement(StockSupport::q('INSERT INTO {hpp_pakai} (bulan,bahan,sa,beli,resep,spoil,team,rnd,comp,opname,updated_at,updated_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE sa=VALUES(sa), beli=VALUES(beli), resep=VALUES(resep),
                       spoil=VALUES(spoil), team=VALUES(team), rnd=VALUES(rnd), comp=VALUES(comp),
                       opname=VALUES(opname), updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'),
                    [$bulan, $nama, $v('sa'), $v('beli'), $v('resep'), $v('spoil'), $v('team'), $v('rnd'), $v('comp'), $v('opname'), $ms, $ub]);
                $n++;
            }
        }
        self::db()->statement(StockSupport::q('INSERT INTO {hpp_bulan} (bulan,penjualan,catatan,updated_at,updated_by) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE penjualan=VALUES(penjualan), catatan=VALUES(catatan),
               updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'),
            [$bulan, self::num(self::f($d, 'penjualan', 0)), self::txt(self::f($d, 'catatan'), 255), $ms, $ub]);

        return ['status' => 'success', 'saved' => true, 'bulan' => $bulan, 'baris' => $n];
    }

    /** hpp_simpan_bahan(): upsert by name; `namaLama` renames and follows into recipes and usage. */
    public function saveIngredient(mixed $d, mixed $by): array
    {
        $nama = self::txt(self::f($d, 'nama'), 190);
        if ($nama === '') {
            throw new RuntimeException('Nama bahan kosong');
        }
        $lama = self::txt(self::f($d, 'namaLama'), 190);
        $s = strtolower(trim(StockSupport::str(self::f($d, 'sisi_harga'))));
        $sisi = ($s === 'beli' || $s === 'resep') ? $s : (self::f($d, 'dibeli_jadi', false) ? 'beli' : '');
        self::db()->statement(StockSupport::q('INSERT INTO {hpp_bahan} (nama,satuan,qty_beli,harga_beli,vendor,produk,kategori,catatan,di_purchasing,sisi_harga,updated_at,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE satuan=VALUES(satuan), qty_beli=VALUES(qty_beli),
               harga_beli=VALUES(harga_beli), vendor=VALUES(vendor), produk=VALUES(produk),
               kategori=VALUES(kategori), catatan=VALUES(catatan), di_purchasing=VALUES(di_purchasing),
               sisi_harga=VALUES(sisi_harga),
               updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'), [
            $nama,
            self::txt(self::f($d, 'satuan'), 32),
            self::num(self::f($d, 'qty_beli', 0)),
            self::num(self::f($d, 'harga_beli', 0)),
            self::txt(self::f($d, 'vendor'), 190),
            self::txt(self::f($d, 'produk'), 190),
            self::txt(self::f($d, 'kategori'), 64),
            self::txt(self::f($d, 'catatan'), 255),
            // raw ingredients are bought by default; the ones that are not are switched off one by one
            (is_object($d) && isset($d->di_purchasing) && ! $d->di_purchasing) ? 0 : 1,
            $sisi,
            self::ms(), self::txt($by, 120),
        ]);
        $ikut = 0;
        if ($lama !== '' && $lama !== $nama) {
            self::db()->delete(StockSupport::q('DELETE FROM {hpp_bahan} WHERE nama=?'), [$lama]);
            $this->moveUsage($lama, $nama);
            self::db()->update(StockSupport::q('UPDATE {hpp_bahan} SET produk=? WHERE produk=?'), [$nama, $lama]);
            $ikut = $this->relinkRecipes($lama, $nama);
        }

        return ['status' => 'success', 'saved' => true, 'nama' => $nama, 'resepIkutBerubah' => $ikut];
    }

    /** action simpanBahan: the save, then (switch on) register the name as a Purchasing product if missing. */
    public function saveIngredientAction(mixed $d, mixed $by): array
    {
        $nm = self::txt(self::f($d, 'nama'), 190);
        $hasil = $this->saveIngredient($d, $by);
        if (! (is_object($d) && isset($d->di_purchasing) && ! $d->di_purchasing)) {
            try {
                if (! (int) self::db()->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {products} WHERE nama=?'), [$nm])->c) {
                    $sat = self::txt(self::f($d, 'satuan'), 32);
                    $this->catalog->saveProduct($nm, '', [], '', $sat !== '' ? [$sat] : []);
                    $hasil['purchasingBaru'] = true;
                }
            } catch (Throwable $e) {
                Log::error('[stock/hpp] daftar ke purchasing gagal: '.$e->getMessage());
                $hasil['purchasingGagal'] = true;
            }
        }

        return $hasil;
    }

    /** hpp_simpan_resep(): upsert by id (a new one gets 'r<hex ms><hex rand>'). */
    public function saveRecipe(mixed $d, mixed $by): array
    {
        $nama = self::txt(self::f($d, 'nama'), 190);
        if ($nama === '') {
            throw new RuntimeException('Nama resep kosong');
        }
        $id = self::txt(self::f($d, 'id'), 48);
        if ($id === '') {
            $id = 'r'.dechex(self::ms()).dechex(mt_rand(0, 0xFFFF));
        }
        $baris = [];
        $lines = self::f($d, 'bahan', null);
        if (is_array($lines)) {
            foreach ($lines as $b) {
                if (is_object($b)) {
                    $b = (array) $b;
                }
                if (! is_array($b)) {
                    continue;
                }
                // note lines ("bumbu blender saring") are kept: they separate the cooking steps
                if (isset($b['catatan']) && ! isset($b['nama'])) {
                    $baris[] = ['catatan' => self::txt($b['catatan'], 190)];

                    continue;
                }
                $nm = self::txt($b['nama'] ?? '', 190);
                if ($nm === '') {
                    continue;
                }
                $baris[] = [
                    'nama' => $nm,
                    'qty' => self::num($b['qty'] ?? 0),
                    'satuan' => self::txt($b['satuan'] ?? '', 32),
                    'ref' => (isset($b['ref']) && $b['ref'] === 'resep') ? 'resep' : 'bahan',
                ];
            }
        }
        $yq = self::num(self::f($d, 'yield_qty', 1));
        self::db()->statement(StockSupport::q('INSERT INTO {hpp_resep} ({id},nama,jenis,tipe,seksi,kode,yield_qty,yield_unit,
               harga_lama,harga_baru,harga_upsize,modal_manual,catatan,bahan,aktif,di_purchasing,updated_at,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE nama=VALUES(nama), jenis=VALUES(jenis), tipe=VALUES(tipe),
               seksi=VALUES(seksi), kode=VALUES(kode), yield_qty=VALUES(yield_qty), yield_unit=VALUES(yield_unit),
               harga_lama=VALUES(harga_lama), harga_baru=VALUES(harga_baru),
               harga_upsize=VALUES(harga_upsize), modal_manual=VALUES(modal_manual),
               catatan=VALUES(catatan), bahan=VALUES(bahan),
               aktif=VALUES(aktif), di_purchasing=VALUES(di_purchasing),
               updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'), [
            $id, $nama,
            self::f($d, 'jenis', null) === 'drink' ? 'drink' : 'food',
            self::f($d, 'tipe', null) === 'dish' ? 'dish' : 'base',
            self::txt(self::f($d, 'seksi'), 96),
            strtoupper(self::txt(self::f($d, 'kode'), 64)), // menu codes are matched against the POS: one case
            $yq > 0 ? $yq : 1, // a zero yield would divide every dish using this base by zero
            self::txt(self::f($d, 'yield_unit'), 32),
            self::num(self::f($d, 'harga_lama', 0)),
            self::num(self::f($d, 'harga_baru', 0)),
            self::num(self::f($d, 'harga_upsize', 0)),
            self::num(self::f($d, 'modal_manual', 0)),
            self::txt(self::f($d, 'catatan'), 2000),
            json_encode($baris, JSON_UNESCAPED_UNICODE),
            (is_object($d) && isset($d->aktif) && ! $d->aktif) ? 0 : 1,
            self::f($d, 'di_purchasing', false) ? 1 : 0,
            self::ms(), self::txt($by, 120),
        ]);

        return ['status' => 'success', 'saved' => true, 'id' => $id, 'bahan' => count($baris)];
    }

    /** action simpanResep: the save, then a recipe marked di_purchasing becomes a CK product if missing. */
    public function saveRecipeAction(mixed $d, mixed $by): array
    {
        $hasil = $this->saveRecipe($d, $by);
        if (is_object($d) && ! empty($d->di_purchasing)) {
            $nmR = self::txt(self::f($d, 'nama'), 190);
            try {
                if ($nmR !== '' && ! (int) self::db()->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {products} WHERE nama=?'), [$nmR])->c) {
                    $satR = self::txt(self::f($d, 'yield_unit'), 32);
                    $this->catalog->saveProduct($nmR, '', [], '', $satR !== '' ? [$satR] : [], null, null, null, 'ck');
                    $hasil['purchasingBaru'] = true;
                }
            } catch (Throwable $e) {
                Log::error('[stock/hpp] daftar resep ke purchasing gagal: '.$e->getMessage());
                $hasil['purchasingGagal'] = true;
            }
        }

        return $hasil;
    }

    public function deleteIngredient(mixed $nama): array
    {
        self::db()->delete(StockSupport::q('DELETE FROM {hpp_bahan} WHERE nama=?'), [self::txt($nama, 190)]);

        return ['status' => 'success', 'saved' => true];
    }

    public function deleteRecipe(mixed $id): array
    {
        self::db()->delete(StockSupport::q('DELETE FROM {hpp_resep} WHERE {id}=?'), [self::txt($id, 48)]);

        return ['status' => 'success', 'saved' => true];
    }

    /**
     * gabungBahan: recipe lines and monthly usage of $dari move to $ke (months that
     * already hold $ke are dropped), then $dari is deleted. $ke's price is untouched.
     */
    public function merge(mixed $dari, mixed $ke): array
    {
        $dari = self::txt($dari, 190);
        $ke = self::txt($ke, 190);
        if ($dari === '' || $ke === '' || $dari === $ke) {
            return ['status' => 'error', 'message' => 'Nama gabung tidak sah'];
        }
        if (! (int) self::db()->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {hpp_bahan} WHERE nama=?'), [$ke])->c) {
            return ['status' => 'error', 'message' => 'Bahan tujuan "'.$ke.'" tidak ada'];
        }
        $nResep = $this->relinkRecipes($dari, $ke);
        $this->moveUsage($dari, $ke);
        self::db()->delete(StockSupport::q('DELETE FROM {hpp_pakai} WHERE bahan=?'), [$dari]);
        self::db()->delete(StockSupport::q('DELETE FROM {hpp_bahan} WHERE nama=?'), [$dari]);

        return ['status' => 'success', 'saved' => true, 'resep' => $nResep];
    }

    /** tarikProduk: every Purchasing product without an HPP row gets a zero-priced one. */
    public function pullProducts(): array
    {
        $n = 0;
        foreach (self::db()->select(StockSupport::q('SELECT nama, data FROM {products}')) as $p) {
            $j = json_decode((string) $p->data, true);
            $sat = is_array($j) && isset($j['satuan']) && is_array($j['satuan']) ? $j['satuan'] : [];
            if ($this->names->followAdd((string) $p->nama, $sat)) {
                $n++;
            }
        }

        return ['status' => 'success', 'saved' => true, 'ditarik' => $n];
    }

    /**
     * hapusBahanSamaResep: delete the "shadow" ingredients named like a recipe —
     * except when sisi_harga is set, when a BASE recipe has that name, or when a
     * recipe uses it as an ingredient line. Same rule as tabrakan() on the screen.
     */
    public function deleteShadows(): array
    {
        $dipakai = [];
        foreach (self::db()->select(StockSupport::q('SELECT bahan FROM {hpp_resep}')) as $row) {
            $baris = json_decode((string) $row->bahan, true);
            if (! is_array($baris)) {
                continue;
            }
            foreach ($baris as $ln) {
                if (! is_array($ln) || ! isset($ln['nama'])) {
                    continue;
                }
                if (isset($ln['ref']) && $ln['ref'] === 'resep') {
                    continue;
                }
                $dipakai[mb_strtolower(trim(StockSupport::str($ln['nama'])))] = true;
            }
        }
        $nama = [];
        foreach (self::db()->select(StockSupport::q("SELECT b.nama FROM {hpp_bahan} b
                  WHERE b.sisi_harga = ''
                    AND LOWER(b.nama) IN (SELECT LOWER(r.nama) FROM {hpp_resep} r)
                    AND LOWER(b.nama) NOT IN (SELECT LOWER(r2.nama) FROM {hpp_resep} r2 WHERE r2.tipe = 'base')")) as $r) {
            if (isset($dipakai[mb_strtolower(trim((string) $r->nama))])) {
                continue;
            }
            $nama[] = $r->nama;
        }
        foreach ($nama as $n) {
            self::db()->delete(StockSupport::q('DELETE FROM {hpp_bahan} WHERE nama=?'), [$n]);
        }

        return ['status' => 'success', 'dihapus' => count($nama), 'nama' => array_slice($nama, 0, 50)];
    }

    /**
     * samakanNama: every ingredient paired with a Purchasing product (`produk`) is
     * renamed to that product's name (recipes follow), then the pairing is cleared.
     * A target name already used by another row is skipped and reported.
     */
    public function alignNames(mixed $by): array
    {
        $rows = self::db()->select(StockSupport::q("SELECT {*hpp_bahan} FROM {hpp_bahan} WHERE produk<>'' AND produk<>'-'"));
        $ada = [];
        foreach (self::db()->select(StockSupport::q('SELECT nama FROM {hpp_bahan}')) as $r) {
            $ada[$r->nama] = 1;
        }
        $ganti = 0;
        $bersih = 0;
        $bentrok = [];
        foreach ($rows as $r) {
            if ($r->produk === $r->nama) {
                self::db()->update(StockSupport::q("UPDATE {hpp_bahan} SET produk='' WHERE nama=?"), [$r->nama]);
                $bersih++;

                continue;
            }
            if (isset($ada[$r->produk])) {
                $bentrok[] = $r->nama.' → '.$r->produk;

                continue;
            }
            $this->saveIngredient((object) ['nama' => $r->produk, 'namaLama' => $r->nama,
                'satuan' => $r->satuan, 'qty_beli' => $r->qty_beli, 'harga_beli' => $r->harga_beli, 'vendor' => $r->vendor,
                'produk' => '', 'kategori' => $r->kategori, 'catatan' => $r->catatan], $by);
            unset($ada[$r->nama]);
            $ada[$r->produk] = 1;
            $ganti++;
        }

        return ['status' => 'success', 'saved' => true, 'ganti' => $ganti, 'bersih' => $bersih, 'bentrok' => $bentrok];
    }

    /** hpp_impor(): the one-time move from Excel; refused when data exists unless $timpa (which wipes both tables). */
    public function import(mixed $d, mixed $by, bool $timpa): array
    {
        $ada = (int) self::db()->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {hpp_resep}'))->c
             + (int) self::db()->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {hpp_bahan}'))->c;
        if ($ada > 0 && ! $timpa) {
            return ['status' => 'error', 'message' => 'Data HPP sudah ada ('.$ada.' baris). Impor dibatalkan.'];
        }
        if ($timpa) {
            self::db()->delete(StockSupport::q('DELETE FROM {hpp_resep}'));
            self::db()->delete(StockSupport::q('DELETE FROM {hpp_bahan}'));
        }
        $nB = 0;
        $nR = 0;
        foreach (is_array($x = self::f($d, 'bahan', null)) ? $x : [] as $b) {
            $this->saveIngredient($b, $by);
            $nB++;
        }
        foreach (is_array($x = self::f($d, 'resep', null)) ? $x : [] as $r) {
            $this->saveRecipe($r, $by);
            $nR++;
        }

        return ['status' => 'success', 'saved' => true, 'bahan' => $nB, 'resep' => $nR];
    }

    /** hpp_impor_resep(): repeated upload; upsert by id, deletes no recipe. */
    public function importRecipes(mixed $rows, mixed $by): array
    {
        if (! is_array($rows)) {
            return ['status' => 'error', 'message' => 'rows bukan array'];
        }
        $ada = [];
        foreach (self::db()->select(StockSupport::q('SELECT {id} AS `id` FROM {hpp_resep}')) as $r) {
            $ada[(string) $r->id] = true;
        }
        $baru = 0;
        $ubah = 0;
        $lewat = 0;
        $galat = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $r = (object) $r;
            }
            if (! is_object($r)) {
                $lewat++;

                continue;
            }
            $nm = self::txt(self::f($r, 'nama'), 190);
            if ($nm === '') {
                $lewat++;

                continue;
            }
            $id = self::txt(self::f($r, 'id'), 48);
            $sebelum = ($id !== '' && isset($ada[$id]));
            try {
                $this->saveRecipe($r, $by);
            } catch (Throwable $e) {
                Log::error('[stock/hpp] impor resep "'.$nm.'" gagal: '.$e->getMessage());
                $galat[] = $nm;

                continue;
            }
            $sebelum ? $ubah++ : $baru++;
        }

        return ['status' => 'success', 'saved' => true, 'baru' => $baru, 'diubah' => $ubah, 'lewat' => $lewat, 'galat' => $galat];
    }

    /** hpp_impor_bahan(): repeated upload; upsert by name, never deletes, never renames. */
    public function importIngredients(mixed $rows, mixed $by): array
    {
        if (! is_array($rows)) {
            return ['status' => 'error', 'message' => 'rows bukan array'];
        }
        $ada = [];
        foreach (self::db()->select(StockSupport::q('SELECT nama FROM {hpp_bahan}')) as $r) {
            $ada[mb_strtolower((string) $r->nama)] = true;
        }
        $baru = 0;
        $ubah = 0;
        $lewat = 0;
        $galat = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $r = (object) $r;
            }
            if (! is_object($r)) {
                $lewat++;

                continue;
            }
            $nm = self::txt(self::f($r, 'nama'), 190);
            if ($nm === '') {
                $lewat++;

                continue;
            }
            unset($r->namaLama);
            $k = mb_strtolower($nm);
            $sebelum = isset($ada[$k]);
            try {
                $this->saveIngredient($r, $by);
            } catch (Throwable $e) {
                Log::error('[stock/hpp] impor bahan "'.$nm.'" gagal: '.$e->getMessage());
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

        return ['status' => 'success', 'saved' => true, 'baru' => $baru, 'diubah' => $ubah, 'dilewati' => $lewat, 'galat' => $galat];
    }

    /** simpanSetting: only known keys change, as numbers. */
    public function saveSettings(mixed $data): array
    {
        $lama = $this->settings();
        foreach ((array) ($data ?? new stdClass) as $k => $v) {
            if (array_key_exists($k, $lama)) {
                $lama[$k] = self::num($v);
            }
        }
        self::db()->statement(StockSupport::q('INSERT INTO {hpp_setting} ({id},data) VALUES (1,?) ON DUPLICATE KEY UPDATE data=VALUES(data)'),
            [json_encode($lama, JSON_UNESCAPED_UNICODE)]);

        return ['status' => 'success', 'saved' => true, 'setting' => $lama];
    }
}
