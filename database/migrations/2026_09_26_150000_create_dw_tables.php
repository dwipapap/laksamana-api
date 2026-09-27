<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DW (Pekerja Harian) in core (#51, PRD #1): talent pool, shift assignments,
 * head requests and the setting blob. Table names already carry the dw_
 * prefix and collide with nothing, so core keeps them (like jadwal #47).
 * ERD and legacy mapping: docs/db/dw.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by resolve the
        // acting Office User by session name (best-effort, NULL when the
        // name matches nobody — the name itself stays in dibuat_oleh etc.).
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 32)->unique();
        };

        // Talent pool. UNIQUE no_hp is the duplicate guard; divisi/posisi
        // stay CSV (first value primary). pin is never read nor written.
        $s->create('dw_pekerja', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('nama', 120);
            $t->string('no_hp', 32)->unique();
            $t->string('pin', 8)->default('');
            $t->string('gender', 10)->default('');
            $t->string('area', 80)->default('');
            $t->string('bank', 120)->default('');
            $t->string('divisi', 120)->default('')->index();
            $t->string('posisi', 240)->default('');
            $t->string('skill', 255)->default('');
            $t->string('status', 16)->default('AKTIF')->index();
            $t->string('catatan', 255)->default('');
            $t->bigInteger('dibuat_at')->default(0);
            $t->string('dibuat_oleh', 120)->default('');
            $t->bigInteger('updated_at')->default(0);
            $t->string('updated_oleh', 120)->default('');
            $t->string('bayar_jenis', 16)->default('BANK');
            $t->string('bayar_nomor', 60)->default('');
            $t->string('bayar_nama', 120)->default('');
            $t->string('bayar_bank', 60)->default('');
            $tech($t);
        });
        $s->table('dw_pekerja', $actors);

        // Shift assignments. dw_id and permintaan_id keep legacy worker /
        // request ids (soft links, no FKs): deleting a worker keeps their
        // assignments (orphans draw as "(DW dihapus)"), and deleting a
        // request only releases the link. nilai* is rating bookkeeping the
        // service never reads; carried verbatim.
        $s->create('dw_ajuan', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('dw_id', 32)->index();
            $t->date('tgl')->index();
            $t->string('jam_mulai', 5)->default('');
            $t->string('jam_selesai', 5)->default('');
            $t->string('divisi', 16)->default('');
            $t->string('posisi', 60)->default('');
            $t->string('catatan', 255)->default('');
            $t->string('status', 16)->default('MENUNGGU')->index();
            $t->bigInteger('dibuat_at')->default(0);
            $t->string('dibuat_oleh', 120)->default('');
            $t->bigInteger('putus_at')->default(0);
            $t->string('putus_oleh', 120)->default('');
            $t->string('putus_nota', 255)->default('');
            $t->string('hadir', 10)->default('');
            $t->tinyInteger('nilai')->default(0);
            $t->string('nilai_nota', 255)->default('');
            $t->string('nilai_oleh', 120)->default('');
            $t->bigInteger('nilai_at')->default(0);
            $t->string('hadir_nota', 255)->default('');
            $t->string('hadir_oleh', 120)->default('');
            $t->bigInteger('hadir_at')->default(0);
            $t->string('permintaan_id', 32)->default('')->index();
            $t->index(['dw_id', 'tgl']);
            $tech($t);
        });
        $s->table('dw_ajuan', $actors);

        // Head requests. "How many staffed" is COUNTED from dw_ajuan, never stored.
        $s->create('dw_permintaan', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('divisi', 16)->default('')->index();
            $t->date('tgl')->index();
            $t->string('jam_mulai', 5)->default('');
            $t->string('jam_selesai', 5)->default('');
            $t->string('posisi', 60)->default('');
            $t->integer('jumlah')->default(1);
            $t->string('catatan', 255)->default('');
            $t->string('status', 16)->default('MENUNGGU')->index();
            $t->bigInteger('dibuat_at')->default(0);
            $t->string('dibuat_oleh', 120)->default('');
            $t->bigInteger('putus_at')->default(0);
            $t->string('putus_oleh', 120)->default('');
            $t->string('putus_nota', 255)->default('');
            $t->string('usulan', 400)->default('');
            $t->bigInteger('diubah_at')->default(0);
            $t->string('diubah_oleh', 120)->default('');
            $tech($t);
        });
        $s->table('dw_permintaan', $actors);

        // Singleton setting blob (legacy_id '1'). The legacy updated_by NAME
        // is not carried: it is never served on any wire shape (like jadwal
        // sel.updated_by, docs/db/jadwal.md); the actor ULIDs cover it.
        $s->create('dw_setting', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 16)->unique();
            $t->longText('data');
            $t->bigInteger('updated_at')->default(0);
            $tech($t);
        });
        $s->table('dw_setting', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['dw_setting', 'dw_permintaan', 'dw_ajuan', 'dw_pekerja'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
