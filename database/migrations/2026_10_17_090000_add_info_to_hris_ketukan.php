<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS flexible attendance (A16, A20–A25): per-punch metrics as one nullable
 * JSON column on hris_ketukan —
 * {telat_menit, pulang_awal, kurang_menit, target_menit, durasi_menit, curiga[]}.
 *
 * Additive and optional: deploys never run migrate, so the code checks
 * information_schema once per request and, while this column is missing,
 * skips writing it and recomputes the metrics live in shape() (curiga []).
 * Apply on dev/prod deliberately (docs/deploy.md § New core migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('hris_ketukan', function (Blueprint $t): void {
            $t->json('info')->nullable()->after('alasan_kru');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('hris_ketukan', function (Blueprint $t): void {
            $t->dropColumn('info');
        });
    }
};
