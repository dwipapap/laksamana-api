<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS profile change requests (issue #344): crew propose contact changes,
 * HR (module admins of hris, incl. superadmin `*`) approve or reject. The
 * official contact stays untouched until approval is fully applied.
 *
 *   hris_perubahan_profil  one row per request: server snapshot of the old
 *                          values (nilai_awal, requested fields only),
 *                          normalized proposal (perubahan), status, actor and
 *                          timestamps, idempotency key, profile revision the
 *                          submit was based on, and durable apply progress.
 *
 * Additive only: no legacy table is touched (docs/deploy.md, ADR-0012).
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

        $s->create('hris_perubahan_profil', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            // Legacy User id (string) + nullable ULID, like every hris_* table
            // (ADR-0009). No FK to `user` while identity is legacy.
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            // MENUNGGU -> DISETUJUI | DITOLAK | DIBATALKAN. DISETUJUI passes
            // through DIPROSES first: the HR decision is persisted before any
            // Account/HRIS write, so a crash leaves a retryable row, never a
            // silent half-apply (issue #344).
            $t->string('status', 16)->default('MENUNGGU');
            // Requested fields only, as JSON objects {field: value}. Snapshot
            // is taken from the server truth, never from the request body.
            $t->json('nilai_awal');
            $t->json('perubahan');
            $t->string('diajukan_oleh', 120)->default('');
            $t->unsignedBigInteger('diajukan_at');
            $t->string('diputus_oleh_id', 64)->nullable();
            $t->string('diputus_oleh', 120)->nullable();
            $t->unsignedBigInteger('diputus_at')->nullable();
            // Rejection reason (required on DITOLAK) or approver note.
            $t->string('catatan', 500)->nullable();
            $t->string('idempotency_key', 64);
            // Profile revision the submit was based on (see HrisProfilService).
            $t->string('revisi_profil', 128)->nullable();
            // Durable apply progress: each target is marked only after its
            // write is verified. galat names the failing target only.
            $t->boolean('apply_account_ok')->default(false);
            $t->boolean('apply_core_ok')->default(false);
            $t->text('apply_galat')->nullable();
            $t->unsignedInteger('apply_percobaan')->default(0);
            $tech($t);
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['user_id', 'status']);
            $t->index('status');
        });

        DB::connection('core')->statement(
            'ALTER TABLE `hris_perubahan_profil` ADD CONSTRAINT `hris_perubahan_profil_chk_0` '
            ."CHECK (status IN ('MENUNGGU','DIPROSES','DISETUJUI','DITOLAK','DIBATALKAN'))"
        );
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('hris_perubahan_profil');
    }
};
