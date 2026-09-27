<?php

namespace App\Modules\Stock\Services;

use stdClass;

/**
 * Single-record access for /api/v1/stock over the Usage Panel records (usage,
 * waste, handovers, opname) and the Central Kitchen movements, on top of the
 * same services the compat routes use.
 *
 * Version = hash of the stored row (photos enter as their MD5, so lists stay
 * light). Writes lock the row (SELECT … FOR UPDATE), check the version and the
 * team scope, then run the legacy save with $verified = true.
 *
 * A PATCH is the current record with the sent fields laid over it, so every
 * legacy validation still applies; `foto` is only written when sent.
 */
class StockEntryRecords
{
    public function __construct(
        private readonly StockEntries $entries,
        private readonly StockCk $ck,
    ) {}

    /** kind => [table, version SELECT, team-scoped, has photo] */
    private const KINDS = [
        'usage' => ['usage_events', 'SELECT {*usage_events} FROM {usage_events}', true, false],
        'waste' => ['waste', 'SELECT {id} AS `id`,`tanggal`,`item`,`qty`,`unit`,`sebab`,`pic`,`tim`,`waktu`,MD5(`foto`) AS `foto`,`foto_nama`,`data` FROM {waste}', true, true],
        'handovers' => ['serah_terima', 'SELECT {id} AS `id`,`tanggal`,`tujuan`,`penerima`,`pic`,`tim`,`waktu`,MD5(`foto`) AS `foto`,`foto_nama`,`data` FROM {serah_terima}', true, true],
        'opname' => ['opname', 'SELECT {*opname} FROM {opname}', false, false],
        'ck' => ['ck_stock', 'SELECT {*ck_stock} FROM {ck_stock}', false, false],
    ];

    public static function hasPhoto(string $kind): bool
    {
        return self::KINDS[$kind][3];
    }

    private function records(string $kind, ?array $teams, string $from, string $to, ?string $id): array
    {
        $teams = self::KINDS[$kind][2] ? $teams : null;

        return match ($kind) {
            'usage' => $this->entries->usageList($teams, $from, $to, $id),
            'waste' => $this->entries->wasteList($teams, $from, $to, $id),
            'handovers' => $this->entries->handoverList($teams, $from, $to, $id),
            'opname' => $this->entries->opnameList($from, $to, $id),
            'ck' => $this->ck->movements($from, $to, $id),
        };
    }

    private function save(string $kind, stdClass $b, bool $verified): array
    {
        return match ($kind) {
            'usage' => $this->entries->usageSave($b, $verified),
            'waste' => $this->entries->wasteSave($b, $verified),
            'handovers' => $this->entries->handoverSave($b, $verified),
            'opname' => $this->entries->opnameSave($b, $verified),
            'ck' => $this->ck->save($b, $verified),
        };
    }

    private function remove(string $kind, string $id): array
    {
        return match ($kind) {
            'usage' => $this->entries->usageDelete($id),
            'waste' => $this->entries->wasteDelete($id),
            'handovers' => $this->entries->handoverDelete($id),
            'opname' => $this->entries->opnameDelete($id),
            'ck' => $this->ck->delete($id),
        };
    }

    /** @return list<array{record:array, version:string}> */
    public function list(string $kind, ?array $teams, string $from, string $to): array
    {
        $versions = [];
        foreach (StockSupport::db()->select(StockSupport::q(self::KINDS[$kind][1])) as $r) {
            $versions[$r->id] = StockRecords::hash((array) $r);
        }

        return array_map(fn ($rec) => ['record' => $rec, 'version' => $versions[$rec['id']] ?? ''],
            $this->records($kind, $teams, $from, $to, null));
    }

    /** @return array{record:array, version:string}|null  null also when outside the caller's teams */
    public function find(string $kind, string $id, ?array $teams, bool $lock = false): ?array
    {
        $raw = StockSupport::db()->selectOne(StockSupport::q(self::KINDS[$kind][1].' WHERE {id} = ?').($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $raw) {
            return null;
        }
        $rec = $this->records($kind, $teams, '', '', $id)[0] ?? null;

        return $rec ? ['record' => $rec, 'version' => StockRecords::hash((array) $raw)] : null;
    }

    /** POST: $b without id; `pic` is set by the caller (the acting user). */
    public function create(string $kind, stdClass $b, ?array $teams): array
    {
        unset($b->id);
        $res = $this->save($kind, $b, false);
        if ($res['status'] !== 'success') {
            throw new StockConflict('invalid', $res['message']);
        }

        return $this->find($kind, $res['id'], null); // the writer sees what it wrote, whatever its team
    }

    public function patch(string $kind, string $id, stdClass $b, string $version, ?array $teams): array
    {
        return StockSupport::db()->transaction(function () use ($kind, $id, $b, $version, $teams) {
            $cur = $this->guard($kind, $id, $version, $teams);
            $merged = (object) array_merge($cur['record'], (array) $b, ['id' => $id]);
            if (self::hasPhoto($kind) && ! property_exists($b, 'foto')) {
                unset($merged->foto);
            } elseif (self::hasPhoto($kind) && ! property_exists($b, 'fotoNama')) {
                $merged->fotoNama = '';
            }
            $res = $this->save($kind, $merged, true);
            if ($res['status'] !== 'success') {
                throw new StockConflict('invalid', $res['message']);
            }

            return $this->find($kind, $id, null);
        });
    }

    public function delete(string $kind, string $id, string $version, ?array $teams): void
    {
        StockSupport::db()->transaction(function () use ($kind, $id, $version, $teams) {
            $this->guard($kind, $id, $version, $teams);
            $res = $this->remove($kind, $id);
            if ($res['status'] !== 'success') {
                throw new StockConflict('invalid', $res['message']);
            }
        });
    }

    /** {foto, fotoNama} or null (no such record, outside the teams, or no photo). */
    public function photo(string $kind, string $id, ?array $teams): ?array
    {
        if (! $this->find($kind, $id, $teams)) {
            return null;
        }
        $res = $kind === 'waste' ? $this->entries->wastePhoto($id) : $this->entries->handoverPhoto($id);

        return $res['status'] === 'success' ? ['foto' => $res['foto'], 'fotoNama' => $res['fotoNama']] : null;
    }

    private function guard(string $kind, string $id, string $version, ?array $teams): array
    {
        $cur = $this->find($kind, $id, $teams, true);
        if (! $cur) {
            throw new StockConflict('not_found');
        }
        if (! hash_equals($cur['version'], $version)) {
            throw new StockConflict('stale', $cur['record']);
        }

        return $cur;
    }
}
