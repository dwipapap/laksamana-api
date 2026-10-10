<?php

namespace App\Modules\Stock\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Stock › Break & Loss (legacy 9–10 Oct 2026) — port of stock-mysql/breakloss.php
 * + lib_stock_breakloss.php for /api/v1/stock/breakloss (module `breakloss`).
 *
 * INVENTORY items (plates, glasses, bar tools) that break or go missing — not
 * ingredients like Waste. Two things Waste does not have:
 *   1. a MASTER of items (`bl_item`) registered once, with a photo, so a monthly
 *      recap per item can be summed;
 *   2. CURRENT STOCK, which is never stored as a number: it is SUM(qty) of every
 *      `bl_mutasi` row with `batal_at IS NULL` (masuk +, break −, loss −, opname ±).
 *
 * Rules kept from legacy (each one fixed or prevents a real problem):
 *  - NO DELETE anywhere. A wrong entry is CANCELLED (reason required, once);
 *    an unused item is DEACTIVATED, so its history still points at a name.
 *  - The opening stock is a `masuk` movement, in the same transaction as the item.
 *  - Opname: the server computes the difference (physical − stock) inside the
 *    transaction with the item row locked FOR UPDATE, so two concurrent opnames
 *    cannot both read the old stock.
 *  - The item price is COPIED onto the movement: last month's loss value does not
 *    change when the item price is edited.
 *  - Every quantity is a whole number (qty, physical count, opening stock, minimum).
 *  - Editing is allowed only for live break/loss rows; the previous values go to
 *    `riwayat` (JSON, last 20) with diubah_oleh/diubah_at. Same item keeps the
 *    row price; another item takes that item's price. A photo not sent is kept.
 *  - Lists carry `thumb`, never the full `foto` (fetched one at a time).
 *
 * The tables (and the riwayat/diubah_* columns) are created by the legacy PHP at
 * runtime. This port never runs DDL: until they exist the endpoints answer 503
 * `breakloss_unavailable`. They have no core twin (stock on core = unavailable).
 */
class StockBreakLoss
{
    public const JENIS = ['break', 'loss', 'masuk', 'opname'];

    private const FOTO_MAX = 4 * 1024 * 1024;

    private const THUMB_MAX = 300 * 1024;

    private ?array $columns = null;

    private function db(): ConnectionInterface
    {
        return StockSupport::db();
    }

    // ───────────────────────────── availability ──

