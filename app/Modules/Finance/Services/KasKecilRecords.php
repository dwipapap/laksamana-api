<?php

namespace App\Modules\Finance\Services;

/**
 * Versioned single-record access for /api/v1/finance/petty-cash on top of
 * the KasKecil service (same validation, same messages).
 *
 * The kk_* tables have no version column, so a version is a hash of what
 * the client sees: a transaction together with its split rows, one source /
 * category row, the whole access matrix, one person's role. Every write checks
 * it under SELECT … FOR UPDATE inside one DB transaction.
 */
class KasKecilRecords
{
    public function __construct(private readonly KasKecil $kas) {}

    public static function version(mixed $v): string
    {
        return substr(sha1(json_encode($v, JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    // ───────────────────────────── transactions ──

    /** @return list<array> transactions (legacy getAll shape), optionally within [from, to] */
    public function transactions(?string $from, ?string $to): array
    {
        return array_values(array_filter($this->kas->read()['trx'],
            fn ($t) => ($from === null || $t['tgl'] >= $from) && ($to === null || $t['tgl'] <= $to)));
    }

    public function transaction(int $id, bool $lock = false): ?array
    {
        $db = $this->kas->db();
        $t = $db->selectOne('SELECT '.KasKecil::idSelect().',`tgl`,`keterangan`,`kategori_id`,`input`,`bon`,`dibuat_at`,`dibuat_oleh` FROM `'.KasKecil::t('kk_trx').'` WHERE `'.KasKecil::idCol().'`=?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $t) {
            return null;
        }
        $baris = array_map(fn ($b) => ['pos_id' => (int) $b->pos_id, 'debet' => (int) $b->debet, 'kredit' => (int) $b->kredit],
            $db->select('SELECT `pos_id`,`debet`,`kredit` FROM `'.KasKecil::t('kk_trx_pos').'` WHERE `trx_id`=? ORDER BY '.KasKecil::idOrder(), [$id]));

        return [
            'id' => (int) $t->id, 'tgl' => (string) $t->tgl, 'keterangan' => (string) $t->keterangan,
            'kategori_id' => $t->kategori_id === null ? null : (int) $t->kategori_id,
            'input' => (int) $t->input, 'bon' => (int) $t->bon, 'dibuat_at' => (int) $t->dibuat_at,
            'dibuat_oleh' => (string) $t->dibuat_oleh, 'baris' => $baris,
        ];
    }

    /** Create; `dibuat_oleh` is the session user. */
    public function createTransaction(array $in, string $by): array
    {
        unset($in['id']);
        $in['oleh'] = $by;

        return $this->transaction($this->kas->saveTrx($in)['id']);
    }

    /**
     * Replace a transaction (and its split rows) or, with $markersOnly, only its
     * input/bon markers. null = not found; throws FinanceConflict on a stale version.
     */
    public function updateTransaction(int $id, array $in, string $base, bool $markersOnly): ?array
    {
        return $this->kas->db()->transaction(function () use ($id, $in, $base, $markersOnly) {
            $cur = $this->transaction($id, true);
            if (! $cur) {
                return null;
            }
            $this->guard($cur, $base);
            if ($markersOnly) {
                foreach (['input', 'bon'] as $f) {
                    if (array_key_exists($f, $in)) {
                        $this->kas->mark($id, $f, ! empty($in[$f]));
                    }
                }
            } else {
                $this->kas->saveTrx(['id' => $id] + $in);
            }

            return $this->transaction($id);
        });
    }

    public function deleteTransaction(int $id, string $base): bool
    {
        return $this->kas->db()->transaction(function () use ($id, $base) {
            $cur = $this->transaction($id, true);
            if (! $cur) {
                return false;
            }
            $this->guard($cur, $base);
            $this->kas->deleteTrx($id);

            return true;
        });
    }

    // ───────────────────────────── sources & categories ──

    public function item(string $table, int $id, bool $lock = false): ?array
    {
        $r = $this->kas->db()->selectOne('SELECT '.KasKecil::idSelect().',`nama`,`urut`,`aktif` FROM `'.KasKecil::t($table).'` WHERE `'.KasKecil::idCol().'`=?'.($lock ? ' FOR UPDATE' : ''), [$id]);

        return $r ? ['id' => (int) $r->id, 'nama' => (string) $r->nama, 'urut' => (int) $r->urut, 'aktif' => (int) $r->aktif === 1] : null;
    }

    /** Update name/order and/or the active flag of one item. */
    public function updateItem(string $table, int $id, array $in, string $base): ?array
    {
        return $this->kas->db()->transaction(function () use ($table, $id, $in, $base) {
            $cur = $this->item($table, $id, true);
            if (! $cur) {
                return null;
            }
            $this->guard($cur, $base);
            if (array_key_exists('nama', $in) || array_key_exists('urut', $in)) {
                $this->kas->saveListItem($table, ['id' => $id, 'nama' => $in['nama'] ?? $cur['nama'], 'urut' => $in['urut'] ?? $cur['urut']]);
            }
            if (array_key_exists('aktif', $in)) {
                $this->kas->setActive($table, $id, (bool) $in['aktif']);
            }

            return $this->item($table, $id);
        });
    }

    public function deleteItem(string $table, int $id, string $base): bool
    {
        return $this->kas->db()->transaction(function () use ($table, $id, $base) {
            $cur = $this->item($table, $id, true);
            if (! $cur) {
                return false;
            }
            $this->guard($cur, $base);
            $this->kas->deleteListItem($table, $id);

            return true;
        });
    }

    // ───────────────────────────── access ──

    /** Replace the whole matrix; the version is the hash of the matrix the client edited. */
    public function saveMatrix(mixed $peta, string $base): object
    {
        return $this->kas->db()->transaction(function () use ($peta, $base) {
            $this->kas->db()->select('SELECT '.KasKecil::idCol().' FROM `'.KasKecil::t('kk_akses').'` FOR UPDATE');
            $this->guard($this->kas->akses(), $base);

            return $this->kas->saveAkses($peta);
        });
    }

    /** One person's role ('' / null = back to the default). */
    public function saveRole(string $userId, ?string $role, string $base): object
    {
        return $this->kas->db()->transaction(function () use ($userId, $role, $base) {
            $cur = $this->kas->db()->selectOne('SELECT `peran` FROM `'.KasKecil::t('kk_peran').'` WHERE `kunci`=? FOR UPDATE', ['#'.$userId]);
            $this->guard($cur ? (string) $cur->peran : null, $base);

            return $this->kas->saveRole(['kunci' => '#'.$userId, 'peran' => (string) $role]);
        });
    }

    private function guard(mixed $current, string $base): void
    {
        if (! hash_equals(self::version($current), $base)) {
            throw new FinanceConflict($current);
        }
    }
}
