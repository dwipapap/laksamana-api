<?php

namespace App\Auth;

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
    public function __construct(private readonly AccountRepository $users) {}

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** Never throws: a failure here must not fail the login (legacy rule) — returns ''. */
    public function create(string $userId): string
    {
        try {
            $token = bin2hex(random_bytes(32));
            $now = self::nowMs();
            $this->users->pruneExpiredSessions($now);
            $this->users->insertSession($token, $userId, $now + 24 * 3600 * 1000);

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
            $r = $this->users->findSession($token);
            if (! $r) {
                return null;
            }
            if ((int) $r['expiry'] < self::nowMs()) {
                $this->users->deleteSession($token);

                return null;
            }
            $u = $this->users->userById((string) $r['user_id']);

            return ($u && (int) $u['active'] === 1) ? $u : null;
        } catch (Throwable) {
            return null; // cannot verify = not recognised (fails closed)
        }
    }

    public function revoke(?string $token): void
    {
        $this->users->deleteSession((string) $token);
    }
}
