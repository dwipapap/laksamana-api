<?php

namespace App\Modules\Finance\Services;

use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finance → Kas Kecil — port of the kas kecil + Akses Halaman part of
 * finance-mysql/lib_finance_mysql.php.
 *
 * NOT a JSON blob: one transaction = one row, split across payment sources
 * (`kk_trx_pos`), written granularly so two finance staff never erase each
 * other. BALANCES ARE NEVER STORED — the frontend recomputes them from the
 * whole history (date, then id).
 *
 * Akses Halaman: `kk_akses` holds only the DIFFERENCES from the frontend's
 * default matrix, keyed by role; `kk_peran` maps '#<userId>' to a role.
 *
 * Runtime DDL / seeding / the one-off cleanup in pastikan_tabel() are not
 * ported (the tables exist live).
 */
class KasKecil
{
    public const LISTS = ['pos' => 'kk_pos', 'kategori' => 'kk_kategori'];

    public function db(): ConnectionInterface
    {
        return Modules::db('finance');
    }

    // ───────────────────────────── core (#67, docs/db/finance.md) ──

    /** Finance served from `core` (DB_FINANCE_CONNECTION=core); unset = the legacy tables. */
    public static function onCore(): bool
    {
        return Modules::connectionName('finance') === 'core';
    }

    /** The legacy table's name on whichever storage the Modul is on. */
    public static function t(string $legacy): string
    {
        return self::onCore() ? 'finance_'.$legacy : $legacy;
    }

    /** The column holding the legacy id the compat surface speaks. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /** `legacy_id AS id` on core: reads keep returning the id the clients know. */
    public static function idSelect(): string
    {
        return self::onCore() ? '`legacy_id` AS `id`' : '`id`';
    }

    /**
     * ORDER BY for the legacy id. On core it lives in a varchar, so it has to be
     * ordered numerically: the legacy ids ARE ints (4 must follow 3, not '10').
     */
    public static function idOrder(): string
    {
        return self::onCore() ? 'CAST(`legacy_id` AS UNSIGNED), `legacy_id`' : '`id`';
    }

    public static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * The next legacy integer id for an int-keyed table, from finance_counter
     * (the importer seeds it with the legacy AUTO_INCREMENT). Rows created after
     * the cutover must keep the legacy numbering: the old screens build inline
     * handlers from these ids (`onclick="kkEdit('+t.id+')"`).
     */
    public static function nextId(ConnectionInterface $db, string $legacy): int
    {
        $row = $db->selectOne('SELECT `next_value` FROM `finance_counter` WHERE `name` = ? FOR UPDATE', [$legacy]);
        $next = $row
            ? (int) $row->next_value
            : (int) $db->selectOne('SELECT COALESCE(MAX(CAST(`legacy_id` AS UNSIGNED)), 0) + 1 AS n FROM `'.self::t($legacy).'`')->n;
        $db->statement('INSERT INTO `finance_counter` (`name`, `next_value`) VALUES (?, ?) '
            .'ON DUPLICATE KEY UPDATE `next_value` = VALUES(`next_value`)', [$legacy, $next + 1]);

        return $next;
    }

    /** The core User behind a role key: '#u-cindy' (the legacy id) or '#<ULID>' (v1). */
    public static function userId(string $kunci): ?string
    {
        $key = ltrim($kunci, '#');
        if ($key === '') {
            return null;
        }
        $user = Modules::db('finance')->table('user');

        return $user->where('legacy_id', $key)->value('id') ?? $user->where('id', $key)->value('id');
    }

    /** PHP's (string) cast without the "Array to string" warning Laravel turns into an exception. */
    public static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    // ───────────────────────────── read ──

