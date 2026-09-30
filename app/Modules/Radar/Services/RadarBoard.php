<?php

namespace App\Modules\Radar\Services;

use App\Auth\OfficeAccess;
use App\Modules\Bd\Services\BdState;
use App\Modules\Event\Services\EventState;
use App\Modules\Marketing\Services\MarketingState;
use App\Modules\Reservasi\Services\ReservasiRecords;
use Throwable;

/**
 * Reads the four source modules for Radar (laksamana-office deploy/radar,
 * muat() + satukan()). Radar holds no data of its own and writes nothing.
 *
 * Every source is read on its own: one module that fails must not take the
 * page down — Radar is used exactly when something is wrong. A failed source
 * is reported in `sumber` (the red strip that names it), never hidden.
 *
 * Unlike the old page, this runs on the server, so it also decides what leaves
 * it: a Radar holder does not hold the source modules, so they get the
 * narrow Radar shapes only (no CRM, no proofs), and money figures only when
 * they are a Head, a Radar admin or a superadmin (radarBolehUang).
 */
class RadarBoard
{
    private const SUMBER = ['mkt' => 'Marketing', 'evt' => 'Event', 'rsv' => 'Reservasi', 'bd' => 'BD OS'];

    public function __construct(
        private readonly OfficeAccess $access,
        private readonly MarketingState $marketing,
        private readonly EventState $event,
        private readonly ReservasiRecords $reservasi,
        private readonly BdState $bd,
    ) {}

    /** radarBolehUang(): Head in the Tim column, or admin of radar / superadmin. */
    public function mayViewMoney(string $userId): bool
    {
        if ($this->access->isModuleAdmin($userId, 'radar')) {
            return true;
        }
        $u = $this->access->userById($userId);

        return RadarRules::isHead((string) ($u['keterangan'] ?? ''));
    }

