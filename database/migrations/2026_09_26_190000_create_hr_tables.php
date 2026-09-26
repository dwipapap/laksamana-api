<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HR (Staff Performance) in core (#63, PRD #1): the 18 record collections
 * (indexed columns + the full `data` JSON), the append-only audit trail,
 * the composite-key KPI actual / monthly input maps, the attendance months
 * with their day rows, the settings documents and the whole-document
 * revision (`hr_meta.version` = legacy `meta.rev`). Legacy names collide
 * across modules, so every table takes the hr_ prefix.
 * ERD and legacy mapping: docs/db/hr.md.
 */
return new class extends Migration
{
    /** Indexed columns per record table, as in the live legacy schema. */
    private const RECORDS = [
        'divisions' => ['nama' => 190, 'warna' => 32],
        'employees' => ['nama' => 190, 'jabatan' => 190, 'div_id' => 64, 'tingkat' => 80, 'app_role' => 40, 'join_date' => 20, 'status' => 40],
        'kpi_templates' => ['div_id' => 64],
        'okrs' => ['owner_type' => 20, 'owner_id' => 64, 'periode' => 40],
        'reviews' => ['emp_id' => 64, 'bulan' => 10, 'status' => 40],
        'competencies' => ['emp_id' => 64],
        'trainings' => ['judul' => 255, 'div_id' => 64, 'jenis' => 80, 'mandatory' => 'bool'],
        'training_records' => ['training_id' => 64, 'emp_id' => 64, 'status' => 40, 'skor' => 'double?', 'tanggal' => 20],
        'coachings' => ['emp_id' => 64, 'coach_id' => 64, 'tanggal' => 20, 'status' => 40],
        'rewards' => ['emp_id' => 64, 'tanggal' => 20, 'jenis' => 20, 'points' => 'double'],
        'badges' => ['emp_id' => 64, 'bulan' => 10, 'badge' => 120],
        'violations' => ['emp_id' => 64, 'tanggal' => 20, 'jenis' => 120, 'severity' => 20, 'sp' => 10, 'status' => 40],
        'feedbacks' => ['emp_id' => 64, 'tanggal' => 20, 'kind' => 40],
        'career_paths' => ['track' => 120],
        'successions' => ['posisi' => 190, 'emp_id' => 64, 'readiness' => 40],
        'moods' => ['emp_id' => 64, 'tanggal' => 20, 'mood' => 'int?'],
        'suggestions' => ['emp_id' => '64?', 'tanggal' => 20, 'status' => 40],
        'calendar' => ['tanggal' => 20, 'kind' => 40, 'judul' => 255],
    ];

    /** Secondary indexes of the legacy schema. */
    private const INDEXES = [
        'employees' => [['div_id'], ['status']], 'kpi_templates' => [['div_id']], 'okrs' => [['owner_type', 'owner_id']],
        'reviews' => [['emp_id', 'bulan']], 'competencies' => [['emp_id']], 'trainings' => [['div_id']],
        'training_records' => [['emp_id'], ['training_id']], 'coachings' => [['emp_id']], 'rewards' => [['emp_id']],
        'badges' => [['emp_id']], 'violations' => [['emp_id']], 'feedbacks' => [['emp_id']], 'successions' => [['emp_id']],
        'moods' => [['emp_id']], 'suggestions' => [['emp_id']], 'calendar' => [['tanggal']],
    ];

    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. created_by/updated_by stay NULL for hr:
        // the legacy actor is the free-text `saved_by` on the whole document,
        // not a row-level Office User. No Laravel timestamps: hr rows never
        // carried any.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };

        foreach (self::RECORDS as $name => $cols) {
            $s->create("hr_$name", function (Blueprint $t) use ($name, $cols, $tech): void {
                $t->ulid('id')->primary();
                $t->string('legacy_id', 64)->unique();
                foreach ($cols as $col => $type) {
                    match ($type) {
                        'bool' => $t->boolean($col)->default(false),
                        'double' => $t->double($col)->default(0),
                        'double?' => $t->double($col)->nullable(),
                        'int?' => $t->integer($col)->nullable(),
                        '64?' => $t->string($col, 64)->nullable(),
                        default => $t->string($col, $type)->default(''),
                    };
                }
                $t->longText('data');
                if ($name === 'employees') {
                    // An employee id is the Office User id wherever one exists
                    // (u-…); the link is resolved on every write and import.
                    $t->ulid('user_id')->nullable();
                    $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
                }
                foreach (self::INDEXES[$name] ?? [] as $idx) {
                    $t->index($idx);
                }
                $tech($t);
            });
            $s->table("hr_$name", $actors);
        }

        // Append-only trail; user_id stays the legacy user id (soft link).
        $s->create('hr_audit', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->string('at', 40)->default('')->index();
            $t->string('user_id', 64)->default('');
            $t->string('user_name', 190)->default('');
            $t->string('action', 80)->default('');
            $t->text('detail')->nullable();
            $tech($t);
        });
        $s->table('hr_audit', $actors);

        // { divId: { month: { itemId: number|null } } }. legacy_id is
        // "div|bulan|item" (the legacy composite PK); div_id stays soft.
        $s->create('hr_kpi_actuals', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->unique();
            $t->string('div_id', 64);
            $t->string('bulan', 10);
            $t->string('item_id', 64);
            $t->double('nilai')->nullable();
            $t->unique(['div_id', 'bulan', 'item_id']);
            $tech($t);
        });
        $s->table('hr_kpi_actuals', $actors);

        // { empId: { month: {...} } }. legacy_id is "emp|bulan".
        $s->create('hr_monthly_inputs', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->unique();
            $t->string('emp_id', 64);
            $t->string('bulan', 10);
            $t->longText('data');
            $t->unique(['emp_id', 'bulan']);
            $tech($t);
        });
        $s->table('hr_monthly_inputs', $actors);

        // Attendance: one month (summary) with its imported day rows.
        // Production kept the whole map in the `extra:attendance` setting
        // (#101); the importer explodes it into these tables.
        $s->create('hr_attendance_months', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 10)->unique(); // = bulan
            $t->string('bulan', 10);
            $t->longText('data');
            $t->string('imported_at', 40)->default('');
            $t->string('imported_by', 120)->default('');
            $tech($t);
        });
        $s->table('hr_attendance_months', $actors);

        $s->create('hr_attendance_days', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique(); // "bulan|talenta|tanggal"
            $t->foreignUlid('month_id')->constrained('hr_attendance_months')->cascadeOnDelete();
            $t->string('bulan', 10)->index();
            $t->string('talenta_id', 32);
            $t->string('tanggal', 10);
            $t->string('emp_id', 64)->default('');
            $t->longText('data');
            $t->unique(['bulan', 'talenta_id', 'tanggal']);
            $t->index(['emp_id', 'bulan']);
            $tech($t);
        });
        $s->table('hr_attendance_days', $actors);

        // Settings documents; `k` keeps the legacy key verbatim (incl. extra:*).
        $s->create('hr_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 80)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('hr_pengaturan', $actors);

        // The whole-document revision: ONE row. `version` IS the legacy
        // `meta.rev` (ADR-0003's single concurrency column), so it starts at 0.
        $s->create('hr_meta', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->unsignedTinyInteger('legacy_id')->unique();
            $t->unsignedBigInteger('version')->default(0);
            $t->string('saved_at', 40)->default('');
            $t->string('saved_by', 120)->default('');
            $t->integer('versi')->default(1); // app document format, not concurrency
            $t->ulid('updated_by')->nullable();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hr_meta', 'hr_pengaturan', 'hr_attendance_days', 'hr_attendance_months',
            'hr_monthly_inputs', 'hr_kpi_actuals', 'hr_audit'] as $table) {
            $s->dropIfExists($table);
        }
        foreach (array_reverse(array_keys(self::RECORDS)) as $name) {
            $s->dropIfExists("hr_$name");
        }
    }
};
