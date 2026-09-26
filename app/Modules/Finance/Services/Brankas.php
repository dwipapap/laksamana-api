<?php

namespace App\Modules\Finance\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * Finance → Brankas (cash vault) — port of the brankas_* part of
 * finance-mysql/lib_finance_mysql.php.
 *
 * The vault state is ONE JSON blob (`bk_state` id=1): only the CFO edits it.
 * Account balances are NOT stored (the screen derives them from kompas'
 * sales recap); what is stored has no other source: opening balances,
 * receivables, payment planning, investors, wallet transfers, settings.
 *
 * KEPT_KEYS is the one list that decides what survives a save: a key the
 * frontend uses but that is not listed here vanishes on the next reload
 * (happened on 2 Sept 2026 with `mutasi`).
 *
 * JSON is decoded as assoc arrays like legacy, so an empty `setting` object
 * comes back as `[]` once it has been round-tripped — kept as is.
 */
class Brankas
{
    public const KEPT_KEYS = ['rekening', 'piutang', 'bayar', 'investor', 'mutasi'];

    public function db(): ConnectionInterface
    {
        return Modules::db('finance');
    }

    /** brankas_baca — {data, akses, peran, updated_at}; a complete empty shape when nothing is stored. */
    public function read(bool $lock = false): array
    {
        $db = $this->db();
        $data = null;
        $ts = 0;
        $row = $db->selectOne('SELECT `data`,`updated_at` FROM `'.KasKecil::t('bk_state').'` WHERE `'.KasKecil::idCol().'`=1'.($lock ? ' FOR UPDATE' : ''));
        if ($row && (string) $row->data !== '') {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $data = $d;
                $ts = (int) $row->updated_at;
            }
        }
        $data ??= ['rekening' => [], 'piutang' => [], 'bayar' => [], 'investor' => [], 'mutasi' => [], 'setting' => new stdClass];

