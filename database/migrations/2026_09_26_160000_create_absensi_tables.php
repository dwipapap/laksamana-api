<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Absensi in core (#53, PRD #1): clock-in locations, registered faces,
 * punches and the setting blob. Table names already carry the abs_ prefix
 * and collide with nothing, so core keeps them (like jadwal #47, dw #51).
 * ERD and legacy mapping: docs/db/absensi.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by resolve the
        // acting Office User by session name (best-effort, NULL when the
        // name matches nobody — the name itself stays in *_oleh columns).
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };

        // The legacy updated_by NAME is not carried: it is never served on
        // any wire shape (locations() returns no actor); the actor ULIDs
        // cover it.
        $s->create('abs_lokasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 32)->unique();
            $t->string('nama', 120);
            $t->decimal('lat', 10, 7)->default(0);
            $t->decimal('lng', 10, 7)->default(0);
            $t->integer('radius_m')->default(120);
            $t->boolean('aktif')->default(true);
            $t->bigInteger('updated_at')->default(0);
            $tech($t);
        });
        $s->table('abs_lokasi', $actors);

        // Registered faces. legacy_id is the natural key 'USER:<id>'/'DW:<id>'.
        $s->create('abs_wajah', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 72)->unique();
            $t->string('nama', 120)->default('');
            $t->text('descriptor')->nullable();
            $t->mediumText('foto')->nullable();
            $t->boolean('aktif')->default(true);
            $t->bigInteger('daftar_at')->default(0);
            $t->string('daftar_oleh', 120)->default('');
            $tech($t);
        });
        $s->table('abs_wajah', $actors);

        // Punches. The composite unique key is the PULANG upsert target;
        // subjek links stay soft (USER ids, DW ids), no FKs.
        $s->create('abs_punch', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 32)->unique();
            $t->string('subjek_tipe', 8)->default('USER');
            $t->string('subjek_id', 64)->default('');
            $t->string('nama', 120)->default('');
            $t->date('tgl');
            $t->string('arah', 8)->default('MASUK');
            $t->bigInteger('waktu')->default(0);
            $t->string('jam', 5)->default('');
            $t->decimal('lat', 10, 7)->default(0);
            $t->decimal('lng', 10, 7)->default(0);
            $t->integer('akurasi_m')->default(0);
            $t->string('lokasi_id', 32)->default('');
            $t->integer('jarak_m')->default(-1);
            $t->boolean('dalam_area')->default(false);
            $t->decimal('wajah_skor', 5, 4)->default(1);
            $t->boolean('wajah_ok')->default(false);
            $t->string('shift_kode', 16)->default('');
            $t->string('shift_mulai', 5)->default('');
            $t->string('shift_selesai', 5)->default('');
            $t->string('shift_sumber', 8)->default('NONE');
            $t->boolean('dalam_shift')->default(false);
            $t->string('status', 16)->default('VALID')->index();
            $t->string('sebab', 32)->default('');
            $t->string('alasan', 255)->default('');
            $t->mediumText('foto')->nullable();
            $t->bigInteger('putus_at')->default(0);
            $t->string('putus_oleh', 120)->default('');
            $t->string('putus_nota', 255)->default('');
            $t->bigInteger('dibuat_at')->default(0);
            $t->unique(['subjek_tipe', 'subjek_id', 'tgl', 'arah']);
            $t->index('tgl');
            $tech($t);
        });
        $s->table('abs_punch', $actors);

        // Singleton setting blob (legacy_id '1'). The legacy updated_by NAME
        // is not carried: it is never served on any wire shape; the actor
        // ULIDs cover it.
        $s->create('abs_setting', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 16)->unique();
            $t->longText('data');
            $t->bigInteger('updated_at')->default(0);
            $tech($t);
        });
        $s->table('abs_setting', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['abs_setting', 'abs_punch', 'abs_wajah', 'abs_lokasi'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
