<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\OfficeAccess;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;

/**
 * Lingkup for HRIS screens (CONTEXT.md): whose data a User may see or change.
 *
 *   sendiri — every crew member: their own punches and faces only.
 *   divisi  — a Kepala Divisi: their divisions' punches and queues (never
 *             their own queue decision — no self-approval).
 *   semua   — HR: module admins of hris plus the `hr` id list in settings.
 *
 * Enforced on the server; the subject is always the token holder.
 */
final class HrisLingkup
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly Divisi $divisi,
        private readonly JadwalService $jadwal,
        private readonly HrisSettingsService $settings,
    ) {}

    private static function id(array $me): string
    {
        return trim((string) ($me['id'] ?? ''));
    }

    public function isHr(array $me): bool
    {
        $id = self::id($me);
        if ($id === '') {
            return false;
        }
        if ($this->access->isModuleAdmin($id, 'hris')) {
            return true;
        }
        $hr = $this->settings->get('hr', []);

        return is_array($hr) && in_array($id, $hr, true);
    }

    public function isHead(array $me): bool
    {
        return $this->divisi->isHead(self::id($me));
    }

    /** @return array<int,string> divisions the user heads */
    public function headDivisi(array $me): array
    {
        return $this->divisi->headDivisi(self::id($me));
    }

    public function lingkup(array $me): string
    {
        if ($this->isHr($me)) {
            return 'semua';
        }

        return $this->isHead($me) ? 'divisi' : 'sendiri';
    }

    /**
     * User ids the caller may READ punches/faces of. null = everyone (HR
     * without a filter). Throws 403 when a head asks for a foreign user.
     *
     * @return array<int,string>|null
     */
    public function scopeUsers(array $me, ?string $requested): ?array
    {
        $id = self::id($me);
        if ($this->isHr($me)) {
            return ($requested !== null && $requested !== '') ? [$requested] : null;
        }
        if ($this->isHead($me)) {
            $allowed = $this->divisionMemberIds($me);
            if ($requested !== null && $requested !== '') {
                if (! in_array($requested, $allowed, true)) {
                    throw new HrisError('forbidden', 'Hanya HR dan Kepala Divisi yang boleh melihat data orang lain.', 403);
                }

                return [$requested];
            }

            return $allowed;
        }

        return [$id];
    }

    /** @return array<int,string>|null null = everyone (HR) */
    public function queueScope(array $me): ?array
    {
        if ($this->isHr($me)) {
            return null;
        }
        if ($this->isHead($me)) {
            return $this->divisionMemberIds($me);
        }
        throw new HrisError('forbidden', 'Antrean hanya untuk HR dan Kepala Divisi.', 403);
    }

    /** May $me decide the punch of $punchUserId? HR and heads may — never their own. */
    public function assertMayDecide(array $me, string $punchUserId): void
    {
        if ($punchUserId === self::id($me)) {
            throw new HrisError('forbidden', 'Absen sendiri tidak bisa diputus sendiri; minta HR lain atau Kepala Divisi.', 403);
        }
        if ($this->isHr($me)) {
            return;
        }
        if ($this->isHead($me)
            && in_array($punchUserId, $this->divisionMemberIds($me), true)) {
            return;
        }
        throw new HrisError('forbidden', 'Keputusan antrean hanya oleh HR atau Kepala Divisi yang bersangkutan.', 403);
    }

    /** May $me open the rows of $userId? Throws 403 otherwise. */
    public function assertCanSee(array $me, string $userId): void
    {
        $scope = $this->scopeUsers($me, $userId);
        if ($scope !== null && ! in_array($userId, $scope, true)) {
            throw new HrisError('forbidden', 'Tidak berhak melihat data ini.', 403);
        }
    }

    public function assertHr(array $me, string $apa): void
    {
        if (! $this->isHr($me)) {
            throw new HrisError('forbidden', $apa.' hanya bisa dilakukan HR/admin modul HRIS.', 403);
        }
    }

    /** @return array<int,string> division members + self (self always included) */
    private function divisionMemberIds(array $me): array
    {
        $id = self::id($me);
        $divs = $this->headDivisi($me);
        $out = [$id];
        try {
            foreach ($this->jadwal->rosterWithDivisi() as $r) {
                $r = (array) $r;
                $uid = trim((string) ($r['id'] ?? $r['user_id'] ?? ''));
                if ($uid === '' || $uid === $id) {
                    continue;
                }
                if (in_array((string) ($r['divisi'] ?? ''), $divs, true) && ! in_array($uid, $out, true)) {
                    $out[] = $uid;
                }
            }
        } catch (\Throwable) {
            // Roster unreadable: the head still sees their own rows.
        }

        return array_values($out);
    }
}
