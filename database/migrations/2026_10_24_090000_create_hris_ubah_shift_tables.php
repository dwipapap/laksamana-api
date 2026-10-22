<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS shift changes and roster audit (issue #346): one crew, one date,
 * decided by the division head (HR fallback), plus an append-only trail of
 * every roster cell change made through HRIS.
 *
 *   hris_ubah_shift    one request per crew per work date: source/target
 *                      shift codes with frozen snapshots, reason, status,
 *                      actor and timestamps, idempotency key.
 *   hris_roster_event  one row per changed cell: who/when, source, affected
 *                      crew/division, work date, before/after snapshots,
 *                      request reference when driven by a request.
 *
 * Additive only on `core`: no legacy table is touched, no v2/frozen
 * `jadwal_*` table is used. History starts at deploy; nothing backfills
 * past manual SQL writes.
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

        $s->create('hris_ubah_shift', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            // Legacy User id (string) + nullable ULID, like every hris_* table
            // (ADR-0009). No FK to `user` while identity is legacy.
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->date('tanggal_kerja');
            // Source and target shift codes plus their frozen definitions
            // (name/hours/blocks), so a later master edit never rewrites
            // what was requested and decided.
            $t->string('shift_lama_kode', 16);
            $t->json('shift_lama');
            $t->string('shift_baru_kode', 16);
            $t->json('shift_baru');
            $t->string('alasan', 500);
            // APPLYING is a durable approval intent across core/Jadwal
            // connections. It freezes the approver before the roster side
            // effect; retries resume that same operation.
            $t->string('status', 16)->default('MENUNGGU');
            $t->char('jadwal_sumber_hash', 64);
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

        $s->create('hris_roster_event', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->unsignedBigInteger('waktu');
            $t->string('aktor', 120)->default('');
            // UBAH_SHIFT | PENGAJUAN | EDIT_MANUAL | SISTEM. Rejections and
            // cancelled pendings are request history, not cell events: they
            // only land here when a cell actually changed.
            $t->string('sumber', 16);
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->string('divisi_kode', 32)->nullable();
            $t->string('divisi_nama', 80)->nullable();
            $t->date('tanggal_kerja');
            // Before/after cell snapshots {kode,nama,mulai,selesai,blok,
            // libur,m,s,n}; null means the cell was empty.
            $t->json('sel_lama')->nullable();
            $t->json('sel_baru')->nullable();
            $t->string('ref_pengajuan_id', 64)->nullable();
            $t->string('diajukan_oleh', 120)->nullable();
            $t->unsignedBigInteger('diajukan_at')->nullable();
            $t->string('diputus_oleh', 120)->nullable();
            $t->unsignedBigInteger('diputus_at')->nullable();
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->index(['user_id', 'tanggal_kerja']);
            $t->index('tanggal_kerja');
            $t->index('waktu');
            $t->index('sumber');
        });

        DB::connection('core')->statement(
            'ALTER TABLE `hris_ubah_shift` ADD CONSTRAINT `hris_ubah_shift_chk_0` '
            ."CHECK (status IN ('MENUNGGU','APPLYING','DISETUJUI','DITOLAK','DIBATALKAN'))"
        );
        DB::connection('core')->statement(
            'ALTER TABLE `hris_roster_event` ADD CONSTRAINT `hris_roster_event_chk_0` '
            ."CHECK (sumber IN ('UBAH_SHIFT','PENGAJUAN','EDIT_MANUAL','SISTEM'))"
        );
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        $s->dropIfExists('hris_roster_event');
        $s->dropIfExists('hris_ubah_shift');
    }
};
