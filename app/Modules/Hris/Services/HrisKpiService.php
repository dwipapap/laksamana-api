<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKpiItem;
use App\Modules\Hris\Models\HrisKpiRealisasi;
use App\Modules\Hris\Models\HrisKpiTemplate;

/**
 * Team KPI (K1): one template per Divisi (3–6 items with target, direction
 * and weight), monthly realisation, achievement clamped to 0–120% and
 * averaged by weight. Phase 1 only SHOWS team KPI; input lives behind the
 * `kpiInputAktif` setting (off until the mentor confirms the items per
 * Divisi — the seed is empty on purpose).
 */
final class HrisKpiService
{
    public function __construct(
        private readonly HrisAuditService $audit,
        private readonly HrisSettingsService $settings,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public function inputAktif(): bool
    {
        return (bool) $this->settings->get('kpiInputAktif', false);
    }

    public function assertInputAktif(): void
    {
        if (! $this->inputAktif()) {
            throw new HrisError('fitur_nonaktif',
                'Input KPI belum dibuka (menunggu daftar item per Divisi dari mentor).', 403);
        }
    }

    /** Latest template at work for the division in the month, or null. */
    public function templateUntuk(string $divisi, string $bulan): ?HrisKpiTemplate
    {
        return HrisKpiTemplate::query()->where('divisi', $divisi)
            ->where('berlaku_dari', '<=', $bulan.'-28')->orderByDesc('berlaku_dari')->first();
    }

    /**
     * Division score 0–120 (weighted achievement) with per-item capaian.
     *
     * @return array{divisi:string,bulan:string,skor:float|null,item:array,kelengkapan:float}
     */
    public function nilai(string $divisi, string $bulan): array
    {
        $t = $this->templateUntuk($divisi, $bulan);
        if (! $t) {
            return ['divisi' => $divisi, 'bulan' => $bulan, 'skor' => null, 'item' => [], 'kelengkapan' => 0.0];
        }
        $items = HrisKpiItem::query()->where('template_id', $t->id)->orderBy('nama')->get();
        $out = [];
        $bobotIsi = 0.0;
        $bobotSemua = 0.0;
        $terisi = 0;
        foreach ($items as $it) {
            $bobot = (float) $it->bobot;
            $bobotSemua += $bobot;
            $real = HrisKpiRealisasi::query()->where('item_id', $it->id)->where('bulan', $bulan)->first();
            $capaian = $real ? self::capaian((float) $it->target, (float) $real->nilai, $it->arah) : null;
            if ($capaian !== null) {
                $terisi++;
                $bobotIsi += $bobot;
            }
            $out[] = ['id' => $it->id, 'nama' => $it->nama, 'target' => (float) $it->target,
                'arah' => $it->arah, 'bobot' => $bobot, 'sumber' => $it->sumber,
                'nilai' => $real ? (float) $real->nilai : null, 'capaian' => $capaian];
        }
        $skor = null;
        if ($bobotIsi > 0) {
            $s = 0.0;
            foreach ($out as $o) {
                if ($o['capaian'] !== null) {
                    $s += $o['capaian'] * $o['bobot'];
                }
            }
            $skor = round($s / $bobotIsi, 2);
        }

        return ['divisi' => $divisi, 'bulan' => $bulan, 'skor' => $skor, 'item' => $out,
            'kelengkapan' => $bobotSemua > 0 ? round(100 * $bobotIsi / $bobotSemua, 2) : 0.0,
            'template_id' => $t->id, 'terisi' => $terisi, 'jumlah_item' => count($out)];
    }

    /**
     * Achievement 0–120%: realisasi/target (NAIK) or target/realisasi
     * (TURUN). Zero target or zero TURUN realisasi scores 0, never an error.
     */
    public static function capaian(float $target, float $nilai, string $arah): float
    {
        if ($target <= 0) {
            return 0.0;
        }
        $x = strtoupper($arah) === 'TURUN'
            ? ($nilai <= 0 ? 0.0 : $target / $nilai * 100)
            : ($nilai / $target * 100);

        return round(max(0.0, min(120.0, $x)), 2);
    }

    // ---------------------------------------------------------------- writes

    public function saveTemplate(array $in, string $by): HrisKpiTemplate
    {
        $this->assertInputAktif();
        $divisi = self::s($in['divisi'] ?? '');
        if ($divisi === '') {
            throw new HrisError('validation_failed', 'Template butuh divisi.', 422);
        }
        $dari = PunchRules::tglValid($in['berlaku_dari'] ?? '');
        if ($dari === '') {
            throw new HrisError('validation_failed', 'Berlaku-dari harus YYYY-MM-DD.', 422);
        }
        $items = is_array($in['item'] ?? null) ? $in['item'] : [];
        if (count($items) < 1 || count($items) > 12) {
            throw new HrisError('validation_failed', 'Template butuh 1–12 item.', 422);
        }
        $id = self::s($in['id'] ?? '');
        $t = $id !== '' ? HrisKpiTemplate::query()->find($id) : null;
        if ($t) {
            $t->fill(['divisi' => mb_substr($divisi, 0, 64), 'berlaku_dari' => $dari])->save();
            HrisKpiItem::query()->where('template_id', $t->id)->delete();
        } else {
            $t = HrisKpiTemplate::query()->create(['divisi' => mb_substr($divisi, 0, 64),
                'berlaku_dari' => $dari, 'dibuat_oleh' => mb_substr($by, 0, 120)]);
        }
        foreach ($items as $it) {
            $it = is_array($it) ? $it : [];
            $nama = self::s($it['nama'] ?? '');
            if ($nama === '') {
                throw new HrisError('validation_failed', 'Setiap item butuh nama.', 422);
            }
            $arah = strtoupper(self::s($it['arah'] ?? 'NAIK'));
            if ($arah !== 'NAIK' && $arah !== 'TURUN') {
                throw new HrisError('validation_failed', 'Arah item harus NAIK atau TURUN.', 422);
            }
            HrisKpiItem::query()->create([
                'template_id' => $t->id, 'nama' => mb_substr($nama, 0, 120),
                'target' => (float) ($it['target'] ?? 0), 'arah' => $arah,
                'bobot' => max(0.0, (float) ($it['bobot'] ?? 0)),
                'sumber' => strtoupper(self::s($it['sumber'] ?? 'MANUAL')) === 'OTOMATIS' ? 'OTOMATIS' : 'MANUAL',
                'kunci_sumber' => mb_substr(self::s($it['kunci_sumber'] ?? ''), 0, 120) ?: null,
            ]);
        }
        $this->audit->record($by, 'kpi_template_disimpan', 'hris_kpi_template', $t->id, ['divisi' => $divisi]);

        return $t->fresh();
    }

    public function saveRealisasi(array $in, string $by): HrisKpiRealisasi
    {
        $this->assertInputAktif();
        $item = HrisKpiItem::query()->find(self::s($in['item_id'] ?? ''));
        if (! $item) {
            throw new HrisError('not_found', 'Item KPI tidak ada.', 404);
        }
        $bulan = HrisMealService::bulanValid($in['bulan'] ?? '');
        if ($bulan === '') {
            throw new HrisError('validation_failed', 'Bulan harus YYYY-MM.', 422);
        }
        $nilai = $in['nilai'] ?? null;
        if (! is_numeric($nilai)) {
            throw new HrisError('validation_failed', 'Nilai realisasi harus angka.', 422);
        }
        $r = HrisKpiRealisasi::query()->updateOrCreate(
            ['item_id' => $item->id, 'bulan' => $bulan],
            ['nilai' => (float) $nilai, 'diinput_oleh' => mb_substr($by, 0, 120)]);
        $this->audit->record($by, 'kpi_realisasi_disimpan', 'hris_kpi_realisasi', $r->id,
            ['item_id' => $item->id, 'bulan' => $bulan]);

        return $r;
    }
}
