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
 * JSON is decoded as assoc arrays like legacy, so an empty `setting` read
 * back comes out as `[]` (as legacy); every write stores it as `{}` again.
 */
class Brankas
{
    public const KEPT_KEYS = ['rekening', 'piutang', 'bayar', 'investor', 'mutasi'];

    /** The five wallets both finance panels agree on (legacy BANKS + KAS_K). */
    public const WALLETS = ['bri', 'mandiri', 'bca', 'uob', 'cash'];

    public const WALLET_NAMES = [
        'bri' => 'BRI',
        'mandiri' => 'Mandiri',
        'bca' => 'BCA',
        'uob' => 'UOB',
        'cash' => 'Cash / Brankas Fisik',
    ];

    public function db(): ConnectionInterface
    {
        return Modules::db('finance');
    }

    /**
     * brankas_baca — {data, akses, peran, updated_at, ver}; a complete empty
     * shape when nothing is stored. `_ver` is guard metadata: it is stripped
     * from `data` and returned as `ver` ({lain, bayar}).
     */
    public function read(): array
    {
        $data = null;
        $ts = 0;
        $ver = ['lain' => 0, 'bayar' => 0];
        $row = $this->db()->selectOne('SELECT `data`,`updated_at` FROM `'.KasKecil::t('bk_state').'` WHERE `'.KasKecil::idCol().'`=1');
        if ($row && (string) $row->data !== '') {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $ts = (int) $row->updated_at;
                $ver = self::ver($d, $ts);
                unset($d['_ver']);
                $data = $d;
            }
        }
        $data ??= ['rekening' => [], 'piutang' => [], 'bayar' => [], 'investor' => [], 'mutasi' => [], 'setting' => new stdClass];

