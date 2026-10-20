<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * J8 (ADR-0012): the default shift of a crew member moves from the Jadwal
 * setting (`shiftKru`) to the Karyawan record. A shift code, like a roster
 * cell; attendance reads it before the old map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('hris_karyawan', function (Blueprint $t): void {
            $t->string('shift_bawaan', 16)->nullable()->after('atasan_user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('hris_karyawan', fn (Blueprint $t) => $t->dropColumn('shift_bawaan'));
    }
};
