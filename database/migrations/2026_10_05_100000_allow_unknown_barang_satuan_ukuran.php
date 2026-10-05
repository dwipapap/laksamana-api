<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Legacy Stock lets a Barang list a valid unit without saying how many Satuan
 * Dasar it holds ("tidak bisa dikonversi"). NULL keeps that state honestly
 * instead of inventing a size; CHECK (ukuran > 0) still holds, as a NULL
 * check result is not a violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('barang_satuan', function (Blueprint $t): void {
            $t->decimal('ukuran', 15, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('barang_satuan', function (Blueprint $t): void {
            $t->decimal('ukuran', 15, 4)->nullable(false)->change();
        });
    }
};
