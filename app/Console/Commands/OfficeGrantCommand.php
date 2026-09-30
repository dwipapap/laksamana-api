<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use Illuminate\Console\Command;

/**
 * `php artisan office:grant Dwipa menu news` — the server-side shortcut for
 * two Kelola Akses steps while the Office has no admin screen for them:
 *
 *  1. register each module key that does not exist yet (syncModules: adds
 *     only, never touches an existing key's label/active flag);
 *  2. grant the user access=1 on each key (the same `grants` upsert as
 *     PUT /api/v1/account/users/{id}/access).
 *
 * The user is found by name or username (case-insensitive, active only), never
 * by PIN. Idempotent: running it twice changes nothing the second time.
 * Runs against whatever database .env points at — the operator's call.
 */
final class OfficeGrantCommand extends Command
{
    protected $signature = 'office:grant
        {login : User name or username}
        {modules* : Module keys to grant, e.g. menu news}
        {--dry-run : Show what would change, write nothing}';

    protected $description = 'Register module keys (if new) and grant them to one Office user';

    /** Labels for the greenfield keys; any other new key is labelled with itself. */
    private const LABELS = ['menu' => 'Menu', 'news' => 'Berita', 'homepage' => 'Homepage'];

    public function handle(AccountRepository $users, AccountService $account, OfficeAccess $access): int
    {
        $login = mb_strtolower(trim((string) $this->argument('login')), 'UTF-8');
        $matches = array_values(array_filter($users->allUsers(), function (array $u) use ($login): bool {
            $name = mb_strtolower(trim((string) ($u['name'] ?? '')), 'UTF-8');
            $username = mb_strtolower(trim((string) ($u['username'] ?? '')), 'UTF-8');

            return (int) ($u['active'] ?? 0) === 1 && ($name === $login || ($username !== '' && $username === $login));
        }));
        if (count($matches) !== 1) {
            $this->error(count($matches) === 0
                ? "Tidak ada user aktif bernama/username \"{$this->argument('login')}\"."
                : "Lebih dari satu user cocok dengan \"{$this->argument('login')}\"; pakai username.");

            return self::FAILURE;
        }
        $user = $matches[0];
        $userId = (string) $user['id'];

        $keys = array_values(array_unique(array_filter(array_map(
            fn ($k) => trim((string) $k), (array) $this->argument('modules')
        ), fn ($k) => $k !== '' && $k !== '*')));
        if ($keys === []) {
            $this->error('Sebutkan minimal satu kunci modul (bukan *).');

            return self::FAILURE;
        }

        $new = array_values(array_filter($keys, fn ($k) => ! $users->moduleExists($k)));
        $this->line("User: {$user['name']} ({$userId})");
        $this->line('Modul baru didaftarkan: '.($new === [] ? '-' : implode(', ', $new)));
        $this->line('Akses diberikan: '.implode(', ', $keys));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        $account->syncModulesCore(array_map(
            fn ($k) => ['key' => $k, 'label' => self::LABELS[$k] ?? $k], $new
        ));
        foreach ($keys as $k) {
            $account->setModuleAccessCore('artisan', $userId, $k, true);
        }
        $access->forgetUser($userId);

        $this->info('Selesai. Modul yang kini bisa dibuka: '.implode(', ', $access->modules($userId)));

        return self::SUCCESS;
    }
}
