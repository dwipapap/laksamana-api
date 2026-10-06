<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * hari_operasional.lokasi_buka was first created as
 * CASE WHEN status = 'buka' THEN lokasi_id END, which MySQL 8.4 accepts but
 * MariaDB 10.11 (production) refuses with error 1901: a stored column may not
 * return a CHAR column as-is. 2026_10_05_110000 now uses RTRIM(lokasi_id), so a
 * fresh database (MariaDB or MySQL) already has the new form and this is a no-op.
 * Only a local MySQL that ran the first form is rebuilt here. The column is
 * derived from status and lokasi_id, so nothing stored is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('core');
        $expr = $db->selectOne(
            'SELECT GENERATION_EXPRESSION AS e FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['hari_operasional', 'lokasi_buka'],
        )?->e;

        if ($expr === null || stripos($expr, 'rtrim') !== false) {
            return;
        }

        $s = Schema::connection('core');
        $s->table('hari_operasional', function (Blueprint $t): void {
            $t->dropUnique(['lokasi_buka']);
            $t->dropColumn('lokasi_buka');
        });
        $s->table('hari_operasional', function (Blueprint $t): void {
            $t->ulid('lokasi_buka')->nullable()->after('catatan')
                ->storedAs("CASE WHEN status = 'buka' THEN RTRIM(lokasi_id) END")->unique();
        });
    }

    public function down(): void
    {
        // Nothing to undo: the first form is the one MariaDB refuses.
    }
};