    /** Columns of bl_mutasi (lowercase) — empty when the tables are missing. */
    private function mutasiColumns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        if (StockSupport::onCore()) {
            return $this->columns = [];
        }
        $items = $this->db()->selectOne('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['bl_item']);
        if ((int) ($items->c ?? 0) === 0) {
            return $this->columns = [];
        }
        $cols = $this->db()->select('SELECT COLUMN_NAME n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['bl_mutasi']);

        return $this->columns = array_map(fn ($c) => strtolower((string) $c->n), $cols);
    }

    private function ready(): void
    {
        if (! $this->mutasiColumns()) {
            throw new StockConflict('unavailable', 'Tabel Break & Loss belum ada di database. Buka panel Break & Loss di Office lama sekali agar tabelnya dibuat.');
        }
    }

    /** The edit history columns arrived later (bl_pastikan_kolom); edits need them. */
    private function hasEditColumns(): bool
    {
        $c = $this->mutasiColumns();

        return in_array('riwayat', $c, true) && in_array('diubah_oleh', $c, true) && in_array('diubah_at', $c, true);
    }

    // ───────────────────────────── helpers ──

    private static function potong(mixed $s, int $n): string
    {
        return mb_substr(trim(StockSupport::str($s ?? '')), 0, $n, 'UTF-8');
    }

    /** A photo must be an image data URI: the column is later drawn as <img src>. */
    private static function fotoSah(string $s, int $max): bool
    {
        if ($s === '') {
            return true;
        }
        if (strlen($s) > $max) {
            return false;
        }

        return (bool) preg_match('#^data:image/(jpeg|png|webp|gif);base64,[A-Za-z0-9+/=\r\n]+$#', $s);
    }

    private static function tanggalSah(string $t): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isWhole(float $x): bool
    {
        return floor($x) == $x;
    }

    private static function invalid(string $msg, array $kurang = []): StockConflict
    {
        return new StockConflict('invalid', $kurang ? ['message' => $msg, 'kurang' => $kurang] : $msg);
    }

    private function stokSatu(string $itemId): float
    {
        $r = $this->db()->selectOne('SELECT COALESCE(SUM(`qty`),0) s FROM `bl_mutasi` WHERE `item_id`=? AND `batal_at` IS NULL', [$itemId]);

        return (float) ($r->s ?? 0);
    }

    // ───────────────────────────── reads ──

    /**
     * bl_daftar — items with their current stock (from the WHOLE history, not the
     * chosen range) and all-time break/loss totals, plus the movements in the
     * range (newest first, max 3000).
     */
    public function list(string $dari = '', string $ke = ''): array
    {
        $this->ready();
        $items = [];
        foreach ($this->db()->select("SELECT i.`id`,i.`nama`,i.`kategori`,i.`satuan`,i.`lokasi`,i.`harga`,i.`stok_min`,
                i.`aktif`,i.`catatan`,i.`thumb`,(COALESCE(i.`foto`,'') <> '') AS ada_foto,
                i.`dibuat_oleh`,i.`dibuat_at`,i.`diubah_oleh`,i.`diubah_at`,
                COALESCE(s.stok,0) AS stok, COALESCE(s.n,0) AS n_mutasi,
                COALESCE(s.tot_break,0) AS tot_break, COALESCE(s.tot_loss,0) AS tot_loss
            FROM `bl_item` i
            LEFT JOIN (SELECT `item_id`, SUM(`qty`) AS stok, COUNT(*) AS n,
                    SUM(CASE WHEN `jenis`='break' THEN -`qty` ELSE 0 END) AS tot_break,
                    SUM(CASE WHEN `jenis`='loss'  THEN -`qty` ELSE 0 END) AS tot_loss
                FROM `bl_mutasi` WHERE `batal_at` IS NULL GROUP BY `item_id`) s ON s.`item_id` = i.`id`
            ORDER BY i.`kategori`, i.`nama`") as $r) {
            $items[] = [
                'id' => $r->id, 'nama' => $r->nama, 'kategori' => $r->kategori, 'satuan' => $r->satuan, 'lokasi' => $r->lokasi,
                'harga' => (float) $r->harga, 'stokMin' => (float) $r->stok_min, 'aktif' => (int) $r->aktif === 1,
                'catatan' => (string) $r->catatan, 'thumb' => (string) $r->thumb, 'adaFoto' => (bool) $r->ada_foto,
                'stok' => (float) $r->stok, 'nMutasi' => (int) $r->n_mutasi,
                // all time, not the report period: an item card must not change with the period
                'totBreak' => (float) $r->tot_break, 'totLoss' => (float) $r->tot_loss,
                'dibuatOleh' => $r->dibuat_oleh, 'dibuatAt' => $r->dibuat_at, 'diubahOleh' => $r->diubah_oleh, 'diubahAt' => $r->diubah_at,
            ];
        }

        return ['items' => $items, 'mutasi' => $this->movements($dari, $ke)];
    }

    private function movements(string $dari, string $ke, ?string $id = null): array
    {
        $edit = $this->hasEditColumns() ? ',`diubah_oleh`,`diubah_at`,`riwayat`' : '';
        $sql = "SELECT `id`,`item_id`,`tanggal`,`jenis`,`qty`,`harga`,`sebab`,`pic`,`tim`,`catatan`,
                (COALESCE(`foto`,'') <> '') AS ada_foto,`oleh`,`waktu`,`batal_at`,`batal_oleh`,`batal_alasan`$edit
            FROM `bl_mutasi` WHERE 1=1";
        $par = [];
        if ($id !== null) {
            $sql .= ' AND `id`=?';
            $par[] = $id;
        } else {
            StockSupport::dateFilter($sql, $par, $dari, $ke);
        }
        $sql .= ' ORDER BY `tanggal` DESC, `waktu` DESC LIMIT 3000';
        $out = [];
        foreach ($this->db()->select($sql, $par) as $r) {
            $rw = isset($r->riwayat) ? json_decode((string) $r->riwayat, true) : null;
            $out[] = [
                'id' => $r->id, 'itemId' => $r->item_id, 'tanggal' => $r->tanggal, 'jenis' => $r->jenis,
                'qty' => (float) $r->qty, 'harga' => (float) $r->harga, 'sebab' => $r->sebab, 'pic' => $r->pic, 'tim' => $r->tim,
                'catatan' => (string) $r->catatan, 'adaFoto' => (bool) $r->ada_foto, 'oleh' => $r->oleh, 'waktu' => $r->waktu,
                'batalAt' => $r->batal_at, 'batalOleh' => $r->batal_oleh, 'batalAlasan' => $r->batal_alasan,
                'diubahOleh' => $r->diubah_oleh ?? '', 'diubahAt' => $r->diubah_at ?? null,
                'riwayat' => is_array($rw) ? $rw : [],
            ];
        }

        return $out;
    }

    /** bl_foto — the full photo of one item or movement; null = none. */
    public function photo(string $jenis, string $id): ?array
    {
        $this->ready();
        $tabel = $jenis === 'mutasi' ? 'bl_mutasi' : 'bl_item'; // closed list, never from the request
        $r = $this->db()->selectOne("SELECT `foto`,`foto_nama` FROM `$tabel` WHERE `id`=? LIMIT 1", [$id]);
        if (! $r || (string) $r->foto === '') {
            return null;
        }

        return ['foto' => $r->foto, 'fotoNama' => $r->foto_nama];
    }

    // ───────────────────────────── items ──

    /**
     * bl_item_simpan. `foto` / `thumb` absent (null) = keep the old one; '' = remove
     * it on purpose (editing the price without re-picking the file must not drop
     * the photo — a Waste lesson). A new item's opening stock is a `masuk` row in
     * the same transaction. Returns the item id.
     */
    public function saveItem(?string $id, array $b, string $oleh): string
    {
        $this->ready();
        $nama = mb_substr(trim(preg_replace('/\s+/u', ' ', StockSupport::str($b['nama'] ?? ''))), 0, 160, 'UTF-8');
        if ($nama === '') {
            throw self::invalid('nama barang wajib diisi', ['nama']);
        }
        $kat = self::potong($b['kategori'] ?? '', 80);
        $satuan = self::potong($b['satuan'] ?? '', 30) ?: 'pcs';
        $lokasi = self::potong($b['lokasi'] ?? '', 80);
        $harga = (float) ($b['harga'] ?? 0);
        $min = (float) ($b['stokMin'] ?? 0);
        if ($harga < 0 || $min < 0) {
            throw self::invalid('harga & stok minimum tidak boleh minus');
        }
        if (! self::isWhole($min)) {
            throw self::invalid('stok minimum harus bilangan bulat');
        }
        $cat = trim(StockSupport::str($b['catatan'] ?? ''));
        $foto = array_key_exists('foto', $b) && $b['foto'] !== null ? StockSupport::str($b['foto']) : null;
        $thumb = array_key_exists('thumb', $b) && $b['thumb'] !== null ? StockSupport::str($b['thumb']) : null;
        if ($foto !== null && ! self::fotoSah($foto, self::FOTO_MAX)) {
            throw self::invalid('foto tidak sah atau terlalu besar');
        }
        if ($thumb !== null && ! self::fotoSah($thumb, self::THUMB_MAX)) {
            throw self::invalid('thumbnail tidak sah');
        }
        $fotoNama = self::potong($b['fotoNama'] ?? '', 200);
        $kini = StockSupport::now();

        try {
            if ($id !== null) {
                if (! $this->db()->selectOne('SELECT 1 x FROM `bl_item` WHERE `id`=?', [$id])) {
                    throw new StockConflict('not_found');
                }
                $set = '`nama`=?,`kategori`=?,`satuan`=?,`lokasi`=?,`harga`=?,`stok_min`=?,`catatan`=?,`diubah_oleh`=?,`diubah_at`=?';
                $par = [$nama, $kat, $satuan, $lokasi, $harga, $min, $cat, $oleh, $kini];
                if ($foto !== null) {
                    $set .= ',`foto`=?,`foto_nama`=?';
                    $par[] = $foto;
                    $par[] = $fotoNama;
                }
                if ($thumb !== null) {
                    $set .= ',`thumb`=?';
                    $par[] = $thumb;
                }
                $par[] = $id;
                $this->db()->update("UPDATE `bl_item` SET $set WHERE `id`=?", $par);

                return $id;
            }

            $awal = (float) ($b['stokAwal'] ?? 0);
            if ($awal < 0 || ! self::isWhole($awal)) {
                throw self::invalid('stok awal harus bilangan bulat dan tidak minus');
            }
            $id = StockSupport::uid('BLI');
            $this->db()->transaction(function () use ($id, $nama, $kat, $satuan, $lokasi, $harga, $min, $cat, $thumb, $foto, $fotoNama, $oleh, $kini, $awal) {
                $this->db()->insert('INSERT INTO `bl_item`
                    (`id`,`nama`,`kategori`,`satuan`,`lokasi`,`harga`,`stok_min`,`aktif`,`catatan`,`thumb`,`foto`,`foto_nama`,`dibuat_oleh`,`dibuat_at`)
                    VALUES (?,?,?,?,?,?,?,1,?,?,?,?,?,?)',
                    [$id, $nama, $kat, $satuan, $lokasi, $harga, $min, $cat, $thumb ?? '', $foto ?? '', $fotoNama, $oleh, $kini]);
                if ($awal > 0) {
                    $this->db()->insert('INSERT INTO `bl_mutasi`
                        (`id`,`item_id`,`tanggal`,`jenis`,`qty`,`harga`,`sebab`,`catatan`,`oleh`,`waktu`)
                        VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [StockSupport::uid('BLM'), $id, StockSupport::now('Y-m-d'), 'masuk', $awal, $harga, 'Stok awal', '', $oleh, $kini]);
                }
            });

            return $id;
        } catch (QueryException $e) {
            // 23000 = the unique name key: two items with one name split the recap in two
            if ((string) $e->getCode() === '23000') {
                throw self::invalid('Nama barang "'.$nama.'" sudah terdaftar.', ['nama']);
            }
            throw $e;
        }
    }

    /** bl_item_aktif — deactivate / reactivate; never deletes. */
    public function setActive(string $id, bool $aktif, string $oleh): void
    {
        $this->ready();
        if (! $this->db()->selectOne('SELECT 1 x FROM `bl_item` WHERE `id`=?', [$id])) {
            throw new StockConflict('not_found');
        }
        $this->db()->update('UPDATE `bl_item` SET `aktif`=?,`diubah_oleh`=?,`diubah_at`=? WHERE `id`=?', [$aktif ? 1 : 0, $oleh, StockSupport::now(), $id]);
    }

    // ───────────────────────────── movements ──

    /**
     * bl_mutasi_simpan — one movement in one transaction with the item row locked.
     * `pic` and `oleh` are the acting user. Returns {id, delta, stok}.
     */
    public function record(array $b, string $oleh): array
    {
        $this->ready();
        $itemId = trim(StockSupport::str($b['itemId'] ?? ''));
        $jenis = trim(StockSupport::str($b['jenis'] ?? ''));
        $tanggal = trim(StockSupport::str($b['tanggal'] ?? ''));
        $sebab = self::potong($b['sebab'] ?? '', 200);
        $kurang = [];
        if ($itemId === '') {
            $kurang[] = 'itemId';
        }
        if (! in_array($jenis, self::JENIS, true)) {
            throw self::invalid('jenis tidak dikenal');
        }
        if (! self::tanggalSah($tanggal)) {
            $kurang[] = 'tanggal';
        }
        if (($jenis === 'break' || $jenis === 'loss') && $sebab === '') {
            $kurang[] = 'sebab';
        }
        $qty = null;
        $fisik = null;
        if ($jenis === 'opname') {
            $fisik = isset($b['fisik']) && $b['fisik'] !== '' ? (float) $b['fisik'] : null;
            if ($fisik === null || $fisik < 0 || ! self::isWhole($fisik)) {
                $kurang[] = 'fisik';
            }
        } else {
            $qty = (float) ($b['qty'] ?? 0);
            // whole numbers: inventory is counted per piece
            if ($qty <= 0 || ! self::isWhole($qty)) {
                $kurang[] = 'qty';
            }
        }
        if ($kurang) {
            throw self::invalid('Belum lengkap: '.implode(', ', $kurang), $kurang);
        }
        $foto = StockSupport::str($b['foto'] ?? '');
        if (! self::fotoSah($foto, self::FOTO_MAX)) {
            throw self::invalid('foto tidak sah atau terlalu besar');
        }

        return $this->db()->transaction(function () use ($itemId, $jenis, $tanggal, $sebab, $qty, $fisik, $foto, $b, $oleh) {
            $it = $this->db()->selectOne('SELECT `harga`,`aktif` FROM `bl_item` WHERE `id`=? FOR UPDATE', [$itemId]);
            if (! $it) {
                throw new StockConflict('not_found');
            }
            // a deactivated item takes no new entries: they are nearly always the wrong item picked
            if ((int) $it->aktif !== 1) {
                throw self::invalid('barang ini sudah dinonaktifkan');
            }
            $stokSebelum = $this->stokSatu($itemId);
            $delta = $jenis === 'opname' ? round($fisik - $stokSebelum, 2) : ($jenis === 'masuk' ? $qty : -$qty);
            $id = StockSupport::uid('BLM');
            $this->db()->insert('INSERT INTO `bl_mutasi`
                (`id`,`item_id`,`tanggal`,`jenis`,`qty`,`harga`,`sebab`,`pic`,`tim`,`catatan`,`foto`,`foto_nama`,`oleh`,`waktu`)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $itemId, $tanggal, $jenis, $delta, (float) $it->harga, $sebab, mb_substr($oleh, 0, 120, 'UTF-8'),
                    self::potong($b['tim'] ?? '', 40), trim(StockSupport::str($b['catatan'] ?? '')), $foto,
                    self::potong($b['fotoNama'] ?? '', 200), $oleh, StockSupport::now()]);

            return ['id' => $id, 'delta' => $delta, 'stok' => $stokSebelum + $delta];
        });
    }

    /**
     * bl_mutasi_ubah — edit a LIVE break/loss row only (masuk/opname: cancel and
     * record again — an opname difference belongs to the stock AT that moment).
     * The previous values go to `riwayat` (last 20). Returns {id, stok}.
     */
    public function edit(string $id, array $b, string $oleh): array
    {
        $this->ready();
        if (! $this->hasEditColumns()) {
            throw new StockConflict('unavailable', 'Kolom riwayat Break & Loss belum ada. Buka panel Break & Loss di Office lama sekali agar kolomnya dibuat.');
        }
        $itemId = trim(StockSupport::str($b['itemId'] ?? ''));
        $jenis = trim(StockSupport::str($b['jenis'] ?? ''));
        $tanggal = trim(StockSupport::str($b['tanggal'] ?? ''));
        $sebab = self::potong($b['sebab'] ?? '', 200);
        $qty = (float) ($b['qty'] ?? 0);
        if (! in_array($jenis, ['break', 'loss'], true)) {
            throw self::invalid('hanya break & loss yang bisa diubah');
        }
        $kurang = [];
        if ($itemId === '') {
            $kurang[] = 'itemId';
        }
        if (! self::tanggalSah($tanggal)) {
            $kurang[] = 'tanggal';
        }
        if ($sebab === '') {
            $kurang[] = 'sebab';
        }
        if ($qty <= 0 || ! self::isWhole($qty)) {
            $kurang[] = 'qty';
        }
        if ($kurang) {
            throw self::invalid('Belum lengkap: '.implode(', ', $kurang), $kurang);
        }
        $foto = array_key_exists('foto', $b) && $b['foto'] !== null ? StockSupport::str($b['foto']) : null;
        if ($foto !== null && ! self::fotoSah($foto, self::FOTO_MAX)) {
            throw self::invalid('foto tidak sah atau terlalu besar');
        }

        return $this->db()->transaction(function () use ($id, $itemId, $jenis, $tanggal, $sebab, $qty, $foto, $b, $oleh) {
            $lama = $this->db()->selectOne('SELECT * FROM `bl_mutasi` WHERE `id`=? FOR UPDATE', [$id]);
            if (! $lama) {
                throw new StockConflict('not_found');
            }
            if ($lama->batal_at !== null) {
                throw self::invalid('catatan yang sudah dibatalkan tidak bisa diubah');
            }
            if (! in_array($lama->jenis, ['break', 'loss'], true)) {
                throw self::invalid('catatan masuk / opname tidak bisa diubah — batalkan lalu catat ulang');
            }
            $it = $this->db()->selectOne('SELECT `harga`,`aktif` FROM `bl_item` WHERE `id`=? FOR UPDATE', [$itemId]);
            if (! $it) {
                throw self::invalid('barang tidak ditemukan');
            }
            $ganti = $itemId !== $lama->item_id;
            // moving TO a deactivated item is refused; fixing a row of an item deactivated later is fine
            if ($ganti && (int) $it->aktif !== 1) {
                throw self::invalid('barang tujuan sudah dinonaktifkan');
            }
            $harga = $ganti ? (float) $it->harga : (float) $lama->harga;
            $kini = StockSupport::now();

            $riwayat = json_decode((string) ($lama->riwayat ?? ''), true);
            $riwayat = is_array($riwayat) ? $riwayat : [];
            $riwayat[] = ['at' => $kini, 'oleh' => $oleh, 'sebelum' => [
                'itemId' => $lama->item_id, 'tanggal' => $lama->tanggal, 'jenis' => $lama->jenis,
                'qty' => (float) $lama->qty, 'harga' => (float) $lama->harga, 'sebab' => $lama->sebab,
                'tim' => $lama->tim, 'catatan' => (string) $lama->catatan, 'adaFoto' => (string) $lama->foto !== '',
            ]];
            $riwayat = array_slice($riwayat, -20);

            $set = '`item_id`=?,`tanggal`=?,`jenis`=?,`qty`=?,`harga`=?,`sebab`=?,`tim`=?,`catatan`=?,`diubah_oleh`=?,`diubah_at`=?,`riwayat`=?';
            $par = [$itemId, $tanggal, $jenis, -$qty, $harga, $sebab, self::potong($b['tim'] ?? '', 40),
                trim(StockSupport::str($b['catatan'] ?? '')), $oleh, $kini, json_encode($riwayat, JSON_UNESCAPED_UNICODE)];
            if ($foto !== null) {
                $set .= ',`foto`=?,`foto_nama`=?';
                $par[] = $foto;
                $par[] = self::potong($b['fotoNama'] ?? '', 200);
            }
            $par[] = $id;
            $this->db()->update("UPDATE `bl_mutasi` SET $set WHERE `id`=?", $par);

            return ['id' => $id, 'stok' => $this->stokSatu($itemId)];
        });
    }

    /** bl_mutasi_batal — reason required; a cancelled row cannot be cancelled again (the first canceller stays). */
    public function cancel(string $id, mixed $alasan, string $oleh): void
    {
        $this->ready();
        $alasan = self::potong($alasan ?? '', 300);
        if ($alasan === '') {
            throw self::invalid('alasan pembatalan wajib diisi', ['alasan']);
        }
        $n = $this->db()->update('UPDATE `bl_mutasi` SET `batal_at`=?,`batal_oleh`=?,`batal_alasan`=? WHERE `id`=? AND `batal_at` IS NULL',
            [StockSupport::now(), $oleh, $alasan, $id]);
        if ($n === 0) {
            throw new StockConflict('not_found', 'catatan tidak ditemukan atau sudah dibatalkan');
        }
    }

    /** One movement as listed (for the write responses). */
    public function movement(string $id): ?array
    {
        try {
            return $this->movements('', '', $id)[0] ?? null;
        } catch (Throwable) {
            return null;
        }
    }
}
