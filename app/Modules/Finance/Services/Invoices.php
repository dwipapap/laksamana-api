<?php

namespace App\Modules\Finance\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Finance → invoices & kwitansi — port of finance-mysql/lib_invoice.php.
 *
 * One row of `inv_kwitansi` = one request for one reservation / marketing
 * deal (UNIQUE res_id). A decision moves the SAME row through MENUNGGU →
 * DIBUAT / DITOLAK, so the trail from "who asked" to "who issued" never breaks.
 *
 * Document kinds: KWITANSI (Reservasi DP receipt), INV_DP / INV_LUNAS
 * (Marketing invoices) — the invoice kinds have their own number pool and
 * default signatories. Numbers are PREFIX/YYYY/MM/NNNN (WIB month), the
 * month's highest + 1, given ONCE and never reused.
 *
 * Signatories' names/titles are COPIED into the row when issued; their
 * images are read live when the document is printed (invBerkas).
 *
 * Callers (Reservasi, Marketing) use request() / statuses() / file() in-process.
 * Runtime DDL, the column checks and the one-off single-signatory migration of
 * inv_pastikan_tabel() are not ported (done live).
 */
class Invoices
{
    public const SETTING_KEYS = ['ttd', 'cap', 'penandaNama', 'penandaJabatan', 'prefix', 'penandaDefault', 'prefixInvoice', 'penandaDefaultInvoice'];

    public function db(): ConnectionInterface
    {
        return Modules::db('finance');
    }

    private static function s(mixed $v): string
    {
        return KasKecil::s($v);
    }

