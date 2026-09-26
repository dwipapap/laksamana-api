<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The legacy heads list is ordered per Divisi; keep that order (#44). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('kepala_divisi', function (Blueprint $t): void {
            $t->unsignedInteger('urutan')->default(0)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('kepala_divisi', function (Blueprint $t): void {
            $t->dropColumn('urutan');
        });
    }
};
