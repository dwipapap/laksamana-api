<?php

use App\Modules\Stock\Http\Legacy\StockLegacyController as C;
use Illuminate\Support\Facades\Route;

// One route per legacy file (the frontends append their own `?t=` cache buster,
// so every source needs its own path). any(): other methods answer 405.
Route::any('stock-api-mysql/items.php', [C::class, 'items']);
Route::any('stock-api-mysql/vendors.php', [C::class, 'vendors']);
Route::any('stock-api-mysql/users.php', [C::class, 'users']);
Route::any('stock-api-mysql/ordering-users.php', [C::class, 'orderingUsers']);
Route::any('stock-api-mysql/ordering-settings.php', [C::class, 'orderingSettings']);
Route::any('stock-api-mysql/purchasing-settings.php', [C::class, 'purchasingSettings']);
Route::any('stock-api-mysql/training.php', [C::class, 'training']);
Route::any('stock-api-mysql/orders.php', [C::class, 'orders']);
Route::any('stock-api-mysql/stock.php', [C::class, 'stock']);
Route::any('stock-api-mysql/ck.php', [C::class, 'ck']);
Route::any('stock-api-mysql/usage.php', [C::class, 'usage']);
Route::any('stock-api-mysql/waste.php', [C::class, 'waste']);
Route::any('stock-api-mysql/serah.php', [C::class, 'serah']);
Route::any('stock-api-mysql/opname.php', [C::class, 'opname']);
Route::any('stock-api-mysql/log.php', [C::class, 'log']);
Route::any('stock-api-mysql/hpp.php', [C::class, 'hpp']);