    /** baca_semua */
    public function read(): array
    {
        $db = $this->db();
        $list = fn (string $t) => array_map(fn ($r) => [
            'id' => (int) $r->id, 'nama' => (string) $r->nama, 'urut' => (int) $r->urut, 'aktif' => (int) $r->aktif === 1,
        ], $db->select('SELECT '.self::idSelect().',`nama`,`urut`,`aktif` FROM `'.self::t($t).'` ORDER BY `urut`,'.self::idOrder()));

        $trx = [];
        foreach ($db->select('SELECT '.self::idSelect().',`tgl`,`keterangan`,`kategori_id`,`input`,`bon`,`dibuat_at`,`dibuat_oleh` FROM `'.self::t('kk_trx').'` ORDER BY `tgl`,'.self::idOrder()) as $t) {
            $trx[(int) $t->id] = [
                'id' => (int) $t->id, 'tgl' => (string) $t->tgl, 'keterangan' => (string) $t->keterangan,
                'kategori_id' => $t->kategori_id === null ? null : (int) $t->kategori_id,
                'input' => (int) $t->input, 'bon' => (int) $t->bon, 'dibuat_at' => (int) $t->dibuat_at,
                'dibuat_oleh' => (string) $t->dibuat_oleh, 'baris' => [],
            ];
        }
        // split rows attached in one pass, not one query per transaction
        foreach ($db->select('SELECT `trx_id`,`pos_id`,`debet`,`kredit` FROM `'.self::t('kk_trx_pos').'` ORDER BY '.self::idOrder()) as $b) {
            if (isset($trx[(int) $b->trx_id])) {
                $trx[(int) $b->trx_id]['baris'][] = ['pos_id' => (int) $b->pos_id, 'debet' => (int) $b->debet, 'kredit' => (int) $b->kredit];
            }
        }

        return ['pos' => $list('kk_pos'), 'kategori' => $list('kk_kategori'), 'trx' => array_values($trx),
            'akses' => $this->akses(), 'peran' => $this->peran()];
    }

    /** {role: {page: tingkat}} — an OBJECT even when empty. */
    public function akses(): object
    {
        $out = [];
        foreach ($this->db()->select('SELECT `kunci`,`halaman`,`tingkat` FROM `'.self::t('kk_akses').'`') as $r) {
            $out[(string) $r->kunci][(string) $r->halaman] = (int) $r->tingkat;
        }

        return (object) $out;
    }

    /** {'#<userId>': role} — an OBJECT even when empty. */
    public function peran(): object
    {
        $out = [];
        foreach ($this->db()->select('SELECT `kunci`,`peran` FROM `'.self::t('kk_peran').'`') as $r) {
            $out[(string) $r->kunci] = (string) $r->peran;
        }

        return (object) $out;
    }

