<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKalender;
use Illuminate\Database\QueryException;

/**
 * Holiday calendar writes: HR CRUD plus the national-holiday import. There
 * is no single reliable public API for Indonesian holidays, so HR imports
 * from a JSON/CSV file (or pastes the rows): [{tanggal, nama, jenis?}].
 */
final class HrisCalendarService
{
    public function __construct(
        private readonly DbCalendar $calendar,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** @return array<int,array{id:string,tanggal:string,nama:string,jenis:string,sumber:string}> */
    public function list(string $dari, string $sampai): array
    {
        $dari = PunchRules::tglValid($dari);
        $sampai = PunchRules::tglValid($sampai);
        $q = HrisKalender::query()->orderBy('tanggal');
        if ($dari !== '' && $sampai !== '') {
            if ($sampai < $dari) {
                [$dari, $sampai] = [$sampai, $dari];
            }
            $q->whereBetween('tanggal', [$dari, $sampai]);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[] = ['id' => $r->id, 'tanggal' => $r->tanggal->format('Y-m-d'),
                'nama' => $r->nama, 'jenis' => $r->jenis, 'sumber' => $r->sumber,
                'berubah' => (int) $r->version];
        }

        return $out;
    }

    public function save(array $in, string $by): HrisKalender
    {
        $tgl = PunchRules::tglValid($in['tanggal'] ?? '');
        if ($tgl === '') {
            throw new HrisError('validation_failed', 'Tanggal libur harus YYYY-MM-DD.', 422);
        }
        $nama = self::s($in['nama'] ?? '');
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama libur wajib diisi.', 422);
        }
        $jenis = strtoupper(self::s($in['jenis'] ?? 'NASIONAL'));
        if ($jenis !== 'NASIONAL' && $jenis !== 'PERUSAHAAN') {
            throw new HrisError('validation_failed', 'Jenis libur harus NASIONAL atau PERUSAHAAN.', 422);
        }
        $id = self::s($in['id'] ?? '');
        $row = $id !== '' ? HrisKalender::query()->find($id) : null;
        $data = ['tanggal' => $tgl, 'nama' => mb_substr($nama, 0, 120), 'jenis' => $jenis];
        try {
            if ($row) {
                $row->fill($data)->save();
            } else {
                $row = HrisKalender::query()->create($data + ['sumber' => 'MANUAL']);
            }
        } catch (QueryException) {
            throw new HrisError('validation_failed', 'Tanggal itu sudah ada di kalender.', 422);
        }
        $this->calendar->flush();
        $this->audit->record($by, 'kalender_disimpan', 'hris_kalender', $row->id, ['tanggal' => $tgl]);

        return $row;
    }

    public function delete(string $id, string $by): bool
    {
        $row = HrisKalender::query()->find(self::s($id));
        if (! $row) {
            return false;
        }
        $row->delete();
        $this->calendar->flush();
        $this->audit->record($by, 'kalender_dihapus', 'hris_kalender', $id, []);

        return true;
    }

    /**
     * Import national holidays from pasted rows or an uploaded file.
     * Rows: {tanggal YYYY-MM-DD, nama, jenis?}. Upsert by tanggal.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function import(array $rows, string $by): array
    {
        $dibuat = 0;
        $diperbarui = 0;
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : [];
            $tgl = PunchRules::tglValid($r['tanggal'] ?? $r['tgl'] ?? '');
            $nama = self::s($r['nama'] ?? '');
            if ($tgl === '' || $nama === '') {
                continue;
            }
            $jenis = strtoupper(self::s($r['jenis'] ?? 'NASIONAL'));
            if ($jenis !== 'NASIONAL' && $jenis !== 'PERUSAHAAN') {
                $jenis = 'NASIONAL';
            }
            $ada = HrisKalender::query()->where('tanggal', $tgl)->first();
            if ($ada) {
                $ada->fill(['nama' => mb_substr($nama, 0, 120), 'jenis' => $jenis, 'sumber' => 'IMPOR'])->save();
                $diperbarui++;
            } else {
                HrisKalender::query()->create(['tanggal' => $tgl,
                    'nama' => mb_substr($nama, 0, 120), 'jenis' => $jenis, 'sumber' => 'IMPOR']);
                $dibuat++;
            }
        }
        $this->calendar->flush();
        $this->audit->record($by, 'kalender_diimpor', 'hris_kalender', '', ['dibuat' => $dibuat, 'diperbarui' => $diperbarui]);

        return ['dibuat' => $dibuat, 'diperbarui' => $diperbarui];
    }

    /**
     * Parse an uploaded .json or .csv file into import rows. CSV columns:
     * tanggal,nama[,jenis] with an optional header row.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parseFile(string $bytes, string $nama): array
    {
        $ext = strtolower((string) pathinfo($nama, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $d = json_decode($bytes, true);
            if (! is_array($d)) {
                throw new HrisError('validation_failed', 'Berkas JSON tidak valid.', 422);
            }
            // Accept both [{…}] and {"libur":[{…}]}.
            if (isset($d['libur']) && is_array($d['libur'])) {
                $d = $d['libur'];
            }

            return array_values(array_filter($d, 'is_array'));
        }
        if ($ext === 'csv') {
            $out = [];
            foreach (preg_split('/\r\n|\r|\n/', $bytes) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $c = str_getcsv($line);
                if (count($c) < 2) {
                    continue;
                }
                if (strtolower(trim((string) $c[0])) === 'tanggal') {
                    continue; // header
                }
                $out[] = ['tanggal' => trim((string) $c[0]), 'nama' => trim((string) $c[1]),
                    'jenis' => trim((string) ($c[2] ?? 'NASIONAL'))];
            }

            return $out;
        }
        throw new HrisError('validation_failed', 'Berkas harus .json atau .csv.', 422);
    }
}
