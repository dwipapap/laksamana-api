<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B6: onboarding). Greenfield — ADR-0009,
 * docs/modules/hris.md (Onboarding O1). Learning materials stay in
 * Akademi (linked, never copied); the HRIS checklist covers the
 * non-learning steps plus the 30/60/90-day reviews.
 *
 * Coordination tickets (R6) store NOTHING here: they live in BD
 * Permintaan Koordinasi and are read/written through BdRecords.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };

        $s->create('hris_onboarding_template', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('divisi', 64)->unique();
            // [{kunci, judul, tenggat_hari?}] — non-learning steps; eval-*
            // keys are the 30/60/90-day reviews (HR/head complete those).
            $t->json('langkah');
            $t->string('program_akademi_id', 64)->nullable();
            $tech($t);
        });

        $s->create('hris_onboarding', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64)->unique();
            $t->ulid('user_ulid')->nullable();
            $t->string('divisi', 64)->default('');
            $t->date('mulai');
            // AKTIF | SELESAI | BATAL
            $t->string('status', 16)->default('AKTIF');
            $t->string('program_akademi_id', 64)->nullable();
            $t->string('dibuat_oleh', 120)->default('');
            $tech($t);
            $t->index(['status']);
        });

        $s->create('hris_onboarding_langkah', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('onboarding_id')->constrained('hris_onboarding')->cascadeOnDelete();
            $t->string('kunci', 64);
            $t->string('judul', 190);
            $t->date('tenggat')->nullable();
            $t->boolean('selesai')->default(false);
            $t->string('selesai_oleh', 120)->default('');
            $t->bigInteger('selesai_at')->default(0);
            $t->string('catatan', 255)->default('');
            $tech($t);
            $t->unique(['onboarding_id', 'kunci']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_onboarding_langkah', 'hris_onboarding', 'hris_onboarding_template'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
