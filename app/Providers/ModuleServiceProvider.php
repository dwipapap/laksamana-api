<?php

namespace App\Providers;

use App\Auth\LegacySessions;
use App\Auth\OfficeAccess;
use App\Modules\Jadwal\Services\HeadDirectory;
use App\Support\Legacy\Sesi;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the module layout:
 *
 *   app/Modules/<Name>/routes/v1.php      -> /api/v1/...   (api middleware group)
 *   app/Modules/<Name>/routes/legacy.php  -> old URLs as-is (legacy middleware group)
 *
 * Each module owns its route files, so parallel work on different modules
 * never touches a shared routes file.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request caches, like the legacy `static $cache` arrays.
        $this->app->scoped(HeadDirectory::class);
        $this->app->scoped(OfficeAccess::class);
        $this->app->scoped(LegacySessions::class);
        $this->app->scoped(Sesi::class);
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        foreach (glob(app_path('Modules/*/routes'), GLOB_ONLYDIR) ?: [] as $dir) {
            $module = strtolower((string) basename(dirname($dir)));
            $maintenance = 'maintenance:'.$module;
            if (is_file($dir.'/v1.php')) {
                Route::middleware(['api', $maintenance])->prefix('api/v1')->group($dir.'/v1.php');
            }
            if (is_file($dir.'/legacy.php')) {
                Route::middleware(['legacy', $maintenance])->group($dir.'/legacy.php');
            }
        }
    }
}