        return ['data' => $data, 'akses' => $this->akses(), 'peran' => $this->peran(), 'updated_at' => $ts];
    }

    public function akses(): object
    {
        $out = [];
        foreach ($this->db()->select('SELECT `kunci`,`halaman`,`tingkat` FROM `'.KasKecil::t('bk_akses').'`') as $r) {
            $out[(string) $r->kunci][(string) $r->halaman] = (int) $r->tingkat;
        }

        return (object) $out;
    }

    public function peran(): object
    {
        $out = [];
        foreach ($this->db()->select('SELECT `kunci`,`peran` FROM `'.KasKecil::t('bk_peran').'`') as $r) {
            $out[(string) $r->kunci] = (string) $r->peran;
        }

        return (object) $out;
    }

    /**
     * brankas_simpan — write the WHOLE state, filtered to the kept keys (lists
     * are re-indexed; `setting` must be a map). The client must send the full state.
     *
     * @return array{saved:true,updated_at:int}
     */
    public function save(mixed $data, mixed $oleh, ?int $ts = null): array
    {
        if (! is_array($data)) {
            throw new RuntimeException('data brankas kosong');
        }
        $clean = [];
        foreach (self::KEPT_KEYS as $k) {
            $clean[$k] = isset($data[$k]) && is_array($data[$k]) ? array_values($data[$k]) : [];
        }
        $clean['setting'] = isset($data['setting']) && is_array($data['setting']) ? $data['setting'] : new stdClass;

        $by = $oleh === null ? '' : substr(trim(KasKecil::s($oleh)), 0, 80);
        $ts ??= (int) round(microtime(true) * 1000);
        if (KasKecil::onCore()) {
            // the singleton keeps legacy id 1; created_at is set once (not in the ODKU)
            $this->db()->insert('INSERT INTO `'.KasKecil::t('bk_state').'` (`id`,`legacy_id`,`data`,`created_at`,`updated_at`,`diubah_oleh`,`version`) VALUES (?,?,?,?,?,?,?)'
                .' ON DUPLICATE KEY UPDATE `version`=`version`+1, `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `diubah_oleh`=VALUES(`diubah_oleh`)',
                [KasKecil::ulid(), '1', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ts, $ts, $by, 1]);
        } else {
            $this->db()->insert('INSERT INTO `bk_state` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
            ON DUPLICATE KEY UPDATE `data`=VALUES(`data`),`updated_at`=VALUES(`updated_at`),`updated_by`=VALUES(`updated_by`)',
                [json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ts, $by]);
        }

        return ['saved' => true, 'updated_at' => $ts];
    }

    /**
     * brankas_bayar_simpan — the NARROW writer used by Kas Kecil's Payment
     * Planning: read the blob, replace only `bayar`, write the rest back as is.
     * (Read and write run under one row lock here; legacy had none.)
     */
    public function saveBayar(mixed $rows, mixed $oleh): array
    {
        if (! is_array($rows)) {
            throw new RuntimeException('daftar pembayaran kosong');
        }

        return $this->db()->transaction(function () use ($rows, $oleh) {
            $data = $this->read(true)['data'];
            $data['bayar'] = array_values($rows);

            return $this->save($data, $oleh);
        });
    }

    /**
     * v1 write: under the row lock, check the client's version (= updated_at of
     * the blob it read), apply $fn to the state and save it with a strictly
     * increasing stamp. Throws FinanceConflict (current state) when stale.
     *
     * @param  \Closure(array): array  $fn
     * @return array{data:array, version:int}
     */
    public function mutate(int $base, \Closure $fn, string $by): array
    {
        return $this->db()->transaction(function () use ($base, $fn, $by) {
            $cur = $this->read(true);
            if ($cur['updated_at'] !== $base) {
                throw new FinanceConflict(['data' => $cur['data'], 'version' => $cur['updated_at']]);
            }
            $ts = max((int) round(microtime(true) * 1000), $base + 1);
            $this->save($fn($cur['data']), $by, $ts);
            $now = $this->read();

            return ['data' => $now['data'], 'version' => $now['updated_at']];
        });
    }

    /** brankas_akses_simpan — replace the whole page × role matrix (clamped 0..2). */
    public function saveAkses(mixed $peta): array
    {
        $peta = is_array($peta) ? $peta : [];
        $this->db()->transaction(function () use ($peta) {
            $core = KasKecil::onCore();
            $t = KasKecil::t('bk_akses');
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
                    if ($hal !== '') {
                        $core
                            ? $this->db()->insert('INSERT INTO `'.$t.'` (`id`,`legacy_id`,`kunci`,`halaman`,`tingkat`,`created_at`,`updated_at`,`version`) VALUES (?,?,?,?,?,?,?,?)',
                                [KasKecil::ulid(), (string) KasKecil::nextId($this->db(), 'bk_akses'), $kunci, $hal, max(0, min(2, (int) $tk)), $now, $now, 1])
                            : $this->db()->insert('INSERT INTO `bk_akses` (`kunci`,`halaman`,`tingkat`) VALUES (?,?,?)', [$kunci, $hal, max(0, min(2, (int) $tk))]);
                    }
                }
            }
        });

        return ['akses' => $this->akses()];
    }

    /** brankas_peran_simpan — ONE person's role; empty deletes the row. */
    public function saveRole(array $in): array
    {
        $kunci = isset($in['kunci']) ? substr(trim(KasKecil::s($in['kunci'])), 0, 80) : '';
        $peran = isset($in['peran']) ? substr(trim(KasKecil::s($in['peran'])), 0, 24) : '';
        if ($kunci === '') {
            throw new RuntimeException('kunci kru kosong');
        }
        if ($peran === '') {
            $this->db()->delete('DELETE FROM `'.KasKecil::t('bk_peran').'` WHERE `kunci`=?', [$kunci]);
        } elseif (KasKecil::onCore()) {
            // version first: it must read the OLD values (ADR-0003 / the recipe)
            $now = (int) round(microtime(true) * 1000);
            $this->db()->insert('INSERT INTO `'.KasKecil::t('bk_peran').'` (`id`,`kunci`,`peran`,`user_id`,`created_at`,`updated_at`,`version`)'
                .' VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `version`=`version`+1, `peran`=VALUES(`peran`),'
                .' `user_id`=VALUES(`user_id`), `updated_at`=VALUES(`updated_at`)',
                [KasKecil::ulid(), $kunci, $peran, KasKecil::userId($kunci), $now, $now, 1]);
        } else {
            $this->db()->insert('INSERT INTO `bk_peran` (`kunci`,`peran`) VALUES (?,?) ON DUPLICATE KEY UPDATE `peran`=VALUES(`peran`)', [$kunci, $peran]);
        }

        return ['peran' => $this->peran()];
    }
}
