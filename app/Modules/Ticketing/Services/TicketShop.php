<?php

namespace App\Modules\Ticketing\Services;

use App\Modules\Event\Services\EventState;
use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The public ticket shop: a faithful port of legacy ticketing-mysql/lib_ticketing.php
 * (events, seat map, holds, checkout, upgrade, Xendit webhook and payment). Legacy
 * compat and v1 both call it. The legacy comments explain the incidents behind each
 * rule; the short versions are kept here.
 *
 * - Prices are ALWAYS computed here, never taken from the browser.
 * - EMS rows are core columns + a `data` JSON that is the source of truth.
 * - Writes that race (holds, checkout, payment) run under GET_LOCK('<db>:tix', 10),
 *   the same lock name as legacy, so old and new serialise together.
 * - Expired holds and orders are swept inside normal requests; there is no cron.
 * - No transactions: legacy ran on autocommit, and a paid order must never be rolled
 *   back by a later failure (e.g. mail).
 *
 * User-facing failures throw plain Exception (their message reaches the buyer);
 * anything else is reported as a generic server error by the controllers.
 */
class TicketShop
{
    public const LIB_VERSION = '2026-07-31a';

    /** The EMS side (events, ticket_classes, seats, orders, tickets) is reached through it, never with our own SQL (ADR-0002). */
    public function __construct(private readonly EventState $ems) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('ticketing');
    }

    private static function cfg(string $k, mixed $d = null): mixed
    {
        return config('laksamana.ticketing.'.$k, $d);
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public static function uid(string $p = 'tx'): string
    {
        return $p.'_'.bin2hex(random_bytes(8));
    }

    public static function randomToken(int $n = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($n)), '+/', '-_'), '=');
    }

    private function locked(\Closure $fn): mixed
    {
        return NamedLock::run('ticketing', 'tix', $fn, 10, 'Server sedang sibuk, coba lagi sebentar.');
    }

    // ───────────────────────────── environment ──

    private static function host(): string
    {
        return (string) (request()?->server('HTTP_HOST') ?? '');
    }

    private static function https(): bool
    {
        $h = request()?->server('HTTPS');

        return ! empty($h) && $h !== 'off';
    }

    /** The site the buyer is on, derived from the serving host (legacy site_url). */
    public static function siteUrl(): string
    {
        $h = self::host();
        if ($h === '') {
            return (string) self::cfg('site_url', '');
        }

        return (self::https() ? 'https' : 'http').'://'.$h.'/ticketing';
    }

    /** From the host, not config; an unknown host counts as PRODUCTION (closes simulation mode). */
    public static function env(): string
    {
        $h = strtolower(self::host());
        if ($h === '') {
            return Modules::envLabel();
        }
        if (str_starts_with($h, 'dev.') || str_contains($h, 'localhost') || str_contains($h, '127.0.0.1')) {
            return 'dev';
        }

        return 'produksi';
    }

    /** Payment simulation (XENDIT_MOCK): never in production, whatever the config says. */
    public static function simulation(): bool
    {
        return (bool) self::cfg('xendit_mock', false) && self::env() !== 'produksi';
    }

    private static function payMinutes(): int
    {
        return max(3, min(60, (int) self::cfg('bayar_menit', 10)));
    }

    public function identity(): array
    {
        $dir = $this->eventFilesDir();

        return array_merge($this->checkDb(), [
            'env' => self::env(),
            'env_config' => Modules::envLabel(),
            'penahan_percobaan' => $this->rateLimiterOn() ? 'aktif' : 'TIDAK AKTIF (tabel tix_gagal belum dibuat)',
            'versi' => self::LIB_VERSION,
            'xendit' => Xendit::mode(),
            'simulasi_bayar' => self::simulation(),
            'config' => 'laravel .env',
            'poster' => $dir !== ''
                ? ['cara' => 'berkas', 'folder' => $dir]
                : ((($u = self::posterUrlEms('CONTOH.jpg')) !== '')
                    ? ['cara' => 'alihkan', 'ke' => $u]
                    : ['cara' => 'tidak ada jalan']),
        ]);
    }

    private function checkDb(): array
    {
        try {
            $this->db()->select('SELECT 1');
            // EMS data goes through the event service (ADR-0002); the hold table is ours.
            $t = $this->ems->emsTableExists('seats');
            $h = $this->db()->selectOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [TicketSchema::table('seat_holds')]) !== null;

            return ['db_ok' => true, 'tabel_ems' => $t, 'tabel_hold' => $h];
        } catch (Throwable $e) {
            $p = ($e->getCode() === '42000' || str_contains($e->getMessage(), '1044'))
                ? 'akses ditolak / nama database salah' : 'tidak bisa menyambung';

            return ['db_ok' => false, 'db_error' => $p];
        }
    }

    // ───────────────────────────── sweeps ──

    /** Expired holds that no order owns, then orders past their payment window. */
    public function sweepHolds(): void
    {
        $this->db()->delete('DELETE FROM `'.TicketSchema::table('seat_holds')."` WHERE expires_at < ? AND (order_id IS NULL OR order_id = '')", [self::nowMs()]);
        $this->sweepExpiredOrders();
    }

    private function sweepExpiredOrders(): void
    {
        try {
            $now = time();
            foreach ($this->ems->emsColumns('orders', ['id', 'data'], ['payment_status' => 'Pending'], 'created_at DESC', 50) as $row) {
                $o = json_decode($row->data, true);
                if (! is_array($o) || empty($o['expires_at'])) {
                    continue;
                }
                if (strtotime($o['expires_at']) >= $now) {
                    continue;
                }
                $this->cancel($row->id, 'EXPIRED');
            }
        } catch (Throwable) {
            // a sweep never fails the request it rides on
        }
    }

    /** Ask Xendit about a few pending orders (each at most once a minute), riding normal traffic. */
    public function sweepPendingXendit(int $max = 3): int
    {
        if (Xendit::unset()) {
            return 0;
        }
        $n = 0;
        try {
            $now = self::nowMs();
            foreach ($this->ems->emsColumns('orders', ['id', 'data'], ['payment_status' => 'Pending'], 'created_at DESC', 30) as $row) {
                if ($n >= $max) {
                    break;
                }
                $o = json_decode($row->data, true);
                if (! is_array($o)) {
                    continue;
                }
                $inv = $o['payment']['invoice_id'] ?? '';
                if ($inv === '' || str_starts_with($inv, 'SIM-')) {
                    continue;
                }
                if (isset($o['_cek_at']) && ($now - (float) $o['_cek_at']) < 60000) {
                    continue;
                }
                $o['_cek_at'] = $now;
                $this->saveOrder($o);
                $this->syncXendit($o);
                $n++;
            }
        } catch (Throwable) {
            // never fail the request it rides on
        }

        return $n;
    }

    // ───────────────────────────── events ──

    /** Only management-approved events are sold: Upcoming (and legacy Today). */
    private static function sellable(string $status): bool
    {
        return $status === 'Upcoming' || $status === 'Today';
    }

    public function events(): array
    {
        $this->sweepPendingXendit(2);
        $out = [];
        foreach ($this->ems->emsRows('events', [], 'start_datetime ASC') as $e) {
            if (! self::sellable((string) ($e['status'] ?? ''))) {
                continue;
            }
            $out[] = $this->summary($e);
        }

        return $out;
    }

    /** An allow-list of fields, never "everything minus secrets". */
    private function summary(array $e): array
    {
        $eid = $e['id'];
        $classes = $this->classes($eid);
        $left = $this->remaining($eid);
        $prices = [];
        foreach ($classes as $c) {
            if ((int) $c['price'] > 0) {
                $prices[] = (int) $c['price'];
            }
        }

        return [
            'id' => $eid,
            'title' => $e['title'] ?? '',
            'category' => $e['category'] ?? '',
            'start' => $e['start_datetime'] ?? '',
            'end' => $e['end_datetime'] ?? '',
            'venue' => $e['venue'] ?? 'Laksamana Muda',
            'poster' => $e['poster'] ?? '',
            'poster_img' => ! empty($e['poster_img']['key']),
            'desc' => $e['description'] ?? '',
            'capacity' => (int) ($e['capacity'] ?? 0),
            'price_from' => $prices ? min($prices) : 0,
            'is_ticketed' => ! empty($e['is_ticketed']),
            'classes' => array_map(fn ($c) => [
                'id' => $c['id'], 'name' => $c['name'], 'price' => (int) $c['price'],
                'quota' => (int) $c['quota'], 'sold' => (int) $c['sold'],
                'sisa' => $left[$c['id']] ?? 0,
                'is_seated' => ! empty($c['is_seated']),
                'benefit' => $c['benefit'] ?? '',
                'description' => $c['description'] ?? '',
            ], $classes),
        ];
    }

    public function event(string $id): ?array
    {
        $rows = $this->ems->emsRows('events', ['id' => $id]);
        if (! $rows || ! self::sellable((string) ($rows[0]['status'] ?? ''))) {
            return null;
        }

        return $this->summary($rows[0]);
    }

    /** An event whatever its status: a past or cancelled event still belongs in a Buyer's history. */
    public function eventAnyStatus(string $id): ?array
    {
        $rows = $this->ems->emsRows('events', ['id' => $id]);

        return $rows ? $this->summary($rows[0]) : null;
    }

    private function classes(string $eid): array
    {
        return $this->ems->emsRows('ticketClasses', ['event_id' => $eid]);
    }

    /** Sellable tickets per class: quota - sold, minus live Pending orders (they already hold tickets). */
    private function remaining(string $eid): array
    {
        $out = [];
        foreach ($this->classes($eid) as $c) {
            $out[$c['id']] = max(0, (int) $c['quota'] - (int) $c['sold']);
        }
        $now = time();
        foreach ($this->ems->emsRows('orders', ['event_id' => $eid, 'payment_status' => 'Pending']) as $o) {
            if (empty($o['items'])) {
                continue;
            }
            if (! empty($o['expires_at']) && strtotime($o['expires_at']) < $now) {
                continue;
            }
            foreach ($o['items'] as $it) {
                $cid = $it['class_id'] ?? '';
                if ($cid === '' || ! isset($out[$cid])) {
                    continue;
                }
                $out[$cid] = max(0, $out[$cid] - max(1, (int) ($it['capacity'] ?? 1)));
            }
        }

        return $out;
    }

    // ───────────────────────────── poster ──

    /** EMS keeps posters on disk: EVENT_FILES_DIR, else the event module's own <data_dir>/files. */
    public function eventFilesDir(): string
    {
        $d = (string) self::cfg('event_files_dir', '');
        if ($d !== '' && is_dir($d)) {
            return rtrim($d, '/\\');
        }
        $d = rtrim((string) Modules::dataDir('event'), '/\\').'/files';

        return is_dir($d) ? $d : '';
    }

    /** Second way: send the browser to the EMS API's own ?action=file. */
    public static function posterUrlEms(string $key): string
    {
        $api = (string) self::cfg('event_api_url', '');
        if ($api !== '') {
            return $api.'?action=file&key='.rawurlencode($key);
        }
        $h = strtolower(self::host());
        if ($h === '') {
            return '';
        }
        $host = str_starts_with($h, 'dev.') ? $h : ('office.'.preg_replace('/^www\./', '', $h));

        return (self::https() ? 'https' : 'http').'://'.$host.'/event-api-mysql/api.php?action=file&key='.rawurlencode($key);
    }

    /**
     * Only the poster of an event that is on sale, only by event id (the folder also
     * holds talent IDs and transfer proofs).
     *
     * @return array{file:string,type:string}|array{redirect:string}|null
     */
    public function poster(string $eid): ?array
    {
        $rows = $this->ems->emsRows('events', ['id' => $eid]);
        if (! $rows || ! self::sellable((string) ($rows[0]['status'] ?? ''))) {
            return null;
        }
        $key = (string) ($rows[0]['poster_img']['key'] ?? '');
        if ($key === '') {
            return null;
        }
        $dir = $this->eventFilesDir();
        $p = $dir === '' ? '' : $dir.'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $key);
        if ($p === '' || ! is_file($p)) {
            $alt = self::posterUrlEms($key);

            return $alt !== '' ? ['redirect' => $alt] : null;
        }
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

        return ['file' => $p, 'type' => $types[strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? 'application/octet-stream'];
    }

    // ───────────────────────────── seat map ──

    /** Copy of LDZ_RUANG in deploy/event/index.html. */
    private const ROOM_PALETTE = [
        ['/^stage$|panggung/i', '#3f4a5a', '#2b3340'],
        ['/^meja ?dj$/i', '#a9791f', '#7a560f'],
        ['/talent/i', '#6d5aa8', '#4e3f80'],
        ['/entrance|masuk|keluar/i', '#e08a1e', '#a86212'],
        ['/tangga/i', '#8a6242', '#63452e'],
        ['/operator|foh/i', '#2f3a4a', '#1d2530'],
        ['/^void/i', '#eceae4', '#c9c4b8'],
        ['/reguler|regular/i', '#5b7fa6', '#3f5d7d'],
        ['/^ext/i', '#f2c14e', '#c9432b'],
    ];

    private static function roomColour($label): ?array
    {
        $t = trim((string) $label);
        if ($t === '') {
            return null;
        }
        foreach (self::ROOM_PALETTE as $r) {
            if (preg_match($r[0], $t)) {
                return ['bg' => $r[1], 'tepi' => $r[2]];
            }
        }

        return null;
    }

    /** The EMS seat map objects as designed, plus each seat's live status for this buyer. */
    public function seatMap(string $eid, string $holdToken = ''): array
    {
        $this->sweepHolds();
        $seats = $this->ems->emsRows('seats', ['event_id' => $eid]);
        $byId = [];
        $byName = [];
        foreach ($this->classes($eid) as $c) {
            $byId[$c['id']] = $c;
            $byName[$c['name']] = $c;
        }

        // a live ticket means sold, whatever seats.status says
        $sold = [];
        foreach ($this->ems->emsColumns('tickets', ['seat_id', 'status'], ['seat_id' => ['notNull' => true]]) as $r) {
            if ($r->status === 'Cancelled') {
                continue;
            }
            $sold[$r->seat_id] = $r->status === 'Checked-In' ? 'checked' : 'sold';
        }
        // a hold bound to an order is awaiting payment, not a basket pick
        $hold = [];
        $holdExp = [];
        $holdOrder = [];
        foreach ($this->db()->select('SELECT seat_id, hold_token, expires_at, order_id FROM `'.TicketSchema::table('seat_holds').'` WHERE event_id = ?', [$eid]) as $r) {
            $hold[$r->seat_id] = $r->hold_token;
            $holdExp[$r->seat_id] = (float) $r->expires_at;
            $holdOrder[$r->seat_id] = isset($r->order_id) ? (string) $r->order_id : '';
        }

        $out = [];
        foreach ($seats as $s) {
            $id = $s['id'];
            $kind = $s['kind'] ?? 'seat';
            $tier = $s['tier'] ?? ($s['zone'] ?? '');
            $c = null;
            if (! empty($s['ticket_class_id']) && isset($byId[$s['ticket_class_id']])) {
                $c = $byId[$s['ticket_class_id']];
            } elseif ($tier !== '' && isset($byName[$tier])) {
                $c = $byName[$tier];
            }

            $status = 'available';
            if ($kind === 'area') {
                $status = 'area';
            } elseif (isset($sold[$id])) {
                $status = $sold[$id];
            } elseif (($s['status'] ?? '') === 'Sold') {
                $status = 'sold';
            } elseif (isset($hold[$id])) {
                $mine = ($holdToken !== '' && $hold[$id] === $holdToken && $holdOrder[$id] === '');
                $status = $mine ? 'mine' : 'held';
            }

            // tables are furniture, not merchandise (decided 31 July 2026)
            $forSale = ($kind !== 'area' && $kind !== 'table');
            if ($kind === 'table' && $status === 'available') {
                $status = 'perabot';
            }
            $w = self::roomColour($s['table_no'] ?? '');

            $out[] = [
                'id' => $id,
                'kind' => $kind,
                'shape' => $s['shape'] ?? 'rect',
                'label' => $s['table_no'] ?? ($s['seat_no'] ?? ''),
                'tier' => $tier,
                'zone' => $s['zone'] ?? '',
                'floor' => (string) ($s['floor'] ?? '1'),
                'capacity' => (int) ($s['capacity'] ?? 1),
                'x' => (float) ($s['x'] ?? 0), 'y' => (float) ($s['y'] ?? 0),
                'w' => (float) ($s['w'] ?? 40), 'h' => (float) ($s['h'] ?? 40),
                'warna' => $s['warna'] ?? '',
                'warna_bg' => $w ? $w['bg'] : '',
                'warna_tepi' => $w ? $w['tepi'] : '',
                'pola' => $s['pola'] ?? '',
                'status' => $status,
                'dijual' => $forSale,
                'hold_exp' => ($status === 'mine' && isset($holdExp[$id])) ? $holdExp[$id] : 0,
                'price' => ($forSale && $c) ? (int) $c['price'] : 0,
                'class_id' => ($forSale && $c) ? $c['id'] : '',
            ];
        }

        return $out;
    }

    // ───────────────────────────── holds ──

    /** Lock + UNIQUE(seat_id): two buyers pressing the same seat in the same second is normal. */
    public function hold(string $eid, array $seatIds, string $holdToken): array
    {
        $this->sweepHolds();
        if ($holdToken === '') {
            $holdToken = self::randomToken(12);
        }
        $exp = self::nowMs() + (int) self::cfg('hold_minutes', 10) * 60000;
        $map = [];
        foreach ($this->seatMap($eid, $holdToken) as $s) {
            $map[$s['id']] = $s;
        }

        $ok = [];
        $refused = [];
        $this->locked(function () use ($seatIds, $map, $eid, $holdToken, $exp, &$ok, &$refused) {
            foreach ($seatIds as $sid) {
                $s = $map[$sid] ?? null;
                if (! $s) {
                    $refused[] = [$sid, 'tidak ada di denah'];

                    continue;
                }
                if (empty($s['dijual'])) {
                    $refused[] = [$sid, 'bukan tempat yang dijual'];

                    continue;
                }
                if ($s['status'] === 'sold' || $s['status'] === 'checked') {
                    $refused[] = [$sid, 'sudah terjual'];

                    continue;
                }
                if ($s['status'] === 'held') {
                    $refused[] = [$sid, 'sedang dipilih orang lain'];

                    continue;
                }
                if ($s['price'] <= 0) {
                    $refused[] = [$sid, 'belum punya harga'];

                    continue;
                }
                // ON DUPLICATE never takes over someone else's hold; only our own is extended
                if (TicketSchema::onCore()) {
                    // core: a fresh ULID `id` (the legacy id moves to legacy_id) and version = 1
                    $holdTable = TicketSchema::table('seat_holds');
                    $eid = RowSync::fit($this->db(), $holdTable, 'event_id', $eid);
                    $holdToken = RowSync::fit($this->db(), $holdTable, 'hold_token', $holdToken);
                    $this->db()->insert('INSERT INTO `'.$holdTable.'` (id,legacy_id,event_id,seat_id,hold_token,expires_at,created_at,version)
                        VALUES (?,?,?,?,?,?,?,1)
                        ON DUPLICATE KEY UPDATE
                          hold_token = IF(hold_token = VALUES(hold_token), hold_token, hold_token),
                          expires_at = IF(hold_token = VALUES(hold_token), VALUES(expires_at), expires_at)',
                        [TicketSchema::newId(), self::uid('hold'), $eid, $sid, $holdToken, $exp, self::nowMs()]);
                } else {
                    $this->db()->insert('INSERT INTO seat_holds (id,event_id,seat_id,hold_token,expires_at,created_at)
                        VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                          hold_token = IF(hold_token = VALUES(hold_token), hold_token, hold_token),
                          expires_at = IF(hold_token = VALUES(hold_token), VALUES(expires_at), expires_at)',
                        [self::uid('hold'), $eid, $sid, $holdToken, $exp, self::nowMs()]);
                }
                $row = $this->db()->selectOne('SELECT hold_token FROM `'.TicketSchema::table('seat_holds').'` WHERE seat_id = ?', [$sid]);
                if ($row && $row->hold_token === $holdToken) {
                    $ok[] = $sid;
                } else {
                    $refused[] = [$sid, 'sedang dipilih orang lain'];
                }
            }
        });

        return ['hold_token' => $holdToken, 'expires_at' => $exp, 'held' => $ok, 'ditolak' => $refused];
    }

    public function release(string $holdToken, ?array $seatIds = null): array
    {
        if ($holdToken === '') {
            return ['released' => 0];
        }
        if ($seatIds) {
            $in = implode(',', array_fill(0, count($seatIds), '?'));
            $n = $this->db()->delete('DELETE FROM `'.TicketSchema::table('seat_holds').'` WHERE hold_token = ? AND order_id IS NULL AND seat_id IN ('.$in.')', array_merge([$holdToken], $seatIds));
        } else {
            $n = $this->db()->delete('DELETE FROM `'.TicketSchema::table('seat_holds').'` WHERE hold_token = ? AND order_id IS NULL', [$holdToken]);
        }

        return ['released' => $n];
    }

    // ───────────────────────────── buyer session (read side; accounts are #40) ──

    public function buyerFromSession($token): ?array
    {
        if (! $token || is_array($token)) {
            return null;
        }
        $r = $this->db()->selectOne('SELECT user_id FROM `'.TicketSchema::table('tix_sessions').'` WHERE `'.TicketSchema::idCol('tix_sessions').'` = ? AND expires_at > ?', [(string) $token, self::nowMs()]);
        if (! $r) {
            return null;
        }
        // `id` is the legacy Buyer id on either connection, which is what the shop speaks
        $u = $this->db()->selectOne('SELECT `'.TicketSchema::idCol('tix_users').'` AS id, email, pass_hash, name, phone, created_at FROM `'.TicketSchema::table('tix_users').'` WHERE `'.TicketSchema::idCol('tix_users').'` = ?', [$r->user_id]);

        return $u ? (array) $u : null;
    }

    // ───────────────────────────── checkout ──

    /** trim((string)$v) of legacy; an array is a TypeError there, so it is here too. */
    private static function trimmed(array $b, string $k): string
    {
        $v = $b[$k] ?? '';
        if (is_array($v)) {
            throw new \TypeError('trim(): Argument #1 ($string) must be of type string, array given');
        }

        return trim((string) $v);
    }

    private static function paxCount(array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $n += max(1, (int) ($it['capacity'] ?? 1));
        }

        return $n;
    }

    /** Builds the Pending order, binds its holds, then asks Xendit (outside the lock) for the invoice. */
    public function checkout(array $b): array
    {
        $eid = $b['event_id'] ?? '';
        $tok = $b['hold_token'] ?? '';
        $name = self::trimmed($b, 'name');
        $email = self::trimmed($b, 'email');
        $phone = self::trimmed($b, 'phone');
        if ($name === '' || $email === '' || $phone === '') {
            throw new Exception('Nama, email, dan nomor HP wajib diisi.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Format email tidak valid.');
        }
        $buyer = $this->buyerFromSession($b['sesi'] ?? '');
        if (! $buyer) {
            throw new Exception('Silakan masuk dulu — tiket dicatat atas nama akunmu.');
        }
        $ev = $this->event((string) $eid);
        if (! $ev) {
            throw new Exception('Event tidak ditemukan atau belum dibuka untuk umum.');
        }

        $this->sweepHolds();
        // the seats paid for are those this token STILL holds, not bound to another order
        $seatIds = array_map(fn ($r) => $r->seat_id, $this->db()->select(
            'SELECT seat_id FROM `'.TicketSchema::table('seat_holds')."` WHERE hold_token = ? AND event_id = ? AND expires_at > ? AND (order_id IS NULL OR order_id = '')",
            [$tok, $eid, self::nowMs()]));

        $general = (isset($b['umum']) && is_array($b['umum'])) ? $b['umum'] : [];
        if (! $seatIds && ! $general) {
            throw new Exception('Belum ada tiket yang dipilih — atau kursi yang ditahan sudah kedaluwarsa. Silakan pilih ulang.');
        }

        // every "still available?" check up to the saved order runs in ONE lock; Xendit is called after it
        [$order, $oid, $ref, $access, $total] = $this->locked(function () use ($eid, $tok, $seatIds, $general, $name, $email, $phone, $b, $buyer) {
            $map = [];
            foreach ($this->seatMap((string) $eid, (string) $tok) as $s) {
                $map[$s['id']] = $s;
            }
            $items = [];
            $subtotal = 0;
            foreach ($seatIds as $sid) {
                $s = $map[$sid] ?? null;
                if (! $s || $s['price'] <= 0) {
                    throw new Exception('Ada kursi yang harganya belum ditetapkan. Hubungi admin.');
                }
                if ($s['status'] === 'sold' || $s['status'] === 'checked') {
                    throw new Exception('Kursi '.$s['label'].' keburu terjual. Silakan pilih ulang.');
                }
                $pax = ($s['kind'] === 'table') ? max(1, (int) $s['capacity']) : 1;
                $items[] = ['seat_id' => $sid, 'label' => $s['label'], 'tier' => $s['tier'],
                    'class_id' => $s['class_id'], 'kind' => $s['kind'],
                    'capacity' => $pax, 'price' => $s['price']];
                $subtotal += $s['price'];
            }

            if ($general) {
                $classes = [];
                foreach ($this->classes((string) $eid) as $c) {
                    $classes[$c['id']] = $c;
                }
                $left = $this->remaining((string) $eid);
                foreach ($general as $u) {
                    $cid = $u['class_id'] ?? '';
                    $qty = (int) ($u['qty'] ?? 0);
                    if (is_array($cid) || ! isset($classes[$cid])) {
                        throw new Exception('Kategori tiket tidak dikenal.');
                    }
                    $c = $classes[$cid];
                    if (! empty($c['is_seated'])) {
                        throw new Exception('Kategori "'.$c['name'].'" harus dipilih tempatnya di denah.');
                    }
                    if ((int) $c['price'] <= 0) {
                        throw new Exception('Kategori "'.$c['name'].'" belum punya harga. Hubungi admin.');
                    }
                    if ($qty < 1 || $qty > (int) self::cfg('max_per_pesanan', 10)) {
                        throw new Exception('Jumlah tiket "'.$c['name'].'" tidak masuk akal.');
                    }
                    $have = $left[$cid] ?? 0;
                    if ($qty > $have) {
                        throw new Exception('Sisa tiket "'.$c['name'].'" tinggal '.$have.' lembar.');
                    }
                    $items[] = ['seat_id' => '', 'label' => '', 'tier' => $c['name'],
                        'class_id' => $cid, 'kind' => 'general',
                        'capacity' => $qty, 'price' => (int) $c['price'] * $qty];
                    $subtotal += (int) $c['price'] * $qty;
                }
            }

            // admin fee per TICKET, not per order line
            $fee = (int) self::cfg('admin_fee', 0) * self::paxCount($items);
            $total = $subtotal + $fee;
            $oid = self::uid('ord');
            $ref = 'LM'.strtoupper(bin2hex(random_bytes(4)));
            $access = self::randomToken(18);
            $order = [
                'id' => $oid, 'event_id' => $eid, 'buyer_name' => $name, 'phone' => $phone, 'email' => $email,
                'birthdate' => '', 'notes' => $b['notes'] ?? '',
                'subtotal' => $subtotal, 'fee' => $fee, 'total' => $total,
                'payment_status' => 'Pending', 'payment_ref' => $ref,
                'recorded_by' => 'Website', 'recorded_via' => 'ticketing-web',
                'channel' => 'online', 'items' => $items, 'access_token' => $access,
                'user_id' => $buyer['id'],
                'expires_at' => gmdate('c', (int) (self::nowMs() / 1000) + self::payMinutes() * 60),
                'created' => gmdate('c'),
            ];
            $this->saveOrder($order);
            // bound holds are no longer swept: the buyer is on the payment page
            if ($seatIds) {
                $in = implode(',', array_fill(0, count($seatIds), '?'));
                $this->db()->update('UPDATE `'.TicketSchema::table('seat_holds')."` SET order_id = ?, expires_at = ? WHERE seat_id IN ($in)",
                    array_merge([$oid, self::nowMs() + self::payMinutes() * 60000], $seatIds));
            }

            return [$order, $oid, $ref, $access, $total];
        });

        // Xendit refused: the order is cancelled (Failed) and its holds released, never left hanging
        try {
            $inv = $this->invoice($order, $ev);
        } catch (Throwable $e) {
            $this->cancel($oid, 'FAILED');
            throw $e;
        }
        $order['payment'] = $inv;
        $this->saveOrder($order);

        return ['order_id' => $oid, 'ref' => $ref, 'access_token' => $access,
            'total' => $total, 'invoice_url' => $inv['invoice_url'] ?? ''];
    }

    public function saveOrder(array $o): void
    {
        $this->ems->emsSaveOrder($o);
    }

    // ───────────────────────────── upgrade ──

    private function ticketForUpgrade(string $ticketId, array $user): array
    {
        $tk = $this->ems->emsRow('tickets', $ticketId);
        if (! $tk) {
            throw new Exception('Tiket tidak ditemukan.');
        }
        if (($tk['status'] ?? '') !== 'Valid') {
            throw new Exception('Tiket ini tidak berlaku lagi.');
        }
        $o = $this->ems->emsRow('orders', (string) ($tk['order_item_id'] ?? ''));
        if (! $o) {
            throw new Exception('Pesanan tiket ini tidak ditemukan.');
        }
        if (($o['payment_status'] ?? '') !== 'Paid') {
            throw new Exception('Tiket ini belum lunas.');
        }
        if (empty($o['user_id']) || $o['user_id'] !== $user['id']) {
            throw new Exception('Tiket ini bukan milik akunmu.');
        }
        $ev = $this->event((string) $o['event_id']);
        if (! $ev) {
            throw new Exception('Event ini sudah tidak menerima perubahan tiket.');
        }

        return [$tk, $o, $ev];
    }

    /** What was PAID for one ticket, not today's class price. */
    public static function pricePaid(array $tk, array $o): int
    {
        foreach (($o['items'] ?? []) as $it) {
            if (! empty($tk['seat_id']) && ($it['seat_id'] ?? '') === $tk['seat_id']) {
                return (int) round(((int) ($it['price'] ?? 0)) / max(1, (int) ($it['capacity'] ?? 1)));
            }
            if (empty($tk['seat_id']) && ($it['class_id'] ?? '') === ($tk['ticket_class_id'] ?? '')) {
                return (int) round(((int) ($it['price'] ?? 0)) / max(1, (int) ($it['capacity'] ?? 1)));
            }
        }

        return 0;
    }

    /** Start an upgrade: hold the target seat, create the difference order, return its payment link. */
    public function startUpgrade(array $b): array
    {
        $user = $this->buyerFromSession($b['sesi'] ?? '');
        if (! $user) {
            throw new Exception('Silakan masuk dulu.');
        }
        $ticketId = isset($b['ticket_id']) ? (string) $b['ticket_id'] : '';
        $newSeat = isset($b['seat_id']) ? (string) $b['seat_id'] : '';
        if ($ticketId === '' || $newSeat === '') {
            throw new Exception('Tiket dan kursi tujuan wajib dipilih.');
        }
        [$tk, $o, $ev] = $this->ticketForUpgrade($ticketId, $user);

        [$order, $oid, $ref, $access, $diff] = $this->locked(function () use ($ticketId, $newSeat, $tk, $o, $user) {
            $this->sweepHolds();
            // one live upgrade per ticket
            foreach ($this->ems->emsRows('orders', ['payment_status' => 'Pending'], 'created_at DESC', 50) as $p) {
                if (($p['upgrade']['tiket_lama'] ?? '') === $ticketId) {
                    throw new Exception('Upgrade untuk tiket ini sedang menunggu pembayaran. Selesaikan atau tunggu waktunya habis.');
                }
            }
            $map = [];
            foreach ($this->seatMap((string) $o['event_id'], '') as $s) {
                $map[$s['id']] = $s;
            }
            $new = $map[$newSeat] ?? null;
            if (! $new || empty($new['dijual'])) {
                throw new Exception('Kursi tujuan tidak dijual.');
            }
            if ($new['status'] !== 'available') {
                throw new Exception('Kursi '.$new['label'].' sedang tidak tersedia.');
            }
            if (! empty($tk['seat_id']) && $newSeat === $tk['seat_id']) {
                throw new Exception('Itu kursimu sendiri.');
            }
            $paid = self::pricePaid($tk, $o);
            $diff = (int) $new['price'] - $paid;
            // a downgrade is a refund, which has its own crew-approved path
            if ($diff <= 0) {
                throw new Exception('Kursi itu tidak lebih tinggi dari tiketmu sekarang. Untuk pindah ke kelas yang lebih murah, hubungi kami.');
            }

            $exp = self::nowMs() + self::payMinutes() * 60000;
            $tokUp = 'up_'.self::randomToken(10);
            if (TicketSchema::onCore()) {
                // core: a fresh ULID `id` (the legacy id moves to legacy_id) and version = 1
                $this->db()->insert('INSERT INTO `'.TicketSchema::table('seat_holds').'` (id,legacy_id,event_id,seat_id,hold_token,expires_at,created_at,version)
                    VALUES (?,?,?,?,?,?,?,1)
                    ON DUPLICATE KEY UPDATE
                      hold_token = IF(hold_token = VALUES(hold_token), hold_token, hold_token)',
                    [TicketSchema::newId(), self::uid('hold'), $o['event_id'], $newSeat, $tokUp, $exp, self::nowMs()]);
            } else {
                $this->db()->insert('INSERT INTO seat_holds (id,event_id,seat_id,hold_token,expires_at,created_at)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      hold_token = IF(hold_token = VALUES(hold_token), hold_token, hold_token)',
                    [self::uid('hold'), $o['event_id'], $newSeat, $tokUp, $exp, self::nowMs()]);
            }
            $row = $this->db()->selectOne('SELECT hold_token FROM `'.TicketSchema::table('seat_holds').'` WHERE seat_id = ?', [$newSeat]);
            if (! $row || $row->hold_token !== $tokUp) {
                throw new Exception('Kursi '.$new['label'].' baru saja diambil orang lain. Pilih kursi lain.');
            }

            $oid = self::uid('ord');
            $ref = 'UP'.strtoupper(bin2hex(random_bytes(4)));
            $access = self::randomToken(18);
            $order = [
                'id' => $oid, 'event_id' => $o['event_id'], 'buyer_name' => $o['buyer_name'],
                'phone' => $o['phone'], 'email' => $o['email'],
                'subtotal' => $diff, 'fee' => 0, 'total' => $diff,
                'payment_status' => 'Pending', 'payment_ref' => $ref,
                'recorded_by' => 'Website', 'recorded_via' => 'ticketing-upgrade', 'channel' => 'online',
                'items' => [['seat_id' => $newSeat, 'label' => $new['label'], 'tier' => $new['tier'],
                    'class_id' => $new['class_id'], 'kind' => 'seat',
                    'capacity' => 1, 'price' => $diff]],
                'upgrade' => [
                    'tiket_lama' => $tk['id'], 'nomor_lama' => $tk['ticket_number'],
                    'seat_lama' => $tk['seat_id'] ?? '', 'kelas_lama' => $tk['ticket_class_id'] ?? '',
                    'label_lama' => $tk['seat_label'] ?? '', 'tier_lama' => $tk['tier'] ?? '',
                    'terbayar' => $paid, 'harga_baru' => (int) $new['price'],
                    'order_lama' => $o['id'], 'hold_token' => $tokUp,
                ],
                'access_token' => $access, 'user_id' => $user['id'],
                'expires_at' => gmdate('c', (int) (self::nowMs() / 1000) + self::payMinutes() * 60),
                'created' => gmdate('c'),
            ];
            $this->saveOrder($order);
            $this->db()->update('UPDATE `'.TicketSchema::table('seat_holds').'` SET order_id = ? WHERE seat_id = ?', [$oid, $newSeat]);

            return [$order, $oid, $ref, $access, $diff];
        });

        try {
            $inv = $this->invoice($order, $ev);
        } catch (Throwable $e) {
            $this->cancel($oid, 'FAILED'); // releases the target seat; the old ticket is untouched
            throw $e;
        }
        $order['payment'] = $inv;
        $this->saveOrder($order);

        return ['order_id' => $oid, 'ref' => $ref, 'access_token' => $access,
            'selisih' => $diff, 'invoice_url' => $inv['invoice_url'] ?? ''];
    }

    /** Inside the payment lock, after the new ticket is issued: cancel the old one, free its seat, move the quota. */
    private function applyUpgrade(array $o): void
    {
        $u = $o['upgrade'] ?? null;
        if (! $u || empty($u['tiket_lama'])) {
            return;
        }
        $tk = $this->ems->emsRow('tickets', (string) $u['tiket_lama']);
        if ($tk) {
            $tk['status'] = 'Cancelled';
            $tk['upgrade_ke'] = $o['id'];
            $this->ems->emsSetTicketStatus((string) $u['tiket_lama'], 'Cancelled', $tk, self::nowMs());
        }
        if (! empty($u['seat_lama'])) {
            $inUse = false;
            foreach ($this->ems->emsRows('tickets', ['seat_id' => $u['seat_lama']]) as $t) {
                if (($t['status'] ?? '') !== 'Cancelled') {
                    $inUse = true;
                }
            }
            if (! $inUse) {
                $s = $this->ems->emsRow('seats', (string) $u['seat_lama']);
                if ($s) {
                    $s['status'] = 'Available';
                    $this->ems->emsSetSeatStatus((string) $u['seat_lama'], 'Available', $s, self::nowMs());
                }
            }
        }
        if (! empty($u['kelas_lama'])) {
            $c = $this->ems->emsRow('ticketClasses', (string) $u['kelas_lama']);
            if ($c) {
                $c['sold'] = max(0, (int) $c['sold'] - 1);
                $this->ems->emsSetClassSold((string) $u['kelas_lama'], $c['sold'], $c, self::nowMs());
            }
        }
    }

    // ───────────────────────────── payment ──

    private function invoice(array $o, array $ev): array
    {
        if (self::simulation()) {
            return ['gateway' => 'simulasi', 'invoice_id' => 'SIM-'.$o['payment_ref'],
                'invoice_url' => self::siteUrl().'/#simbayar/'.$o['payment_ref'].'/'.$o['access_token'],
                'expiry_date' => '', 'status' => 'PENDING', 'simulasi' => true];
        }
        if (Xendit::unset()) {
            throw new Exception('Pembayaran belum dikonfigurasi di server: '.Xendit::mode());
        }
        $d = Xendit::create([
            'external_id' => $o['id'],
            'amount' => $o['total'],
            'payer_email' => $o['email'],
            'description' => $ev['title'].' — '.count($o['items']).' tiket',
            'invoice_duration' => self::payMinutes() * 60,
            'success_redirect_url' => self::siteUrl().'/#tiket/'.$o['payment_ref'].'/'.$o['access_token'],
            'failure_redirect_url' => self::siteUrl().'/#gagal/'.$o['payment_ref'],
            'customer' => ['given_names' => $o['buyer_name'], 'email' => $o['email'], 'mobile_number' => $o['phone']],
            'items' => array_map(fn ($it) => ['name' => $ev['title'].' · '.$it['label'], 'quantity' => 1, 'price' => $it['price'], 'category' => $it['tier']], $o['items']),
            'fees' => $o['fee'] > 0 ? [['type' => 'Biaya Admin', 'value' => $o['fee']]] : [],
        ]);

        return ['gateway' => 'xendit', 'invoice_id' => $d['id'], 'invoice_url' => $d['invoice_url'],
            'expiry_date' => $d['expiry_date'] ?? '', 'status' => 'PENDING'];
    }

    /** Is the invoice paid, asked directly to Xendit (a leaked callback token alone must not mint tickets). */
    private static function invoicePaid(string $invoiceId): ?array
    {
        if ($invoiceId === '' || str_starts_with($invoiceId, 'SIM-') || Xendit::unset()) {
            return null;
        }
        $d = Xendit::invoice($invoiceId);
        if ($d === null) {
            return null;
        }
        $st = strtoupper((string) ($d['status'] ?? ''));

        return ['lunas' => ($st === 'PAID' || $st === 'SETTLED'), 'status' => $st, 'data' => $d];
    }

    /** The Xendit webhook — the ONLY way an order becomes Paid. */
    public function webhook(array $body, string $headerToken): array
    {
        $cb = self::cfg('xendit_callback');
        if ($cb === null || $cb === '' || str_starts_with((string) $cb, 'ISI_')) {
            throw new Exception('XENDIT_CALLBACK belum diisi — webhook ditolak demi keamanan.');
        }
        if (! hash_equals((string) $cb, $headerToken)) {
            throw new Exception('Token callback salah.');
        }
        $oid = $body['external_id'] ?? '';
        $status = strtoupper((string) ($body['status'] ?? ''));
        if ($oid === '') {
            throw new Exception('external_id kosong.');
        }
        if ($status === 'PAID' || $status === 'SETTLED') {
            $o = $this->ems->emsRow('orders', (string) $oid);
            if (! $o) {
                throw new Exception('Pesanan tidak ditemukan: '.$oid);
            }
            $inv = (string) ($o['payment']['invoice_id'] ?? '');
            $check = self::invoicePaid($inv);
            if ($check === null && $inv !== '' && ! str_starts_with($inv, 'SIM-')) {
                throw new Exception('Belum bisa memastikan status ke Xendit — coba lagi.');
            }
            if ($check !== null && ! $check['lunas']) {
                throw new Exception('Xendit menyatakan invoice belum lunas ('.$check['status'].') — webhook diabaikan.');
            }

            return $this->markPaid((string) $oid, $check ? $check['data'] : $body);
        }
        if ($status === 'EXPIRED' || $status === 'FAILED') {
            return $this->cancel((string) $oid, $status);
        }

        return ['diabaikan' => $status];
    }

    /**
     * Pays an order: issues one ticket + QR per guest, marks seats Sold. IDEMPOTENT —
     * Xendit may send the same webhook many times.
     */
    public function markPaid(string $oid, array $body = []): array
    {
        return $this->locked(function () use ($oid, $body) {
            $o = $this->ems->emsRow('orders', $oid);
            if (! $o) {
                throw new Exception('Pesanan tidak ditemukan: '.$oid);
            }
            if ($o['payment_status'] === 'Paid') {
                return ['sudah' => true, 'order_id' => $oid];
            }
            $o['payment_status'] = 'Paid';
            $o['paid_at'] = gmdate('c');
            $o['payment'] = array_merge($o['payment'] ?? [], [
                'status' => 'PAID',
                'payment_method' => $body['payment_method'] ?? '',
                'channel' => $body['payment_channel'] ?? '',
                'paid_amount' => isset($body['paid_amount']) ? (int) $body['paid_amount'] : $o['total'],
            ]);

            $tickets = [];
            $no = $this->ems->emsCount('tickets');
            // one ticket PER GUEST; the number runs over the whole order
            $seq = 0;
            foreach ($o['items'] as $it) {
                $pax = max(1, (int) ($it['capacity'] ?? 1));
                $kind = $it['kind'] ?? ($pax > 1 ? 'table' : 'seat');
                for ($p = 1; $p <= $pax; $p++) {
                    $tk = [
                        'id' => self::uid('tk'), 'order_item_id' => $oid, 'ticket_class_id' => $it['class_id'],
                        'seat_id' => $it['seat_id'],
                        'ticket_number' => 'LM-'.(1000 + $no + $seq),
                        // fully random: a guessable token means a forgeable QR
                        'qr_token' => 'QR'.self::randomToken(20),
                        'status' => 'Valid', 'pdf_url' => '', 'seat_label' => $it['label'], 'tier' => $it['tier'],
                        'kind' => $kind, 'pax_no' => $p, 'pax_total' => $pax,
                        'buyer_name' => $o['buyer_name'], 'issued_at' => gmdate('c'),
                    ];
                    $this->ems->emsInsertTicket($tk, self::nowMs());
                    $tickets[] = $tk;
                    $seq++;
                }
                // Sold ONCE per place: a table stays one `seats` row
                if ($it['seat_id']) {
                    $s = $this->ems->emsRow('seats', (string) $it['seat_id']);
                    if ($s) {
                        $s['status'] = 'Sold';
                        $this->ems->emsSetSeatStatus((string) $it['seat_id'], 'Sold', $s, self::nowMs());
                    }
                }
            }
            $this->raiseSold($o['items']);
            $this->applyUpgrade($o);
            $o['email_eticket'] = app(TicketMail::class)->sendEticket($o, $tickets);
            $this->saveOrder($o);
            $this->db()->delete('DELETE FROM `'.TicketSchema::table('seat_holds').'` WHERE order_id = ?', [$oid]);

            return ['order_id' => $oid, 'tiket' => count($tickets)];
        });
    }

    /** Quota counts PEOPLE (EMS sums each object's capacity), not places. */
    private function raiseSold(array $items): void
    {
        $hit = [];
        foreach ($items as $it) {
            if (! $it['class_id']) {
                continue;
            }
            $hit[$it['class_id']] = ($hit[$it['class_id']] ?? 0) + max(1, (int) ($it['capacity'] ?? 1));
        }
        foreach ($hit as $cid => $n) {
            $c = $this->ems->emsRow('ticketClasses', (string) $cid);
            if (! $c) {
                continue;
            }
            $c['sold'] = (int) $c['sold'] + $n;
            $this->ems->emsSetClassSold((string) $cid, $c['sold'], $c, self::nowMs());
        }
    }

    public function cancel(string $oid, string $reason): array
    {
        $o = $this->ems->emsRow('orders', $oid);
        if (! $o) {
            return ['order_id' => $oid, 'tidak_ada' => true];
        }
        if ($o['payment_status'] === 'Paid') {
            return ['order_id' => $oid, 'sudah_lunas' => true];
        }
        $o['payment_status'] = ($reason === 'EXPIRED') ? 'Expired' : 'Failed';
        $this->saveOrder($o);
        $this->db()->delete('DELETE FROM `'.TicketSchema::table('seat_holds').'` WHERE order_id = ?', [$oid]);

        return ['order_id' => $oid, 'status' => $o['payment_status']];
    }

    /** Dev-only payment (XENDIT_MOCK): through the SAME markPaid() as the Xendit path. */
    public function simulatePayment(string $ref, string $access): array
    {
        if (! self::simulation()) {
            throw new Exception('Mode simulasi tidak aktif di server ini.');
        }
        $rows = $this->ems->emsRows('orders', ['payment_ref' => $ref]);
        if (! $rows) {
            throw new Exception('Pesanan tidak ditemukan.');
        }
        $o = $rows[0];
        if (! isset($o['access_token']) || ! hash_equals((string) $o['access_token'], $access)) {
            throw new Exception('Tautan tidak sah.');
        }

        return $this->markPaid($o['id'], ['payment_method' => 'SIMULASI', 'paid_amount' => $o['total']]);
    }

    // ───────────────────────────── rate limiting (tix_gagal) ──

    private static function failKey(string $kind, $who): string
    {
        $salt = self::cfg('xendit_callback') ?? 'lm';
        $ip = request()?->server('REMOTE_ADDR') ?? '?';

        return hash('sha256', $kind.'|'.strtolower(trim((string) $who)).'|'.$ip.'|'.$salt);
    }

    /** Refuse after $max failures of (kind, who, caller address) in $minutes; silently off without the table. */
    public function throttle(string $kind, $who, int $max = 8, int $minutes = 15): void
    {
        try {
            $n = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `'.TicketSchema::table('tix_gagal').'` WHERE kunci = ? AND at > ?',
                [self::failKey($kind, $who), self::nowMs() - $minutes * 60000])->c;
        } catch (QueryException) {
            return;
        }
        if ($n >= $max) {
            throw new Exception('Terlalu banyak percobaan. Coba lagi dalam '.$minutes.' menit.');
        }
    }

    public function recordFailure(string $kind, $who): void
    {
        try {
            if (TicketSchema::onCore()) {
                // core: a fresh ULID `id` (the legacy id moves to legacy_id) and version = 1
                $this->db()->insert('INSERT INTO `'.TicketSchema::table('tix_gagal').'` (id,legacy_id,kunci,at,version) VALUES (?,?,?,?,1)',
                    [TicketSchema::newId(), self::uid('g'), self::failKey($kind, $who), self::nowMs()]);
            } else {
                $this->db()->insert('INSERT INTO tix_gagal (id,kunci,at) VALUES (?,?,?)', [self::uid('g'), self::failKey($kind, $who), self::nowMs()]);
            }
            $this->db()->delete('DELETE FROM `'.TicketSchema::table('tix_gagal').'` WHERE at < ?', [self::nowMs() - 24 * 3600000]);
        } catch (QueryException) {
            // table missing
        }
    }

    private function rateLimiterOn(): bool
    {
        try {
            $this->db()->select('SELECT 1 FROM `'.TicketSchema::table('tix_gagal').'` LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // ───────────────────────────── order status & e-ticket ──

    /** Do not depend on the webhook alone: a Pending order asks Xendit when it is opened. */
    private function syncXendit(array $o): array
    {
        if ($o['payment_status'] !== 'Pending') {
            return $o;
        }
        $inv = (string) ($o['payment']['invoice_id'] ?? '');
        if ($inv === '' || str_starts_with($inv, 'SIM-') || Xendit::unset()) {
            return $o;
        }
        $d = Xendit::invoice($inv);
        if ($d === null) {
            return $o;
        }
        $st = strtoupper((string) ($d['status'] ?? ''));
        if ($st === 'PAID' || $st === 'SETTLED') {
            $this->markPaid($o['id'], $d);
            $r2 = $this->ems->emsRow('orders', (string) $o['id']);
            if ($r2) {
                return $r2;
            }
        } elseif ($st === 'EXPIRED') {
            $this->cancel($o['id'], 'EXPIRED');
        }

        return $o;
    }

    /** The order behind ref + access_token (an email alone is guessable); throttled per caller. */
    private function openOrder(string $ref, string $access): array
    {
        $this->throttle('tiket', $ref, 20, 10);
        $rows = $this->ems->emsRows('orders', ['payment_ref' => $ref]);
        if (! $rows) {
            $this->recordFailure('tiket', $ref);
            throw new Exception('Pesanan tidak ditemukan.');
        }
        $o = $rows[0];
        if (! isset($o['access_token']) || ! hash_equals((string) $o['access_token'], $access)) {
            $this->recordFailure('tiket', $ref);
            throw new Exception('Tautan tiket tidak sah.');
        }

        return $o;
    }

    /** The e-ticket PDF of a paid order (all its live tickets), as the mail attachment draws it. */
    public function eticketPdf(string $ref, string $access): array
    {
        $o = $this->syncXendit($this->openOrder($ref, $access));
        if ($o['payment_status'] !== 'Paid') {
            throw new Exception('Pesanan ini belum lunas.');
        }
        $tickets = array_values(array_filter(
            $this->ems->emsRows('tickets', ['order_item_id' => $o['id']], 'ticket_number'),
            fn ($t) => ($t['status'] ?? '') !== 'Cancelled'));
        $ev = $this->ems->emsRow('events', (string) $o['event_id']);

        return ['name' => TicketPdf::fileName($o), 'pdf' => TicketPdf::eticket($o, $tickets, $ev, 0)];
    }

    public function orderStatus(string $ref, string $access): array
    {
        $o = $this->syncXendit($this->openOrder($ref, $access));
        $ev = $this->event((string) $o['event_id']);
        $tickets = [];
        if ($o['payment_status'] === 'Paid') {
            foreach ($this->ems->emsRows('tickets', ['order_item_id' => $o['id']]) as $t) {
                $tickets[] = ['id' => $t['id'], 'ticket_number' => $t['ticket_number'], 'qr_token' => $t['qr_token'],
                    'terbayar' => self::pricePaid($t, $o),
                    'seat_label' => $t['seat_label'] ?? '',
                    'kind' => $t['kind'] ?? 'seat',
                    'pax_no' => (int) ($t['pax_no'] ?? 1),
                    'pax_total' => (int) ($t['pax_total'] ?? 1),
                    'tier' => $t['tier'] ?? '', 'status' => $t['status']];
            }
        }

        return [
            'ref' => $ref, 'status' => $o['payment_status'], 'total' => (int) $o['total'],
            'buyer' => $o['buyer_name'], 'email' => $o['email'],
            'event_id' => $o['event_id'],
            'upgrade' => $o['upgrade'] ?? null,
            'invoice_url' => $o['payment']['invoice_url'] ?? '',
            'event' => $ev ? ['title' => $ev['title'], 'start' => $ev['start'], 'venue' => $ev['venue']] : null,
            'items' => $o['items'], 'tickets' => $tickets,
        ];
    }
}
