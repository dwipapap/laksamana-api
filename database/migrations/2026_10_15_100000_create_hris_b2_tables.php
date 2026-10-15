<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B2: calendar, leave/PH balances, requests). Greenfield —
 * ADR-0009, docs/modules/hris.md (Leave L1–L9).
 *
 *   hris_kalender     holiday calendar (HR CRUD + import; B1's HARI_LIBUR
 *                     stub now reads it through DbCalendar)
 *   hris_saldo_mutasi append-only balance ledger per person (PH, CUTI):
 *                     saldo = SUM(delta). Earned PH carries kedaluwarsa and a
 *                     batch_id; usage rows point at the batches they consume
 *                     (FIFO); expiry rows zero out leftovers. Idempotency is
 *                     UNIQUE(sumber_tipe, sumber_id); usage chunks share the
 *                     source id with a #n suffix.
 *   hris_pengajuan    CUTI/PH/IZIN requests: MENUNGGU (crew) → MENUNGGU_HRD
 *                     (Kepala Divisi) → DISETUJUI/DITOLAK (HRD); DIBATALKAN
 *                     ends it. Approved requests deduct the ledger and write
 *                     Jadwal cells; cancelling an approved one refunds through
 *                     new earn rows and deletes the cells.
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

        $s->create('hris_kalender', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('tanggal')->unique();
            $t->string('nama', 120);
            $t->string('jenis', 16)->default('NASIONAL'); // NASIONAL | PERUSAHAAN
            $t->string('sumber', 16)->default('MANUAL'); // MANUAL | IMPOR
            $tech($t);
            $t->timestamp('deleted_at')->nullable();
            $t->index(['tanggal']);
        });

        $s->create('hris_saldo_mutasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->string('jenis', 8); // PH | CUTI
            $t->integer('delta'); // earned +, used -, expired -
            $t->string('sumber_tipe', 32); // KETUKAN_LIBUR | PENGAJUAN | PENGEMBALIAN | PENYESUAIAN | KEDALUWARSA | JADWAL_LAMA | JATAH
            $t->string('sumber_id', 96);
            $t->ulid('batch_id')->nullable(); // the earn batch consumed (own id for earns)
            $t->date('tanggal');
            $t->date('kedaluwarsa')->nullable(); // PH earns only
            $t->string('catatan', 255)->default('');
            $tech($t);
            $t->unique(['sumber_tipe', 'sumber_id']);
            $t->index(['user_id', 'jenis']);
            $t->index(['batch_id']);
            $t->index(['kedaluwarsa']);
        });

        $s->create('hris_pengajuan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->string('jenis', 8); // CUTI | PH | IZIN
            $t->date('tgl_mulai');
            $t->date('tgl_selesai');
            $t->integer('hari')->default(1);
            $t->string('alasan', 500)->default('');
            $t->string('lampiran_path', 190)->nullable();
            $t->string('lampiran_nama', 190)->nullable();
            $t->string('lampiran_mime', 120)->nullable();
            $t->string('status', 16)->default('MENUNGGU'); // MENUNGGU | MENUNGGU_HRD | DISETUJUI | DITOLAK | DIBATALKAN
            $t->string('head_oleh_id', 64)->default('');
            $t->string('head_oleh', 120)->default('');
            $t->bigInteger('head_at')->default(0);
            $t->string('head_nota', 255)->default('');
            $t->string('hrd_oleh_id', 64)->default('');
            $t->string('hrd_oleh', 120)->default('');
            $t->bigInteger('hrd_at')->default(0);
            $t->string('hrd_nota', 255)->default('');
            $tech($t);
            $t->index(['user_id', 'status']);
            $t->index(['status']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_pengajuan', 'hris_saldo_mutasi', 'hris_kalender'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
