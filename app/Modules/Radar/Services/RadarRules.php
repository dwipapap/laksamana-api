<?php

namespace App\Modules\Radar\Services;

/**
 * Radar's rules as pure functions on the legacy data shapes — no DB, no clock
 * (the caller passes "today"), so the DB-free Unit suite pins them.
 *
 * Port of laksamana-office deploy/radar/index.html. Radar only READS: it joins
 * Marketing events, Reservasi VIP (from Marketing), Event events and
 * Reservasi bookings on one board, and lists BD promos. The comments name the
 * legacy function each rule comes from; each of those fixed a real incident.
 */
final class RadarRules
{
    /** Marketing statuses that mean "this will happen" (agPasti, ST_MKT_JALAN). */
    public const MKT_JALAN = ['deal', 'confirmed', 'event done'];

    /**
     * Event-module statuses that mean "this will happen" (ST_EVT_JALAN, legacy
     * 4ee871e, 7 Oct 2026). The Event module migrated its statuses (Draft →
     * Planning, Today → Upcoming, Finished → Event Done); the old list let 1 of 26
     * production events through. The list equals EVT_STATUS_HASIL in deploy/event
     * (Approval, Upcoming, Event Done); the old names stay for rows not saved
     * since the migration. Planning and Prospect are not decided yet. If
     * EVT_STATUS_HASIL changes, this list MUST follow. DwGuests uses it too.
     */
    public const EVT_JALAN = ['approval', 'upcoming', 'event done', 'today', 'finished'];

    /**
     * Event-detail keys that hold prices / DP / settlement (RADAR_KUNCI_UANG),
     * on top of the word pattern in isMoneyKey(): a new Marketing field whose
     * name says money is hidden even before it is listed here.
     */
    private const MONEY_KEYS = ['budgetPax', 'sistemBayar', 'depositNama', 'depositNominal', 'depositVia', 'metodeBayar',
        'diskonKasir', 'diskon', 'sewaVenue', 'biayaTeknis', 'biayaLain', 'noteInvoice', 'npwp', 'picBayar', 'refundPIC',
        'dealPax', 'limitAnggaran', 'boothRevshare'];

    private const MONEY_PATTERN = '/harga|nominal|deposit|^dp|lunas|bayar|biaya|diskon|budget|sewa|invoice|tagih|grand|total|payment|price|^fbManual|^fbDeal|rincian|pajak|^tax|svcApply|serviceCharge/i';

    /** Promo fields Radar shows (vPromo). Anything else stays in BD. */
    public const PROMO_FIELDS = ['id', 'nama', 'tipe', 'kategori', 'benefit', 'partner', 'kode', 'ketentuan', 'outlet',
        'hari', 'jamMulai', 'jamSelesai', 'kuota', 'lmPIC', 'poster', 'mulai', 'selesai', 'paused'];

    /** Reservation fields every Radar holder sees (tabelRes, resCard, drawerRes). */
    public const RES_FIELDS = ['id', 'date', 'time', 'name', 'pax', 'table', 'category', 'status', 'vip', 'notes',
        'foodReq', 'drinkReq', 'cancelReason', 'source', 'picName', 'phone', 'member', 'memberNo'];

    /** Reservation money fields, only for those allowed to see amounts. */
    public const RES_MONEY_FIELDS = ['dpAmount', 'dpStatus'];

    /**
     * "Head" = the whole word Head in the Office Tim column (radarBolehUang;
     * same rule as pbHead in assets/performa-bonus.js). Module admins of radar
     * and superadmins are checked by the caller.
     */
    public static function isHead(?string $keterangan): bool
    {
        foreach (preg_split('/[\s,;\/]+/', mb_strtolower((string) $keterangan)) ?: [] as $t) {
            if ($t === 'head') {
                return true;
            }
        }

        return false;
    }

    public static function isMoneyKey(string $key): bool
    {
        return in_array($key, self::MONEY_KEYS, true) || preg_match(self::MONEY_PATTERN, $key) === 1;
    }

    /**
     * pecahWaktu(): an instant with a zone marker (the Event module stores UTC
     * ending in Z) is shifted to WIB; a value without a zone is already local
     * (Marketing stores plain dates). Cutting the UTC string as is moved every
     * time 7 hours EARLIER and night events to the previous day.
     *
     * @return array{tgl:string,jam:string}|null
     */
    public static function splitTime(mixed $dt): ?array
    {
        $s = trim((string) ($dt ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s)) {
            $t = strtotime($s);
            if ($t === false) {
                return null;
            }
            $w = $t + 7 * 3600;

            return ['tgl' => gmdate('Y-m-d', $w), 'jam' => gmdate('H:i', $w)];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $s, $m)) {
            return ['tgl' => $m[1], 'jam' => $m[2] ?? ''];
        }

