<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;
use App\Support\JsonDoc;

/**
 * The Jadwal side effects of HRIS master data (ADR-0012), written through
 * JadwalService only: Penempatan Divisi follows the Karyawan's Divisi, heads
 * of shift Divisi stay in the Jadwal `heads` map, and a leaver's future
 * roster cells are removed.
 *
 * The setting is one blob, so every write is read-modify-write of the
 * current blob; parts HRIS does not own are passed back untouched.
 */
final class HrisJadwalSinkron
{
    public function __construct(private readonly JadwalService $jadwal) {}

    /** Acting identity with jadwal admin rights; the trail names the HR official. */
    private static function sistem(string $by): array
    {
        return ['id' => '', 'name' => $by, 'adminModules' => ['jadwal']];
    }

    /** The Jadwal setting blob (read-only use outside this class). */
    public function setting(): mixed
    {
        return $this->jadwal->setting();
    }

    /** @return array<string,mixed> */
    private function blob(): array
    {
        return JsonDoc::toArray($this->jadwal->setting());
    }

    /**
     * Penempatan Divisi of one User: a shift Divisi kode, or Nonshift for a
     * kantor Divisi / no Divisi. Skips the write when it already holds.
     */
    public function tempatkan(string $userId, ?string $kodeShift, string $by): void
    {
        $mau = $kodeShift ?? Divisi::NONSHIFT;
        $d = $this->blob();
        $ov = is_array($d['divOverride'] ?? null) ? $d['divOverride'] : [];
        if (($ov[$userId] ?? null) === $mau) {
            return;
        }
        $ov[$userId] = $mau;
        $d['divOverride'] = $ov;
        $this->jadwal->saveSetting($d, 'HRIS:'.$by);
    }

    /** @return array<int,string> heads of one shift Divisi */
    public function kepalaShift(string $kode): array
    {
        $heads = $this->blob()['heads'] ?? [];

        return is_array($heads) && is_array($heads[$kode] ?? null) ? array_values(array_map('strval', $heads[$kode])) : [];
    }

    /** @param  array<int,string>  $userIds */
    public function setKepalaShift(string $kode, array $userIds, string $by): void
    {
        $d = $this->blob();
        $heads = is_array($d['heads'] ?? null) ? $d['heads'] : [];
        if ($userIds === []) {
            unset($heads[$kode]);
        } else {
            $heads[$kode] = array_values($userIds);
        }
        $d['heads'] = $heads;
        $this->jadwal->saveSetting($d, 'HRIS:'.$by);
    }

    /** Remove one User's roster cells after $tanggal (exclusive), up to a year ahead. */
    public function hapusSelSesudah(string $userId, string $tanggal, string $by): int
    {
        $akhir = date('Y-m-d', strtotime($tanggal.' +366 days'));
        $hapus = [];
        foreach ($this->jadwal->shiftRange($userId, date('Y-m-d', strtotime($tanggal.' +1 day')), $akhir)['rows'] as $r) {
            $hapus[] = ['u' => $userId, 'd' => (string) $r['d']];
        }
        if ($hapus !== []) {
            $this->jadwal->saveCells([], $hapus, 'HRIS:'.$by, self::sistem($by));
        }

        return count($hapus);
    }
}
