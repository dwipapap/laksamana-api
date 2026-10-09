<?php

namespace App\Modules\Kompas\Services;

use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Kompas → Catatan Void (void_log) and QRIS BRI fund matching (bri_mutasi,
 * bri_dp_abai) — port of the void_* / bri_* part of lib_kompas_mysql.php.
 *
 * Accountability records: NOTHING is deleted. A wrong entry is CANCELLED
 * (batal_at + a required reason) and stays visible. The only DELETE is
 * lifting an "ignored DP" marker (briAbai pulih).
 *
 * Money is decided HERE, never taken from the screen: a void's nominal is
 * subtotal + service + tax, bill-level service/tax is split cumulatively
 * (no row can go negative), BRI sidik keys are generated server-side, and
 * one DP can be held by only one live mutation.
 *
 * Every result is the legacy array with 'ok' (the controller wraps it as
 * {ok:true, data:<result>} or returns the failure array as is).
 * Runtime DDL / column checks (void_pastikan, bri_pastikan…) are not ported.
 *
 * Core (#69, DB_KOMPAS_CONNECTION=core): the same statements run on the
 * kompas_* tables of `core` (see KompasState::t()), rows keyed by
 * `legacy_id`, the void percentages as the `void` document of
 * kompas_pengaturan, and `version` counting accepted writes. Mapping:
 * docs/db/kompas.md.
 */
class VoidBri
{
    public const VOID_MAX = 1500;

    public const BRI_MAX = 2000;

    public const BRI_UPLOAD_MAX = 3000;

    public function db(): ConnectionInterface
    {
        return Modules::db('kompas');
    }

    /** Physical table for a legacy table name on the current connection. */
    private static function t(string $table): string
    {
        return KompasState::t($table);
    }

    /** Record id column: the legacy id, or `legacy_id` on core. */
    private static function idCol(): string
    {
        return KompasState::idCol();
    }

    /**
     * The legacy id of a row read with `SELECT *`, whichever storage holds it:
     * it is what every wire shape and the v1 edit/cancel paths speak.
     */
    private static function rowId(object $row): string
    {
        return (string) (KompasState::onCore() ? $row->legacy_id : $row->id);
    }

    /** `a`,`b`,`c` — a column list built from the SAME array as the bindings. */
    private static function cols(array $cols): string
    {
        return KompasState::cols($cols);
    }

    /** ?,?,? — one placeholder per column, so the two lists cannot drift. */
    private static function ph(int $n): string
    {
        return KompasState::ph($n);
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * A row's `diubah` version (ms), or null when the row is gone — the v1
     * edit/cancel precondition (VoidBriController).
     */
    public function rowVersion(string $table, string $id): ?int
    {
        $row = $this->db()->selectOne('SELECT `diubah` FROM `'.self::t($table).'` WHERE `'.self::idCol().'`=?', [$id]);

        return $row ? (int) $row->diubah : null;
    }

    /**
     * The core User an actor key names, on core only. The key is what the caller
     * recorded as `oleh_id`: the legacy account id when a Sesi sent it, the core
     * ULID when v1 did. '' (or an unknown key) is NULL, never invented.
     */
    private function userId(mixed $key): ?string
    {
        $k = self::s($key);
        if ($k === '') {
            return null;
        }
        $u = $this->db()->selectOne('SELECT `id` FROM `user` WHERE `legacy_id` = ? OR `id` = ? LIMIT 1', [$k, $k]);

        return $u ? (string) $u->id : null;
    }

    private static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    private static function cut(mixed $v, int $n): string
    {
        return mb_substr(self::s($v), 0, $n);
    }

    private static function ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** An ISO date that exists on the calendar ('2026-02-31' is refused), else ''. */
    public static function date(mixed $s): string
    {
        $s = trim(self::s($s));
        if ($s === '' || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : '';
    }

    private static function dateOrNull(mixed $s): ?string
    {
        $t = self::date($s);

        return $t === '' ? null : $t;
    }

    /** WHERE for an optional [dari, sampai] range on `tgl`. */
    private static function range(mixed $dari, mixed $sampai): array
    {
        $d = self::date($dari);
        $s = self::date($sampai);
        if ($d !== '' && $s !== '') {
            return [' WHERE `tgl` BETWEEN ? AND ?', [$d, $s]];
        }
        if ($d !== '') {
            return [' WHERE `tgl` >= ?', [$d]];
        }
        if ($s !== '') {
            return [' WHERE `tgl` <= ?', [$s]];
        }

        return ['', []];
    }

    // ═════════════════════════════ VOID ══

    /** Tax/service percentages (defaults 10 / 5, measured on the POS cancel report). */
    public function voidSetting(): array
    {
        if (KompasState::onCore()) {
            $r = $this->db()->selectOne('SELECT `v` FROM `'.self::t('void_setting')."` WHERE `k`='void'");
            $d = $r ? json_decode((string) $r->v, true) : null;
            if (! is_array($d)) {
                return ['tax' => 10.0, 'service' => 5.0, 'diubah' => 0, 'oleh' => ''];
            }

            return ['tax' => (float) $d['tax_persen'], 'service' => (float) $d['service_persen'],
                'diubah' => (float) $d['updated_at'], 'oleh' => (string) $d['oleh']];
        }
        $r = $this->db()->selectOne('SELECT `tax_persen`,`service_persen`,`updated_at`,`updated_by` FROM `void_setting` WHERE `id`=1');
        if (! $r) {
            return ['tax' => 10.0, 'service' => 5.0, 'diubah' => 0, 'oleh' => ''];
        }

        return ['tax' => (float) $r->tax_persen, 'service' => (float) $r->service_persen, 'diubah' => (float) $r->updated_at, 'oleh' => (string) $r->updated_by];
    }

    /** Admin-only (checked by the caller). Both values must be within 0..100. */
    public function saveVoidSetting(mixed $d, string $oleh): array
    {
        if (! is_array($d)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $tax = isset($d['tax']) ? (float) (is_array($d['tax']) ? 1 : $d['tax']) : -1;
        $svc = isset($d['service']) ? (float) (is_array($d['service']) ? 1 : $d['service']) : -1;
        foreach ([$tax, $svc] as $p) {
            if (! is_finite($p) || $p < 0 || $p > 100) {
                return ['ok' => false, 'error' => 'Persen harus antara 0 dan 100.'];
            }
        }
        if (KompasState::onCore()) {
            // The legacy columns are DECIMAL(6,3): keep the same value the old
            // row would have stored, as the `void` settings document.
            $now = self::ms();
            $doc = KompasState::enc(['tax_persen' => round($tax, 3), 'service_persen' => round($svc, 3),
                'updated_at' => $now, 'oleh' => self::cut($oleh, 120)]);
            $this->db()->insert('INSERT INTO `'.self::t('void_setting').'` (`id`,`legacy_id`,`k`,`v`,`created_at`,`updated_at`,`version`)
                VALUES (?,1,\'void\',?,?,?,1)
                ON DUPLICATE KEY UPDATE `version`=`version`+(`v`<>VALUES(`v`)), `v`=VALUES(`v`), `updated_at`=VALUES(`updated_at`)',
                [self::ulid(), $doc, $now, $now]);

            return ['ok' => true, 'saved' => true, 'setting' => $this->voidSetting()];
        }
        $this->db()->insert('INSERT INTO `void_setting` (`id`,`tax_persen`,`service_persen`,`updated_at`,`updated_by`) VALUES (1,?,?,?,?)
            ON DUPLICATE KEY UPDATE `tax_persen`=VALUES(`tax_persen`), `service_persen`=VALUES(`service_persen`),
              `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)', [$tax, $svc, self::ms(), self::cut($oleh, 120)]);

        return ['ok' => true, 'saved' => true, 'setting' => $this->voidSetting()];
    }

    /**
     * void_rinci — nominal is COMPUTED (subtotal + service + tax). A payload
     * without `subtotal` is an old screen: nominal as sent, service & tax 0.
     * null = a negative or non-finite component.
     */
    public static function detail(mixed $it): ?array
    {
        $it = is_array($it) ? $it : [];
        $f = fn ($v) => (float) (is_array($v) ? 1 : $v);
        $adaSub = array_key_exists('subtotal', $it);
        $sub = $adaSub ? $f($it['subtotal']) : (isset($it['nominal']) ? $f($it['nominal']) : 0);
        $svc = $adaSub && isset($it['service']) ? $f($it['service']) : 0;
        $tax = $adaSub && isset($it['tax']) ? $f($it['tax']) : 0;
        foreach ([$sub, $svc, $tax] as $v) {
            if (! is_finite($v) || $v < 0) {
                return null;
            }
        }

        return ['subtotal' => (int) round($sub), 'service' => (int) round($svc), 'tax' => (int) round($tax), 'nominal' => (int) round($sub + $svc + $tax)];
    }

    /**
     * void_bagi — split a bill-level amount over items by subtotal CUMULATIVELY:
     * the parts sum to exactly $total and none can be negative. All subtotals 0 → the first row takes it all.
     *
     * @param  list<int>  $subs
     * @return list<int>
     */
    public static function split(array $subs, float $total): array
    {
        $n = count($subs);
        $out = array_fill(0, $n, 0);
        if ($n === 0 || $total <= 0) {
            return $out;
        }
        $semua = array_sum($subs);
        if ($semua <= 0) {
            $out[0] = (int) round($total);

            return $out;
        }
        $akum = 0;
        $akumSub = 0;
        foreach ($subs as $i => $v) {
            $akumSub += $v;
            $sampai = (int) round($total * $akumSub / $semua);
            $out[$i] = $sampai - $akum;
            $akum = $sampai;
        }

        return $out;
    }

    /** Required fields (twin of VOID_WAJIB in both frontends). */
    public const REQUIRED = ['tgl' => 'Tanggal', 'bill' => 'Nomor Bill', 'item' => 'Nama Item',
        'penginput' => 'Siapa yang Menginput', 'salah' => 'Kesalahan dari Siapa', 'alasan' => 'Alasan / Kronologi'];

    /** A pre-17-Sep-2026 screen still sends `pemesan` and never penginput/salah: ask for a reload. */
    private static function oldScreen(array $d): ?array
    {
        if (! array_key_exists('pemesan', $d) || array_key_exists('penginput', $d) || array_key_exists('salah', $d)) {
            return null;
        }

        return ['ok' => false, 'error' => 'Halaman Cashier yang terbuka versi lama — ia masih mengirim kolom "Siapa yang Memesan", '
            .'yang sejak 17 September 2026 diganti "Siapa yang Menginput" dan "Kesalahan dari Siapa". '
            .'Muat ulang halamannya (Ctrl+Shift+R), lalu isi lagi.'];
    }

    private static function shared(array $d, bool $withItem): array
    {
        $out = ['tgl' => self::date($d['tgl'] ?? '')];
        foreach ($withItem ? ['bill', 'item', 'penginput', 'salah', 'alasan'] : ['bill', 'penginput', 'salah', 'alasan'] as $k) {
            $out[$k] = trim(self::s($d[$k] ?? ''));
        }

        return $out;
    }

    private function insertVoid(string $id, array $nil, string $item, array $rn, string $oleh, string $olehId, int $now): void
    {
        if (KompasState::onCore()) {
            // Columns and bindings come from ONE array: `user_id` can never drift
            // out of step with the values again.
            $cols = ['tgl' => $nil['tgl'], 'bill' => mb_substr($nil['bill'], 0, 60), 'item' => mb_substr($item, 0, 200),
                'penginput' => mb_substr($nil['penginput'], 0, 120), 'salah' => mb_substr($nil['salah'], 0, 120),
                'alasan' => $nil['alasan'], 'nominal' => $rn['nominal'], 'subtotal' => $rn['subtotal'],
                'service' => $rn['service'], 'tax' => $rn['tax'], 'oleh' => self::cut($oleh, 120),
                'oleh_id' => self::cut($olehId, 60), 'user_id' => $this->userId($olehId), 'dibuat' => $now,
                'diubah' => $now, 'diubah_oleh' => self::cut($oleh, 120), 'created_at' => $now, 'updated_at' => $now,
                'version' => 1];
            $this->db()->insert('INSERT INTO `'.self::t('void_log').'` (`id`,`legacy_id`,'.self::cols(array_keys($cols)).')'
                .' VALUES (?,?,'.self::ph(count($cols)).')', [self::ulid(), $id, ...array_values($cols)]);

            return;
        }
        $this->db()->insert('INSERT INTO `void_log` (`id`,`tgl`,`bill`,`item`,`penginput`,`salah`,`alasan`,`nominal`,
            `subtotal`,`service`,`tax`,`oleh`,`oleh_id`,`dibuat`,`diubah`,`diubah_oleh`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $nil['tgl'], mb_substr($nil['bill'], 0, 60), mb_substr($item, 0, 200), mb_substr($nil['penginput'], 0, 120),
                mb_substr($nil['salah'], 0, 120), $nil['alasan'], $rn['nominal'], $rn['subtotal'], $rn['service'], $rn['tax'],
                self::cut($oleh, 120), self::cut($olehId, 60), $now, $now, self::cut($oleh, 120)]);
    }

    /** void_simpan — one row: create (server id) or edit (not once cancelled). */
    public function saveVoid(mixed $d, string $oleh, string $olehId): array
    {
        if (! is_array($d)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $nil = self::shared($d, true);
        if ($e = self::oldScreen($d)) {
            return $e;
        }
        $kurang = [];
        foreach (self::REQUIRED as $k => $label) {
            if ($nil[$k] === '') {
                $kurang[] = $label;
            }
        }
        if ($kurang) {
            return ['ok' => false, 'kurang' => $kurang, 'error' => 'Belum lengkap: '.implode(', ', $kurang)];
        }
        // zero is allowed (voided before it was made); negative is not
        $rn = self::detail($d);
        if ($rn === null) {
            return ['ok' => false, 'error' => 'Nominal tidak boleh minus.'];
        }

        $now = self::ms();
        $id = trim(self::s($d['id'] ?? ''));
        $lama = $id !== '' ? $this->db()->selectOne('SELECT * FROM `'.self::t('void_log').'` WHERE `'.self::idCol().'`=?', [$id]) : null;
        if ($lama) {
            if ((float) $lama->batal_at > 0) {
                return ['ok' => false, 'error' => 'Catatan ini sudah dibatalkan dan tidak bisa diubah lagi.'];
            }
            $vals = [$nil['tgl'], mb_substr($nil['bill'], 0, 60), mb_substr($nil['item'], 0, 200), mb_substr($nil['penginput'], 0, 120),
                mb_substr($nil['salah'], 0, 120), $nil['alasan'], $rn['nominal'], $rn['subtotal'], $rn['service'], $rn['tax'],
                $now, self::cut($oleh, 120)];
            if (KompasState::onCore()) {
                $this->db()->update('UPDATE `'.self::t('void_log').'` SET `tgl`=?,`bill`=?,`item`=?,`penginput`=?,`salah`=?,`alasan`=?,
                    `nominal`=?,`subtotal`=?,`service`=?,`tax`=?,`diubah`=?,`diubah_oleh`=?,`updated_at`=?,`version`=`version`+1
                    WHERE `'.self::idCol().'`=?', [...$vals, $now, $id]);

                return ['ok' => true, 'saved' => true, 'id' => $id, 'baru' => false];
            }
            $this->db()->update('UPDATE `void_log` SET `tgl`=?,`bill`=?,`item`=?,`penginput`=?,`salah`=?,`alasan`=?,
                `nominal`=?,`subtotal`=?,`service`=?,`tax`=?,`diubah`=?,`diubah_oleh`=? WHERE `id`=?', [...$vals, $id]);

            return ['ok' => true, 'saved' => true, 'id' => $id, 'baru' => false];
        }
        // the id is always generated here: a sent id could collide with someone else's row
        $id = 'v'.dechex($now).substr(bin2hex(random_bytes(4)), 0, 8);
        $this->insertVoid($id, $nil, $nil['item'], $rn, $oleh, $olehId, $now);

        return ['ok' => true, 'saved' => true, 'id' => $id, 'baru' => true];
    }

    /**
     * void_simpan_banyak — one bill, many items, ONE transaction. Items without
     * a name are dropped; bill-level service/tax (when sent) win and are split.
     */
    public function saveVoidMany(array $d, string $oleh, string $olehId): array
    {
        $nil = self::shared($d, false);
        if ($e = self::oldScreen($d)) {
            return $e;
        }
        $kurang = [];
        foreach (self::REQUIRED as $k => $label) {
            if ($k !== 'item' && (! isset($nil[$k]) || $nil[$k] === '')) {
                $kurang[] = $label;
            }
        }
        $masuk = [];
        foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $it) {
            if (! is_array($it)) {
                continue;
            }
            $nama = trim(self::s($it['item'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $rn = self::detail($it);
            if ($rn === null) {
                return ['ok' => false, 'error' => 'Nominal "'.$nama.'" tidak boleh minus.'];
            }
            $rn['item'] = $nama;
            $masuk[] = $rn;
        }
        if (! $masuk) {
            $kurang[] = 'Nama Item';
        }

        // bill-level service & tax win (array_key_exists: a typed 0 is still "sent")
        if ((array_key_exists('service', $d) || array_key_exists('tax', $d)) && $masuk) {
            $f = fn ($v) => (float) (is_array($v) ? 1 : $v);
            $svcT = isset($d['service']) ? $f($d['service']) : 0;
            $taxT = isset($d['tax']) ? $f($d['tax']) : 0;
            foreach ([$svcT, $taxT] as $v) {
                if (! is_finite($v) || $v < 0) {
                    return ['ok' => false, 'error' => 'Service & tax tidak boleh minus.'];
                }
            }
            $subs = array_column($masuk, 'subtotal');
            $bs = self::split($subs, $svcT);
            $bt = self::split($subs, $taxT);
            foreach ($masuk as $i => $m) {
                $masuk[$i]['service'] = $bs[$i];
                $masuk[$i]['tax'] = $bt[$i];
                $masuk[$i]['nominal'] = $m['subtotal'] + $bs[$i] + $bt[$i];
            }
        }
        if ($kurang) {
            return ['ok' => false, 'kurang' => $kurang, 'error' => 'Belum lengkap: '.implode(', ', $kurang)];
        }

        $now = self::ms();
        $ids = $this->db()->transaction(function () use ($masuk, $nil, $oleh, $olehId, $now) {
            $ids = [];
            foreach ($masuk as $i => $m) {
                // $i in the id: two items saved in the same millisecond never collide
                $id = 'v'.dechex($now).dechex($i).substr(bin2hex(random_bytes(4)), 0, 8);
                $this->insertVoid($id, $nil, $m['item'], $m, $oleh, $olehId, $now);
                $ids[] = $id;
            }

            return $ids;
        });

        return ['ok' => true, 'saved' => true, 'n' => count($ids), 'ids' => $ids];
    }

    /** void_batal — cancel, never delete; a reason is required. */
    public function cancelVoid(mixed $id, mixed $alasan, string $oleh): array
    {
        $id = trim(self::s($id));
        $alasan = trim(self::s($alasan));
        if ($id === '') {
            return ['ok' => false, 'error' => 'id kosong'];
        }
        if ($alasan === '') {
            return ['ok' => false, 'error' => 'Alasan pembatalan wajib diisi.'];
        }
        $row = $this->db()->selectOne('SELECT `batal_at` FROM `'.self::t('void_log').'` WHERE `'.self::idCol().'`=?', [$id]);
        if (! $row) {
            return ['ok' => false, 'error' => 'Catatan tidak ditemukan.'];
        }
        if ((float) $row->batal_at > 0) {
            return ['ok' => false, 'error' => 'Catatan ini sudah dibatalkan.'];
        }
        if (KompasState::onCore()) {
            $now = self::ms();
            $this->db()->update('UPDATE `'.self::t('void_log').'` SET `batal_at`=?,`batal_oleh`=?,`batal_alasan`=?,`updated_at`=?,`version`=`version`+1
                WHERE `'.self::idCol().'`=?', [$now, self::cut($oleh, 120), mb_substr($alasan, 0, 255), $now, $id]);

            return ['ok' => true, 'saved' => true];
        }
        $this->db()->update('UPDATE `void_log` SET `batal_at`=?,`batal_oleh`=?,`batal_alasan`=? WHERE `id`=?',
            [self::ms(), self::cut($oleh, 120), mb_substr($alasan, 0, 255), $id]);

        return ['ok' => true, 'saved' => true];
    }

    /** void_list — newest first, at most VOID_MAX; `total` is counted BEFORE the limit. */
    public function voidList(mixed $dari, mixed $sampai): array
    {
        [$where, $args] = self::range($dari, $sampai);
        $total = (int) $this->db()->selectOne('SELECT COUNT(*) AS c FROM `'.self::t('void_log').'`'.$where, $args)->c;
        $baris = [];
        foreach ($this->db()->select('SELECT * FROM `'.self::t('void_log').'`'.$where.' ORDER BY `tgl` DESC, `dibuat` DESC LIMIT '.self::VOID_MAX, $args) as $r) {
            $baris[] = [
                'id' => self::rowId($r), 'tgl' => (string) $r->tgl, 'bill' => (string) $r->bill, 'item' => (string) $r->item,
                'penginput' => (string) ($r->penginput ?? ''), 'salah' => (string) ($r->salah ?? ''),
                'alasan' => (string) $r->alasan, 'nominal' => (float) $r->nominal,
                'subtotal' => (float) $r->subtotal, 'service' => (float) $r->service, 'tax' => (float) $r->tax,
                // rows from before the breakdown existed carry no detail: 0 ≠ "no service & tax"
                'rinci' => ((float) $r->subtotal > 0 || (float) $r->service > 0 || (float) $r->tax > 0),
                'oleh' => (string) $r->oleh, 'olehId' => (string) $r->oleh_id,
                'dibuat' => (float) $r->dibuat, 'diubah' => (float) $r->diubah, 'diubahOleh' => (string) $r->diubah_oleh,
                'batalAt' => (float) $r->batal_at, 'batalOleh' => (string) $r->batal_oleh, 'batalAlasan' => (string) $r->batal_alasan,
            ];
        }

        return ['baris' => $baris, 'total' => $total, 'maks' => self::VOID_MAX, 'setting' => $this->voidSetting()];
    }

    // ═════════════════════════════ BRI ══

    /** HH:MM from "15.46", "15:46" or an Excel day fraction; '' otherwise. */
    public static function jam(mixed $s): string
    {
        $s = trim(self::s($s));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2})[.:](\d{2})/', $s, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h >= 0 && $h < 24 && $i >= 0 && $i < 60) {
                return sprintf('%02d:%02d', $h, $i);
            }
        }
        if (is_numeric($s)) {
            $f = (float) $s;
            if ($f > 0 && $f < 1) {
                $det = (int) round($f * 86400);

                return sprintf('%02d:%02d', intdiv($det, 3600) % 24, intdiv($det % 3600, 60));
            }
        }

        return '';
    }

    /** tgl|jam|nominal|#k — k = occurrence among identical rows, so two equal transfers both survive. */
    public static function sidik(string $tgl, string $jam, float|int $nominal, int $k): string
    {
        return $tgl.'|'.$jam.'|'.(int) $nominal.'|#'.$k;
    }

    /**
     * bri_unggah — upsert by sidik in ONE transaction. A re-upload updates only
     * ket/settle/booking (never the match columns); rows missing from the file stay.
     */
    public function upload(mixed $d, string $oleh, string $olehId): array
    {
        if (! is_array($d)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $baris = is_array($d['baris'] ?? null) ? $d['baris'] : [];
        if (! count($baris)) {
            return ['ok' => false, 'error' => 'Tidak ada satu baris pun yang bisa dibaca dari berkasnya.'];
        }
        if (count($baris) > self::BRI_UPLOAD_MAX) {
            return ['ok' => false, 'error' => 'Terlalu banyak baris ('.count($baris).'). Batasnya '.self::BRI_UPLOAD_MAX.' per unggah, pilih satu bulan saja.'];
        }

        $now = self::ms();
        $siap = [];
        $hitungK = [];
        $lewat = 0;
        foreach ($baris as $b) {
            if (! is_array($b)) {
                $lewat++;

                continue;
            }
            $tgl = self::date($b['tgl'] ?? '');
            $nom = isset($b['nominal']) ? (float) (is_array($b['nominal']) ? 1 : $b['nominal']) : 0;
            if ($tgl === '' || ! is_finite($nom) || $nom <= 0) {
                $lewat++; // blank, total and header rows of the Excel file are skipped, not fatal

                continue;
            }
            $jam = self::jam($b['jam'] ?? '');
            $kunci = $tgl.'|'.$jam.'|'.(int) $nom;
            $k = isset($hitungK[$kunci]) ? $hitungK[$kunci] + 1 : 0;
            $hitungK[$kunci] = $k;
            $siap[] = ['sidik' => self::sidik($tgl, $jam, $nom, $k), 'tgl' => $tgl, 'jam' => $jam, 'nominal' => (int) round($nom),
                'ket' => mb_substr(trim(self::s($b['ket'] ?? '')), 0, 255),
                'settle' => self::dateOrNull($b['settle'] ?? ''), 'booking' => self::dateOrNull($b['booking'] ?? '')];
        }
        if (! $siap) {
            return ['ok' => false, 'error' => 'Tidak ada satu baris pun yang punya tanggal DAN nominal. Periksa lagi kolom yang dipilih.'];
        }

        // counted BEFORE writing, or "new rows" would always be 0
        $ada = [];
        foreach ($siap as $s) {
            if ($this->db()->selectOne('SELECT `sidik` FROM `'.self::t('bri_mutasi').'` WHERE `sidik`=?', [$s['sidik']])) {
                $ada[$s['sidik']] = true;
            }
        }
        $this->db()->transaction(function () use ($siap, $oleh, $olehId, $now) {
            $uid = KompasState::onCore() ? $this->userId($olehId) : null;
            foreach ($siap as $i => $s) {
                $vals = [$s['sidik'], $s['tgl'], $s['jam'], $s['nominal'], $s['ket'], $s['settle'], $s['booking'],
                    self::cut($oleh, 120), self::cut($olehId, 60), $now, $now, self::cut($oleh, 120)];
                if (KompasState::onCore()) {
                    $cols = ['sidik' => $s['sidik'], 'tgl' => $s['tgl'], 'jam' => $s['jam'], 'nominal' => $s['nominal'],
                        'ket' => $s['ket'], 'settle' => $s['settle'], 'booking' => $s['booking'], 'oleh' => self::cut($oleh, 120),
                        'oleh_id' => self::cut($olehId, 60), 'user_id' => $uid, 'dibuat' => $now, 'diubah' => $now,
                        'diubah_oleh' => self::cut($oleh, 120), 'created_at' => $now, 'updated_at' => $now, 'version' => 1];
                    $this->db()->insert('INSERT INTO `'.self::t('bri_mutasi').'` (`id`,`legacy_id`,'.self::cols(array_keys($cols)).')'
                        .' VALUES (?,?,'.self::ph(count($cols)).')'
                        ." ON DUPLICATE KEY UPDATE `version`=`version`+1, `ket` = IF(VALUES(`ket`)='', `ket`, VALUES(`ket`)),"
                        .' `settle` = COALESCE(VALUES(`settle`), `settle`), `booking` = COALESCE(VALUES(`booking`), `booking`),'
                        .' `diubah` = VALUES(`diubah`), `diubah_oleh` = VALUES(`diubah_oleh`), `updated_at` = VALUES(`updated_at`)',
                        [self::ulid(), 'b'.dechex($now).dechex($i).substr(bin2hex(random_bytes(4)), 0, 8), ...array_values($cols)]);

                    continue;
                }
                $this->db()->insert("INSERT INTO `bri_mutasi` (`id`,`sidik`,`tgl`,`jam`,`nominal`,`ket`,`settle`,`booking`,
                    `oleh`,`oleh_id`,`dibuat`,`diubah`,`diubah_oleh`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE `ket` = IF(VALUES(`ket`)='', `ket`, VALUES(`ket`)),
                      `settle` = COALESCE(VALUES(`settle`), `settle`), `booking` = COALESCE(VALUES(`booking`), `booking`),
                      `diubah` = VALUES(`diubah`), `diubah_oleh` = VALUES(`diubah_oleh`)",
                    ['b'.dechex($now).dechex($i).substr(bin2hex(random_bytes(4)), 0, 8), ...$vals]);
            }
        });
        $baru = count(array_filter($siap, fn ($s) => empty($ada[$s['sidik']])));

        return ['ok' => true, 'saved' => true, 'n' => count($siap), 'baru' => $baru, 'lama' => count($siap) - $baru, 'lewat' => $lewat];
    }

    /** bri_tambah — a manual incoming fund (not a reservation DP): sidik 'm|…', cara='bukan', ket required. */
    public function addManual(mixed $d, string $oleh, string $olehId): array
    {
        if (! is_array($d)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $tgl = self::date($d['tgl'] ?? '');
        $ket = trim(self::s($d['ket'] ?? ''));
        $nom = isset($d['nominal']) ? (float) (is_array($d['nominal']) ? 1 : $d['nominal']) : 0;
        $kurang = [];
        if ($tgl === '') {
            $kurang[] = 'Tanggal';
        }
        if (! is_finite($nom) || $nom <= 0) {
            $kurang[] = 'Nominal';
        }
        if ($ket === '') {
            $kurang[] = 'Keterangan';
        }
        if ($kurang) {
            return ['ok' => false, 'kurang' => $kurang, 'error' => 'Belum lengkap: '.implode(', ', $kurang)];
        }
        $jam = self::jam($d['jam'] ?? '');
        $now = self::ms();
        $k = 0;
        do {
            $sidik = 'm|'.self::sidik($tgl, $jam, $nom, $k);
            $bentrok = (bool) $this->db()->selectOne('SELECT `'.self::idCol().'` FROM `'.self::t('bri_mutasi').'` WHERE `sidik`=?', [$sidik]);
            $k++;
        } while ($bentrok && $k < 200);
        if ($bentrok) {
            return ['ok' => false, 'error' => 'Terlalu banyak baris manual yang sama persis pada jam itu.'];
        }
        $id = 'b'.dechex($now).'m'.substr(bin2hex(random_bytes(4)), 0, 8);
        $vals = [$sidik, $tgl, $jam, (int) round($nom), mb_substr($ket, 0, 255), mb_substr($ket, 0, 255), self::cut($oleh, 120), $now,
            self::cut($oleh, 120), self::cut($olehId, 60), $now, $now, self::cut($oleh, 120)];
        if (KompasState::onCore()) {
            $cols = ['sidik' => $sidik, 'tgl' => $tgl, 'jam' => $jam, 'nominal' => (int) round($nom),
                'ket' => mb_substr($ket, 0, 255), 'sumber' => 'manual', 'cara' => 'bukan',
                'catatan' => mb_substr($ket, 0, 255), 'cocok_oleh' => self::cut($oleh, 120), 'cocok_at' => $now,
                'oleh' => self::cut($oleh, 120), 'oleh_id' => self::cut($olehId, 60), 'user_id' => $this->userId($olehId),
                'dibuat' => $now, 'diubah' => $now, 'diubah_oleh' => self::cut($oleh, 120), 'created_at' => $now,
                'updated_at' => $now, 'version' => 1];
            $this->db()->insert('INSERT INTO `'.self::t('bri_mutasi').'` (`id`,`legacy_id`,'.self::cols(array_keys($cols)).')'
                .' VALUES (?,?,'.self::ph(count($cols)).')', [self::ulid(), $id, ...array_values($cols)]);

            return ['ok' => true, 'saved' => true, 'id' => $id];
        }
        $this->db()->insert("INSERT INTO `bri_mutasi` (`id`,`sidik`,`tgl`,`jam`,`nominal`,`ket`,`sumber`,`cara`,`catatan`,`cocok_oleh`,`cocok_at`,
            `oleh`,`oleh_id`,`dibuat`,`diubah`,`diubah_oleh`) VALUES (?,?,?,?,?,?,'manual','bukan',?,?,?,?,?,?,?,?)",
            [$id, ...$vals]);

        return ['ok' => true, 'saved' => true, 'id' => $id];
    }

    /** '' = saved, else the reason this row failed. */
    private function matchOne(array $b, string $oleh, int $now): string
    {
        $id = trim(self::s($b['id'] ?? ''));
        $cara = trim(self::s($b['cara'] ?? ''));
        $cara = in_array($cara, ['cocok', 'bukan', 'lepas'], true) ? $cara : '';
        if ($id === '') {
            return 'Baris tanpa id.';
        }
        if ($cara === '') {
            return 'Keputusan tidak dikenal (harus cocok / bukan / lepas).';
        }
        $row = $this->db()->selectOne('SELECT `batal_at` FROM `'.self::t('bri_mutasi').'` WHERE `'.self::idCol().'`=?', [$id]);
        if (! $row) {
            return 'Baris mutasi tidak ditemukan.';
        }
        if ((float) $row->batal_at > 0) {
            return 'Baris ini sudah dibatalkan, tidak bisa dicocokkan lagi.';
        }

        $resId = '';
        $dpId = '';
        $nama = '';
        $resTgl = null;
        $catatan = '';
        if ($cara === 'cocok') {
            $resId = trim(self::s($b['resId'] ?? ''));
            $dpId = trim(self::s($b['dpId'] ?? ''));
            if ($resId === '' || $dpId === '') {
                return 'Reservasi & cicilan DP-nya wajib disebut.';
            }
            // one DP can be held by only ONE live mutation, or it is counted twice
            $bentrok = $this->db()->selectOne('SELECT `'.self::idCol().'`,`tgl`,`nominal` FROM `'.self::t('bri_mutasi')."` WHERE `dp_id`=? AND `cara`='cocok' AND `batal_at`=0 AND `".self::idCol().'`<>? LIMIT 1', [$dpId, $id]);
            if ($bentrok) {
                return 'DP itu sudah dicocokkan ke mutasi '.(string) $bentrok->tgl.' sebesar Rp'.number_format((float) $bentrok->nominal, 0, ',', '.').'. Lepas dulu pencocokan di sana.';
            }
            // name & date are COPIED: the reservation may later be pruned
            $nama = mb_substr(trim(self::s($b['resNama'] ?? '')), 0, 160);
            $resTgl = self::dateOrNull($b['resTgl'] ?? '');
        } elseif ($cara === 'bukan') {
            $catatan = trim(self::s($b['catatan'] ?? ''));
            if ($catatan === '') {
                return 'Sebutkan dulu uang ini masuk dari mana.';
            }
            $catatan = mb_substr($catatan, 0, 255);
        }
        if (KompasState::onCore()) {
            $t = self::t('bri_mutasi');
            $resId = RowSync::fit($this->db(), $t, 'res_id', $resId);
            $dpId = RowSync::fit($this->db(), $t, 'dp_id', $dpId);
            $this->db()->update('UPDATE `'.$t.'` SET `res_id`=?,`dp_id`=?,`res_nama`=?,`res_tgl`=?,`cara`=?,`catatan`=?,`cocok_oleh`=?,`cocok_at`=?,`diubah`=?,`diubah_oleh`=?,`updated_at`=?,`version`=`version`+1
                WHERE `'.self::idCol().'`=?',
                [$resId, $dpId, $nama, $resTgl, $cara === 'lepas' ? '' : $cara, $catatan, self::cut($oleh, 120), $now, $now, self::cut($oleh, 120), $now, $id]);

            return '';
        }
        $this->db()->update('UPDATE `bri_mutasi` SET `res_id`=?,`dp_id`=?,`res_nama`=?,`res_tgl`=?,`cara`=?,`catatan`=?,`cocok_oleh`=?,`cocok_at`=?,`diubah`=?,`diubah_oleh`=? WHERE `id`=?',
            [$resId, $dpId, $nama, $resTgl, $cara === 'lepas' ? '' : $cara, $catatan, self::cut($oleh, 120), $now, $now, self::cut($oleh, 120), $id]);

        return '';
    }

    /**
     * bri_cocok — one row or items[]; NOT one transaction on purpose: each row is
     * its own decision, failures are reported per row. All failed → ok:false.
     */
    public function match(mixed $d, string $oleh): array
    {
        if (! is_array($d)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $items = is_array($d['items'] ?? null) ? $d['items'] : [$d];
        if (! count($items)) {
            return ['ok' => false, 'error' => 'Tidak ada satu baris pun yang dikirim.'];
        }
        $now = self::ms();
        $ok = 0;
        $gagal = [];
        foreach ($items as $b) {
            if (! is_array($b)) {
                $gagal[] = ['id' => '', 'sebab' => 'baris bukan objek'];

                continue;
            }
            $e = $this->matchOne($b, $oleh, $now);
            if ($e === '') {
                $ok++;
            } else {
                $gagal[] = ['id' => self::s($b['id'] ?? ''), 'sebab' => $e];
            }
        }
        if (! $ok && $gagal) {
            return ['ok' => false, 'error' => $gagal[0]['sebab'], 'gagal' => $gagal];
        }

        return ['ok' => true, 'saved' => true, 'n' => $ok, 'gagal' => $gagal];
    }

    /** bri_batal — cancel a mutation row, never delete; a reason is required. */
    public function cancelMutation(mixed $id, mixed $alasan, string $oleh): array
    {
        $id = trim(self::s($id));
        $alasan = trim(self::s($alasan));
        if ($id === '') {
            return ['ok' => false, 'error' => 'id kosong'];
        }
        if ($alasan === '') {
            return ['ok' => false, 'error' => 'Alasan pembatalan wajib diisi.'];
        }
        $row = $this->db()->selectOne('SELECT `batal_at` FROM `'.self::t('bri_mutasi').'` WHERE `'.self::idCol().'`=?', [$id]);
        if (! $row) {
            return ['ok' => false, 'error' => 'Baris mutasi tidak ditemukan.'];
        }
        if ((float) $row->batal_at > 0) {
            return ['ok' => false, 'error' => 'Baris ini sudah dibatalkan.'];
        }
        if (KompasState::onCore()) {
            $now = self::ms();
            $this->db()->update('UPDATE `'.self::t('bri_mutasi').'` SET `batal_at`=?,`batal_oleh`=?,`batal_alasan`=?,`updated_at`=?,`version`=`version`+1
                WHERE `'.self::idCol().'`=?', [$now, self::cut($oleh, 120), mb_substr($alasan, 0, 255), $now, $id]);

            return ['ok' => true, 'saved' => true];
        }
        $this->db()->update('UPDATE `bri_mutasi` SET `batal_at`=?,`batal_oleh`=?,`batal_alasan`=? WHERE `id`=?',
            [self::ms(), self::cut($oleh, 120), mb_substr($alasan, 0, 255), $id]);

        return ['ok' => true, 'saved' => true];
    }

    /** bri_abai — mark a reservation DP as not a valid BRI fund (reason required), or lift the mark (pulih). */
    public function ignoreDp(mixed $d, string $oleh): array
    {
        $d = is_array($d) ? $d : [];
        $dpId = trim(self::s($d['dpId'] ?? ''));
        if ($dpId === '') {
            return ['ok' => false, 'error' => 'dpId kosong'];
        }
        if (! empty($d['pulih'])) {
            $this->db()->delete('DELETE FROM `'.self::t('bri_dp_abai').'` WHERE `dp_id`=?', [$dpId]);

            return ['ok' => true, 'saved' => true, 'pulih' => true];
        }
        $alasan = trim(self::s($d['alasan'] ?? ''));
        if ($alasan === '') {
            return ['ok' => false, 'error' => 'Sebutkan dulu kenapa baris ini tidak valid.'];
        }
        $vals = [mb_substr($dpId, 0, 60), self::cut($d['resId'] ?? '', 60), self::cut($d['nama'] ?? '', 160), self::dateOrNull($d['tgl'] ?? ''),
            (int) round((float) (is_array($d['nominal'] ?? 0) ? 1 : ($d['nominal'] ?? 0))), mb_substr($alasan, 0, 255), self::cut($oleh, 120), self::ms()];
        if (KompasState::onCore()) {
            $now = self::ms();
            $cols = ['legacy_id' => mb_substr($dpId, 0, 60), 'dp_id' => mb_substr($dpId, 0, 60), 'res_id' => self::cut($d['resId'] ?? '', 60),
                'nama' => self::cut($d['nama'] ?? '', 160), 'tgl' => self::dateOrNull($d['tgl'] ?? ''),
                'nominal' => (int) round((float) (is_array($d['nominal'] ?? 0) ? 1 : ($d['nominal'] ?? 0))),
                'alasan' => mb_substr($alasan, 0, 255), 'oleh' => self::cut($oleh, 120), 'abai_at' => $now,
                'created_at' => $now, 'updated_at' => $now, 'version' => 1];
            $this->db()->insert('INSERT INTO `'.self::t('bri_dp_abai').'` (`id`,'.self::cols(array_keys($cols)).')'
                .' VALUES (?,'.self::ph(count($cols)).')'
                .' ON DUPLICATE KEY UPDATE `version`=`version`+1, `alasan`=VALUES(`alasan`),`oleh`=VALUES(`oleh`),'
                .'`abai_at`=VALUES(`abai_at`),`updated_at`=VALUES(`updated_at`)',
                [self::ulid(), ...array_values($cols)]);

            return ['ok' => true, 'saved' => true];
        }
        $this->db()->insert('INSERT INTO `bri_dp_abai` (`dp_id`,`res_id`,`nama`,`tgl`,`nominal`,`alasan`,`oleh`,`abai_at`) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE `alasan`=VALUES(`alasan`),`oleh`=VALUES(`oleh`),`abai_at`=VALUES(`abai_at`)', $vals);

        return ['ok' => true, 'saved' => true];
    }

    /** Ignored DPs in the range; those without a date are always included. */
    public function ignoredList(mixed $dari, mixed $sampai): array
    {
        $d = self::date($dari);
        $s = self::date($sampai);
        [$where, $args] = $d !== '' && $s !== '' ? [' WHERE `tgl` IS NULL OR `tgl` BETWEEN ? AND ?', [$d, $s]] : ['', []];

        return array_map(fn ($r) => [
            'dpId' => (string) $r->dp_id, 'resId' => (string) $r->res_id, 'nama' => (string) $r->nama,
            'tgl' => $r->tgl === null ? '' : (string) $r->tgl, 'nominal' => (float) $r->nominal,
            'alasan' => (string) $r->alasan, 'oleh' => (string) $r->oleh, 'at' => (float) $r->abai_at,
        ], $this->db()->select('SELECT * FROM `'.self::t('bri_dp_abai').'`'.$where, $args));
    }

    /** bri_list — oldest first, at most BRI_MAX (`total` counted before the limit), with the ignored DPs and the dpIds held by any live row. */
    public function briList(mixed $dari, mixed $sampai): array
    {
        [$where, $args] = self::range($dari, $sampai);
        $total = (int) $this->db()->selectOne('SELECT COUNT(*) AS c FROM `'.self::t('bri_mutasi').'`'.$where, $args)->c;
        $n = fn ($v) => $v === null ? '' : (string) $v;
        $baris = array_map(fn ($r) => [
            'id' => self::rowId($r), 'tgl' => (string) $r->tgl, 'jam' => (string) $r->jam, 'nominal' => (float) $r->nominal,
            'ket' => (string) $r->ket, 'settle' => $n($r->settle), 'booking' => $n($r->booking),
            'resId' => (string) $r->res_id, 'dpId' => (string) $r->dp_id, 'resNama' => (string) $r->res_nama, 'resTgl' => $n($r->res_tgl),
            'cara' => (string) $r->cara, 'catatan' => (string) $r->catatan, 'sumber' => (string) ($r->sumber ?? 'unggah'),
            'cocokOleh' => (string) $r->cocok_oleh, 'cocokAt' => (float) $r->cocok_at,
            'oleh' => (string) $r->oleh, 'olehId' => (string) $r->oleh_id, 'dibuat' => (float) $r->dibuat, 'diubah' => (float) $r->diubah,
            'diubahOleh' => (string) $r->diubah_oleh, 'batalAt' => (float) $r->batal_at, 'batalOleh' => (string) $r->batal_oleh,
            'batalAlasan' => (string) $r->batal_alasan,
        ], $this->db()->select('SELECT * FROM `'.self::t('bri_mutasi').'`'.$where.' ORDER BY `tgl` ASC, `jam` ASC, `dibuat` ASC LIMIT '.self::BRI_MAX, $args));

        /* dpIds held by ANY live row, whatever month (legacy bri_list): the
           "unrecorded reservation DPs" worklist must not offer a DP again in
           a month where its row is not listed. Ids only — the screen just
           needs "held or not". Cancelled rows hold nothing. No `cara`
           filter: a row with a dpId holds it even when its decision is not
           `cocok`. */
        $dipakai = array_map(fn ($r) => (string) $r->dp_id,
            $this->db()->select('SELECT DISTINCT `dp_id` FROM `'.self::t('bri_mutasi').'` WHERE `dp_id`<>? AND `batal_at`=?', ['', 0]));

        return ['baris' => $baris, 'total' => $total, 'maks' => self::BRI_MAX, 'abai' => $this->ignoredList($dari, $sampai), 'dipakai' => $dipakai];
    }
}
