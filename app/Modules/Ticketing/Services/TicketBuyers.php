<?php

namespace App\Modules\Ticketing\Services;

use App\Support\Modules;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Buyer accounts of the public shop (legacy daftar / masuk / keluar / lupaPassword /
 * resetPassword / saya / tiketSaya). A Buyer is NOT a User: its own tables
 * (tix_users, tix_sessions, tix_reset), so a leak on the public site never touches
 * the staff list. Sessions last 30 days; tokens are fully random.
 */
class TicketBuyers
{
    public function __construct(
        private readonly TicketShop $shop,
        private readonly TicketMail $mail,
    ) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('ticketing');
    }

    private static function email($e): string
    {
        return is_array($e) ? '' : strtolower(trim((string) $e));
    }

    /** (string)$v of legacy: an array reads as "Array". */
    private static function str(array $b, string $k): string
    {
        $v = $b[$k] ?? '';

        return is_array($v) ? 'Array' : (string) $v;
    }

    private function byEmail(string $email): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM tix_users WHERE email = ?', [self::email($email)]);

        return $r ? (array) $r : null;
    }

    private function byId(string $id): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM tix_users WHERE id = ?', [$id]);

        return $r ? (array) $r : null;
    }

    public static function public(?array $u): ?array
    {
        return $u ? ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'phone' => $u['phone']] : null;
    }

    private function newSession(string $userId): string
    {
        $tok = TicketShop::randomToken(24);
        $this->db()->insert('INSERT INTO tix_sessions (token,user_id,expires_at,created_at) VALUES (?,?,?,?)',
            [$tok, $userId, TicketShop::nowMs() + 30 * 24 * 3600 * 1000, TicketShop::nowMs()]);
        $this->db()->delete('DELETE FROM tix_sessions WHERE expires_at < ?', [TicketShop::nowMs()]);

        return $tok;
    }

    /** Orders placed with this email before the account existed become the Buyer's. */
    private function linkOldOrders(string $userId, string $email): int
    {
        $n = 0;
        foreach ($this->db()->select('SELECT id, data FROM orders WHERE email = ?', [$email]) as $row) {
            $o = json_decode($row->data, true);
            if (! is_array($o) || ! empty($o['user_id'])) {
                continue;
            }
            $o['user_id'] = $userId;
            $this->db()->update('UPDATE orders SET updated_at = ?, data = ? WHERE id = ?',
                [TicketShop::nowMs(), json_encode($o, JSON_UNESCAPED_UNICODE), $row->id]);
            $n++;
        }

        return $n;
    }

    public function register(array $b): array
    {
        $email = self::email($b['email'] ?? '');
        $pass = self::str($b, 'password');
        $name = trim(self::str($b, 'name'));
        $phone = trim(self::str($b, 'phone'));
        if ($name === '' || $email === '') {
            throw new Exception('Nama dan email wajib diisi.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Format email tidak valid.');
        }
        // eight characters, not complexity rules (those end up written in the phone's notes)
        if (strlen($pass) < 8) {
            throw new Exception('Password minimal 8 karakter.');
        }
        if ($this->byEmail($email)) {
            throw new Exception('Email ini sudah terdaftar. Silakan masuk.');
        }
        $id = TicketShop::uid('usr');
        $this->db()->insert('INSERT INTO tix_users (id,email,pass_hash,name,phone,created_at) VALUES (?,?,?,?,?,?)',
            [$id, $email, password_hash($pass, PASSWORD_DEFAULT), $name, $phone, TicketShop::nowMs()]);
        $n = $this->linkOldOrders($id, $email);

        return ['user' => self::public($this->byId($id)), 'token' => $this->newSession($id), 'pesanan_lama' => $n];
    }

    public function login(array $b): array
    {
        $email = self::email($b['email'] ?? '');
        $pass = self::str($b, 'password');
        $this->shop->throttle('masuk', $email);
        $u = $this->byEmail($email);
        // one message for unknown email and wrong password: never reveal who has an account
        if (! $u || ! password_verify($pass, (string) $u['pass_hash'])) {
            $this->shop->recordFailure('masuk', $email);
            throw new Exception('Email atau password salah.');
        }
        $this->linkOldOrders($u['id'], $u['email']);

        return ['user' => self::public($u), 'token' => $this->newSession($u['id'])];
    }

    public function logout(string $tok): array
    {
        if ($tok !== '') {
            $this->db()->delete('DELETE FROM tix_sessions WHERE token = ?', [$tok]);
        }

        return ['keluar' => true];
    }

    /** Always the same answer, registered or not; throttled so no inbox can be flooded through it. */
    public function forgotPassword($email): array
    {
        $this->shop->throttle('lupa', $email, 5, 30);
        $this->shop->recordFailure('lupa', $email); // every request counts
        $u = $this->byEmail(is_array($email) ? '' : (string) $email);
        if ($u) {
            // a new request voids the old link
            $this->db()->delete('DELETE FROM tix_reset WHERE user_id = ?', [$u['id']]);
            $tok = TicketShop::randomToken(24);
            $this->db()->insert('INSERT INTO tix_reset (token,user_id,expires_at,created_at) VALUES (?,?,?,?)',
                [$tok, $u['id'], TicketShop::nowMs() + 3600000, TicketShop::nowMs()]);
            if (TicketMail::ready()) {
                try {
                    $this->mail->send($u['email'], 'Atur ulang password — Laksamana Muda Ticketing',
                        TicketMail::resetHtml($u['name'], TicketShop::siteUrl().'/#reset/'.rawurlencode($tok)));
                } catch (Throwable) {
                    // swallowed: a send failure must not reveal that the email is registered
                }
            }
        }

        return ['terkirim' => true,
            'pesan' => 'Kalau email itu terdaftar, tautan pengaturan ulang sudah kami kirim. Cek inbox dan folder spam.'];
    }

    /** One-time link; every old session of the Buyer is cut (a reset usually means a suspected takeover). */
    public function resetPassword($tok, $new): array
    {
        if (strlen(is_array($new) ? '' : (string) $new) < 8) {
            throw new Exception('Password minimal 8 karakter.');
        }
        $this->db()->delete('DELETE FROM tix_reset WHERE expires_at < ?', [TicketShop::nowMs()]);
        $r = is_array($tok) ? null : $this->db()->selectOne('SELECT user_id FROM tix_reset WHERE token = ? AND expires_at > ?', [(string) $tok, TicketShop::nowMs()]);
        if (! $r) {
            throw new Exception('Tautan sudah kedaluwarsa atau pernah dipakai. Minta tautan baru.');
        }
        $this->db()->update('UPDATE tix_users SET pass_hash = ? WHERE id = ?', [password_hash((string) $new, PASSWORD_DEFAULT), $r->user_id]);
        $this->db()->delete('DELETE FROM tix_reset WHERE token = ?', [(string) $tok]);
        $this->db()->delete('DELETE FROM tix_sessions WHERE user_id = ?', [$r->user_id]);
        $u = $this->byId($r->user_id);

        return ['user' => self::public($u), 'token' => $this->newSession($u['id'])];
    }

    /** "Kursi 78, 70" / "3 tiket Reguler". */
    private static function places(array $items): string
    {
        $seats = [];
        $general = [];
        foreach ($items as $it) {
            $kind = $it['kind'] ?? 'seat';
            if ($kind === 'general') {
                $n = $it['tier'] ?? 'Reguler';
                $general[$n] = ($general[$n] ?? 0) + max(1, (int) ($it['capacity'] ?? 1));
            } elseif (isset($it['label']) && $it['label'] !== '') {
                $seats[] = ($kind === 'table' ? 'Meja ' : '').$it['label'];
            }
        }
        $parts = [];
        if ($seats) {
            $parts[] = 'Kursi '.implode(', ', $seats);
        }
        foreach ($general as $n => $c) {
            $parts[] = $c.' tiket '.$n;
        }

        return implode(' · ', $parts);
    }

    /** The Buyer's orders (by email AND by user_id), newest first, each with its own access token. */
    public function myTickets(?array $u): array
    {
        if (! $u) {
            throw new Exception('Silakan masuk dulu.');
        }
        $this->shop->sweepPendingXendit(5);
        $orders = [];
        foreach ($this->db()->select('SELECT id, data FROM orders WHERE email = ? ORDER BY created_at DESC', [$u['email']]) as $row) {
            $o = json_decode($row->data, true);
            if (is_array($o)) {
                $orders[$row->id] = $o;
            }
        }
        // orders bought for someone else's email still belong to the Buyer (user_id is inside the JSON)
        foreach ($this->db()->select('SELECT id, data FROM orders ORDER BY created_at DESC LIMIT 1000') as $row) {
            if (isset($orders[$row->id])) {
                continue;
            }
            $o = json_decode($row->data, true);
            if (is_array($o) && ! empty($o['user_id']) && $o['user_id'] === $u['id']) {
                $orders[$row->id] = $o;
            }
        }
        $list = array_values($orders);
        usort($list, fn ($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));

        $out = [];
        foreach ($list as $o) {
            $ev = $this->shop->eventAnyStatus((string) $o['event_id']);
            $items = $o['items'] ?? [];
            $out[] = [
                'ref' => $o['payment_ref'], 'access_token' => $o['access_token'],
                'status' => $o['payment_status'], 'total' => (int) $o['total'],
                'created' => $o['created'] ?? '',
                'jml_tiket' => array_sum(array_map(fn ($it) => max(1, (int) ($it['capacity'] ?? 1)), (array) $items)),
                'tempat' => self::places((array) $items),
                'event' => $ev ? ['title' => $ev['title'], 'start' => $ev['start'], 'venue' => $ev['venue']] : null,
            ];
        }

        return $out;
    }
}
