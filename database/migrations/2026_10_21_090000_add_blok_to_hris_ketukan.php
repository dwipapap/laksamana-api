<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Block shifts (J4, ADR-0012): a SPLIT day has one MASUK/PULANG pair per
 * block, so a punch is unique per user, work date, direction AND block.
 * Every existing row is block 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('hris_ketukan', function (Blueprint $t): void {
            $t->unsignedTinyInteger('blok')->default(1)->after('arah');
        });
        Schema::connection('core')->table('hris_ketukan', function (Blueprint $t): void {
            $t->unique(['user_id', 'tanggal_kerja', 'arah', 'blok']);
            $t->dropUnique(['user_id', 'tanggal_kerja', 'arah']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('hris_ketukan', function (Blueprint $t): void {
            $t->unique(['user_id', 'tanggal_kerja', 'arah']);
            $t->dropUnique(['user_id', 'tanggal_kerja', 'arah', 'blok']);
            $t->dropColumn('blok');
        });
    }
};