    public function stats(): array
    {
        $n = fn (string $t) => (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM `'.self::t($t).'`')->n;

        return ['pos' => $n('kk_pos'), 'kategori' => $n('kk_kategori'), 'trx' => $n('kk_trx'), 'baris' => $n('kk_trx_pos')];
    }

    // ───────────────────────────── transactions ──

    /**
     * simpan_trx — create ($in.id empty) or edit one transaction with its split
     * rows, in one DB transaction. An edit deletes and re-inserts the split rows.
     *
     * @return array{id:int}
     */
    public function saveTrx(array $in): array
    {
        $tgl = isset($in['tgl']) ? trim(self::s($in['tgl'])) : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            throw new RuntimeException('Tanggal tidak sah.');
        }
        $ket = isset($in['keterangan']) ? trim(self::s($in['keterangan'])) : '';
        if (self::onCore()) {
            $ket = RowSync::fit($this->db(), self::t('kk_trx'), 'keterangan', $ket);
        }
        if ($ket === '') {
            throw new RuntimeException('Keterangan wajib diisi.');
        }
        $kat = (isset($in['kategori_id']) && $in['kategori_id'] !== '' && $in['kategori_id'] !== null) ? (int) $in['kategori_id'] : null;

        $bersih = [];
        foreach (isset($in['baris']) && is_array($in['baris']) ? $in['baris'] : [] as $b) {
            $pid = isset($b['pos_id']) ? (int) $b['pos_id'] : 0;
            $d = isset($b['debet']) ? (int) $b['debet'] : 0;
            $k = isset($b['kredit']) ? (int) $b['kredit'] : 0;
            if ($pid <= 0 || ($d === 0 && $k === 0)) {
                continue;
            }
            if ($d < 0 || $k < 0) {
                throw new RuntimeException('Nominal tidak boleh negatif.');
            }
            // one row is either a debit or a credit: both at once is two transactions
            if ($d > 0 && $k > 0) {
                throw new RuntimeException('Satu pos tidak boleh debet dan kredit sekaligus.');
            }
            if (isset($bersih[$pid])) {
                throw new RuntimeException('Pos yang sama dikirim dua kali.');
            }
            $bersih[$pid] = ['debet' => $d, 'kredit' => $k];
        }
        if (! $bersih) {
            throw new RuntimeException('Belum ada nominal di satu pos pun.');
        }

        $id = (isset($in['id']) && $in['id']) ? (int) $in['id'] : 0;
        $input = ! empty($in['input']) ? 1 : 0;
        $bon = ! empty($in['bon']) ? 1 : 0;
        $oleh = isset($in['oleh']) ? substr(trim(self::s($in['oleh'])), 0, 120) : '';

        return $this->db()->transaction(function () use ($id, $tgl, $ket, $kat, $input, $bon, $oleh, $bersih) {
            $db = $this->db();
            $core = self::onCore();
            $trx = self::t('kk_trx');
            $idc = self::idCol();
            $now = (int) round(microtime(true) * 1000);
            if ($id > 0) {
                $n = $core
                    ? $db->update('UPDATE `'.$trx.'` SET `tgl`=?, `keterangan`=?, `kategori_id`=?, `input`=?, `bon`=?, `updated_at`=?, `version`=`version`+1 WHERE `'.$idc.'`=?',
                        [$tgl, $ket, $kat, $input, $bon, $now, $id])
                    : $db->update('UPDATE `kk_trx` SET `tgl`=?, `keterangan`=?, `kategori_id`=?, `input`=?, `bon`=? WHERE `id`=?',
                        [$tgl, $ket, $kat, $input, $bon, $id]);
                if ($n === 0 && (int) $db->selectOne('SELECT COUNT(*) AS n FROM `'.$trx.'` WHERE `'.$idc.'`=?', [$id])->n === 0) {
                    throw new RuntimeException('Transaksi sudah tidak ada — mungkin dihapus orang lain.');
                }
                // rewrite the split rows rather than patching them: an emptied source must disappear
                $db->delete('DELETE FROM `'.self::t('kk_trx_pos').'` WHERE `trx_id`=?', [$id]);
            } elseif ($core) {
                // the new id continues the legacy numbering (finance_counter) and the
                // split rows point at it, so read() keeps returning `id` unchanged
                $id = self::nextId($db, 'kk_trx');
                $db->insert('INSERT INTO `'.$trx.'` (`id`,`legacy_id`,`tgl`,`keterangan`,`kategori_id`,`input`,`bon`,`dibuat_at`,`dibuat_oleh`,`created_at`,`updated_at`,`version`)'
                    .' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [self::ulid(), (string) $id, $tgl, $ket, $kat, $input, $bon, $now, $oleh, $now, $now, 1]);
            } else {
                $db->insert('INSERT INTO `kk_trx` (`tgl`,`keterangan`,`kategori_id`,`input`,`bon`,`dibuat_at`,`dibuat_oleh`) VALUES (?,?,?,?,?,?,?)',
                    [$tgl, $ket, $kat, $input, $bon, (int) round(microtime(true) * 1000), $oleh]);
                $id = (int) $db->getPdo()->lastInsertId();
            }
            foreach ($bersih as $pid => $v) {
                if ($core) {
                    $db->insert('INSERT INTO `'.self::t('kk_trx_pos').'` (`id`,`legacy_id`,`trx_id`,`pos_id`,`debet`,`kredit`,`created_at`,`updated_at`,`version`)'
                        .' VALUES (?,?,?,?,?,?,?,?,?)',
                        [self::ulid(), (string) self::nextId($db, 'kk_trx_pos'), (string) $id, (string) $pid, $v['debet'], $v['kredit'], $now, $now, 1]);
                } else {
                    $db->insert('INSERT INTO `kk_trx_pos` (`trx_id`,`pos_id`,`debet`,`kredit`) VALUES (?,?,?,?)', [$id, $pid, $v['debet'], $v['kredit']]);
                }
            }

            return ['id' => $id];
        });
    }

    /** hapus_trx — split rows go with it (ON DELETE CASCADE). */
    public function deleteTrx(mixed $id): array
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new RuntimeException('Id transaksi tidak sah.');
        }

