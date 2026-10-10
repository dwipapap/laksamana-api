<?php

use App\Modules\Finance\Http\V1\InvoiceController as I;
use App\Modules\Finance\Http\V1\PettyCashController as C;
use App\Modules\Finance\Http\V1\TagihanRutinController as T;
use App\Modules\Finance\Http\V1\VaultController as V;
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

// Kas Kecil's payment plan lives in the vault blob (`bayar`), written narrowly (legacy bayarSave).
// Its wallet balances are served narrowly too: vault-side saldo per wallet for
// `finance` holders who lack `brankas` (the full blob stays behind /vault).
Route::middleware(['auth:sanctum', 'module:finance'])->prefix('finance/petty-cash')->group(function () {
    Route::get('payment-plan', [V::class, 'paymentPlan']);
    Route::put('payment-plan', [V::class, 'putPaymentPlan']);
    Route::get('wallet-balances', [V::class, 'walletBalances']);
});

// Panel Brankas: gated by its own Modul
Route::middleware(['auth:sanctum', 'module:brankas'])->prefix('finance/vault')->group(function () {
    Route::get('/', [V::class, 'state']);
    Route::get('setting', [V::class, 'setting']);
    Route::put('setting', [V::class, 'putSetting']);

    Route::get('access', [V::class, 'access']);
    Route::middleware('module:brankas,admin')->group(function () {
        Route::put('access/matrix', [V::class, 'putMatrix']);
        Route::put('access/roles/{userId}', [V::class, 'putRole']);
    });

    $lists = ['rekening', 'piutang', 'bayar', 'investor', 'mutasi'];
    Route::get('{list}', [V::class, 'list'])->whereIn('list', $lists);
    Route::post('{list}', [V::class, 'store'])->whereIn('list', $lists);
    Route::get('{list}/{id}', [V::class, 'show'])->whereIn('list', $lists);
    Route::put('{list}/{id}', [V::class, 'update'])->whereIn('list', $lists);
    Route::patch('{list}/{id}', [V::class, 'patch'])->whereIn('list', $lists);
    Route::delete('{list}/{id}', [V::class, 'destroy'])->whereIn('list', $lists);
});

// Tagihan Rutin: recurring subscriptions + their payments (module `finance`).
// No DELETE route on purpose: payments are cancelled, tagihan deactivated.
Route::middleware(['auth:sanctum', 'module:finance'])->prefix('finance/tagihan')->group(function () {
    Route::get('/', [T::class, 'index']);
    Route::post('/', [T::class, 'store']);
    Route::patch('{id}', [T::class, 'update'])->whereNumber('id');
    Route::put('{id}/active', [T::class, 'setActive'])->whereNumber('id');
    Route::post('{id}/payments', [T::class, 'pay'])->whereNumber('id');
    Route::post('payments/{id}/cancel', [T::class, 'cancel'])->whereNumber('id');
});

// Invoices & kwitansi. Requests / status / file serve Reservasi and Marketing too
// (the controller checks for any of finance, reservasi, marketing).
Route::middleware('auth:sanctum')->prefix('finance/invoices')->group(function () {
    Route::post('requests', [I::class, 'request']);
    Route::get('status', [I::class, 'status']);
    Route::get('file/{resId}', [I::class, 'file']);

    Route::middleware('module:finance')->group(function () {
        Route::get('/', [I::class, 'index']);
        Route::get('queue', [I::class, 'queue']);
        Route::get('settings', [I::class, 'settings']);
        Route::put('settings', [I::class, 'putSettings']);
        Route::get('signatories', [I::class, 'signatories']);
        Route::post('signatories', [I::class, 'createSignatory']);
        Route::patch('signatories/{id}', [I::class, 'updateSignatory']);
        Route::delete('signatories/{id}', [I::class, 'deleteSignatory']);
        Route::get('{id}', [I::class, 'show']);
        Route::post('{id}/decision', [I::class, 'decide']);
    });
});
