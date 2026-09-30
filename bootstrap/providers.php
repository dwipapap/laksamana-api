<?php

use App\Modules\Homepage\HomepageServiceProvider;
use App\Modules\Menu\MenuServiceProvider;
use App\Modules\News\NewsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    ModuleServiceProvider::class,
    MenuServiceProvider::class,
    NewsServiceProvider::class,
    HomepageServiceProvider::class,
];
