<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B1: attendance). Greenfield — no legacy backend, no legacy_id,
 * no importer. Spec: docs/modules/hris.md (Attendance A1–A9), ADR-0009.
 *
 * Tables:
 *   hris_lokasi     attendance locations (GPS radius per location)
 *   hris_wajah      enrolled faces, one AKTIF row per user (UNIQUE user_id+status;
 *                   an HR reset flips AKTIF to DIRESET, so re-enrolment is only
 *                   possible after a reset; prior DIRESET rows are pruned on enrol)
 *   hris_ketukan    punches (UNIQUE user_id+tanggal_kerja+arah: a repeated MASUK
 *                   answers duplikat, a repeated PULANG overwrites)
 *   hris_pengaturan key/value settings (nilai JSON, berlaku_dari for history)
 *   hris_audit      append-only audit trail (no personal biometrics inside)
 *
 * The prompt's `berubah` optimistic-lock column is `version` here (ADR-0007:
 * version is the only concurrency check). Clients send it back as `berubah`
 * (alias `version`) on the decision endpoint; a mismatch is a 409.
 *
 * hris_kalender comes in B2; until then HARI_LIBUR is wired through the
 * HrisCalendar interface (NullCalendar returns false).
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns. Identity is NOT on core yet
        // (AccountRepository::onCore() is false), so created_by/updated_by stay
        // NULL until identity moves; the acting NAME lives in *_oleh columns.
        // No FK to `user`: the row may not exist on core while identity is legacy.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };

        $s->create('hris_lokasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 120);
            $t->decimal('lat', 10, 7)->default(0);
            $t->decimal('lng', 10, 7)->default(0);
            $t->integer('radius_m')->default(120);
            $t->boolean('aktif')->default(true);
            $tech($t);
            $t->timestamp('deleted_at')->nullable();
            $t->index(['aktif', 'nama']);
        });

        $s->create('hris_wajah', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->string('model', 64)->default('');
            $t->longText('descriptor')->nullable(); // JSON float array; never served to clients
            $t->string('foto_path', 190)->nullable(); // storage/app/hris-faces key, HR only
            $t->timestamp('consent_at')->nullable();
            $t->string('status', 16)->default('AKTIF'); // AKTIF | DIRESET
            $t->string('dibuat_oleh', 120)->default('');
            $tech($t);
            $t->timestamp('deleted_at')->nullable();
            $t->unique(['user_id', 'status']);
            $t->index(['status']);
        });

        $s->create('hris_ketukan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $t->date('tanggal_kerja');
            $t->string('arah', 8); // MASUK | PULANG
            $t->bigInteger('waktu')->default(0); // server epoch ms
            $t->decimal('lat', 10, 7)->default(0);
            $t->decimal('lng', 10, 7)->default(0);
            $t->integer('akurasi_m')->default(0);
            $t->ulid('lokasi_id')->nullable();
            $t->integer('jarak_m')->default(-1);
            $t->boolean('dalam_area')->default(false);
            $t->string('shift_kode', 32)->default('');
            $t->string('shift_mulai', 5)->default('');
            $t->string('shift_selesai', 5)->default('');
            $t->boolean('lintas_hari')->default(false);
            $t->string('shift_sumber', 16)->default('NONE'); // JADWAL | BAWAAN | NONE | TAK_TERBACA
            $t->boolean('dalam_shift')->default(false);
            $t->decimal('skor_wajah', 5, 4)->nullable();
            $t->string('wajah_model', 64)->default('');
            $t->boolean('wajah_ok')->default(false);
            $t->string('foto_path', 190)->nullable(); // storage/app/hris-faces key
            $t->string('status', 16)->default('VALID'); // VALID | MENUNGGU | DITOLAK
            $t->json('alasan_antrean')->nullable(); // e.g. ["LUAR_AREA"]
            $t->string('alasan_kru', 255)->default('');
            $t->string('diputus_oleh', 120)->default('');
            $t->bigInteger('diputus_at')->default(0);
            $t->string('nota', 255)->default('');
            $tech($t);
            $t->unique(['user_id', 'tanggal_kerja', 'arah']);
            $t->index(['status', 'waktu']);
            $t->index(['tanggal_kerja']);
            $t->foreign('lokasi_id')->references('id')->on('hris_lokasi')->nullOnDelete();
        });

        $s->create('hris_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kunci', 64)->unique();
            $t->json('nilai')->nullable();
            $t->date('berlaku_dari')->nullable();
            $tech($t);
        });

        $s->create('hris_audit', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->bigInteger('waktu')->default(0); // server epoch ms
            $t->string('aktor', 120)->default('');
            $t->string('aksi', 64)->default('');
            $t->string('ref_tipe', 32)->default('');
            $t->string('ref_id', 64)->default('');
            $t->json('data')->nullable(); // ids and codes only, never biometrics
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['aksi', 'waktu']);
            $t->index(['ref_tipe', 'ref_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_audit', 'hris_pengaturan', 'hris_ketukan', 'hris_wajah', 'hris_lokasi'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
