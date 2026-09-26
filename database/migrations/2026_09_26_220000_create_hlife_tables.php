<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Howandi Life OS in core (#65, PRD #1): the twelve id-keyed collections and
 * the finance ledger (indexed columns + the full `data` JSON, exactly as the
 * app edits them) and the settings documents. Every table takes the hlife_
 * prefix; ERD and legacy mapping: docs/db/hlife.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. created_by/updated_by stay NULL for
        // hlife: it is a private Life OS with no link to an Office User, so
        // the actors columns are the plain tech pair with the user FK like
        // akademi. Legacy hlife rows carry NO timestamps and NO conflict
        // guard (last save wins); the ms stamps here are ADR-0003 tech
        // columns only — `created_at` written once, `updated_at` on every
        // write — nothing on the wire exposes them, and a record's version
        // stays the SHA-1 of its stored JSON (docs/api/hlife.md).
        $tech = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0);
            $t->bigInteger('created_at')->default(0);
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // Every row table keeps its legacy id in `legacy_id` and the full row
        // verbatim in `data` (hlife records are open-ended); the typed columns
        // are the query surface, derived from the row on every write. String
        // columns keep their legacy NOT NULL DEFAULT '' shape: the writer
        // fills them with '' (hl_nilai), never NULL.
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->longText('data');
        };
        $table = function (string $name, Closure $cols, ?Closure $index = null) use ($s, $row, $tech, $actors): void {
            $s->create($name, function (Blueprint $t) use ($row, $cols, $tech, $index): void {
                $row($t);
                $cols($t);
                if ($index) {
                    $index($t);
                }
                $tech($t);
            });
            $s->table($name, $actors);
        };

        $table('hlife_businesses', function (Blueprint $t): void {
            $t->string('nama', 190)->default('');
            $t->string('bidang', 190)->default('');
        });
        $table('hlife_projects', function (Blueprint $t): void {
            $t->string('nama', 190)->default('');
            $t->string('biz', 190)->default('');
            $t->string('stage', 60)->default('');
            $t->string('pic', 190)->default('');
            $t->string('due', 20)->default('');
        }, fn (Blueprint $t) => $t->index('biz', 'idx_prj_biz'));
        $table('hlife_tasks', function (Blueprint $t): void {
            $t->string('nama', 255)->default('');
            $t->string('biz', 190)->default('');
            $t->string('project', 190)->default('');
            $t->string('owner', 190)->default('');
            $t->string('due', 20)->default('');
            $t->tinyInteger('done')->default(0);
        }, function (Blueprint $t): void {
            $t->index('biz', 'idx_tsk_biz');
            $t->index('done', 'idx_tsk_done');
        });
        $table('hlife_goals', function (Blueprint $t): void {
            $t->string('nama', 255)->default('');
            $t->string('area', 80)->default('');
        });
        $table('hlife_dreams', function (Blueprint $t): void {
            $t->string('judul', 255)->default('');
            $t->string('kategori', 80)->default('');
            $t->string('status', 60)->default('');
            $t->integer('tahun')->nullable();
        });
        $table('hlife_roadmap', function (Blueprint $t): void {
            $t->string('judul', 255)->default('');
            $t->integer('tahun')->nullable();
            $t->tinyInteger('done')->default(0);
        });
        $table('hlife_content', function (Blueprint $t): void {
            $t->string('judul', 255)->default('');
            $t->string('channel', 190)->default('');
            $t->string('platform', 60)->default('');
            $t->string('stage', 60)->default('');
            $t->string('tanggal', 20)->default('');
        }, fn (Blueprint $t) => $t->index('stage', 'idx_cnt_stage'));
        $table('hlife_learning', function (Blueprint $t): void {
            $t->string('judul', 255)->default('');
            $t->string('jenis', 60)->default('');
            $t->string('status', 60)->default('');
        });
        $table('hlife_habits', function (Blueprint $t): void {
            $t->string('nama', 190)->default('');
            $t->integer('streak')->default(0);
        });
        $table('hlife_events', function (Blueprint $t): void {
            $t->string('judul', 255)->default('');
            $t->string('tanggal', 20)->default('');
            $t->string('jenis', 60)->default('');
        }, fn (Blueprint $t) => $t->index('tanggal', 'idx_evt_tgl'));
        $table('hlife_assets', function (Blueprint $t): void {
            $t->string('nama', 190)->default('');
            $t->string('kategori', 80)->default('');
        });
        $table('hlife_reviews', function (Blueprint $t): void {
            $t->string('week_start', 20)->default('');
        }, fn (Blueprint $t) => $t->index('week_start', 'idx_rev_week'));
        $table('hlife_ledger', function (Blueprint $t): void {
            $t->string('bulan', 10)->default('');
            $t->string('scope', 40)->default('');
            $t->double('income')->default(0);
            $t->double('expense')->default(0);
        }, fn (Blueprint $t) => $t->index('bulan', 'idx_led_bulan'));

        // firstRun, mood, energy, focus, weeklyTarget, auth, channels, dump
        $s->create('hlife_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 80)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('hlife_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hlife_pengaturan', 'hlife_ledger', 'hlife_reviews', 'hlife_assets', 'hlife_events',
            'hlife_habits', 'hlife_learning', 'hlife_content', 'hlife_roadmap', 'hlife_dreams',
            'hlife_goals', 'hlife_tasks', 'hlife_projects', 'hlife_businesses'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
