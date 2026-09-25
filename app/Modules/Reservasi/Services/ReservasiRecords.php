<?php

namespace App\Modules\Reservasi\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Single-record writes for /api/v1/reservasi, on the SAME rules as legacy
 * saveAll: inline photos are moved to disk (@f:<key>), the row's `updated_at`
 * is its version, and every write bumps the global `_ver` so an old tab that
 * loaded before it gets its "reload" conflict instead of reconciling (and
 * deleting) around it.
 */
class ReservasiRecords
{
    public function __construct(private readonly ReservasiState $state) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('reservasi');
    }

    /** @return list<array> reservations with `tanggal` in [from, to] (either bound optional) */
    public function list(?string $from, ?string $to): array
    {
        $w = [];
        $a = [];
        if ($from) {
            $w[] = 'tanggal >= ?';
            $a[] = $from;
        }
        if ($to) {
            $w[] = 'tanggal <= ?';
            $a[] = $to;
        }
        $out = [];
        foreach ($this->db()->select('SELECT data FROM reservations'.($w ? ' WHERE '.implode(' AND ', $w) : '').' ORDER BY created_at ASC, id ASC', $a) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** @return array{row:array,version:int}|null */
    public function find(string $id, bool $lock = false): ?array
    {
        $r = $this->db()->selectOne('SELECT data, updated_at FROM reservations WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $r) {
            return null;
        }
        $d = json_decode((string) $r->data, true);

        return ['row' => is_array($d) ? $d : ['id' => $id], 'version' => (int) $r->updated_at];
    }

    /**
     * Create ($base === null) or replace a reservation. Throws ReservasiConflict
     * ('exists' / 'stale' with the current row) — the caller maps it to 409.
     *
     * @return array{row:array,version:int}
     */
    public function put(array $row, ?int $base, bool $mustExist): array
    {
        return $this->state->writeLocked(function () use ($row, $base, $mustExist) {
            $id = (string) $row['id'];
            $cur = $this->find($id, true);
            if ($mustExist && ! $cur) {
                throw new ReservasiConflict('missing');
            }
            if (! $mustExist && $cur) {
                throw new ReservasiConflict('exists');
            }
            if ($cur && $cur['version'] !== $base) {
                throw new ReservasiConflict('stale', $cur);
            }
            $now = (int) floor(microtime(true) * 1000);
            $row['updatedAt'] = max($now, ($cur['version'] ?? 0) + 1);
            $row['createdAt'] = $cur['row']['createdAt'] ?? ($row['createdAt'] ?? $now);
            $this->state->upsertRow($this->state->externalizeOne('reservation', $row)); // photos never stay inline
            $this->state->bumpVer();

            return $this->find($id);
        });
    }

    public function delete(string $id, int $base): bool
    {
        return $this->state->writeLocked(function () use ($id, $base) {
            $cur = $this->find($id, true);
            if (! $cur) {
                return false;
            }
            if ($cur['version'] !== $base) {
                throw new ReservasiConflict('stale', $cur);
            }
            $this->db()->delete('DELETE FROM reservations WHERE id = ?', [$id]);
            $this->state->deleteRowFiles($cur['row']);
            $this->state->bumpVer();

            return true;
        });
    }

    /** @return array{value:mixed,version:string} master blob (shared with Service Excellent) and its content hash */
    public function master(): array
    {
        $m = $this->state->read()['master'];

        return ['value' => $m, 'version' => substr(sha1(json_encode($m)), 0, 16)];
    }

    public function putMaster(mixed $value, string $base): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('master must be an object');
        }

        return $this->state->writeLocked(function () use ($value, $base) {
            $this->db()->selectOne("SELECT v FROM settings WHERE k='_ver' FOR UPDATE");
            $cur = $this->master();
            if (! hash_equals($cur['version'], $base)) {
                throw new ReservasiConflict('stale', ['master' => $cur['value']]);
            }
            $this->state->saveMaster($value);
            $this->state->bumpVer();

            return $this->master();
        });
    }
}