        return ['data' => $data, 'akses' => $this->akses(), 'peran' => $this->peran(), 'updated_at' => $ts, 'ver' => $ver];
    }

    /**
     * Vault-side wallet balances — the vault half of the old `saldoSemua()`
     * (deploy/finance/brankas/index.html), the same formula Kas Kecil's
     * Payment Planning reuses (deploy/finance/kas/index.html: saldoSemua).
     * This is the ONE place that computes it: GET /finance/vault exposes the
     * raw blob for `brankas` holders, and the narrow
     * GET /finance/petty-cash/wallet-balances calls this for `finance`
     * holders — never a copied formula.
     *
     * Only what the vault blob itself records: opening balances
     * (`setting.awal`), paid plans (`bayar` with status `paid`), wallet
     * transfers (`mutasi`) and investor flows (`returns` out of a wallet,
     * `tambahan` into one). Kompas sales (Aktual Masuk, setoran) are NOT
     * folded in — no PHP port of that mapping exists, and finance holders
     * already read kompas state, so the client adds them with the same
     * engine as the Brankas panel. Rows naming no known wallet are ignored,
     * never guessed (as legacy); scheduled (unpaid) plans change nothing.
     *
     * @return array{wallets: list<array{wallet: string, nama: string, saldo: int}>, total: int, peta: array}
     */
    public function walletBalances(?array $data = null): array
    {
        $data ??= $this->read()['data'];
        $bal = [];
        foreach (self::WALLETS as $w) {
            $bal[$w] = 0;
        }
        $setting = isset($data['setting']) && is_array($data['setting']) ? $data['setting'] : [];
        $awal = isset($setting['awal']) && is_array($setting['awal']) ? $setting['awal'] : [];
        foreach (self::WALLETS as $w) {
            $bal[$w] += self::num($awal[$w] ?? 0);
        }
        foreach (isset($data['bayar']) && is_array($data['bayar']) ? $data['bayar'] : [] as $p) {
            if (! is_array($p) || ($p['status'] ?? null) !== 'paid') {
                continue;
            }
            $w = isset($p['dari']) ? (string) $p['dari'] : '';
            if (isset($bal[$w])) {
                $bal[$w] -= self::num($p['amount'] ?? 0);
            }
        }
        foreach (isset($data['mutasi']) && is_array($data['mutasi']) ? $data['mutasi'] : [] as $m) {
            if (! is_array($m)) {
                continue;
            }
            $n = self::num($m['nominal'] ?? 0);
            if ($n === 0) {
                continue;
            }
            $jenis = isset($m['jenis']) ? (string) $m['jenis'] : '';
            $dari = isset($m['dari']) ? (string) $m['dari'] : '';
            $ke = isset($m['ke']) ? (string) $m['ke'] : '';
            if ($jenis === 'masuk') {
                if (isset($bal[$ke])) {
                    $bal[$ke] += $n;
                }
            } elseif ($jenis === 'keluar') {
                if (isset($bal[$dari])) {
                    $bal[$dari] -= $n;
                }
            } elseif ($jenis === 'pindah') {
                if (isset($bal[$dari])) {
                    $bal[$dari] -= $n;
                }
                if (isset($bal[$ke])) {
                    $bal[$ke] += $n;
                }
            }
        }
        foreach (isset($data['investor']) && is_array($data['investor']) ? $data['investor'] : [] as $inv) {
            if (! is_array($inv)) {
                continue;
            }
            foreach (isset($inv['returns']) && is_array($inv['returns']) ? $inv['returns'] : [] as $r) {
                $w = is_array($r) && isset($r['dari']) ? (string) $r['dari'] : '';
                if (isset($bal[$w])) {
                    $bal[$w] -= self::num(is_array($r) ? ($r['amount'] ?? 0) : 0);
                }
            }
            foreach (isset($inv['tambahan']) && is_array($inv['tambahan']) ? $inv['tambahan'] : [] as $t) {
                $w = is_array($t) && isset($t['ke']) ? (string) $t['ke'] : '';
                if (isset($bal[$w])) {
                    $bal[$w] += self::num(is_array($t) ? ($t['amount'] ?? 0) : 0);
                }
            }
        }
        $wallets = [];
        foreach (self::WALLETS as $w) {
            $wallets[] = ['wallet' => $w, 'nama' => self::WALLET_NAMES[$w], 'saldo' => $bal[$w]];
        }
        $peta = isset($setting['peta']) && is_array($setting['peta']) ? $setting['peta'] : [];

        return ['wallets' => $wallets, 'total' => array_sum($bal), 'peta' => $peta];
    }

    /** Legacy `num()`: whole rupiah from free text; unparseable is 0. */
    public static function num(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }
        $s = preg_replace('/[^0-9-]/', '', (string) $v);

        return ($s === '' || $s === '-') ? 0 : (int) $s;
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

    // ───────────────────────────── writing the blob ──
    //
    // Port of brankas_tulis() (legacy ff6a774, 1 Oct 2026): ONE writer for both
    // panels. Before it, brankasSave wrote the whole blob including `bayar`, so
    // a Brankas tab opened in the morning wiped the Payment Planning rows typed
    // in Kas Kecil since then, and two tabs of one panel overwrote each other —
    // all without an error. Now:
    //  - each panel writes ONLY its own keys; the rest come from the blob on the
    //    server (Brankas never writes `bayar`);
    //  - versions are kept per owner (`_ver.lain` = Brankas, `_ver.bayar` = Kas
    //    Kecil), so a save in one panel never makes the other one conflict;
    //  - a baseVer that differs from the server's is REJECTED, never overwritten.
    //    No baseVer = an old cached client: let through (it can no longer touch
    //    `bayar` anyway), as legacy does;
    //  - read-modify-write runs in one transaction under SELECT … FOR UPDATE.

    /** The keys each owner writes. */
    private const OWNER_KEYS = [
        'lain' => ['rekening', 'piutang', 'investor', 'mutasi', 'setting'],
        'bayar' => ['bayar'],
    ];

    /** brankas_ver — {lain, bayar}; a blob without `_ver` counts as its row's updated_at for both. */
    public static function ver(mixed $data, int $ts): array
    {
        $v = is_array($data) && isset($data['_ver']) && is_array($data['_ver']) ? $data['_ver'] : [];
        $out = [];
        foreach (['lain', 'bayar'] as $j) {
            $out[$j] = isset($v[$j]) ? (int) $v[$j] : $ts;
        }

        return $out;
    }

    /**
     * brankas_saring — only the kept keys survive (lists re-indexed). An empty
     * `setting` MUST become {}: the blob is decoded as assoc arrays, and an empty
     * map would otherwise be written back as [].
     */
    public static function filter(array $data): array
    {
        $clean = [];
        foreach (self::KEPT_KEYS as $k) {
            $clean[$k] = isset($data[$k]) && is_array($data[$k]) ? array_values($data[$k]) : [];
        }
        $clean['setting'] = isset($data['setting']) && is_array($data['setting']) && $data['setting'] ? $data['setting'] : new stdClass;

        return $clean;
    }

    /**
     * brankas_simpan — panel Brankas: writes its own keys, never `bayar`.
     *
     * @return array{saved:true,updated_at:int,ver:array}|array{conflict:true,oleh:string,ver:array}
     */
    public function save(mixed $data, mixed $oleh, mixed $baseVer = null): array
    {
        if (! is_array($data)) {
            throw new RuntimeException('data brankas kosong');
        }

        return $this->write($data, 'lain', $baseVer, $oleh);
    }

    /** brankas_bayar_simpan — panel Kas Kecil (Payment Planning): writes only `bayar`. */
    public function saveBayar(mixed $rows, mixed $oleh, mixed $baseVer = null): array
    {
        if (! is_array($rows)) {
            throw new RuntimeException('daftar pembayaran kosong');
        }

        return $this->write(['bayar' => array_values($rows)], 'bayar', $baseVer, $oleh);
    }

    /** brankas_tulis — merge $owner's keys from $new into the server blob, guarded by that owner's version. */
    private function write(array $new, string $owner, mixed $baseVer, mixed $oleh): array
    {
        return $this->db()->transaction(function () use ($new, $owner, $baseVer, $oleh) {
            [$old, $ts, $lastBy] = $this->lockRow();
            $ver = self::ver($old, $ts);
            if ($baseVer !== null && $baseVer !== '' && (int) $baseVer > 0 && (int) $baseVer !== $ver[$owner]) {
                return ['conflict' => true, 'oleh' => $lastBy, 'ver' => $ver];
            }

            return $this->store($old, $new, self::OWNER_KEYS[$owner], $ver, [$owner], $oleh);
        });
    }

    /**
     * v1 write: under the same row lock and writer, check the client's version
     * (= updated_at of the blob it read), apply $fn to the state and save it with
     * a strictly increasing stamp, bumping `_ver` of each owner whose keys changed.
     * Throws FinanceConflict (current state) when stale.
     *
     * @param  \Closure(array): array  $fn
     * @return array{data:array, version:int}
     */
    public function mutate(int $base, \Closure $fn, string $by): array
    {
        return $this->db()->transaction(function () use ($base, $fn, $by) {
            [$old, $ts] = $this->lockRow();
            $ver = self::ver($old, $ts);
            $cur = $old;
            unset($cur['_ver']);
            $cur = $old === [] ? self::filter([]) : $cur;
            if ($ts !== $base) {
                throw new FinanceConflict(['data' => $cur, 'version' => $ts]);
            }
            $new = $fn($cur);
            $owners = [];
            foreach (self::OWNER_KEYS as $owner => $keys) {
                foreach ($keys as $k) {
                    if (($new[$k] ?? null) !== ($cur[$k] ?? null)) {
                        $owners[] = $owner;
                        break;
                    }
                }
            }
            $this->store($old, $new, [...self::KEPT_KEYS, 'setting'], $ver, $owners ?: ['lain'], $by, $base + 1);
            $now = $this->read();

            return ['data' => $now['data'], 'version' => $now['updated_at']];
        });
    }

    /** SELECT … FOR UPDATE of the blob row: [decoded data or [], updated_at, last writer]. */
    private function lockRow(): array
    {
        $by = KasKecil::onCore() ? '`diubah_oleh`' : '`updated_by`';
        $row = $this->db()->selectOne('SELECT `data`,`updated_at`,'.$by.' AS `oleh` FROM `'.KasKecil::t('bk_state').'` WHERE `'.KasKecil::idCol().'`=1 FOR UPDATE');
        if (! $row) {
            return [[], 0, ''];
        }
        $d = json_decode((string) $row->data, true);

        return [is_array($d) ? $d : [], (int) $row->updated_at, (string) $row->oleh];
    }

    /** Merge $keys of $new over $old, filter, bump the owners' versions and upsert. Caller holds the row lock. */
    private function store(array $old, array $new, array $keys, array $ver, array $owners, mixed $oleh, int $floor = 0): array
    {
        $merged = $old;
        foreach ($keys as $k) {
            if (array_key_exists($k, $new)) {
                $merged[$k] = $new[$k];
            }
        }
        $clean = self::filter($merged);

        // the version MUST go up even if the server clock stepped back: an equal
        // version would let a stale tab pass the check
        $now = max((int) round(microtime(true) * 1000), $floor);
        foreach ($owners as $o) {
            if ($now <= $ver[$o]) {
                $now = $ver[$o] + 1;
            }
        }
        foreach ($owners as $o) {
            $ver[$o] = $now;
        }
        $clean['_ver'] = $ver;

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $by = substr(trim(KasKecil::s($oleh ?? '')), 0, 80);
        if (KasKecil::onCore()) {
            // the singleton keeps legacy id 1; created_at is set once (not in the ODKU)
            $this->db()->insert('INSERT INTO `'.KasKecil::t('bk_state').'` (`id`,`legacy_id`,`data`,`created_at`,`updated_at`,`diubah_oleh`,`version`) VALUES (?,?,?,?,?,?,?)'
                .' ON DUPLICATE KEY UPDATE `version`=`version`+1, `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `diubah_oleh`=VALUES(`diubah_oleh`)',
                [KasKecil::ulid(), '1', $json, $now, $now, $by, 1]);
        } else {
            $this->db()->insert('INSERT INTO `bk_state` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
            ON DUPLICATE KEY UPDATE `data`=VALUES(`data`),`updated_at`=VALUES(`updated_at`),`updated_by`=VALUES(`updated_by`)',
                [$json, $now, $by]);
        }

        return ['saved' => true, 'updated_at' => $now, 'ver' => $ver];
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
