<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Attendance corrections (koreksi absen): a crew member files a missing
 * clock-in/out for a past work date with a reason; the division head
 * decides (HR fallback), and approval writes the punch row. Never a
 * self-service edit: nothing lands in hris_ketukan while MENUNGGU.
 *
 *   hris_koreksi_absen  one request per crew per work date per direction:
 *                       tanggal_kerja, arah (MASUK|PULANG), jam (HH:MM),
 *                       alasan, status, actor and timestamps, idempotency key.
 *
 * Additive only on `core`: no legacy table is touched. History starts at
 * deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns; created_by/updated_by stay NULL while
        // identity is legacy (the acting NAME goes to *_oleh where needed).
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };

        $s->create('hris_koreksi_absen', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            // Legacy User id (string) + nullable ULID, like every hris_* table
            // (ADR-0009). No FK to `user` while identity is legacy.
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->date('tanggal_kerja');
            // Which punch is missing: MASUK (clock-in) or PULANG (clock-out).
            $t->string('arah', 8);
            // Requested clock time on that date, WIB, HH:MM.
            $t->string('jam', 5);
            $t->string('alasan', 500);
            $t->string('status', 16)->default('MENUNGGU');
            $t->string('diajukan_oleh', 120)->default('');
            $t->unsignedBigInteger('diajukan_at');
            $t->string('diputus_oleh_id', 64)->nullable();
            $t->string('diputus_oleh', 120)->nullable();
            $t->unsignedBigInteger('diputus_at')->nullable();
            // Rejection reason (required on DITOLAK) or decider note.
            $t->string('catatan', 500)->nullable();
            $t->string('idempotency_key', 64);
            $tech($t);
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['user_id', 'status']);
            $t->index('status');
            $t->index('tanggal_kerja');
        });

        DB::connection('core')->statement(
            'ALTER TABLE `hris_koreksi_absen` ADD CONSTRAINT `hris_koreksi_absen_chk_0` '
            ."CHECK (status IN ('MENUNGGU','DISETUJUI','DITOLAK','DIBATALKAN'))"
        );
        DB::connection('core')->statement(
            'ALTER TABLE `hris_koreksi_absen` ADD CONSTRAINT `hris_koreksi_absen_chk_1` '
            ."CHECK (arah IN ('MASUK','PULANG'))"
        );
    }

    public function down(): void
    {
        $s = Schema::connection('core');

        $s->dropIfExists('hris_koreksi_absen');
    }
};
