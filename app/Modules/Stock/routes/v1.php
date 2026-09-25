<?php

use App\Modules\Stock\Http\V1\HppController as H;
use App\Modules\Stock\Http\V1\StockAdminController as A;
use App\Modules\Stock\Http\V1\StockController as C;
use App\Modules\Stock\Http\V1\StockEntriesController as E;
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

// ─── #35: Central Kitchen, Usage Panel records, activity log ───

// CK ledger: everyone who sees stock reads it; manual movements are Purchasing's
// Central Kitchen tab; the outlet → CK delivery is sent from Ordering.
Route::middleware(['auth:sanctum', $any])->prefix('stock/ck')->group(function () {
    Route::get('balance', [E::class, 'ckBalance']);
    Route::get('movements', [E::class, 'index'])->defaults('kind', 'ck');
    Route::get('movements/{id}', [E::class, 'show'])->defaults('kind', 'ck');
});
Route::middleware(['auth:sanctum', 'module:purchasing'])->prefix('stock/ck')->group(function () {
    Route::post('movements', [E::class, 'store'])->defaults('kind', 'ck');
    Route::patch('movements/{id}', [E::class, 'update'])->defaults('kind', 'ck');
    Route::delete('movements/{id}', [E::class, 'destroy'])->defaults('kind', 'ck');
});
Route::middleware(['auth:sanctum', 'module:ordering|purchasing'])->prefix('stock')->group(function () {
    Route::post('ck/deliveries', [E::class, 'ckDeliver']);
    Route::post('logs', [E::class, 'logStore']);
});
// Log Aktivitas is an admin page of Purchasing (it names people and what they changed).
Route::middleware(['auth:sanctum', 'module:purchasing,admin'])->get('stock/logs', [E::class, 'logIndex']);

// The Usage Panel (Pemakaian): Daily SO, event usage, waste, serah terima.
Route::middleware(['auth:sanctum', 'module:usage'])->prefix('stock')->group(function () {
    foreach (['usage', 'waste', 'handovers', 'opname'] as $k) {
        Route::get($k, [E::class, 'index'])->defaults('kind', $k);
        Route::post($k, [E::class, 'store'])->defaults('kind', $k);
        Route::get("$k/{id}", [E::class, 'show'])->defaults('kind', $k);
        Route::patch("$k/{id}", [E::class, 'update'])->defaults('kind', $k);
        Route::delete("$k/{id}", [E::class, 'destroy'])->defaults('kind', $k);
    }
    Route::get('waste/{id}/photo', [E::class, 'photo'])->defaults('kind', 'waste');
    Route::get('handovers/{id}/photo', [E::class, 'photo'])->defaults('kind', 'handovers');
});

// ─── #36: HPP & Resep (the HPP Panel) ───
Route::middleware(['auth:sanctum', 'module:hpp'])->prefix('stock/hpp')->group(function () {
    Route::get('ingredients', [H::class, 'ingredientIndex']);
    Route::post('ingredients', [H::class, 'ingredientStore']);
    Route::get('ingredients/{nama}', [H::class, 'ingredientShow'])->where('nama', '.+');
    Route::patch('ingredients/{nama}', [H::class, 'ingredientUpdate'])->where('nama', '.+');
    Route::delete('ingredients/{nama}', [H::class, 'ingredientDestroy'])->where('nama', '.+');
    Route::post('ingredients-import', [H::class, 'ingredientImport']);
    Route::post('ingredients-merge', [H::class, 'ingredientMerge']);
    Route::post('pull-products', [H::class, 'pullProducts']);
    Route::post('remove-shadows', [H::class, 'removeShadows']);
    Route::post('align-names', [H::class, 'alignNames']);

    Route::get('recipes', [H::class, 'recipeIndex']);
    Route::post('recipes', [H::class, 'recipeStore']);
    Route::get('recipes/{id}', [H::class, 'recipeShow']);
    Route::patch('recipes/{id}', [H::class, 'recipeUpdate']);
    Route::delete('recipes/{id}', [H::class, 'recipeDestroy']);
    Route::post('recipes-import', [H::class, 'recipeImport']);

    Route::get('usage/{bulan}', [H::class, 'monthShow']);
    Route::patch('usage/{bulan}', [H::class, 'monthUpdate']);
    Route::get('settings', [H::class, 'settingsShow']);
    Route::patch('settings', [H::class, 'settingsUpdate']);
});
// The one-time move from Excel can wipe HPP (`replace`): HPP admins only.
Route::middleware(['auth:sanctum', 'module:hpp,admin'])->post('stock/hpp/import', [H::class, 'import']);

// Pohon Resep is a read-only Panel: its Users may read the recipe graph, never write it.
Route::middleware(['auth:sanctum', 'module:tree'])->prefix('stock/hpp')->group(function () {
    Route::get('ingredients', [H::class, 'ingredientIndex']);
    Route::get('ingredients/{nama}', [H::class, 'ingredientShow'])->where('nama', '.+');
    Route::get('recipes', [H::class, 'recipeIndex']);
    Route::get('recipes/{id}', [H::class, 'recipeShow']);
});

// #37: Purchasing crew, Ordering crew, Akses Halaman settings and the training archive.
Route::middleware(['auth:sanctum', 'module:purchasing,admin'])->prefix('stock')->group(function () {
    Route::get('users', [A::class, 'userIndex'])->defaults('kind', 'purchasing');
    Route::post('users', [A::class, 'userStore'])->defaults('kind', 'purchasing');
    Route::get('users/{id}', [A::class, 'userShow'])->defaults('kind', 'purchasing');
    Route::patch('users/{id}', [A::class, 'userUpdate'])->defaults('kind', 'purchasing');
    Route::delete('users/{id}', [A::class, 'userDestroy'])->defaults('kind', 'purchasing');
    Route::get('settings/purchasing', [A::class, 'settingsShow'])->defaults('module', 'purchasing');
    Route::put('settings/purchasing', [A::class, 'settingsPut'])->defaults('module', 'purchasing');
});
Route::middleware(['auth:sanctum', 'module:ordering,admin'])->prefix('stock')->group(function () {
    Route::get('ordering-users', [A::class, 'userIndex'])->defaults('kind', 'ordering');
    Route::post('ordering-users', [A::class, 'userStore'])->defaults('kind', 'ordering');
    Route::post('ordering-users-import', [A::class, 'userImport']);
    Route::get('ordering-users/{id}', [A::class, 'userShow'])->defaults('kind', 'ordering');
    Route::patch('ordering-users/{id}', [A::class, 'userUpdate'])->defaults('kind', 'ordering');
    Route::delete('ordering-users/{id}', [A::class, 'userDestroy'])->defaults('kind', 'ordering');
    Route::get('settings/ordering', [A::class, 'settingsShow'])->defaults('module', 'ordering');
    Route::put('settings/ordering', [A::class, 'settingsPut'])->defaults('module', 'ordering');

    Route::get('training', [A::class, 'trainingSummary']);
    Route::post('training', [A::class, 'trainingStore']);
    Route::get('training/{target}', [A::class, 'trainingList']);
    Route::get('training/{target}/{name}', [A::class, 'trainingDownload'])->where('name', '.+');
});