    public static function ms(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** inv_uid: prefix + hex seconds + 6 chars. */
    public static function uid(string $p): string
    {
        return $p.dechex(time()).substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
    }

    /** Unknown kinds fall back to KWITANSI (a typo must not block a request). */
    public static function kind(mixed $j): string
    {
        $j = strtoupper(trim(self::s($j)));

        return in_array($j, ['KWITANSI', 'INV_DP', 'INV_LUNAS'], true) ? $j : 'KWITANSI';
    }

    public static function isInvoice(string $j): bool
    {
        return $j === 'INV_DP' || $j === 'INV_LUNAS';
    }

    // ───────────────────────────── core (#67) ──
    // `SELECT *` on legacy (unchanged); on core the explicit column list with the
    // legacy id AS id, because the core table also carries a ULID id and the tech
    // columns the wire must not see.

    private static function reqSelect(): string
    {
        return KasKecil::onCore()
            ? 'SELECT '.KasKecil::idSelect().',`res_id`,`no_invoice`,`status`,`ringkas`,`minta_oleh`,`minta_at`,`catatan`,`putus_oleh`,`putus_at`,`penanda`,`jenis` FROM `'.KasKecil::t('inv_kwitansi').'`'
            : 'SELECT * FROM `inv_kwitansi`';
    }

    private static function signatorySelect(): string
    {
        return KasKecil::onCore()
            ? 'SELECT '.KasKecil::idSelect().',`nama`,`jabatan`,`ttd`,`urut`,`aktif` FROM `'.KasKecil::t('inv_penanda').'`'
            : 'SELECT * FROM `inv_penanda`';
    }

    /** One request in its public shape, by the legacy id (v1 show / decision). */
    public function find(string $id): ?array
    {
        $row = $this->db()->selectOne(self::reqSelect().' WHERE `'.KasKecil::idCol().'`=?', [$id]);

        return $row ? self::row($row) : null;
    }

    // ───────────────────────────── settings & signatories ──

    public function settings(): array
    {
        $out = ['ttd' => '', 'cap' => '', 'penandaNama' => '', 'penandaJabatan' => '', 'prefix' => 'INV', 'penandaDefault' => '',
            'prefixInvoice' => 'INV', 'penandaDefaultInvoice' => ''];
        foreach ($this->db()->select('SELECT `k`,`v` FROM `'.KasKecil::t('inv_setting').'`') as $r) {
            $out[$r->k] = $r->v ?? '';
        }

        return $out;
    }

    /** inv_setting_simpan — only the known keys, and only the ones SENT (an absent key is left as is). */
    public function saveSettings(mixed $in): array
    {
        if (! is_array($in)) {
            throw new RuntimeException('data pengaturan kosong');
        }
        foreach (self::SETTING_KEYS as $k) {
            if (array_key_exists($k, $in)) {
                KasKecil::onCore()
                    ? $this->db()->insert('INSERT INTO `'.KasKecil::t('inv_setting').'` (`id`,`k`,`v`,`created_at`,`updated_at`,`version`) VALUES (?,?,?,?,?,?) '
                        .'ON DUPLICATE KEY UPDATE `v`=VALUES(`v`), `version`=`version`+1, `updated_at`=VALUES(`updated_at`)',
                        [KasKecil::ulid(), $k, self::s($in[$k]), self::ms(), self::ms(), 1])
                    : $this->db()->insert('INSERT INTO `inv_setting` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, self::s($in[$k])]);
            }
        }

        return $this->settings();
    }

    public function signatories(bool $all): array
    {
        return array_map(fn ($r) => [
            'id' => $r->id, 'nama' => $r->nama, 'jabatan' => $r->jabatan, 'ttd' => $r->ttd ?? '',
            'urut' => (int) $r->urut, 'aktif' => (int) $r->aktif === 1,
        ], $this->db()->select(self::signatorySelect().($all ? '' : ' WHERE `aktif`=1').' ORDER BY `urut`, `nama`'));
    }

    /** inv_penanda_simpan — `ttd` is written only when sent (renaming must not erase the signature). */
    public function saveSignatory(mixed $in): array
    {
        if (! is_array($in)) {
            throw new RuntimeException('data penanda tangan kosong');
        }
        $id = isset($in['id']) && $in['id'] !== '' ? self::s($in['id']) : '';
        $nama = isset($in['nama']) ? trim(self::s($in['nama'])) : '';
        if ($nama === '') {
            throw new RuntimeException('nama penanda tangan wajib diisi');
        }
        $jab = isset($in['jabatan']) ? trim(self::s($in['jabatan'])) : '';
        $urut = isset($in['urut']) ? (int) $in['urut'] : 0;
        $aktif = array_key_exists('aktif', $in) ? (! empty($in['aktif']) ? 1 : 0) : 1;

        $core = KasKecil::onCore();
        $t = KasKecil::t('inv_penanda');
        $idc = KasKecil::idCol();
        if ($core) {
            $nama = RowSync::fit($this->db(), $t, 'nama', $nama);
            $jab = RowSync::fit($this->db(), $t, 'jabatan', $jab);
        }
        $stamp = $core ? ', `updated_at`=?, `version`=`version`+1' : '';
        if ($id === '') {
            $core
                ? $this->db()->insert('INSERT INTO `'.$t.'` (`id`,`legacy_id`,`nama`,`jabatan`,`ttd`,`urut`,`aktif`,`created_at`,`updated_at`,`version`) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [KasKecil::ulid(), self::uid('pen'), $nama, $jab, isset($in['ttd']) ? self::s($in['ttd']) : '', $urut, $aktif, self::ms(), self::ms(), 1])
                : $this->db()->insert('INSERT INTO `inv_penanda` (`id`,`nama`,`jabatan`,`ttd`,`urut`,`aktif`) VALUES (?,?,?,?,?,?)',
                    [self::uid('pen'), $nama, $jab, isset($in['ttd']) ? self::s($in['ttd']) : '', $urut, $aktif]);
        } elseif (array_key_exists('ttd', $in)) {
            $this->db()->update('UPDATE `'.$t.'` SET `nama`=?,`jabatan`=?,`ttd`=?,`urut`=?,`aktif`=?'.$stamp.' WHERE `'.$idc.'`=?',
                $core ? [$nama, $jab, self::s($in['ttd']), $urut, $aktif, self::ms(), $id] : [$nama, $jab, self::s($in['ttd']), $urut, $aktif, $id]);
        } else {
            $this->db()->update('UPDATE `'.$t.'` SET `nama`=?,`jabatan`=?,`urut`=?,`aktif`=?'.$stamp.' WHERE `'.$idc.'`=?',
                $core ? [$nama, $jab, $urut, $aktif, self::ms(), $id] : [$nama, $jab, $urut, $aktif, $id]);
        }

        return $this->signatories(true);
    }

    /** inv_penanda_hapus — refused once on an issued document (deactivate instead). */
    public function deleteSignatory(mixed $id): array
    {
        $id = self::s($id);
        $n = (int) $this->db()->selectOne('SELECT COUNT(*) AS c FROM `'.KasKecil::t('inv_kwitansi')."` WHERE `status`='DIBUAT' AND `penanda` LIKE ?", ['%'.$id.'%'])->c;
        if ($n > 0) {
            throw new RuntimeException('Penanda tangan ini sudah menempel di '.$n.' kwitansi yang terbit, jadi tidak bisa dihapus. Nonaktifkan saja — ia hilang dari daftar pilihan, tapi dokumen lama tetap bertanda tangan.');
        }
        $this->db()->delete('DELETE FROM `'.KasKecil::t('inv_penanda').'` WHERE `'.KasKecil::idCol().'`=?', [$id]);

        return $this->signatories(true);
    }

    // ───────────────────────────── requests ──

    /** inv_baris — the public shape of one request row. */
    public static function row(object $r): array
    {
        $ringkas = [];
        if (! empty($r->ringkas)) {
            $d = json_decode($r->ringkas, true);
            $ringkas = is_array($d) ? $d : [];
        }
        $penanda = [];
        if (isset($r->penanda) && $r->penanda !== '') {
            $d = json_decode($r->penanda, true);
            $penanda = is_array($d) ? $d : [];
        }

        return [
            'id' => $r->id, 'resId' => $r->res_id, 'no' => $r->no_invoice, 'status' => $r->status,
            'jenis' => isset($r->jenis) && $r->jenis !== '' ? $r->jenis : 'KWITANSI',
            'ringkas' => $ringkas, 'penanda' => $penanda,
            'mintaOleh' => $r->minta_oleh, 'mintaAt' => (int) $r->minta_at, 'catatan' => $r->catatan ?? '',
            'putusOleh' => $r->putus_oleh, 'putusAt' => (int) $r->putus_at,
        ];
    }

    private function byResId(string $resId): ?object
    {
        return $this->db()->selectOne(self::reqSelect().' WHERE `res_id`=?', [$resId]);
    }

    /**
     * inv_minta — IDEMPOTENT per res_id. An issued (DIBUAT) request is returned
     * untouched (the printed summary must match the archive); a waiting or
     * rejected one is refreshed into a live request again.
     */
    public function request(mixed $in): array
    {
        if (! is_array($in) || empty($in['resId'])) {
            throw new RuntimeException('resId kosong');
        }
        $resId = self::s($in['resId']);
        $oleh = isset($in['oleh']) ? self::s($in['oleh']) : '';
        $jenis = self::kind($in['jenis'] ?? 'KWITANSI');
        $ringkas = isset($in['ringkas']) && is_array($in['ringkas']) ? json_encode($in['ringkas'], JSON_UNESCAPED_UNICODE) : null;

        $core = KasKecil::onCore();
        if ($core) {
            $tK = KasKecil::t('inv_kwitansi');
            $resId = RowSync::fit($this->db(), $tK, 'res_id', $resId);
            $oleh = RowSync::fit($this->db(), $tK, 'minta_oleh', $oleh);
        }
        $ada = $this->byResId($resId);
        if ($ada) {
            if ($ada->status === 'DIBUAT') {
                return self::row($ada);
            }
            $core
                ? $this->db()->update('UPDATE `'.KasKecil::t('inv_kwitansi')."` SET `status`='MENUNGGU', `ringkas`=COALESCE(?,`ringkas`), `jenis`=?,
                `minta_oleh`=?, `minta_at`=?, `catatan`='', `putus_oleh`='', `putus_at`=0, `updated_at`=?, `version`=`version`+1 WHERE `".KasKecil::idCol().'`=?',
                    [$ringkas, $jenis, $oleh, self::ms(), self::ms(), $ada->id])
                : $this->db()->update("UPDATE `inv_kwitansi` SET `status`='MENUNGGU', `ringkas`=COALESCE(?,`ringkas`), `jenis`=?,
                `minta_oleh`=?, `minta_at`=?, `catatan`='', `putus_oleh`='', `putus_at`=0 WHERE `id`=?",
                    [$ringkas, $jenis, $oleh, self::ms(), $ada->id]);

            return self::row($this->byResId($resId));
        }
        $invId = self::uid('inv');
        $core
            ? $this->db()->insert('INSERT INTO `'.KasKecil::t('inv_kwitansi')."` (`id`,`legacy_id`,`res_id`,`no_invoice`,`status`,`jenis`,`ringkas`,`minta_oleh`,`minta_at`,`catatan`,`created_at`,`updated_at`,`version`)
            VALUES (?,?,?,'','MENUNGGU',?,?,?,?,'',?,?,?)", [KasKecil::ulid(), $invId, $resId, $jenis, $ringkas, $oleh, self::ms(), self::ms(), self::ms(), 1])
            : $this->db()->insert("INSERT INTO `inv_kwitansi` (`id`,`res_id`,`no_invoice`,`status`,`jenis`,`ringkas`,`minta_oleh`,`minta_at`,`catatan`)
            VALUES (?,?,'','MENUNGGU',?,?,?,?,'')", [$invId, $resId, $jenis, $ringkas, $oleh, self::ms()]);

        return self::row($this->byResId($resId));
    }

    /** inv_status_banyak — {resId: row} for at most 400 ids (no images). */
    public function statuses(mixed $resIds): array|object
    {
        $out = [];
        if (! is_array($resIds) || ! count($resIds)) {
            return $out;
        }
        $ids = array_map(fn ($r) => self::s($r), array_slice(array_values($resIds), 0, 400));
        foreach ($this->db()->select(self::reqSelect().' WHERE `res_id` IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids) as $r) {
            $out[$r->res_id] = self::row($r);
        }

        return $out;
    }

    public function list(): array
    {
        return array_map([self::class, 'row'], $this->db()->select(self::reqSelect().' ORDER BY `minta_at` DESC'));
    }

    /** inv_antre_jumlah — waiting requests, for the menu badge. */
    public function queueCount(): array
    {
        $out = ['total' => 0, 'perJenis' => []];
        foreach ($this->db()->select('SELECT `jenis`, COUNT(*) AS c FROM `'.KasKecil::t('inv_kwitansi')."` WHERE `status`='MENUNGGU' GROUP BY `jenis`") as $r) {
            $j = $r->jenis === null || $r->jenis === '' ? 'KWITANSI' : $r->jenis;
            $out['total'] += (int) $r->c;
            $out['perJenis'][$j] = (int) $r->c;
        }

        return $out;
    }

    /** inv_nomor_berikut — PREFIX/YYYY/MM/NNNN: the (WIB) month's highest + 1, per prefix pool. */
    private function nextNumber(string $jenis): string
    {
        $set = $this->settings();
        $prefix = self::isInvoice($jenis)
            ? ($set['prefixInvoice'] !== '' ? $set['prefixInvoice'] : 'INV')
            : ($set['prefix'] !== '' ? $set['prefix'] : 'INV');
        $awalan = $prefix.'/'.gmdate('Y/m', time() + 7 * 3600).'/';
        $max = 0;
        foreach ($this->db()->select('SELECT `no_invoice` FROM `'.KasKecil::t('inv_kwitansi').'` WHERE `no_invoice` LIKE ?', [$awalan.'%']) as $r) {
            $max = max($max, (int) substr($r->no_invoice, strlen($awalan)));
        }

        return $awalan.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * inv_putus — buat (issue: number given ONCE, signatories snapshotted),
     * tolak (a note is required), batal (back to MENUNGGU; the number is kept).
     * Issuing runs under a lock so two decisions cannot take the same number.
     */
    public function decide(mixed $in): array
    {
        if (! is_array($in) || empty($in['id'])) {
            throw new RuntimeException('id permintaan kosong');
        }
        $id = self::s($in['id']);
        $aksi = isset($in['aksi']) ? self::s($in['aksi']) : '';
        $oleh = isset($in['oleh']) ? self::s($in['oleh']) : '';
        $catatan = isset($in['catatan']) ? trim(self::s($in['catatan'])) : '';

        return NamedLock::run('finance', 'inv_nomor', function () use ($in, $id, $aksi, $oleh, $catatan) {
            $core = KasKecil::onCore();
            $t = KasKecil::t('inv_kwitansi');
            $idc = KasKecil::idCol();
            if ($core) {
                $oleh = RowSync::fit($this->db(), $t, 'putus_oleh', $oleh);
            }
            $stamp = $core ? ', `updated_at`=?, `version`=`version`+1' : '';
            $row = $this->db()->selectOne(self::reqSelect().' WHERE `'.$idc.'`=?', [$id]);
            if (! $row) {
                throw new RuntimeException('permintaan tidak ditemukan');
            }
            if ($aksi === 'buat') {
                $jns = isset($row->jenis) && $row->jenis !== '' ? $row->jenis : 'KWITANSI';
                $no = $row->no_invoice !== '' ? $row->no_invoice : $this->nextNumber($jns);
                $pen = json_encode($this->snapshotSignatories(array_key_exists('penanda', $in) ? $in['penanda'] : null, $jns), JSON_UNESCAPED_UNICODE);
                $this->db()->update("UPDATE `$t` SET `status`='DIBUAT', `no_invoice`=?, `catatan`=?, `penanda`=?, `putus_oleh`=?, `putus_at`=?$stamp WHERE `$idc`=?",
                    $core ? [$no, $catatan, $pen, $oleh, self::ms(), self::ms(), $id] : [$no, $catatan, $pen, $oleh, self::ms(), $id]);
            } elseif ($aksi === 'tolak') {
                if ($catatan === '') {
                    throw new RuntimeException('alasan penolakan wajib diisi');
                }
                $this->db()->update("UPDATE `$t` SET `status`='DITOLAK', `catatan`=?, `putus_oleh`=?, `putus_at`=?$stamp WHERE `$idc`=?",
                    $core ? [$catatan, $oleh, self::ms(), self::ms(), $id] : [$catatan, $oleh, self::ms(), $id]);
            } elseif ($aksi === 'batal') {
                $this->db()->update("UPDATE `$t` SET `status`='MENUNGGU', `catatan`='', `putus_oleh`=?, `putus_at`=?$stamp WHERE `$idc`=?",
                    $core ? [$oleh, self::ms(), self::ms(), $id] : [$oleh, self::ms(), $id]);
            } else {
                throw new RuntimeException('aksi tidak dikenal: '.$aksi);
            }

            return self::row($this->db()->selectOne(self::reqSelect().' WHERE `'.$idc.'`=?', [$id]));
        });
    }

    /** null = the kind's default list; [] = deliberately unsigned. Names/titles only (images stay in inv_penanda). */
    private function snapshotSignatories(mixed $ids, string $jenis): array
    {
        if ($ids === null) {
            $csv = $this->settings()[self::isInvoice($jenis) ? 'penandaDefaultInvoice' : 'penandaDefault'] ?? '';
            $ids = $csv !== '' ? explode(',', $csv) : [];
        }
        if (! is_array($ids) || ! count($ids)) {
            return [];
        }
        $map = array_column($this->signatories(true), null, 'id');
        $out = [];
        foreach ($ids as $pid) {
            $pid = trim(self::s($pid));
            if ($pid !== '' && isset($map[$pid])) {
                $out[] = ['id' => $pid, 'nama' => $map[$pid]['nama'], 'jabatan' => $map[$pid]['jabatan']];
            }
        }

        return $out;
    }

    /** inv_berkas — the printable file of an ISSUED request: number, stamp and live signature images. */
    public function file(mixed $resId): array
    {
        $row = $this->byResId(self::s($resId));
        if (! $row || $row->status !== 'DIBUAT') {
            return ['ada' => false];
        }
        $set = $this->settings();
        $baris = self::row($row);
        $map = array_column($this->signatories(true), null, 'id');
        $penanda = [];
        foreach ($baris['penanda'] as $p) {
            $pid = $p['id'] ?? '';
            $penanda[] = ['nama' => $p['nama'] ?? '', 'jabatan' => $p['jabatan'] ?? '', 'ttd' => isset($map[$pid]) ? $map[$pid]['ttd'] : ''];
        }

        return ['ada' => true, 'jenis' => $baris['jenis'], 'no' => $row->no_invoice, 'putusOleh' => $row->putus_oleh,
            'putusAt' => (int) $row->putus_at, 'cap' => $set['cap'], 'penanda' => $penanda];
    }
}
