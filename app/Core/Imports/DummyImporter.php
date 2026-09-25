<?php

declare(strict_types=1);

namespace App\Core\Imports;

use App\Core\Models\CoreImportProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** C1 proof importer: account Users -> the disposable `core_import_probe` table. */
final class DummyImporter implements Importer
{
    public function module(): string
    {
        return 'dummy';
    }

    public function legacyConnection(): string
    {
        return 'legacy_account';
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function import(): int
    {
        $users = DB::connection($this->legacyConnection())
            ->table('users')
            ->orderBy('id')
            ->get(['id', 'name', 'username', 'created_at', 'updated_at']);

        DB::connection($this->targetConnection())->transaction(function () use ($users): void {
            foreach ($users as $user) {
                $probe = CoreImportProbe::withTrashed()->firstOrNew(['legacy_id' => (string) $user->id]);
                $name = (string) $user->name;
                $username = (string) $user->username;
                $updatedAt = Carbon::parse((string) $user->updated_at);
                $changed = $probe->name !== $name
                    || ($probe->username ?? '') !== $username
                    || $probe->updated_at?->format('Y-m-d H:i:s') !== $updatedAt->format('Y-m-d H:i:s')
                    || $probe->deleted_at !== null;

                if ($probe->exists && ! $changed) {
                    continue;
                }

                $probe->forceFill([
                    'name' => $name,
                    'username' => $username,
                    'updated_at' => $updatedAt,
                    'deleted_at' => null,
                ]);

                if (! $probe->exists) {
                    $probe->created_at = Carbon::parse((string) $user->created_at);
                }

                $probe->save();
            }
        });

        return $users->count();
    }
}
