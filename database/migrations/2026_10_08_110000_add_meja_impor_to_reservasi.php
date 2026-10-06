<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Production reservations name their table as free text, often several at
 * once ("11, 12"), in codes that match neither the master list nor the floor
 * plans. The text is kept as it was; meja_id is only set for an exact single
 * match (docs/erp/reservasi.md, found by core:import erp-reservasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('reservasi', function (Blueprint $t): void {
            $t->string('meja_impor', 120)->nullable()->after('meja_id');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('reservasi', function (Blueprint $t): void {
            $t->dropColumn('meja_impor');
        });
    }
};
