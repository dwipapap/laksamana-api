<?php

declare(strict_types=1);

namespace App\Erp\Master\Imports;

use Illuminate\Support\Facades\DB;

/**
 * Resolves a legacy reference to a person into a core `user` id, for every v2
 * importer (docs/erp/orang-divisi.md "Nama sebagai rujukan"): the Office id
 * first, then the username, then the name or display name (case and spacing
 * ignored). A name shared by two Users is ambiguous and resolves to nothing;
 * the caller keeps the legacy text in its `*_impor` column and reports it.
 */
final class PencocokUser
{
    /** @var array<string, string> legacy id => user id */
    private array $byLegacy = [];

    /** @var array<string, string> lower username => user id */
    private array $byUsername = [];

    /** @var array<string, list<string>> normalised name => user ids */
    private array $byNama = [];

    private bool $loaded = false;

    /** Forget the cache, e.g. after `core:import account` ran again. */
    public function reset(): void
    {
        $this->loaded = false;
        $this->byLegacy = $this->byUsername = $this->byNama = [];
    }

    /** By Office id (`user.legacy_id`) only. */
    public function id(?string $legacyId): ?string
    {
        $this->load();
        $legacyId = trim((string) $legacyId);

        return $legacyId === '' ? null : ($this->byLegacy[$legacyId] ?? null);
    }

    /** By Office id, then username, then name; NULL when unknown or ambiguous. */
    public function cocok(?string $legacyId = null, ?string $nama = null): ?string
    {
        if ($id = $this->id($legacyId)) {
            return $id;
        }
        $nama = trim((string) $nama);
        if ($nama === '') {
            return null;
        }
        if ($id = $this->id($nama)) { // some legacy fields hold an Office id in a "name" slot
            return $id;
        }
        if ($id = $this->byUsername[mb_strtolower($nama)] ?? null) {
            return $id;
        }
        $hits = $this->byNama[self::norm($nama)] ?? [];

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** Whether a name matches several Users (worth saying in an issue line). */
    public function ambigu(?string $nama): bool
    {
        $this->load();

        return count($this->byNama[self::norm((string) $nama)] ?? []) > 1;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        foreach (DB::connection('core')->table('user')->get(['id', 'legacy_id', 'username', 'nama', 'nama_tampilan']) as $u) {
            $this->byLegacy[(string) $u->legacy_id] = (string) $u->id;
            if ((string) $u->username !== '') {
                $this->byUsername[mb_strtolower((string) $u->username)] = (string) $u->id;
            }
            foreach (array_unique([self::norm((string) $u->nama), self::norm((string) $u->nama_tampilan)]) as $k) {
                if ($k !== '' && ! in_array((string) $u->id, $this->byNama[$k] ?? [], true)) {
                    $this->byNama[$k][] = (string) $u->id;
                }
            }
        }
        $this->loaded = true;
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
