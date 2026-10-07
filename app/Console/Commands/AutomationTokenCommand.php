<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Automation\Http\Middleware\RequireAutomationToken;
use Illuminate\Console\Command;

/**
 * `php artisan automation:token n8n --user="<name|username>"` — mint the one
 * token an n8n workflow needs (#234).
 *
 * The token carries exactly the `automation:read` ability and no expiry, and
 * is owned by an existing active Office account (looked up by name or
 * username, like office:grant). It grants no Modul: the owner is expected to
 * be a technical account with no module access at all. The plain token is
 * shown once; only its hash is stored.
 */
final class AutomationTokenCommand extends Command
{
    protected $signature = 'automation:token
        {name : Token label shown in the token list, e.g. n8n}
        {--user= : Owner (Office account name or username, active only)}';

    protected $description = 'Create a Sanctum token with only the automation:read ability (no expiry)';

    public function handle(AccountRepository $users, OfficeAccess $access): int
    {
        $name = trim((string) $this->argument('name'));
        $login = mb_strtolower(trim((string) $this->option('user')), 'UTF-8');

        if ($name === '') {
            $this->error('Beri nama token, mis. n8n.');

            return self::FAILURE;
        }
        if ($login === '') {
            $this->error('Sebutkan pemiliknya: --user="Nama atau username".');

            return self::FAILURE;
        }

        $matches = array_values(array_filter($users->allUsers(), function (array $u) use ($login): bool {
            $name = mb_strtolower(trim((string) ($u['name'] ?? '')), 'UTF-8');
            $username = mb_strtolower(trim((string) ($u['username'] ?? '')), 'UTF-8');

            return (int) ($u['active'] ?? 0) === 1 && ($name === $login || ($username !== '' && $username === $login));
        }));
        if (count($matches) !== 1) {
            $this->error(count($matches) === 0
                ? "Tidak ada user aktif bernama/username \"{$this->option('user')}\"."
                : "Lebih dari satu user cocok dengan \"{$this->option('user')}\"; pakai username.");

            return self::FAILURE;
        }

        $user = $matches[0];
        $userId = (string) $user['id'];

        // A module grant would still open `module:*` gates on other endpoints;
        // the ability alone must never widen access, so warn the operator.
        $modules = array_values(array_filter($access->modules($userId)));
        if ($modules !== []) {
            $this->warn('Peringatan: '.$user['name'].' punya akses Modul ('.implode(', ', $modules).'). Pakai akun teknis tanpa akses Modul.');
        }

        $token = $users->tokenOwner($userId)->createToken($name, [RequireAutomationToken::ABILITY], null);

        $this->info("Token {$name} dibuat.");
        $this->line('Pemilik: '.$user['name']." ({$userId})");
        $this->line('Ability: '.RequireAutomationToken::ABILITY.' — kedaluwarsa: tidak ada');
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->comment('Salin sekarang; token lengkap tidak bisa ditampilkan lagi.');

        return self::SUCCESS;
    }
}
