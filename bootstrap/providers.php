<?php

use App\Modules\Menu\MenuServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    ModuleServiceProvider::class,
    MenuServiceProvider::class,
];