    /**
     * The board for [from, to]: agenda (Marketing + VIP + Event, only what will
     * happen), reservations (every status — the page filters), F&B formats for
     * the filter, and per-source status.
     */
    public function board(string $from, string $to, bool $money): array
    {
        $sumber = [];
        $mkt = $this->try('mkt', fn () => $this->marketing->read(), $sumber);
        $evt = $this->try('evt', fn () => $this->event->read(), $sumber);
        $rsv = $this->try('rsv', fn () => $this->reservasi->list($from, $to), $sumber);

        $agenda = RadarRules::agenda(
            self::list($mkt, 'events'), self::list($mkt, 'vip'), self::list($evt, 'events'), $from, $to);

        $reservations = [];
        foreach (is_array($rsv) ? $rsv : [] as $r) {
            if (is_array($r) && ! empty($r['date'])) {
                $reservations[] = RadarRules::reservation($r, $money);
            }
        }

        $sumber['mkt']['n'] = count(self::list($mkt, 'events'));
        $sumber['evt']['n'] = count(self::list($evt, 'events'));
        $sumber['rsv']['n'] = count(array_filter($reservations, fn ($r) => ! RadarRules::isCancelled($r)));

        $fb = is_array($mkt) && is_array($mkt['fbFormats'] ?? null) ? array_values($mkt['fbFormats']) : null;

        return [
            'agenda' => $agenda,
            'reservations' => $reservations,
            'fbFormats' => $fb,
            'sumber' => array_values($sumber),
            'bolehUang' => $money,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * One agenda item for the detail panel and the Detail Lengkap page, in the
     * narrow shape of its source. null = not found or not on the board (only
     * what will happen is shown, so only that can be opened).
     */
    public function detail(string $sumber, string $id, bool $money): ?array
    {
        if ($sumber === 'evt') {
            $s = $this->event->read();
            $e = self::find(self::list($s, 'events'), $id);
            if (! $e) {
                return null;
            }
            $a = RadarRules::agenda([], [], [$e], '0000-00-00', '9999-12-31')[0] ?? null;
            if (! $a) {
                return null;
            }
            $talents = [];
            foreach (self::list($s, 'schedules') as $sc) {
                if (is_array($sc) && (string) ($sc['event_id'] ?? '') === $id) {
                    $t = self::find(self::list($s, 'talents'), (string) ($sc['talent_id'] ?? ''));
                    $talents[] = ['nama' => (string) ($t ? ($t['name'] ?? $t['nama'] ?? '') : '') ?: '(talent)',
                        'jam' => (string) ($sc['start_time'] ?? ''), 'tipe' => (string) ($sc['performance_type'] ?? '')];
                }
            }

            return $a + ['event' => [
                'venue' => (string) ($e['venue'] ?? ''), 'category' => (string) ($e['category'] ?? ''),
                'capacity' => $e['capacity'] ?? null, 'pic' => (string) ($e['pic'] ?? ''), 'co_pic' => (string) ($e['co_pic'] ?? ''),
                'theme' => (string) ($e['theme'] ?? ''), 'is_ticketed' => (bool) ($e['is_ticketed'] ?? false),
                'description' => (string) ($e['description'] ?? ''),
            ], 'talents' => $talents];
        }
        if ($sumber !== 'mkt' && $sumber !== 'vip') {
            return null;
        }

        $s = $this->marketing->read();
        $row = self::find(self::list($s, $sumber === 'mkt' ? 'events' : 'vip'), $id);
        if (! $row) {
            return null;
        }
        $a = $sumber === 'mkt'
            ? (RadarRules::agenda([$row], [], [], '0000-00-00', '9999-12-31')[0] ?? null)
            : (RadarRules::agenda([], [$row], [], '0000-00-00', '9999-12-31')[0] ?? null);
        if (! $a) {
            return null;
        }
        $client = self::find(self::list($s, 'clients'), (string) ($row['clientId'] ?? ''));
        $klien = $client ? [
            'nama' => (string) ($client['nama'] ?? $client['name'] ?? ''), 'perusahaan' => (string) ($client['perusahaan'] ?? ''),
            'pic' => (string) ($client['pic'] ?? ''), 'hp' => (string) ($client['hp'] ?? ''),
        ] : null;
        $pic = self::find(self::list($s, 'users'), (string) ($row['mktPIC'] ?? ''));
        $picNama = $pic ? (string) ($pic['name'] ?? $pic['nama'] ?? '') : (string) ($row['mktPIC'] ?? '');

        if ($sumber === 'vip') {
            return $a + ['vip' => [
                'mktPIC' => $picNama, 'hp' => (string) ($row['hp'] ?? ''), 'perusahaan' => (string) ($row['perusahaan'] ?? ''),
                'catatan' => (string) ($row['catatan'] ?? ''),
            ], 'klien' => $klien];
        }

        $detail = RadarRules::detailForBoard(is_array($row['detail'] ?? null) ? $row['detail'] : [], $money);

        return $a + ['mkt' => [
            'mktPIC' => $picNama,
            'pembayaran' => $money ? count(is_array($row['payments'] ?? null) ? $row['payments'] : []) : null,
            'detail' => (object) $detail,
        ], 'klien' => $klien];
    }

    /**
     * May this caller open Marketing file $key through Radar? Only attachments
     * of a Marketing event on the board, in a detail field the caller may see.
     * Marketing files also hold payment receipts, which a Radar holder must not
     * reach by guessing a key.
     */
    public function attachmentAllowed(string $key, bool $money): bool
    {
        foreach (self::list($this->marketing->read(), 'events') as $e) {
            if (! is_array($e) || ! RadarRules::willHappen(['sumber' => 'mkt', 'status' => $e['status'] ?? ''])) {
                continue;
            }
            $d = RadarRules::detailForBoard(is_array($e['detail'] ?? null) ? $e['detail'] : [], $money);
            if (in_array($key, RadarRules::attachmentKeys($d), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{promos:list<array>,arsip:int,sumber:array} */
    public function promos(string $today): array
    {
        $sumber = [];
        $bd = $this->try('bd', fn () => $this->bd->read(), $sumber);

        return RadarRules::promos(is_array($bd) && is_array($bd['promos'] ?? null) ? $bd['promos'] : [], $today)
            + ['sumber' => array_values($sumber)];
    }

    private function try(string $id, \Closure $read, array &$sumber): mixed
    {
        try {
            $v = $read();
            $sumber[$id] = ['id' => $id, 'nama' => self::SUMBER[$id], 'ok' => true, 'pesan' => ''];

            return $v;
        } catch (Throwable $e) {
            report($e);
            $sumber[$id] = ['id' => $id, 'nama' => self::SUMBER[$id], 'ok' => false, 'pesan' => 'tidak terhubung'];

            return null;
        }
    }

    private static function list(mixed $state, string $key): array
    {
        return is_array($state) && is_array($state[$key] ?? null) ? $state[$key] : [];
    }

    private static function find(array $rows, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($rows as $r) {
            if (is_array($r) && (string) ($r['id'] ?? '') === $id) {
                return $r;
            }
        }

        return null;
    }
}
