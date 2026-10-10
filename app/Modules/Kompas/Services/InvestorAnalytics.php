<?php

namespace App\Modules\Kompas\Services;

use App\Modules\Bd\Services\BdState;
use App\Modules\Event\Services\EventState;
use App\Modules\Finance\Services\Brankas;
use App\Modules\Marketing\Services\MarketingState;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Kompas → Investor Compass (investor.laksamanamuda.id) and Analytics —
 * port of ringkas_investor / agenda_investor / dividen_investor / inv_lapor_*
 * and an_* in lib_kompas_mysql.php.
 *
 * Investor figures come ONLY from KompasState::dailyMap() (one source, three
 * conventions). What investors see is already summed: no names of staff, no
 * per-cashier breakdown, no receivables.
 *
 * Cross-module reads that legacy did over HTTP (marketing/event/bd getAll
 * for the agenda, finance brankasGet for dividends) are in-process service
 * calls; a module that fails is reported in `gagal`, never fatal.
 *
 * Monthly PDF reports live on disk in <KOMPAS_DATA_DIR>/lapor (lp_<hex>.pdf),
 * one row per (bulan, jenis) in `inv_lapor`; the old file is removed only
 * AFTER the new one is written. Runtime DDL is not ported.
 *
 * Core (#69, DB_KOMPAS_CONNECTION=core): the same statements run on the
 * kompas_* tables of `core` (see KompasState::t()), `inv_lapor` and `an_akses`
 * keyed by their own natural key (legacy_id), and the Analytics access matrix
 * resolving a `#<legacy user id>` kunci to a real `user_id` FK. Mapping:
 * docs/db/kompas.md.
 */
class InvestorAnalytics
{
    public const JENIS = ['balance' => 'Balance Report', 'ledger' => 'General Ledger Report'];

    public function __construct(private readonly KompasState $kompas) {}

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
     * The legacy Office User id an analytics `kunci` names: a per-actor key is
     * `#<legacy user id>` (deploy/analytics), a role key ('staf') or a
     * `@<name>` key names nobody. '' never matches a User.
     */
    private static function keyUser(mixed $kunci): string
    {
        $k = self::s($kunci);

        return str_starts_with($k, '#') ? substr($k, 1) : '';
    }

    private static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    public static function todayWib(): string
    {
        return gmdate('Y-m-d', time() + 7 * 3600);
    }

    private static function teks(mixed $v, int $max = 120): string
    {
        $s = trim(self::s($v));

        return $s === '' ? '' : mb_substr($s, 0, $max, 'UTF-8');
    }

    // ═════════════════════════════ investor summary ══

    /** kp_peta_bulanan — month => [net, bill, days, service, tax, compliment, food, bev, lainnya, discount]. */
    public static function monthly(array $peta): array
    {
        $b = [];
        foreach ($peta as $t => $v) {
            $k = substr($t, 0, 7);
            $b[$k] ??= [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
            $b[$k][0] += KompasState::net($v);
            $b[$k][1] += $v[6];
            $b[$k][2]++;
            $b[$k][3] += $v[4];
            $b[$k][4] += $v[5];
            $b[$k][5] += $v[7];
            $b[$k][6] += $v[0];
            $b[$k][7] += $v[1];
            $b[$k][8] += $v[2];
            $b[$k][9] += $v[3];
        }

        return $b;
    }

    /** laba_rugi_bulanan — the CFO Profit & Loss layout, newest month first; only the lines this system knows. */
    public static function profitLoss(array $peta): array
    {
        $b = self::monthly($peta);
        krsort($b);
        $out = [];
        foreach ($b as $k => $v) {
            $totalSales = $v[6] + $v[7] + $v[8] + $v[4] + $v[3];
            $totalDiskon = $v[9] + $v[5];
            $out[] = ['kunci' => $k, 'food' => $v[6], 'bev' => $v[7], 'lainnya' => $v[8], 'pb1' => $v[4], 'service' => $v[3],
                'totalSales' => $totalSales, 'diskon' => $v[9], 'compliment' => $v[5], 'totalDiskon' => $totalDiskon,
                'netSales' => $totalSales - $totalDiskon, 'hariTerisi' => $v[2]];
        }

        return $out;
    }

    /**
     * ringkas_investor. $viewer = [id, name] of the session User; $all = the
     * viewer may see every investor (module admin). The defaults keep the old
     * whole-list behaviour for callers that hand no session.
     */
    public function summary(?array $viewer = null, bool $all = true): array
    {
        $peta = $this->kompas->dailyMap();
        $b = self::monthly($peta);
        $terakhir = null;
        foreach (array_keys($peta) as $t) {
            if ($terakhir === null || strcmp($t, $terakhir) > 0) {
                $terakhir = $t;
            }
        }
        $hariIni = self::todayWib();
        $kemarin = gmdate('Y-m-d', strtotime($hariIni.' -1 day'));
        $blnIni = substr($hariIni, 0, 7);
        $blnLalu = gmdate('Y-m', strtotime($blnIni.'-01 -1 month'));

        $blok = function (string $k) use ($b) {
            $v = $b[$k] ?? [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
            $tagihan = $v[0] + $v[3] + $v[4];

            return ['kunci' => $k, 'omset' => $v[0], 'transaksi' => $v[1], 'hariTerisi' => $v[2], 'svc' => $v[3], 'pajak' => $v[4],
                'compliment' => $v[5], 'dibayarTamu' => $tagihan, 'netSales' => $tagihan - $v[5]];
        };

        // year over year in NET SALES; only years with data, months without data are null
        $tahunan = [];
        foreach ($b as $k => $v) {
            $th = substr($k, 0, 4);
            $tahunan[$th] ??= array_fill(0, 12, null);
            $tahunan[$th][(int) substr($k, 5, 2) - 1] = $v[0] + $v[3] + $v[4] - $v[5];
        }
        ksort($tahunan);

        $harian = [];
        $tgl = array_keys($peta);
        sort($tgl);
        foreach ($tgl as $t) {
            $harian[] = ['tgl' => $t, 'omset' => KompasState::netSales($peta[$t]), 'tagihan' => KompasState::tagihan($peta[$t]), 'net' => KompasState::net($peta[$t])];
        }

        $s = $this->kompas->assoc();
        $st = isset($s['settings']) && is_array($s['settings']) ? $s['settings'] : [];
        $hi = $peta[$hariIni] ?? null;
        $km = $peta[$kemarin] ?? null;
        $bi = $blok($blnIni);
        $bi['target'] = KompasState::num($st['companyMonthlyTarget'] ?? 0);

        return [
            'ts' => gmdate('c'),
            'hariIni' => ['tgl' => $hariIni, 'omset' => $hi ? KompasState::net($hi) : null, 'transaksi' => $hi ? $hi[6] : null,
                'svc' => $hi ? $hi[4] : null, 'pajak' => $hi ? $hi[5] : null, 'compliment' => $hi ? $hi[7] : null,
                'dibayarTamu' => $hi ? KompasState::tagihan($hi) : null, 'netSales' => $hi ? KompasState::netSales($hi) : null],
            'kemarin' => ['tgl' => $kemarin, 'omset' => $km ? KompasState::net($km) : null,
                'dibayarTamu' => $km ? KompasState::tagihan($km) : null, 'netSales' => $km ? KompasState::netSales($km) : null],
            'bulanIni' => $bi,
            'bulanLalu' => $blok($blnLalu),
            'tahunan' => $tahunan,
            'harian' => $harian,
            'lapor' => $this->reports(),
            'dividen' => $this->dividends($viewer, $all),
            'labaRugi' => self::profitLoss($peta),
            'terakhir' => $terakhir,
            'adaData' => count($peta) > 0,
        ];
    }

    /**
     * dividen_investor — capital returns recorded in Finance → Brankas (names,
     * dates, amounts only).
     *
     * Per investor (legacy, 6 Oct 2026): a module admin sees every investor;
     * anyone else only the records that are theirs. Filtered HERE, on the server —
     * a full list filtered by the browser is still readable in devtools. A record
     * is the viewer's when its `akunId` equals the viewer's id, or, for a record
     * WITHOUT `akunId`, when its name equals the viewer's name (case and edge
     * spaces ignored). A record that has an `akunId` is never matched by name.
     */
    public function dividends(?array $viewer = null, bool $all = true): array
    {
        try {
            $inv = app(Brankas::class)->read()['data']['investor'] ?? null;
        } catch (Throwable) {
            $inv = null;
        }
        if (! is_array($inv)) {
            return ['riwayat' => [], 'gagal' => true];
        }
        $uid = trim(self::s($viewer['id'] ?? ''));
        $unama = mb_strtolower(trim(self::s($viewer['name'] ?? '')), 'UTF-8');

        $riwayat = [];
        $modal = 0;
        $per = [];
        $jumlahSemua = 0;
        foreach ($inv as $i) {
            if (! is_array($i)) {
                continue;
            }
            $jumlahSemua++;
            $akun = trim(self::s($i['akunId'] ?? ''));
            if (! $all) {
                $milik = ($akun !== '' && $uid !== '' && $akun === $uid)
                    || ($akun === '' && $unama !== '' && mb_strtolower(trim(self::s($i['name'] ?? '')), 'UTF-8') === $unama);
                if (! $milik) {
                    continue;
                }
            }
            $modalI = self::capitalTotal($i);
            $modal += $modalI;
            $nama = self::teks($i['name'] ?? '', 80);
            $kembaliI = 0;
            $nI = 0;
            foreach (is_array($i['returns'] ?? null) ? $i['returns'] : [] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $t = KompasState::tgl($r['date'] ?? '');
                $n = KompasState::num($r['amount'] ?? 0);
                if ($t && $n) {
                    $riwayat[] = ['tgl' => $t, 'investor' => $nama, 'nominal' => $n];
                    $kembaliI += $n;
                    $nI++;
                }
            }
            $per[] = ['nama' => $nama, 'modal' => $modalI, 'kembali' => $kembaliI, 'kali' => $nI,
                'kepemilikan' => KompasState::num($i['ownership'] ?? 0), 'terhubung' => $akun !== ''];
        }
        // newest first: what an investor opens first is "when was I last paid"
        usort($riwayat, fn ($a, $b) => strcmp($b['tgl'], $a['tgl']));

        return ['riwayat' => $riwayat, 'total' => array_sum(array_column($riwayat, 'nominal')), 'modal' => $modal,
            'investor' => count($per), 'per' => $per,
            // the full count is none of a plain investor's business
            'semua' => $all, 'jumlahSemua' => $all ? $jumlahSemua : null, 'gagal' => false];
    }

    /**
     * An investor's capital: `capital` plus every `tambahan[].amount` (Tambah
     * Modal, 6 Oct 2026) — twin of modalTotal() in deploy/finance/brankas. Without
     * the additions the page states less capital than Brankas does.
     */
    public static function capitalTotal(array $i): int
    {
        $n = KompasState::num($i['capital'] ?? 0);
        foreach (is_array($i['tambahan'] ?? null) ? $i['tambahan'] : [] as $t) {
            if (is_array($t)) {
                $n += KompasState::num($t['amount'] ?? 0);
            }
        }

        return $n;
    }

    // ═════════════════════════════ investor agenda ══

    /** getAll of another module as assoc arrays; null = that module failed. */
    private static function moduleState(string $class): ?array
    {
        try {
            $d = json_decode(json_encode(app($class)->read(), JSON_UNESCAPED_UNICODE), true);

            return is_array($d) ? $d : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function splitTime(mixed $v): ?array
    {
        $v = trim(self::s($v));

        return $v !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/', $v, $m) ? ['tgl' => $m[1], 'jam' => $m[2] ?? ''] : null;
    }

    /** agenda_investor — upcoming events (Marketing + Event, max 200) and running/upcoming promos (BD, max 50). No client data, no prices. */
    public function agenda(): array
    {
        $hariIni = self::todayWib();
        $gagal = [];
        $ev = [];

        $mkt = self::moduleState(MarketingState::class);
        if ($mkt === null) {
            $gagal[] = 'Marketing';
        } else {
            foreach (is_array($mkt['events'] ?? null) ? $mkt['events'] : [] as $e) {
                if (! is_array($e) || ! ($t = KompasState::tgl($e['tanggal'] ?? '')) || strcmp($t, $hariIni) < 0) {
                    continue;
                }
                $d = is_array($e['detail'] ?? null) ? $e['detail'] : [];
                $ev[] = ['tgl' => $t, 'jam' => self::teks($d['tamuDatang'] ?? '', 5), 'judul' => self::teks($e['nama'] ?? '') ?: '(tanpa nama)',
                    'tempat' => self::teks($d['area'] ?? '', 60), 'jenis' => self::teks($e['jenis'] ?? '', 40),
                    'pax' => KompasState::num($e['pax'] ?? 0), 'sumber' => 'Marketing'];
            }
        }
        $evt = self::moduleState(EventState::class);
        if ($evt === null) {
            $gagal[] = 'Event';
        } else {
            foreach (is_array($evt['events'] ?? null) ? $evt['events'] : [] as $e) {
                if (! is_array($e)) {
                    continue;
                }
                $m = self::splitTime($e['start_datetime'] ?? ($e['tanggal'] ?? ''));
                if (! $m || strcmp($m['tgl'], $hariIni) < 0) {
                    continue;
                }
                $ev[] = ['tgl' => $m['tgl'], 'jam' => $m['jam'], 'judul' => self::teks($e['title'] ?? '') ?: '(tanpa judul)',
                    'tempat' => self::teks($e['venue'] ?? '', 60), 'jenis' => self::teks($e['category'] ?? '', 40),
                    'pax' => KompasState::num($e['capacity'] ?? 0), 'sumber' => 'Event'];
            }
        }
        // nearest first; an event without a time goes to the END of its day
        usort($ev, fn ($a, $b) => $a['tgl'] !== $b['tgl'] ? strcmp($a['tgl'], $b['tgl'])
            : strcmp($a['jam'] === '' ? '99:99' : $a['jam'], $b['jam'] === '' ? '99:99' : $b['jam']));
        $evLebih = max(0, count($ev) - 200);
        $ev = array_slice($ev, 0, 200);

        $pr = [];
        $prLebih = 0;
        $bd = self::moduleState(BdState::class);
        if ($bd === null) {
            $gagal[] = 'BD OS';
        } else {
            foreach (is_array($bd['promos'] ?? null) ? $bd['promos'] : [] as $p) {
                if (! is_array($p) || ! empty($p['paused'])) {
                    continue;
                }
                $a = KompasState::tgl($p['mulai'] ?? '');
                $z = KompasState::tgl($p['selesai'] ?? '');
                if ($z && strcmp($z, $hariIni) < 0) {
                    continue; // already over
                }
                $pr[] = ['nama' => self::teks($p['nama'] ?? '') ?: '(tanpa nama)', 'kategori' => self::teks($p['kategori'] ?? '', 20),
                    'benefit' => self::teks($p['benefit'] ?? '', 80), 'outlet' => self::teks($p['outlet'] ?? '', 60),
                    'mulai' => $a ?: '', 'selesai' => $z ?: '', 'status' => ($a && strcmp($hariIni, $a) < 0) ? 'upcoming' : 'running'];
            }
            usort($pr, fn ($x, $y) => ($x['status'] === 'running' ? 0 : 1) <=> ($y['status'] === 'running' ? 0 : 1) ?: strcmp($x['mulai'], $y['mulai']));
            $prLebih = max(0, count($pr) - 50);
            $pr = array_slice($pr, 0, 50);
        }

        return ['ts' => gmdate('c'), 'hariIni' => $hariIni, 'event' => $ev, 'eventLebih' => $evLebih, 'promo' => $pr, 'promoLebih' => $prLebih, 'gagal' => $gagal];
    }

    // ═════════════════════════════ monthly PDF reports ══

    private static function dir(): string
    {
        $base = Modules::dataDir('kompas');
        if (! $base) {
            throw new RuntimeException('KOMPAS_DATA_DIR belum disetel');
        }

        return rtrim($base, '/\\').'/lapor';
    }

    private static function path(string $kunci): string
    {
        return self::dir().'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $kunci);
    }

    /** inv_lapor_daftar — {bulan: {jenis: {nama, ukuran, at, oleh}}}, an OBJECT when empty. */
    public function reports(): stdClass
    {
        $out = [];
        foreach ($this->db()->select('SELECT `bulan`,`jenis`,`nama`,`ukuran`,`at`,`oleh` FROM `'.self::t('inv_lapor').'` ORDER BY `bulan` DESC, `jenis`') as $r) {
            $out[(string) $r->bulan][(string) $r->jenis] = ['nama' => (string) $r->nama, 'ukuran' => (int) $r->ukuran, 'at' => (int) $r->at, 'oleh' => (string) $r->oleh];
        }

        return (object) $out;
    }

    /** inv_lapor_simpan — PDF only (checked by content), ≤ 12 MB; the old file is removed AFTER the new one is written. */
    public function saveReport(mixed $bulan, mixed $jenis, mixed $payload, string $oleh): array
    {
        $bulan = trim(self::s($bulan));
        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            return ['ok' => false, 'error' => 'bulan harus YYYY-MM'];
        }
        $jenis = self::s($jenis);
        if (! isset(self::JENIS[$jenis])) {
            return ['ok' => false, 'error' => 'jenis laporan tidak dikenal: '.$jenis];
        }
        if (! $payload || ! is_array($payload) || empty($payload['dataBase64'])) {
            return ['ok' => false, 'error' => 'berkas kosong'];
        }
        $bin = base64_decode(preg_replace('#^data:[^,]+,#', '', self::s($payload['dataBase64'])), true);
        if ($bin === false) {
            return ['ok' => false, 'error' => 'base64 tidak valid'];
        }
        if (substr($bin, 0, 4) !== '%PDF') {
            return ['ok' => false, 'error' => 'berkasnya bukan PDF'];
        }
        if (strlen($bin) > 12 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'berkas melebihi 12 MB ('.round(strlen($bin) / 1048576, 1).' MB)'];
        }
        if (! is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        $kunci = 'lp_'.bin2hex(random_bytes(8)).'.pdf';
        if (@file_put_contents(self::path($kunci), $bin) === false) {
            return ['ok' => false, 'error' => 'gagal menulis berkas (cek izin folder)'];
        }
        $lama = $this->db()->selectOne('SELECT `kunci` FROM `'.self::t('inv_lapor').'` WHERE `bulan`=? AND `jenis`=?', [$bulan, $jenis])?->kunci;
        if (KompasState::onCore()) {
            // The legacy id is a MySQL counter that never leaves the database:
            // the module's own key is (bulan, jenis), which is the legacy_id.
            $at = (int) (microtime(true) * 1000);
            $cols = ['legacy_id' => "$bulan|$jenis", 'bulan' => $bulan, 'jenis' => $jenis, 'kunci' => $kunci,
                'nama' => mb_substr(self::s($payload['fileName'] ?? $kunci), 0, 190), 'ukuran' => strlen($bin),
                'at' => $at, 'oleh' => mb_substr($oleh, 0, 80), 'created_at' => $at, 'updated_at' => $at, 'version' => 1];
            $this->db()->insert('INSERT INTO `'.self::t('inv_lapor').'` (`id`,'.self::cols(array_keys($cols)).')'
                .' VALUES (?,'.self::ph(count($cols)).')'
                .' ON DUPLICATE KEY UPDATE `version`=`version`+1, `kunci`=VALUES(`kunci`), `nama`=VALUES(`nama`), `ukuran`=VALUES(`ukuran`),'
                .' `at`=VALUES(`at`), `oleh`=VALUES(`oleh`), `updated_at`=VALUES(`updated_at`)',
                [self::ulid(), ...array_values($cols)]);
        } else {
            $this->db()->insert('INSERT INTO `inv_lapor` (`bulan`,`jenis`,`kunci`,`nama`,`ukuran`,`at`,`oleh`) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE `kunci`=VALUES(`kunci`), `nama`=VALUES(`nama`), `ukuran`=VALUES(`ukuran`), `at`=VALUES(`at`), `oleh`=VALUES(`oleh`)',
                [$bulan, $jenis, $kunci, mb_substr(self::s($payload['fileName'] ?? $kunci), 0, 190), strlen($bin),
                    (int) (microtime(true) * 1000), mb_substr($oleh, 0, 80)]);
        }
        if ($lama && $lama !== $kunci) {
            @unlink(self::path($lama));
        }

        return ['ok' => true, 'bulan' => $bulan, 'jenis' => $jenis, 'ukuran' => strlen($bin)];
    }

    /** @return array{path:string,nama:string}|string the file, or the legacy 404 message */
    public function reportFile(mixed $bulan, mixed $jenis): array|string
    {
        $r = $this->db()->selectOne('SELECT `kunci`,`nama` FROM `'.self::t('inv_lapor').'` WHERE `bulan`=? AND `jenis`=?', [self::s($bulan), self::s($jenis)]);
        if (! $r) {
            return 'laporan tidak ada';
        }
        $p = self::path($r->kunci);

        return is_file($p) ? ['path' => $p, 'nama' => (string) $r->nama] : 'berkasnya hilang dari disk';
    }

    public function deleteReport(mixed $bulan, mixed $jenis): void
    {
        $k = $this->db()->selectOne('SELECT `kunci` FROM `'.self::t('inv_lapor').'` WHERE `bulan`=? AND `jenis`=?', [self::s($bulan), self::s($jenis)])?->kunci;
        $this->db()->delete('DELETE FROM `'.self::t('inv_lapor').'` WHERE `bulan`=? AND `jenis`=?', [self::s($bulan), self::s($jenis)]);
        if ($k) {
            @unlink(self::path($k));
        }
    }

    // ═════════════════════════════ analytics ══

    /** an_baca — {data: {laporan, setting,…}, akses, peran, ts}. */
    public function analytics(): array
    {
        $data = null;
        $ts = 0;
        $row = $this->db()->selectOne('SELECT `data`,`updated_at` FROM `'.self::t('an_state').'` WHERE `'.self::idCol().'`=1');
        if ($row && isset($row->data) && $row->data !== '') {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $data = $d;
                $ts = (int) $row->updated_at;
            }
        }
        $data ??= ['laporan' => new stdClass, 'setting' => new stdClass];
        $akses = [];
        foreach ($this->db()->select('SELECT `kunci`,`halaman`,`tingkat` FROM `'.self::t('an_akses').'`') as $r) {
            $akses[(string) $r->kunci][(string) $r->halaman] = (int) $r->tingkat;
        }
        $peran = [];
        foreach ($this->db()->select('SELECT `kunci`,`peran` FROM `'.self::t('an_peran').'`') as $r) {
            $peran[(string) $r->kunci] = (string) $r->peran;
        }

        return ['data' => $data, 'akses' => (object) $akses, 'peran' => (object) $peran, 'ts' => $ts];
    }

    public function saveAnalytics(mixed $data, mixed $oleh, ?int $ts = null): array
    {
        if (! is_array($data)) {
            return ['ok' => false, 'error' => 'data bukan objek'];
        }
        $now = $ts ?? (int) (microtime(true) * 1000);
        if (KompasState::onCore()) {
            $this->db()->insert('INSERT INTO `'.self::t('an_state').'` (`id`,`legacy_id`,`data`,`created_at`,`updated_at`,`oleh`,`version`)
                VALUES (?,1,?,?,?,?,1)
                ON DUPLICATE KEY UPDATE `version`=`version`+1, `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `oleh`=VALUES(`oleh`)',
                [self::ulid(), json_encode($data, JSON_UNESCAPED_UNICODE), $now, $now, mb_substr(self::s($oleh), 0, 80)]);

            return ['ok' => true, 'saved' => true];
        }
        $this->db()->insert('INSERT INTO `an_state` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
            ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
            [json_encode($data, JSON_UNESCAPED_UNICODE), $now, mb_substr(self::s($oleh), 0, 80)]);

        return ['ok' => true, 'saved' => true];
    }

    /** an_akses_simpan — replace the whole page × role matrix (tingkat clamped 0..2). */
    public function saveAnalyticsAccess(mixed $peta): array
    {
        if (! is_array($peta)) {
            return ['ok' => false, 'error' => 'akses bukan objek'];
        }
        $this->db()->transaction(function () use ($peta) {
            $now = (int) (microtime(true) * 1000);
            $this->db()->delete('DELETE FROM `'.self::t('an_akses').'`');
            foreach ($peta as $kunci => $baris) {
                if (! is_array($baris)) {
                    continue;
                }
                foreach ($baris as $hal => $tk) {
                    $k = mb_substr((string) $kunci, 0, 80);
                    $h = mb_substr((string) $hal, 0, 40);
                    $t = max(0, min(2, (int) $tk));
                    if (KompasState::onCore()) {
                        // columns: id, legacy_id, kunci, halaman, tingkat, user_id(SELECT),
                        // created_at, updated_at — bindings in exactly that order
                        $this->db()->insert('INSERT INTO `'.self::t('an_akses').'` (`id`,`legacy_id`,`kunci`,`halaman`,`tingkat`,`user_id`,`created_at`,`updated_at`)
                            VALUES (?,?,?,?,?,(SELECT u.`id` FROM `user` u WHERE u.`legacy_id` = ?),?,?)',
                            [self::ulid(), "$k|$h", $k, $h, $t, self::keyUser($kunci), $now, $now]);

                        continue;
                    }
                    $this->db()->insert('INSERT INTO `an_akses` (`kunci`,`halaman`,`tingkat`) VALUES (?,?,?)', [$k, $h, $t]);
                }
            }
        });

        return ['ok' => true, 'saved' => true];
    }

    /** an_peran_simpan — replace the whole {'#<userId>': role} map. */
    public function saveAnalyticsRoles(mixed $peta): array
    {
        if (! is_array($peta)) {
            return ['ok' => false, 'error' => 'peran bukan objek'];
        }
        $this->db()->transaction(function () use ($peta) {
            $now = (int) (microtime(true) * 1000);
            $this->db()->delete('DELETE FROM `'.self::t('an_peran').'`');
            foreach ($peta as $kunci => $p) {
                $k = mb_substr((string) $kunci, 0, 80);
                if (KompasState::onCore()) {
                    // columns: id, legacy_id, kunci, peran, user_id(SELECT), created_at,
                    // updated_at — bindings in exactly that order
                    $this->db()->insert('INSERT INTO `'.self::t('an_peran').'` (`id`,`legacy_id`,`kunci`,`peran`,`user_id`,`created_at`,`updated_at`)
                        VALUES (?,?,?,?,(SELECT u.`id` FROM `user` u WHERE u.`legacy_id` = ?),?,?)',
                        [self::ulid(), $k, $k, mb_substr(self::s($p), 0, 20), self::keyUser($kunci), $now, $now]);

                    continue;
                }
                $this->db()->insert('INSERT INTO `an_peran` (`kunci`,`peran`) VALUES (?,?)', [$k, mb_substr(self::s($p), 0, 20)]);
            }
        });

        return ['ok' => true, 'saved' => true];
    }
}
