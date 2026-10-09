<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Imports;

use App\Erp\Master\Imports\PencocokUser;
use Illuminate\Support\Facades\DB;

/**
 * BD keeps its own people list (bd.people) and every PIC, approver and
 * requester in BD points at it by id; each person carries the Office user
 * id (officeUserId). This resolves a BD people id, or failing that a name,
 * to a core user id — shared by the erp-po-proyek and erp-kerja-tim imports.
 */
final class BdPeople
{
    /** @var array<string, array{office: string, nama: string}>|null */
    private ?array $people = null;

    public function __construct(private readonly PencocokUser $users) {}

    /** @return array{0: ?string, 1: ?string} [user id, legacy text when it matched no User] */
    public function user(?string $peopleIdOrName): array
    {
        $this->people ??= DB::connection('legacy_bd')->table('people')->get(['id', 'office_user_id', 'name'])
            ->mapWithKeys(fn ($p) => [(string) $p->id => ['office' => (string) $p->office_user_id, 'nama' => (string) $p->name]])->all();
        $v = trim((string) $peopleIdOrName);
        if ($v === '') {
            return [null, null];
        }
        $p = $this->people[$v] ?? null;
        $id = $p ? ($this->users->id($p['office']) ?? $this->users->cocok(null, $p['nama'])) : $this->users->cocok($v, $v);

        return [$id, $id ? null : mb_substr($p['nama'] ?? $v, 0, 120)];
    }

    public function reset(): void
    {
        $this->people = null;
        $this->users->reset();
    }
}
