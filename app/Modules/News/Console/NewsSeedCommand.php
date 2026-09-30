<?php

declare(strict_types=1);

namespace App\Modules\News\Console;

use App\Modules\News\Services\NewsSeed;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan news:seed {--fresh}` — load the committed JSON
 * (database/seeders/data/news.json) into core, idempotent by slug (§8).
 */
class NewsSeedCommand extends Command
{
    protected $signature = 'news:seed {--fresh : Delete every news row first}';

    protected $description = 'Seed the news categories/articles from database/seeders/data/news.json';

    public function handle(NewsSeed $seed): int
    {
        try {
            $r = $seed->run((bool) $this->option('fresh'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('kategori: %d baru, %d diubah; artikel: %d baru, %d diubah.',
            $r['kategori_baru'], $r['kategori_ubah'], $r['artikel_baru'], $r['artikel_ubah']));

        return self::SUCCESS;
    }
}
