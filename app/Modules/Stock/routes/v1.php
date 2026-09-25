<?php

use App\Modules\Stock\Http\V1\StockController as C;
use Illuminate\Support\Facades\Route;

// One Backend, several Panels (Office Modul): ordering, purchasing, hpp, usage.
// Reads are open to any of them; writes follow who does them in the old Panels.
$any = 'module:ordering|purchasing|hpp|usage';
$kind = 'products|vendors';

Route::middleware(['auth:sanctum', $any])->prefix('stock')->group(function () use ($kind) {
    Route::get('{kind}', [C::class, 'catalogIndex'])->where('kind', $kind);
    Route::get('{kind}/{nama}', [C::class, 'catalogShow'])->where(['kind' => $kind, 'nama' => '.+']);

    Route::get('orders', [C::class, 'orderIndex']);
    Route::get('orders/batches', [C::class, 'batches']);
    Route::get('orders/stats', [C::class, 'stats']);
    Route::get('orders/{nomor}', [C::class, 'orderShow']);

    Route::get('stock-today', [C::class, 'stockToday']);
});

// Literal paths + ->defaults('kind'): route values reach the controller in order
// (URI params first, then defaults), hence the ($r, $nama, $kind) signatures.
// Product database: Purchasing, and HPP (its ingredient editor saves products).
Route::middleware(['auth:sanctum', 'module:purchasing|hpp'])->prefix('stock')->group(function () {
    Route::post('products', [C::class, 'catalogStore'])->defaults('kind', 'products');
    Route::patch('products/{nama}', [C::class, 'catalogUpdate'])->where('nama', '.+')->defaults('kind', 'products');
    Route::delete('products/{nama}', [C::class, 'catalogDestroy'])->where('nama', '.+')->defaults('kind', 'products');
    Route::post('products-import', [C::class, 'catalogImport'])->defaults('kind', 'products');
});

// Crew ordering and check-in happen in both Ordering and Purchasing.
Route::middleware(['auth:sanctum', 'module:ordering|purchasing'])->prefix('stock')->group(function () {
    Route::post('orders/batches', [C::class, 'submitBatch']);
    Route::patch('orders/{nomor}', [C::class, 'orderUpdate']);
    Route::delete('orders/{nomor}', [C::class, 'orderDestroy']);
    Route::put('stock-today', [C::class, 'putStockToday']);
});

// Purchasing only: vendor database, the monitor's archive/restore, the migration import.
Route::middleware(['auth:sanctum', 'module:purchasing'])->prefix('stock')->group(function () {
    Route::post('vendors', [C::class, 'catalogStore'])->defaults('kind', 'vendors');
    Route::patch('vendors/{nama}', [C::class, 'catalogUpdate'])->where('nama', '.+')->defaults('kind', 'vendors');
    Route::delete('vendors/{nama}', [C::class, 'catalogDestroy'])->where('nama', '.+')->defaults('kind', 'vendors');
    Route::post('vendors-import', [C::class, 'catalogImport'])->defaults('kind', 'vendors');
    Route::post('orders/archive', [C::class, 'archive'])->defaults('to', 'archive');
    Route::post('orders/unarchive', [C::class, 'archive'])->defaults('to', 'unarchive');
    Route::post('orders/import', [C::class, 'import']);
});
