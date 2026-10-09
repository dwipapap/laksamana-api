<?php

namespace App\Modules\Reservasi\Services;

/**
 * Who may DELETE a reservation row (#241).
 *
 * The old Office only drew the Hapus button for `CAN.deleteReservation`, which
 * is the extra permission row `inputDelete` of the Akses Halaman matrix
 * (laksamana-office deploy/reservasi/index.html:1013-1024, pagePerm :3523):
 * admin always may, viewer never may, everyone else needs level 2 (Ubah) in
 * `master.perms.inputDelete[role]`. The legacy backend never enforced it —
 * saveAll took the whole state from whoever sent it — so a host could delete
 * any row by hand. v1 enforces it for this one action: a deletion cannot be
 * undone, unlike every other write the matrix covers.
 *
 * The rest of the matrix stays client-side, as before (see routes/v1.php).
 */
final class HapusReservasiGate
{
    public const ROW = 'inputDelete';

    private const EDIT = 2;

    /** The old DEFAULT_PERMS for this row, used only while master.perms does not exist yet. */
    private const DEFAULT = ['manager' => 2, 'admin' => 2];

    /**
     * @param  string  $role  'admin' for a module admin of reservasi, else master.users[id].role ('' when unknown)
     * @param  mixed  $perms  master.perms as stored
     */
    public static function allowed(string $role, mixed $perms): bool
    {
        if ($role === 'admin') {
            return true;
        }
        // viewer is capped at view in code, not in data; an unknown person has no row.
        if ($role === 'viewer' || $role === '') {
            return false;
        }
        if (! is_array($perms)) {
            return (self::DEFAULT[$role] ?? 1) >= self::EDIT;
        }
        // A stored matrix without this row (or this role) falls back to view,
        // exactly like pagePerm: the old check was `typeof p[role]==="number"`.
        $row = $perms[self::ROW] ?? null;
        $level = is_array($row) && (is_int($row[$role] ?? null) || is_float($row[$role] ?? null)) ? (int) $row[$role] : 1;

        return $level >= self::EDIT;
    }
}