        return ['dihapus' => $this->db()->delete('DELETE FROM `'.self::t('kk_trx').'` WHERE `'.self::idCol().'`=?', [$id])];
    }

    /** tandai_trx — flip the Input / Bon marker without rewriting the transaction. */
    public function mark(mixed $id, string $field, bool $nilai): array
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new RuntimeException('Id transaksi tidak sah.');
        }
        if ($field !== 'input' && $field !== 'bon') {
            throw new RuntimeException('Penanda tidak dikenal: '.$field);
        }

        // The wire value IS the affected-row count (0 when the marker was already
        // set), so this one writes only the legacy column on core too: a `version`
        // bump would report a change the legacy backend would not have reported.
        return ['diubah' => $this->db()->update('UPDATE `'.self::t('kk_trx')."` SET `$field`=? WHERE `".self::idCol().'`=?', [$nilai ? 1 : 0, $id])];
    }

    // ───────────────────────────── sources (pos) & categories ──

    /** simpan_daftar — create or rename/reorder one source or category. */
    public function saveListItem(string $table, array $in): array
    {
        $nama = isset($in['nama']) ? trim(self::s($in['nama'])) : '';
        if ($nama === '') {
            throw new RuntimeException('Nama wajib diisi.');
        }
        $urut = isset($in['urut']) ? (int) $in['urut'] : 0;
        $id = (isset($in['id']) && $in['id']) ? (int) $in['id'] : 0;
        $t = self::t($table);
        if (self::onCore()) {
            $nama = RowSync::fit($this->db(), $t, 'nama', $nama);
        }
        $now = (int) round(microtime(true) * 1000);
        try {
            if ($id > 0) {
                $this->db()->update(self::onCore()
                    ? 'UPDATE `'.$t.'` SET `nama`=?, `urut`=?, `updated_at`=?, `version`=`version`+1 WHERE `'.self::idCol().'`=?'
                    : 'UPDATE `'.$t.'` SET `nama`=?, `urut`=? WHERE `'.self::idCol().'`=?',
                    self::onCore() ? [$nama, $urut, $now, $id] : [$nama, $urut, $id]);
            } elseif (self::onCore()) {
                // one transaction: the new id comes from finance_counter and the insert
                // must not burn it for nothing (a duplicate name rolls both back)
                $id = $this->db()->transaction(function () use ($table, $t, $nama, $urut, $now) {
                    $id = self::nextId($this->db(), $table);
                    $this->db()->insert('INSERT INTO `'.$t.'` (`id`,`legacy_id`,`nama`,`urut`,`created_at`,`updated_at`,`version`) VALUES (?,?,?,?,?,?,?)',
                        [self::ulid(), (string) $id, $nama, $urut, $now, $now, 1]);

                    return $id;
                });
            } else {
                $this->db()->insert("INSERT INTO `$table` (`nama`,`urut`) VALUES (?,?)", [$nama, $urut]);
                $id = (int) $this->db()->getPdo()->lastInsertId();
            }
        } catch (QueryException $e) {
            // 23000 = UNIQUE violation: said in human words
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException('"'.$nama.'" sudah ada dalam daftar.');
            }
            throw $e;
        }

        return ['id' => $id];
    }

    public function setActive(string $table, mixed $id, bool $aktif): array
    {
        // like mark(): the response is the affected-row count, so no version bump
        return ['diubah' => $this->db()->update('UPDATE `'.self::t($table).'` SET `aktif`=? WHERE `'.self::idCol().'`=?', [$aktif ? 1 : 0, (int) $id])];
    }

    /** hapus_pos / hapus_kategori — refused once used (the real guard; the screen only suggests deactivating). */
    public function deleteListItem(string $table, mixed $id): array
    {
        $id = (int) $id;
        [$usedSql, $msg] = $table === 'kk_pos'
            ? ['SELECT COUNT(*) AS n FROM `'.self::t('kk_trx_pos').'` WHERE `pos_id`=?', 'Pos ini sudah dipakai transaksi — nonaktifkan saja.']
            : ['SELECT COUNT(*) AS n FROM `'.self::t('kk_trx').'` WHERE `kategori_id`=?', 'Kategori ini sudah dipakai transaksi — nonaktifkan saja.'];
        if ((int) $this->db()->selectOne($usedSql, [$id])->n > 0) {
            throw new RuntimeException($msg);
        }

        return ['dihapus' => $this->db()->delete('DELETE FROM `'.self::t($table).'` WHERE `'.self::idCol().'`=?', [$id])];
    }

    // ───────────────────────────── Akses Halaman ──

    /**
     * akses_simpan — replace the WHOLE matrix (delete, then insert). The client
     * must always send the complete map; tingkat is clamped to 0..2.
     */
    public function saveAkses(mixed $peta): object
    {
        $peta = is_array($peta) ? $peta : [];
        $this->db()->transaction(function () use ($peta) {
            $core = self::onCore();
            $t = self::t('kk_akses');
            $now = (int) round(microtime(true) * 1000);
            $this->db()->delete('DELETE FROM `'.$t.'`');
            foreach ($peta as $kunci => $baris) {
                if (! is_array($baris)) {
                    continue;
                }
                $kunci = substr((string) $kunci, 0, 80);
                if ($kunci === '') {
                    continue;
                }
                foreach ($baris as $hal => $tk) {
                    $hal = substr((string) $hal, 0, 40);
                    if ($hal === '') {
                        continue;
                    }
                    $core
                        ? $this->db()->insert('INSERT INTO `'.$t.'` (`id`,`legacy_id`,`kunci`,`halaman`,`tingkat`,`created_at`,`updated_at`,`version`) VALUES (?,?,?,?,?,?,?,?)',
                            [self::ulid(), (string) self::nextId($this->db(), 'kk_akses'), $kunci, $hal, max(0, min(2, (int) $tk)), $now, $now, 1])
                        : $this->db()->insert('INSERT INTO `kk_akses` (`kunci`,`halaman`,`tingkat`) VALUES (?,?,?)',
                            [$kunci, $hal, max(0, min(2, (int) $tk))]);
                }
            }
        });

        return $this->akses();
    }

    /** peran_simpan — ONE person's role; an empty role deletes the row (back to the default). */
    public function saveRole(array $in): object
    {
        $kunci = isset($in['kunci']) ? substr(trim(self::s($in['kunci'])), 0, 80) : '';
        $peran = isset($in['peran']) ? substr(trim(self::s($in['peran'])), 0, 24) : '';
        if ($kunci === '') {
            throw new RuntimeException('kunci kru kosong');
        }
        if ($peran === '') {
            $this->db()->delete('DELETE FROM `'.self::t('kk_peran').'` WHERE `kunci`=?', [$kunci]);
        } elseif (self::onCore()) {
            // version first: it must read the OLD values (ADR-0003 / the recipe)
            $now = (int) round(microtime(true) * 1000);
            $this->db()->insert('INSERT INTO `'.self::t('kk_peran').'` (`id`,`kunci`,`peran`,`user_id`,`created_at`,`updated_at`,`version`)'
                .' VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `version`=`version`+1, `peran`=VALUES(`peran`),'
                .' `user_id`=VALUES(`user_id`), `updated_at`=VALUES(`updated_at`)',
                [self::ulid(), $kunci, $peran, self::userId($kunci), $now, $now, 1]);
        } else {
            $this->db()->insert('INSERT INTO `kk_peran` (`kunci`,`peran`) VALUES (?,?) ON DUPLICATE KEY UPDATE `peran`=VALUES(`peran`)', [$kunci, $peran]);
        }

        return $this->peran();
    }

    // ───────────────────────────── diagnostics ──

    /** ping — always ok:true; reports the DB state instead of failing with it. */
    public function ping(): array
    {
        try {
            $this->db()->select('SELECT 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = self::hint($e->getMessage());
        }

        return ['pong' => true, 'env' => Modules::envLabel(), 'db' => $db];
    }

    /** petunjuk_galat — the three MySQL errors everyone meets on a fresh cPanel install, with the fix. Order matters (1044 before 1045). */
    public static function hint(string $p): string
    {
        if (str_contains($p, '1044') || stripos($p, 'to database') !== false) {
            return $p.'  ->  User-nya benar tapi belum dikaitkan ke database. cPanel > MySQL Databases > "Add User To Database" -> pilih database yang sama dengan DB_NAME -> ALL PRIVILEGES.';
        }
        if (str_contains($p, '1049') || stripos($p, 'Unknown database') !== false) {
            return $p.'  ->  Database-nya belum dibuat. cPanel > MySQL Databases > "Create New Database", namanya harus sama persis dengan DB_NAME di config.php (awalan lakk5493_ ditambahkan cPanel sendiri).';
        }
        if (str_contains($p, '1045') || stripos($p, 'Access denied for user') !== false) {
            return $p.'  ->  User MySQL-nya belum ada, ATAU password di config.php beda dengan yang tersimpan di cPanel. Galat ini TIDAK membedakan keduanya. Cek cPanel > MySQL Databases > Current Users: kalau user-nya belum terdaftar, buat dulu; kalau sudah, pakai "Change Password" dan tempel PERSIS isi DB_PASS (awas spasi ikut tersalin).';
        }

        return $p;
    }
}
