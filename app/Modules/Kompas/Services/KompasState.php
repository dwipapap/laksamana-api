<?php

namespace App\Modules\Kompas\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Kompas core — port of the omset part of kompas-mysql/lib_kompas_mysql.php.
 *
 * ONE-ROW JSON BLOB (`app_state` id=1): daily revenue rows, Daily Reports,
 * deposits (rekap_setoran), employees per division with targets,
 * compliments, settings… written whole by Cashier and Finance > Omset.
 *
 * Guards kept from legacy:
 *  - saveAll refuses a stale write (`baseTs` ≠ stored `updated_at`), under
 *    GET_LOCK('<db>:kompas_save'); an empty baseTs (old clients) passes.
 *  - simpanTarget / simpanRekap are NARROW read-modify-writes under the same
 *    lock: they can never overwrite revenue, breakdowns or Daily Reports.
 *  - deposit amounts are computed HERE from each day's cash actual and capped
 *    at the day's REMAINING amount; legacy rows without `jumlah` count as full.
 *  - the bank / payment-group key lists are closed.
 *  - dailyMap() (kp_peta_harian) is the single source for daily revenue
 *    figures: net / tagihan / netSales are derived from it, never re-summed.
 *
 * Money rule: kp_num() mirrors the app's num(): strip everything but digits
 * and '-', so "3.855.000" is 3855000, not 3.
 *
 * Core (#69, DB_KOMPAS_CONNECTION=core): the same statements run on the
 * kompas_* tables of `core` (t()), rows keyed by `legacy_id` (idCol()), the
 * legacy blob author in `oleh` (byCol(), ADR-0003 gives `updated_by` to the
 * ULID FK) and `version` counting accepted writes. The wire shapes are
 * identical. Mapping: docs/db/kompas.md.
 */
class KompasState
{
    public const BUSY = 'Server sedang sibuk menyimpan, coba lagi sebentar.';

    /** "Input POS" groups (closed list; the last three are legacy keys kept readable). */
    public const GRUP = ['cash', 'qr_order', 'edc_bri', 'qris_bri', 'edc_mandiri', 'qris_mandiri', 'edc_bca', 'qris_bca',
        'transfer', 'gofood', 'grabfood', 'tiktokgo', 'error', 'bri', 'mandiri', 'bca'];

    /** Groups that carry an MDR (closed list: unknown keys are dropped). */
    public const BANK = ['qr_order', 'edc_bri', 'qris_bri', 'edc_mandiri', 'qris_mandiri', 'edc_bca', 'qris_bca',
        'gofood', 'grabfood', 'tiktokgo', 'bri', 'mandiri', 'bca'];

    public function db(): ConnectionInterface
    {
        return Modules::db('kompas');
    }

    /** Kompas cut over (#69): every statement runs on the kompas_* tables of core. */
    public static function onCore(): bool
    {
        return Modules::connectionName('kompas') === 'core';
    }

    /**
     * Physical table for a legacy table name on the current connection. The
     * module's settings documents (`void_setting`) live in kompas_pengaturan.
     */
    public static function t(string $table): string
    {
        if (! self::onCore()) {
            return $table;
        }

        return $table === 'void_setting' ? 'kompas_pengaturan' : 'kompas_'.$table;
    }

    /** Record id column: the legacy id, or `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /** `a`,`b`,`c` — a column list built from the SAME array as the bindings. */
    public static function cols(array $cols): string
    {
        return '`'.implode('`,`', $cols).'`';
    }

    /** ?,?,? — one placeholder per column, so the two lists cannot drift. */
    public static function ph(int $n): string
    {
        return implode(',', array_fill(0, $n, '?'));
    }

    /** Legacy `updated_by` NAME column (a string, not the ADR-0003 actor FK). */
    public static function byCol(): string
    {
        return self::onCore() ? 'oleh' : 'updated_by';
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    public static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    public static function tgl(mixed $d): ?string
    {
        $d = trim(self::s($d));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** kp_num — digits and '-' only; floats rounded. */
    public static function num(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }
        $s = preg_replace('/[^0-9-]/', '', self::s($v));

        return ($s === '' || $s === '-') ? 0 : (int) $s;
    }

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    // ───────────────────────────── blob ──

    /** baca_state — the blob decoded as OBJECTS ({} when nothing is stored). */
    public function read(): mixed
    {
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `'.self::t('app_state').'` WHERE `'.self::idCol().'`=1');
        } catch (Throwable) {
            return new stdClass;
        }
        if (! $row || $row->data === null || $row->data === '') {
            return new stdClass;
        }
        $v = json_decode((string) $row->data);

        return $v === null ? new stdClass : $v;
    }

    /** kp_state_assoc — the blob as arrays (for reading nested values). */
    public function assoc(): array
    {
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `'.self::t('app_state').'` WHERE `'.self::idCol().'`=1');
        } catch (Throwable) {
            return [];
        }
        if (! $row || ! isset($row->data) || $row->data === '') {
            return [];
        }
        $s = json_decode((string) $row->data, true);

        return is_array($s) ? $s : [];
    }

    /** state_ts — the stored version (updated_at). */
    public function ts(): int
    {
        try {
            $row = $this->db()->selectOne('SELECT `updated_at` FROM `'.self::t('app_state').'` WHERE `'.self::idCol().'`=1');
        } catch (Throwable) {
            return 0;
        }

        return $row ? (int) $row->updated_at : 0;
    }

    private function write(array|object $state, string $by, int $ts): void
    {
        if (self::onCore()) {
            $this->db()->insert('INSERT INTO `'.self::t('app_state').'` (`id`,`legacy_id`,`data`,`created_at`,`updated_at`,`oleh`,`version`)
                VALUES (?,1,?,?,?,?,1)
                ON DUPLICATE KEY UPDATE `version`=`version`+1, `data`=VALUES(`data`),
                  `updated_at`=VALUES(`updated_at`), `oleh`=VALUES(`oleh`)',
                [self::ulid(), self::enc($state), $ts, $ts, $by]);

            return;
        }
        $this->db()->insert('INSERT INTO `app_state` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
            ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
            [self::enc($state), $ts, $by]);
    }

    /**
     * save_all — replace the whole blob, refused when stale.
     *
     * @return array{saved:true,ts:int,waktu:string}|array{saved:false,konflik:true,ts:int,by:string}
     */
    public function saveAll(mixed $state, ?int $baseTs): array
    {
        return NamedLock::run('kompas', 'kompas_save', function () use ($state, $baseTs) {
            if (! is_array($state) && ! is_object($state)) {
                throw new RuntimeException('Payload kosong/invalid');
            }
            // read INSIDE the lock, or two saves could both pass the check
            if ($baseTs !== null && $baseTs > 0) {
                $by = self::byCol();
                $r = $this->db()->selectOne('SELECT `updated_at`,`'.$by.'` FROM `'.self::t('app_state').'` WHERE `'.self::idCol().'`=1');
                $tsKini = $r ? (int) $r->updated_at : 0;
                if ($tsKini > 0 && $baseTs !== $tsKini) {
                    return ['saved' => false, 'konflik' => true, 'ts' => $tsKini, 'by' => $r ? (string) $r->$by : ''];
                }
            }
            $ub = is_array($state) && isset($state['_savedBy']) ? self::s($state['_savedBy'])
                : (is_object($state) && isset($state->_savedBy) ? self::s($state->_savedBy) : '');
            // computed ONCE: the value written is the value returned
            $ua = self::nowMs();
            $this->write($state, $ub, $ua);

            return ['saved' => true, 'ts' => $ua, 'waktu' => gmdate('c')];
        }, 10, self::BUSY);
    }

    // ───────────────────────────── narrow writers ──

    /** v1 only: an optional version checked under the lock (legacy narrow writes send none). */
    private function guard(?int $baseTs): void
    {
        if ($baseTs !== null && $this->ts() !== $baseTs) {
            throw new KompasConflict($this->ts());
        }
    }

    /**
     * simpan_target — patches ONLY settings.companyMonthlyTarget / useWorkingDays /
     * workingDaysPerMonth and employees[divi][].target; unknown PIC ids are reported.
     */
    public function saveTarget(mixed $data, ?int $baseTs = null): array
    {
        return NamedLock::run('kompas', 'kompas_save', function () use ($data, $baseTs) {
            $this->guard($baseTs);
            if (! is_array($data)) {
                throw new RuntimeException('Payload kosong/invalid');
            }
            $s = $this->assoc();
            if (! $s) {
                throw new RuntimeException('Data omset belum pernah tersimpan — buka panel Input Omset Harian lebih dulu');
            }
            if (! isset($s['settings']) || ! is_array($s['settings'])) {
                $s['settings'] = [];
            }
            if (array_key_exists('companyMonthlyTarget', $data)) {
                $s['settings']['companyMonthlyTarget'] = self::num($data['companyMonthlyTarget']);
            }
            if (array_key_exists('useWorkingDays', $data)) {
                $s['settings']['useWorkingDays'] = ! empty($data['useWorkingDays']);
            }
            if (array_key_exists('workingDaysPerMonth', $data)) {
                $wd = self::num($data['workingDaysPerMonth']);
                $s['settings']['workingDaysPerMonth'] = $wd > 0 ? $wd : 26; // 0 days = division by zero on every dashboard
            }

            $tg = isset($data['target']) && is_array($data['target']) ? $data['target'] : [];
            $ubah = 0;
            foreach (['marketing', 'event', 'kasir'] as $divi) {
                if (! isset($s['employees'][$divi]) || ! is_array($s['employees'][$divi])) {
                    continue;
                }
                foreach ($s['employees'][$divi] as $i => $e) {
                    if (! is_array($e) || ! isset($e['id'])) {
                        continue;
                    }
                    $id = self::s($e['id']);
                    if (! array_key_exists($id, $tg)) {
                        continue;
                    }
                    $baru = self::num($tg[$id]);
                    $lama = isset($e['target']) ? self::num($e['target']) : 0;
                    if ($lama !== $baru) {
                        $ubah++;
                    }
                    $s['employees'][$divi][$i]['target'] = $baru;
                    unset($tg[$id]);
                }
            }
            $hilang = array_values(array_map('strval', array_keys($tg)));

            $this->write($s, isset($data['by']) ? self::s($data['by']) : '', self::nowMs());

            return ['saved' => true, 'ubah' => $ubah, 'hilang' => $hilang, 'ts' => gmdate('c')];
        }, 10, self::BUSY);
    }

    // ───────────────────────────── v1 granular parts ──

    /**
     * One part of the blob: a top-level section ('compliments', 'piutang',
     * 'rokok', …), one Daily Report (`report`, key = date) or the `daily` rows
     * of one date (`day`). Arrays, as the legacy narrow writers read them.
     */
    public static function part(array $s, string $kind, string $key): mixed
    {
        return match ($kind) {
            'section' => $s[$key] ?? null,
            'report' => $s['reports'][$key] ?? null,
            'day' => array_values(array_filter(is_array($s['daily'] ?? null) ? $s['daily'] : [], fn ($d) => is_array($d) && ($d['date'] ?? null) === $key)),
        };
    }

    public static function partVersion(mixed $v): string
    {
        return substr(sha1(self::enc($v)), 0, 16);
    }

    /**
     * Replace ONE part under the kompas_save lock, guarded by that part's own
     * content hash — edits to other parts (another day, another section) never
     * conflict. null removes a report / a section; a day's rows are all
     * replaced (each forced to that date). Throws KompasConflict on a stale hash
     * (with the stored blob version) or RuntimeException when nothing is stored yet.
     *
     * @return array{value:mixed, version:string, ts:int}
     */
    public function savePart(string $kind, string $key, mixed $value, string $baseHash, string $by): array
    {
        return NamedLock::run('kompas', 'kompas_save', function () use ($kind, $key, $value, $baseHash, $by) {
            $s = $this->assoc();
            if (! $s) {
                throw new RuntimeException('Data omset belum pernah tersimpan — buka panel Input Omset Harian lebih dulu');
            }
            if (! hash_equals(self::partVersion(self::part($s, $kind, $key)), $baseHash)) {
                throw new KompasConflict($this->ts());
            }
            if ($kind === 'section') {
                if ($value === null) {
                    unset($s[$key]);
                } else {
                    $s[$key] = $value;
                }
            } elseif ($kind === 'report') {
                if ($value === null) {
                    unset($s['reports'][$key]);
                } else {
                    $s['reports'][$key] = $value;
                }
            } else {
                $rows = array_values(array_filter(is_array($s['daily'] ?? null) ? $s['daily'] : [], fn ($d) => ! (is_array($d) && ($d['date'] ?? null) === $key)));
                foreach (is_array($value) ? $value : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = ['date' => $key] + $row;
                    }
                }
                $s['daily'] = $rows;
            }
            $ts = max(self::nowMs(), $this->ts() + 1);
            $this->write($s, $by, $ts);
            $now = self::part($s, $kind, $key);

            return ['value' => $now, 'version' => self::partVersion($now), 'ts' => $ts];
        }, 10, self::BUSY);
    }

    /** kp_cash_hari — cash actual of one day (the Daily Report). */
    public static function cashDay(array $s, string $h): int
    {
        return isset($s['reports'][$h]['pay']['cash']['actual']) ? self::num($s['reports'][$h]['pay']['cash']['actual']) : 0;
    }

    /** kp_setor_masuk — already deposited for one day; legacy rows without `jumlah` = a full deposit. */
    public static function depositedDay(array $s, string $h): int
    {
        $n = 0;
        if (! isset($s['rekap_setoran']) || ! is_array($s['rekap_setoran'])) {
            return 0;
        }
        foreach ($s['rekap_setoran'] as $row) {
            if (! isset($row['hari']) || ! is_array($row['hari'])) {
                continue;
            }
            if (! in_array($h, array_map(fn ($x) => self::s($x), $row['hari']), true)) {
                continue;
            }
            if (isset($row['jumlah'][$h]) && $row['jumlah'][$h] !== '' && $row['jumlah'][$h] !== null) {
                $n += self::num($row['jumlah'][$h]);
            } else {
                $n += self::cashDay($s, $h);
            }
        }

        return $n;
    }

    /**
     * simpan_rekap — the Rekap Penjualan panel's own marks only: setor, mdr,
     * aktual, mdrManual, esb per day (never `pay`), plus deposits
     * (setoran.tambah / hapus) whose amounts the SERVER computes and caps.
     */
    public function saveRekap(mixed $data, ?int $baseTs = null): array
    {
        if (! is_array($data)) {
            throw new RuntimeException('Payload kosong/invalid');
        }
        $baris = isset($data['hari']) && is_array($data['hari']) ? $data['hari'] : null;
        if ($baris === null) {
            throw new RuntimeException('Payload kosong/invalid: tidak ada daftar hari');
        }

        return NamedLock::run('kompas', 'kompas_save', function () use ($data, $baris, $baseTs) {
            $this->guard($baseTs);

            return $this->saveRekapLocked($data, $baris);
        }, 10, self::BUSY);
    }

    private function saveRekapLocked(array $data, array $baris): array
    {
        $s = $this->assoc();
        if (! $s) {
            throw new RuntimeException('Data omset belum pernah tersimpan — buka panel Input Omset Harian lebih dulu');
        }
        if (! isset($s['reports']) || ! is_array($s['reports'])) {
            $s['reports'] = [];
        }

        $ubah = 0;
        $takDikenal = [];
        foreach ($baris as $tgl => $isi) {
            $tgl = self::tgl($tgl);
            if (! $tgl || ! is_array($isi)) {
                continue;
            }
            // a report row is created when missing: days may be marked before their Daily Report exists
            if (! isset($s['reports'][$tgl]) || ! is_array($s['reports'][$tgl])) {
                $s['reports'][$tgl] = [];
            }
            $r = &$s['reports'][$tgl];

            if (array_key_exists('setor', $isi)) {
                $baru = ! empty($isi['setor']);
                if (! isset($r['setor']) || (bool) $r['setor'] !== $baru) {
                    $ubah++;
                }
                $r['setor'] = $baru;
            }
            if (isset($isi['mdr']) && is_array($isi['mdr'])) {
                if (! isset($r['mdr']) || ! is_array($r['mdr'])) {
                    $r['mdr'] = [];
                }
                foreach (self::BANK as $b) {
                    if (! array_key_exists($b, $isi['mdr'])) {
                        continue;
                    }
                    $baru = self::num($isi['mdr'][$b]);
                    $lama = isset($r['mdr'][$b]) ? self::num($r['mdr'][$b]) : 0;
                    if ($lama !== $baru) {
                        $ubah++;
                    }
                    $r['mdr'][$b] = $baru;
                }
            }
            // aktual (what actually reached the account) and mdrManual: '' / null DELETES, it is not zero
            foreach (['aktual' => false, 'mdrManual' => true] as $key => $clampPositive) {
                if (! isset($isi[$key]) || ! is_array($isi[$key])) {
                    continue;
                }
                if (! isset($r[$key]) || ! is_array($r[$key])) {
                    $r[$key] = [];
                }
                foreach (self::BANK as $b) {
                    if (! array_key_exists($b, $isi[$key])) {
                        continue;
                    }
                    $v = $isi[$key][$b];
                    if ($v === '' || $v === null) {
                        if (array_key_exists($b, $r[$key])) {
                            unset($r[$key][$b]);
                            $ubah++;
                        }

                        continue;
                    }
                    $baru = self::num($v);
                    if ($clampPositive && $baru < 0) {
                        $baru = 0; // an MDR is a deduction: never negative
                    }
                    if (! array_key_exists($b, $r[$key]) || self::num($r[$key][$b]) !== $baru) {
                        $ubah++;
                    }
                    $r[$key][$b] = $baru;
                }
            }
            if (isset($isi['esb']) && is_array($isi['esb'])) {
                if (! isset($r['esb']) || ! is_array($r['esb'])) {
                    $r['esb'] = [];
                }
                foreach ($isi['esb'] as $g => $v) {
                    if (! in_array($g, self::GRUP, true)) {
                        $takDikenal[$g] = 1;

                        continue;
                    }
                    $baru = ! empty($v);
                    if (! isset($r['esb'][$g]) || (bool) $r['esb'][$g] !== $baru) {
                        $ubah++;
                    }
                    $r['esb'][$g] = $baru;
                }
            }
            unset($r);
        }

        $setoran = isset($data['setoran']) && is_array($data['setoran']) ? $data['setoran'] : null;
        if ($setoran) {
            if (! isset($s['rekap_setoran']) || ! is_array($s['rekap_setoran'])) {
                $s['rekap_setoran'] = [];
            }
            $tersentuh = [];

            if (isset($setoran['hapus']) && is_array($setoran['hapus'])) {
                foreach ($setoran['hapus'] as $id) {
                    $id = self::s($id);
                    foreach ($s['rekap_setoran'] as $i => $row) {
                        if (! isset($row['id']) || self::s($row['id']) !== $id) {
                            continue;
                        }
                        if (isset($row['hari']) && is_array($row['hari'])) {
                            foreach ($row['hari'] as $h) {
                                if ($h = self::tgl($h)) {
                                    $tersentuh[$h] = 1;
                                }
                            }
                        }
                        array_splice($s['rekap_setoran'], $i, 1);
                        $ubah++;
                        break;
                    }
                }
            }

            if (isset($setoran['tambah']) && is_array($setoran['tambah'])) {
                foreach ($setoran['tambah'] as $baru) {
                    if (! is_array($baru)) {
                        continue;
                    }
                    $tglSetor = self::tgl($baru['tgl'] ?? '');
                    if (! $tglSetor) {
                        throw new RuntimeException('Tanggal setor tidak sah');
                    }
                    $hari = [];
                    if (isset($baru['hari']) && is_array($baru['hari'])) {
                        foreach ($baru['hari'] as $h) {
                            $h = self::tgl($h);
                            if ($h && ! in_array($h, $hari, true)) {
                                $hari[] = $h;
                            }
                        }
                    }
                    if (! count($hari)) {
                        throw new RuntimeException('Setoran tanpa satu pun hari yang dicakup');
                    }
                    sort($hari);
                    // partial deposits: per-day amounts are CAPPED at the day's remaining cash
                    $jml = isset($baru['jumlah']) && is_array($baru['jumlah']) ? $baru['jumlah'] : [];
                    $nominal = 0;
                    $perHari = [];
                    foreach ($hari as $h) {
                        $sisa = max(0, self::cashDay($s, $h) - self::depositedDay($s, $h));
                        $n = (isset($jml[$h]) && $jml[$h] !== '' && $jml[$h] !== null) ? self::num($jml[$h]) : $sisa;
                        $n = min(max(0, $n), $sisa);
                        $perHari[$h] = $n;
                        $nominal += $n;
                        $tersentuh[$h] = 1;
                    }
                    if ($nominal <= 0) {
                        throw new RuntimeException('Setoran bernilai nol — tidak ada uang yang berpindah');
                    }
                    $s['rekap_setoran'][] = [
                        'id' => 'st'.dechex(self::nowMs()).dechex(mt_rand(0, 0xFFFF)),
                        'tgl' => $tglSetor,
                        'tujuan' => mb_substr(trim(self::s($baru['tujuan'] ?? '')), 0, 80),
                        'catatan' => mb_substr(trim(self::s($baru['catatan'] ?? '')), 0, 200),
                        'hari' => $hari,
                        'jumlah' => $perHari,
                        'nominal' => $nominal,
                        'by' => isset($data['by']) ? self::s($data['by']) : '',
                        'at' => self::nowMs(),
                    ];
                    $ubah++;
                }
            }

            // the per-day `setor` flag is RECOMPUTED from all deposits (1 rupiah tolerance for float cash)
            foreach (array_keys($tersentuh) as $h) {
                if (! isset($s['reports'][$h]) || ! is_array($s['reports'][$h])) {
                    $s['reports'][$h] = [];
                }
                $s['reports'][$h]['setor'] = (self::cashDay($s, $h) - self::depositedDay($s, $h)) < 1;
            }
        }

        $this->write($s, isset($data['by']) ? self::s($data['by']) : '', self::nowMs());

        return [
            'saved' => true,
            'ubah' => $ubah,
            'hari' => count($baris),
            'takDikenal' => array_values(array_map('strval', array_keys($takDikenal))),
            'setoran' => isset($s['rekap_setoran']) && is_array($s['rekap_setoran']) ? array_values($s['rekap_setoran']) : [],
            'ts' => gmdate('c'),
        ];
    }

    // ───────────────────────────── read models ──

    /**
     * kp_peta_harian — THE daily map every revenue figure comes from:
     * date => [food, bev, lainnya, diskon, service, pajak, bill, compliment].
     * Days with only a compliment still exist (goods left the house).
     *
     * @return array<string, array<int,int>>
     */
    public function dailyMap(): array
    {
        $s = $this->assoc();
        $peta = [];
        $blank = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach (isset($s['daily']) && is_array($s['daily']) ? $s['daily'] : [] as $d) {
            if (! is_array($d) || ! ($t = self::tgl($d['date'] ?? ''))) {
                continue;
            }
            $peta[$t] ??= $blank;
            foreach (['food', 'bev', 'lainnya', 'discount', 'service_charge', 'tax', 'bill'] as $i => $k) {
                $peta[$t][$i] += self::num($d[$k] ?? 0);
            }
        }
        foreach (isset($s['compliments']) && is_array($s['compliments']) ? $s['compliments'] : [] as $c) {
            if (! is_array($c) || ! ($t = self::tgl($c['date'] ?? ''))) {
                continue;
            }
            $peta[$t] ??= $blank;
            $peta[$t][7] += self::num($c['nominal'] ?? 0);
        }

        return $peta;
    }

    /** net = food + bev + lainnya − discount (Dashboard Omset). */
    public static function net(array $v): int
    {
        return $v[0] + $v[1] + $v[2] - $v[3];
    }

    /** tagihan = net + service + pajak (Rekap Penjualan, what the guest is billed). */
    public static function tagihan(array $v): int
    {
        return self::net($v) + $v[4] + $v[5];
    }

    /** netSales = tagihan − compliment (CFO report). */
    public static function netSales(array $v): int
    {
        return self::tagihan($v) - $v[7];
    }

    private static function range(mixed $dari, mixed $sampai): array
    {
        $dari = self::tgl($dari);
        $sampai = self::tgl($sampai);
        if (! $dari || ! $sampai) {
            throw new RuntimeException('rentang tanggal tidak sah (pakai YYYY-MM-DD)');
        }

        return strcmp($dari, $sampai) > 0 ? [$sampai, $dari] : [$dari, $sampai];
    }

    /**
     * omset_pic — Section B (bd.marketing) per PIC in a date range, as Finance
     * recognises it: diakui = amount + tax + service + open bill (only when ticked).
     */
    public function omsetPic(mixed $dari, mixed $sampai): array
    {
        [$dari, $sampai] = self::range($dari, $sampai);
        $s = $this->assoc();

        $peta = [];
        foreach (isset($s['employees']['marketing']) && is_array($s['employees']['marketing']) ? $s['employees']['marketing'] : [] as $e) {
            if (is_array($e) && isset($e['id'])) {
                $peta[self::s($e['id'])] = ['name' => isset($e['name']) ? self::s($e['name']) : '', 'officeUserId' => isset($e['officeUserId']) ? self::s($e['officeUserId']) : ''];
            }
        }

        $agg = [];
        $hariAda = 0;
        $hariIsi = 0;
        foreach (isset($s['daily']) && is_array($s['daily']) ? $s['daily'] : [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $tgl = self::tgl($d['date'] ?? '');
            if (! $tgl || strcmp($tgl, $dari) < 0 || strcmp($tgl, $sampai) > 0) {
                continue;
            }
            $hariAda++;
            $rows = isset($d['bd']['marketing']) && is_array($d['bd']['marketing']) ? $d['bd']['marketing'] : [];
            if ($rows) {
                $hariIsi++;
            }
            foreach ($rows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $pid = isset($r['picId']) ? self::s($r['picId']) : '';
                $om = self::num($r['amount'] ?? 0);
                $tax = self::num($r['tax'] ?? 0);
                $svc = self::num($r['service'] ?? 0);
                // open bill counts only while its tick is on (the amounts are kept when unticked)
                $ob = ! empty($r['ob']) ? self::num($r['obAmount'] ?? 0) + self::num($r['obTax'] ?? 0) + self::num($r['obService'] ?? 0) : 0;
                $agg[$pid] ??= ['picId' => $pid, 'name' => '', 'officeUserId' => '', 'omset' => 0, 'tax' => 0, 'service' => 0, 'openBill' => 0, 'diakui' => 0, 'baris' => 0];
                $agg[$pid]['omset'] += $om;
                $agg[$pid]['tax'] += $tax;
                $agg[$pid]['service'] += $svc;
                $agg[$pid]['openBill'] += $ob;
                $agg[$pid]['diakui'] += $om + $tax + $svc + $ob;
                $agg[$pid]['baris']++;
            }
        }

        $pic = [];
        $tot = ['omset' => 0, 'tax' => 0, 'service' => 0, 'openBill' => 0, 'diakui' => 0, 'baris' => 0];
        foreach ($agg as $pid => $a) {
            if (isset($peta[$pid])) {
                $a['name'] = $peta[$pid]['name'];
                $a['officeUserId'] = $peta[$pid]['officeUserId'];
            }
            foreach (array_keys($tot) as $k) {
                $tot[$k] += $a[$k];
            }
            $pic[] = $a;
        }
        usort($pic, fn ($a, $b) => $b['diakui'] - $a['diakui']);

        return ['dari' => $dari, 'sampai' => $sampai, 'hariAda' => $hariAda, 'hariIsi' => $hariIsi, 'pic' => $pic, 'total' => $tot];
    }

    /**
     * performa_divisi — the division's PIC roster, its breakdown rows per day
     * (filtered to the fields the bonus calculator uses; no `shift`), that day's
     * revenue (tagihan, from dailyMap) and the compliments that involve its PICs.
     * The formulas live in deploy/assets/performa-bonus.js, not here.
     */
    public function performaDivisi(string $divi, mixed $dari, mixed $sampai): array
    {
        $divi = $divi === 'event' ? 'event' : 'marketing';
        [$dari, $sampai] = self::range($dari, $sampai);
        $s = $this->assoc();

        $pic = [];
        foreach (isset($s['employees'][$divi]) && is_array($s['employees'][$divi]) ? $s['employees'][$divi] : [] as $e) {
            if (is_array($e) && isset($e['id'])) {
                $pic[] = ['id' => self::s($e['id']), 'name' => isset($e['name']) ? self::s($e['name']) : '', 'officeUserId' => $e['officeUserId'] ?? null];
            }
        }

        $petaHari = $this->dailyMap();
        $days = [];
        foreach (isset($s['daily']) && is_array($s['daily']) ? $s['daily'] : [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $tgl = self::tgl($d['date'] ?? '');
            if (! $tgl || strcmp($tgl, $dari) < 0 || strcmp($tgl, $sampai) > 0) {
                continue;
            }
            $rows = [];
            foreach (isset($d['bd'][$divi]) && is_array($d['bd'][$divi]) ? $d['bd'][$divi] : [] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $rows[] = [
                    'picId' => $r['picId'] ?? null,
                    'eventName' => isset($r['eventName']) ? self::s($r['eventName']) : '',
                    'amount' => $r['amount'] ?? 0,
                    'tax' => $r['tax'] ?? 0,
                    'service' => $r['service'] ?? 0,
                    'ob' => $r['ob'] ?? null,
                    'obAmount' => $r['obAmount'] ?? 0,
                    'obTax' => $r['obTax'] ?? 0,
                    'obService' => $r['obService'] ?? 0,
                    'tiket' => $r['tiket'] ?? null,
                    'srcPic' => isset($r['srcPic']) ? self::s($r['srcPic']) : '',
                    'srcId' => isset($r['srcId']) ? self::s($r['srcId']) : '',     // mkt:/vip:/evt:<id>, the event's own key
                    'srcJenis' => isset($r['srcJenis']) ? self::s($r['srcJenis']) : '',
                ];
            }
            $days[] = ['date' => $tgl, 'omsetHari' => isset($petaHari[$tgl]) ? self::tagihan($petaHari[$tgl]) : 0, 'bd' => [$divi => $rows]];
        }

        $oid = [];
        $nama = [];
        foreach ($pic as $p) {
            if ($p['officeUserId'] !== null && $p['officeUserId'] !== '') {
                $oid[self::s($p['officeUserId'])] = true;
            }
            if ($p['name'] !== '') {
                $nama[strtolower(trim($p['name']))] = true;
            }
        }
        $comps = [];
        foreach (isset($s['compliments']) && is_array($s['compliments']) ? $s['compliments'] : [] as $c) {
            if (! is_array($c)) {
                continue;
            }
            $tgl = self::tgl($c['date'] ?? '');
            if (! $tgl || strcmp($tgl, $dari) < 0 || strcmp($tgl, $sampai) > 0) {
                continue;
            }
            foreach ([['picDiv', 'picOfficeId', 'picName'], ['pemberiDiv', 'pemberiOfficeId', 'pemberiName']] as [$dk, $ik, $nk]) {
                if ((isset($c[$dk]) ? self::s($c[$dk]) : '') === 'tamu') {
                    continue;
                }
                $id = isset($c[$ik]) ? self::s($c[$ik]) : '';
                $nm = isset($c[$nk]) ? strtolower(trim(self::s($c[$nk]))) : '';
                if (($id !== '' && isset($oid[$id])) || ($nm !== '' && isset($nama[$nm]))) {
                    $comps[] = $c;
                    break;
                }
            }
        }

        return ['pic' => $pic, 'days' => $days, 'comps' => $comps, 'dari' => $dari, 'sampai' => $sampai];
    }

    // ───────────────────────────── diagnostics ──

    public function identity(): array
    {
        return ['env' => Modules::envLabel(), 'db' => Modules::databaseName('kompas')];
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', ...$this->identity(), 'bytes' => 0, 'updated_at' => 0, 'ada' => false];
        try {
            $row = $this->db()->selectOne('SELECT LENGTH(`data`) n, `updated_at` FROM `'.self::t('app_state').'` WHERE `'.self::idCol().'`=1');
            if ($row) {
                $out['bytes'] = (int) $row->n;
                $out['updated_at'] = (int) $row->updated_at;
                $out['ada'] = true;
            }
        } catch (Throwable) {
            // table missing: zeros, not an error
        }
        $out['ts'] = gmdate('c');

        return $out;
    }
}
