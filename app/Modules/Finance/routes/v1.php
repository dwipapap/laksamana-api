<?php

use App\Modules\Finance\Http\V1\PettyCashController as C;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:finance'])->prefix('finance/petty-cash')->group(function () {
    Route::get('/', [C::class, 'state']);

    Route::get('transactions', [C::class, 'transactions']);
    Route::post('transactions', [C::class, 'createTransaction']);
    Route::get('transactions/{id}', [C::class, 'transaction'])->whereNumber('id');
    Route::put('transactions/{id}', [C::class, 'updateTransaction'])->whereNumber('id');
    Route::patch('transactions/{id}', [C::class, 'markTransaction'])->whereNumber('id');
    Route::delete('transactions/{id}', [C::class, 'deleteTransaction'])->whereNumber('id');

    Route::get('{list}', [C::class, 'items'])->whereIn('list', ['sources', 'categories']);
    Route::post('{list}', [C::class, 'createItem'])->whereIn('list', ['sources', 'categories']);
    Route::patch('{list}/{id}', [C::class, 'updateItem'])->whereIn('list', ['sources', 'categories'])->whereNumber('id');
    Route::delete('{list}/{id}', [C::class, 'deleteItem'])->whereIn('list', ['sources', 'categories'])->whereNumber('id');

    // Akses Halaman: readable by every finance user (the menu needs it); edited by the module admin
    Route::get('access', [C::class, 'access']);
    Route::middleware('module:finance,admin')->group(function () {
        Route::put('access/matrix', [C::class, 'putMatrix']);
        Route::put('access/roles/{userId}', [C::class, 'putRole']);
    });
});
