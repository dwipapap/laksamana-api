<?php

use App\Modules\Stock\Http\Legacy\StockLegacyController as C;
use Illuminate\Support\Facades\Route;

// One route per legacy file (the frontends append their own `?t=` cache buster,
// so every source needs its own path). any(): other methods answer 405.
Route::any('stock-api-mysql/items.php', [C::class, 'items']);
Route::any('stock-api-mysql/vendors.php', [C::class, 'vendors']);
Route::any('stock-api-mysql/orders.php', [C::class, 'orders']);
Route::any('stock-api-mysql/stock.php', [C::class, 'stock']);