        return null;
    }

    public static function isCancelled(array $r): bool
    {
        // "batal" kept for old data; the Reservasi vocabulary is "Cancelled" (resBatal).
        return preg_match('/cancel|batal/i', (string) ($r['status'] ?? '')) === 1;
    }

    public static function isNoShow(array $r): bool
    {
        return preg_match('/no.?show/i', (string) ($r['status'] ?? '')) === 1;
    }

    /**
     * agPasti(): only what will actually happen. The two modules use different
     * words for the same idea, so one list would empty the other module's side.
     * VIP has no whitelist on purpose: they are bookings, not a sales pipeline.
     */
    public static function willHappen(array $a): bool
    {
        $s = mb_strtolower(trim((string) ($a['status'] ?? '')));

        return match ($a['sumber'] ?? '') {
            'mkt' => in_array($s, self::MKT_JALAN, true),
            'evt' => in_array($s, self::EVT_JALAN, true),
            'vip' => ! self::isCancelled($a) && ! self::isNoShow($a),
            default => true,
        };
    }

    /** Upper bound of the VIP guest estimate (paxVipAngka): preparing too little costs more than too much. */
    public static function vipPax(array $v): int
    {
        return (int) ($v['paxMax'] ?? 0) ?: (int) ($v['paxMin'] ?? 0);
    }

    /**
     * satukan(): one agenda list from the three shapes, already filtered to
     * willHappen() and to [from, to], sorted by date then time with no-time
     * items LAST in their day (an empty string would sort as the earliest).
     *
     * @return list<array{sumber:string,id:string,tgl:string,judul:string,jam:string,selesai:string,tempat:string,status:string,pax:int,jenis:string,fb:string,paxMin?:int,paxMax?:int}>
     */
    public static function agenda(array $mktEvents, array $vips, array $evtEvents, string $from, string $to): array
    {
        $out = [];
        foreach ($mktEvents as $e) {
            if (! is_array($e) || empty($e['tanggal'])) {
                continue;
            }
            $d = is_array($e['detail'] ?? null) ? $e['detail'] : [];
            $out[] = ['sumber' => 'mkt', 'id' => (string) ($e['id'] ?? ''), 'tgl' => (string) $e['tanggal'],
                'judul' => (string) (($e['nama'] ?? '') ?: '(tanpa nama)'),
                'jam' => (string) ($d['tamuDatang'] ?? ''), 'selesai' => (string) ($d['selesai'] ?? ''),
                'tempat' => (string) ($d['area'] ?? ''), 'status' => (string) ($e['status'] ?? ''),
                'pax' => (int) ($e['pax'] ?? 0), 'jenis' => (string) ($e['jenis'] ?? ''), 'fb' => (string) ($d['fbFormat'] ?? '')];
        }
        foreach ($evtEvents as $e) {
            if (! is_array($e)) {
                continue;
            }
            $mulai = self::splitTime($e['start_datetime'] ?? ($e['tanggal'] ?? null));
            if (! $mulai || $mulai['tgl'] === '') {
                continue;
            }
            $habis = self::splitTime($e['end_datetime'] ?? null);
            $out[] = ['sumber' => 'evt', 'id' => (string) ($e['id'] ?? ''), 'tgl' => $mulai['tgl'],
                'judul' => (string) (($e['title'] ?? '') ?: '(tanpa judul)'),
                'jam' => $mulai['jam'], 'selesai' => $habis['jam'] ?? '',
                'tempat' => (string) ($e['venue'] ?? ''), 'status' => (string) ($e['status'] ?? ''),
                'pax' => (int) ($e['capacity'] ?? 0), 'jenis' => (string) ($e['category'] ?? ''), 'fb' => ''];
        }
        foreach ($vips as $v) {
            // Cancelled VIP is marked by batalAt (as vip_hari() in marketing-mysql), not by a status word.
            if (! is_array($v) || empty($v['tanggal']) || ! empty($v['batalAt'])) {
                continue;
            }
            $meja = is_array($v['meja'] ?? null) ? implode(', ', array_map('strval', $v['meja'])) : (string) ($v['meja'] ?? '');
            $out[] = ['sumber' => 'vip', 'id' => (string) ($v['id'] ?? ''), 'tgl' => (string) $v['tanggal'],
                'judul' => (string) (($v['nama'] ?? '') ?: '(tanpa nama)'),
                'jam' => (string) ($v['jamMulai'] ?? ''), 'selesai' => (string) ($v['jamSelesai'] ?? ''),
                'tempat' => $meja, 'status' => (string) ($v['status'] ?? ''),
                'pax' => self::vipPax($v), 'jenis' => (string) ($v['jenis'] ?? ''), 'fb' => '',
                'paxMin' => (int) ($v['paxMin'] ?? 0), 'paxMax' => (int) ($v['paxMax'] ?? 0)];
        }

        $out = array_values(array_filter($out, fn ($a) => $a['tgl'] >= $from && $a['tgl'] <= $to && self::willHappen($a)));
        usort($out, function ($a, $b) {
            if ($a['tgl'] !== $b['tgl']) {
                return $a['tgl'] <=> $b['tgl'];
            }
            if ($a['jam'] === '' || $b['jam'] === '') {
                return ($a['jam'] === '') <=> ($b['jam'] === '');
            }

            return $a['jam'] <=> $b['jam'];
        });

        return $out;
    }

    /** One reservation in the narrow Radar shape: no proofs, no transfer data; money only when allowed. */
    public static function reservation(array $r, bool $money): array
    {
        $out = [];
        foreach ($money ? [...self::RES_FIELDS, ...self::RES_MONEY_FIELDS] : self::RES_FIELDS as $k) {
            if (array_key_exists($k, $r)) {
                $out[$k] = $r[$k];
            }
        }

        return $out;
    }

    /**
     * detailFields() money filter, applied to a Marketing event `detail` before
     * it leaves the server: money keys are dropped for non-Heads, the `nominal`
     * of name/nominal rows is dropped for non-Heads, and bill lines
     * ({desc, harga|jumlah}) are always dropped — Radar is a coordination board,
     * not a finance board.
     */
    public static function detailForBoard(array $detail, bool $money): array
    {
        $out = [];
        foreach ($detail as $k => $v) {
            $k = (string) $k;
            if (! $money && self::isMoneyKey($k)) {
                continue;
            }
            if (is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])) {
                if (array_key_exists('desc', $v[0]) && (array_key_exists('harga', $v[0]) || array_key_exists('jumlah', $v[0]))) {
                    continue;
                }
                if (! $money && array_key_exists('nama', $v[0])) {
                    $v = array_map(fn ($row) => is_array($row) ? array_diff_key($row, ['nominal' => 1]) : $row, $v);
                }
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** A {key,name,...} attachment list (adalahLampiran). */
    public static function isAttachmentList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])
            && array_key_exists('key', $v[0]) && array_key_exists('name', $v[0]);
    }

    /** @return list<string> attachment keys of a detail (as filtered for the caller) */
    public static function attachmentKeys(array $detail): array
    {
        $keys = [];
        foreach ($detail as $v) {
            if (self::isAttachmentList($v)) {
                foreach ($v as $f) {
                    if (is_array($f) && ($f['key'] ?? '') !== '') {
                        $keys[] = (string) $f['key'];
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * promoStatus(): computed from dates, never read from a stored field
     * (a stored status goes stale every midnight). $today = Y-m-d in WIB.
     */
    public static function promoStatus(array $p, string $today): string
    {
        if (! empty($p['paused'])) {
            return 'paused';
        }
        $a = self::date($p['mulai'] ?? null);
        $b = self::date($p['selesai'] ?? null);
        if ($a !== null && $today < $a) {
            return 'upcoming';
        }
        if ($b !== null && $today > $b) {
            return 'ended';
        }

        return 'running';
    }

    /**
     * vPromo(): only running and upcoming (a paused or ended promo shown on a
     * board read at a glance promises guests what cannot be redeemed), running
     * first then the nearest start — the same order as BD. `arsip` counts the
     * rest so a short list does not read as missing data.
     *
     * @return array{promos:list<array>,arsip:int}
     */
    public static function promos(array $all, string $today): array
    {
        $aktif = [];
        $arsip = 0;
        foreach ($all as $p) {
            if (! is_array($p)) {
                continue;
            }
            $st = self::promoStatus($p, $today);
            if ($st !== 'running' && $st !== 'upcoming') {
                $arsip++;

                continue;
            }
            $row = ['status' => $st];
            foreach (self::PROMO_FIELDS as $k) {
                if (array_key_exists($k, $p)) {
                    $row[$k] = $p[$k];
                }
            }
            $aktif[] = $row;
        }
        usort($aktif, fn ($a, $b) => [$a['status'] === 'running' ? 0 : 1, (string) ($a['mulai'] ?? '')]
            <=> [$b['status'] === 'running' ? 0 : 1, (string) ($b['mulai'] ?? '')]);

        return ['promos' => $aktif, 'arsip' => $arsip];
    }

    private static function date(mixed $s): ?string
    {
        $s = trim((string) ($s ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }
}
