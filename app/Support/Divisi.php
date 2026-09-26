<?php

namespace App\Support;

use App\Modules\Jadwal\Services\HeadDirectory;

/**
 * Divisi, Kepala Divisi and Penempatan Divisi — the ONE copy in this API.
 *
 * Divisi resolution (Penempatan Divisi → Office/Kantor words → synonym
 * words → Nonshift), the heads list and the Tim word lists for Akses Bawaan
 * used to live in three places (JadwalService, HeadDirectory callers,
 * OfficeAccess). They are united here so the identity cutover (PRD #1) can
 * move them into `divisi`, `divisi_kata`, `kepala_divisi` and
 * `penempatan_divisi` without touching the callers.
 *
 * Twins that MUST be changed in pairs with the lists below (same rule as the
 * legacy file had it):
 *  - `JDW_DIV_SINONIM` / `JDW_KANTOR_KATA` in
 *    laksamana-office/jadwal-mysql/lib_jadwal_mysql.php
 *  - `DIV_SINONIM` / `KANTOR_KATA` in deploy/jadwal/index.html
 *  If one copy drifts, a head the screen shows owning a division is rejected
 *  by the backend when saving — with no place to report the confusion.
 *
 * Matching is always WHOLE-WORD on the Tim column ("Barista" is not "bar"),
 * and Office/Kantor wins over every division word ("Kasir Office" is office
 * staff, never Cashier shift crew).
 */
final class Divisi
{
    /** Division synonyms, in legacy order (first match wins). */
    public const SYNONYMS = [
        'bar' => ['bar', 'bartender'],
        'kitchen' => ['kitchen', 'dapur'],
        'floor' => ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess'],
        'cashier' => ['cashier', 'kasir'],
    ];

    /** Office/Kantor wins over every division word. */
    public const OFFICE_WORDS = ['office', 'kantor'];

    public const NONSHIFT = 'nonshift';

    /** Tim words that grant Akses Bawaan to the jadwal Modul. */
    public const TIM_BAWAAN_JADWAL = ['kitchen', 'dapur', 'bar', 'bartender', 'floor', 'service', 'waiter', 'waitress',
        'host', 'hostess', 'cashier', 'kasir', 'hrd', 'hr', 'ceo'];

    /** Tim words that grant Akses Bawaan to the dw Modul. */
    public const TIM_BOLEH_DW = ['hrd', 'hr', 'ceo'];

    /** Tim words that make a User a roster manager (admin of jadwal). */
    public const TIM_ADMIN_ROSTER = ['hrd', 'hr'];

    /** Modules a roster manager administers without an explicit grant. */
    public const MODUL_ADMIN_BAWAAN = ['jadwal', 'dw'];

    public function __construct(private readonly HeadDirectory $heads) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** Whole-word match on the Tim column: "Barista" is not "bar". */
    public static function timCocok(?string $keterangan, array $daftar): bool
    {
        $kata = preg_split('/[^a-z]+/', strtolower((string) $keterangan), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($daftar as $x) {
            if (in_array($x, $kata, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A crew member's Divisi, in the same order as divisiDari() on screen and
     * jdw_divisi_user() in the legacy lib: Penempatan Divisi (manual
     * override) wins over everything, then the Tim words, else Nonshift.
     *
     * @param  array<string,string>  $override  divOverride map (uid => divisi)
     * @param  array<string,mixed>|null  $rosterRow  roster row (needs `keterangan`), null when unknown
     * @param  array<string,array<int,string>>  $synonyms  Divisi words, first match wins (core: `divisi_kata` by `divisi.urutan`)
     * @param  array<int,string>  $officeWords  words that make anyone Nonshift (core: `divisi_kata` without a Divisi)
     */
    public static function resolve(string $uid, array $override, ?array $rosterRow,
        array $synonyms = self::SYNONYMS, array $officeWords = self::OFFICE_WORDS): string
    {
        if (isset($override[$uid]) && $override[$uid] !== '') {
            return (string) $override[$uid];
        }
        if ($rosterRow === null) {
            return self::NONSHIFT;
        }
        $kata = preg_split('/[^a-z]+/', strtolower((string) ($rosterRow['keterangan'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($officeWords as $x) {
            if (in_array($x, $kata, true)) {
                return self::NONSHIFT;
            }
        }
        foreach ($synonyms as $kode => $sin) {
            foreach ($sin as $x) {
                if (in_array($x, $kata, true)) {
                    return $kode;
                }
            }
        }

        return self::NONSHIFT;
    }

    // ---------------------------------------------------------------- heads

    /** @return array<string,array<int,string>> userId => [divisi…] */
    public function headMap(): array
    {
        return $this->heads->map();
    }

    /** @return array<int,string> divisions a user heads (empty when none) */
    public function headDivisi(string $userId): array
    {
        $p = $this->headMap();

        return isset($p[$userId]) ? array_values($p[$userId]) : [];
    }

    /** Heads ANY division. */
    public function isHead(string $userId): bool
    {
        $p = $this->headMap();

        return isset($p[$userId]) && count($p[$userId]) > 0;
    }

    /** Heads THIS division ($u is a whoami-shaped identity, may be null). */
    public function isHeadOf(?array $u, string $div): bool
    {
        if (! $u) {
            return false;
        }

        return in_array($div, $this->headDivisi((string) ($u['id'] ?? '')), true);
    }

    /** Heads some division (whoami-shaped identity, may be null). */
    public function isHeadAnywhere(?array $u): bool
    {
        if (! $u) {
            return false;
        }
        foreach ($this->headMap() as $daftar) {
            foreach (is_array($daftar) ? $daftar : [] as $id) {
                if ((string) $id === (string) $u['id']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function divHasHead(string $div): bool
    {
        foreach ($this->headMap() as $daftar) {
            if (is_array($daftar) && in_array($div, $daftar, true)) {
                return true;
            }
        }

        return false;
    }

    public function anyHead(): bool
    {
        foreach ($this->headMap() as $d) {
            if (is_array($d) && count($d)) {
                return true;
            }
        }

        return false;
    }
}
