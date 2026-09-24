<?php

namespace App\Auth;

use App\Support\Modules;
use Throwable;

/**
 * The OLD Office session tokens (`sessions` table in the account DB,
 * 64-hex, 24 h) that every existing frontend keeps in `lm_session.token` and
 * sends as `sesi`. The legacy compat routes must keep accepting them, so this
 * is a straight port of buat_token_sesi / user_dari_token / aksi_logout.
 *
 * New apps use Sanctum instead (App\Auth\AccountUser::createToken).
 */
class LegacySessions
{
    public function __construct(private readonly OfficeAccess $access) {}

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** Never throws: a failure here must not fail the login (legacy rule) — returns ''. */
    public function create(string $userId): string
    {
        try {
            $db = Modules::db('account');
            $token = bin2hex(random_bytes(32));
            $now = self::nowMs();
            $db->delete('DELETE FROM `sessions` WHERE expiry < ?', [$now]);
            $db->insert('INSERT INTO `sessions` (token, user_id, expiry) VALUES (?, ?, ?)',
                [$token, trim($userId), $now + 24 * 3600 * 1000]);

            return $token;
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Owner of a token, or null when unknown / expired / account inactive.
     * Deactivating an account takes effect immediately, not when its token expires.
     *
     * @return array<string,mixed>|null user row
     */
    public function user(?string $token): ?array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }
        try {
            $db = Modules::db('account');
            $r = $db->selectOne('SELECT user_id, expiry FROM `sessions` WHERE token = ? LIMIT 1', [$token]);
            if (! $r) {
                return null;
            }
            if ((int) $r->expiry < self::nowMs()) {
                $db->delete('DELETE FROM `sessions` WHERE token = ?', [$token]);

                return null;
            }
            $u = $this->access->userById((string) $r->user_id);

            return ($u && (int) $u['active'] === 1) ? $u : null;
        } catch (Throwable) {
            return null; // cannot verify = not recognised (fails closed)
        }
    }

    public function revoke(?string $token): void
    {
        $t = trim((string) $token);
        if ($t !== '') {
            Modules::db('account')->delete('DELETE FROM `sessions` WHERE token = ?', [$t]);
        }
    }
}
