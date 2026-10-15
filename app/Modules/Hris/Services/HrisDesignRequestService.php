<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Marketing\Services\MarketingQueries;

/**
 * Marketing → Konten design requests, shown read-only (R6): HRIS never
 * writes a single Marketing row. Projections are allow-lists — reference
 * images stay in Marketing (the list never had them; the detail drops the
 * raw bytes and keeps the count).
 */
final class HrisDesignRequestService
{
    public function __construct(private readonly MarketingQueries $marketing) {}

    public function daftar(bool $hanyaAktif): array
    {
        try {
            $d = $this->marketing->designRequests($hanyaAktif);
        } catch (\Throwable) {
            throw new HrisError('marketing_tidak_terhubung', 'Desain Marketing tidak terbaca saat ini.', 503);
        }
        $out = [];
        foreach ($d['reqs'] ?? [] as $r) {
            $out[] = $this->bentuk($r);
        }

        return $out;
    }

    public function satu(string $id): array
    {
        if (trim($id) === '') {
            throw new HrisError('validation_failed', 'Butuh id permintaan.', 422);
        }
        try {
            $d = $this->marketing->designRequest(trim($id));
        } catch (\Throwable) {
            throw new HrisError('marketing_tidak_terhubung', 'Desain Marketing tidak terbaca saat ini.', 503);
        }
        $r = is_array($d) ? ($d['req'] ?? null) : null;
        if (! is_array($r)) {
            throw new HrisError('not_found', 'Permintaan desain tidak ada.', 404);
        }
        $b = $this->bentuk($r);
        $b['nRef'] = isset($r['refs']) && is_array($r['refs']) ? count($r['refs']) : (int) ($b['nRef'] ?? 0);

        return $b;
    }

    private function bentuk(array $r): array
    {
        return [
            'id' => (string) ($r['id'] ?? ''), 'jenis' => (string) ($r['jenis'] ?? ''),
            'judul' => (string) ($r['judul'] ?? ''), 'brief' => (string) ($r['brief'] ?? ''),
            'acara' => (string) ($r['acara'] ?? ''), 'deadline' => (string) ($r['deadline'] ?? ''),
            'prioritas' => (string) ($r['prioritas'] ?? ''), 'pemohon' => (string) ($r['pemohon'] ?? ''),
            'brand' => (string) ($r['brand'] ?? ''), 'brandNama' => (string) ($r['brandNama'] ?? ''),
            'platforms' => $r['platforms'] ?? [], 'status' => (string) ($r['status'] ?? ''),
            'pic' => (string) ($r['pic'] ?? ''), 'doneAt' => (string) ($r['doneAt'] ?? ''),
            'nRef' => (int) ($r['nRef'] ?? 0),
        ];
    }
}
