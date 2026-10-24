<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Roster publish log (owner decision 2026-10-24): a Kepala Divisi publishes
 * their Divisi's roster directly, and HR follows every save in Kelola.
 *
 *   hris_roster_simpan  one row per roster save that changed at least one
 *                       cell: who (name, User id, HR or KEPALA), which
 *                       Divisi, the work-date range, counts, optional note.
 *   hris_roster_event   + simpan_id: the cell events of that save.
 *
 * Also a data fix: approved attendance corrections were written to
 * hris_ketukan as status DISETUJUI, which no recap counts. They become
 * VALID, like a queued punch HR approved.
 *
 * Additive only on `core`: no legacy table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        $s->create('hris_roster_simpan', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->unsignedBigInteger('waktu');
            $t->string('aktor', 120)->default('');
            $t->string('aktor_user_id', 64)->default('');
            // HR | KEPALA
            $t->string('peran', 8);
            // Shift Divisi codes touched by this save.
            $t->json('divisi');
            $t->date('tanggal_dari');
            $t->date('tanggal_sampai');
            $t->unsignedInteger('jumlah_isi')->default(0);
            $t->unsignedInteger('jumlah_hapus')->default(0);
            $t->unsignedInteger('jumlah_berubah')->default(0);
            $t->string('catatan', 255)->nullable();
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('waktu');
            $t->index(['tanggal_dari', 'tanggal_sampai']);
        });

        $s->table('hris_roster_event', function (Blueprint $t): void {
            $t->ulid('simpan_id')->nullable()->after('sumber');
            $t->index('simpan_id');
        });

        DB::connection('core')->table('hris_ketukan')->where('status', 'DISETUJUI')->update(['status' => 'VALID']);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        $s->table('hris_roster_event', function (Blueprint $t): void {
            $t->dropIndex(['simpan_id']);
            $t->dropColumn('simpan_id');
        });
        $s->dropIfExists('hris_roster_simpan');
    }
};
