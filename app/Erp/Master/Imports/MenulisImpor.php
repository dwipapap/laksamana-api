<?php

declare(strict_types=1);

namespace App\Erp\Master\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Small helpers every v2 importer needs: write a row only when it changed
 * (so a re-run keeps `version`), read legacy dates and instants, trim text,
 * decode a JSON blob, and collect the cases a person must decide.
 */
trait MenulisImpor
{
    /** @var array<string, list<string>> */
    private array $issues = [];

    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function upsert(Model $row, array $values): Model
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /** A business date: 'Y-m-d…' text, or epoch milliseconds read in WIB. */
    private function tanggal(mixed $v): ?string
    {
        if (is_numeric($v) && (float) $v > 1e11) {
            return Carbon::createFromTimestampMs((int) $v, 'Asia/Jakarta')->toDateString();
        }
        $v = substr(trim((string) $v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    /** An instant in UTC: epoch ms or s, or a date(-time) string read as WIB when it has no zone. */
    private function waktu(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0' || $v === false) {
            return null;
        }
        if (is_numeric($v)) {
            $x = (float) $v;

            return $x > 1e11 ? Carbon::createFromTimestampMs((int) $x, 'UTC')->format('Y-m-d H:i:s')
                : ($x > 1e8 ? Carbon::createFromTimestamp((int) $x, 'UTC')->format('Y-m-d H:i:s') : null);
        }
        try {
            return Carbon::parse((string) $v, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** "19.30" / "19:30" / "7:30" => "19:30:00". */
    private function jam(mixed $v): ?string
    {
        if (! preg_match('/^(\d{1,2})[:.](\d{2})/', trim((string) $v), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }

    private function text(mixed $v, int $max): ?string
    {
        if (is_array($v)) {
            $v = implode(', ', array_map('strval', $v));
        }
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function rp(mixed $v): string
    {
        return (string) (int) round((float) $v);
    }

    /** @return array<string, mixed> */
    private function json(?string $raw): array
    {
        $d = json_decode((string) $raw, true);

        return is_array($d) ? $d : [];
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
