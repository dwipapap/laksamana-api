<?php

declare(strict_types=1);

namespace App\Modules\Menu\Console;

use App\Modules\Menu\Services\MenuSeed;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan menu:seed {--fresh}` — load the committed JSON catalogue
 * (database/seeders/data/menu.json) into core, idempotent by slug (§8).
 */
class MenuSeedCommand extends Command
{
    protected $signature = 'menu:seed {--fresh : Delete every menu row first}';

    protected $description = 'Seed the menu catalogue from database/seeders/data/menu.json';

    public function handle(MenuSeed $seed): int
    {
        try {
            $r = $seed->run((bool) $this->option('fresh'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('kategori: %d baru, %d diubah; item: %d baru, %d diubah; varian: %d.',
            $r['kategori_baru'], $r['kategori_ubah'], $r['item_baru'], $r['item_ubah'], $r['varian']));

        return self::SUCCESS;
    }
}
