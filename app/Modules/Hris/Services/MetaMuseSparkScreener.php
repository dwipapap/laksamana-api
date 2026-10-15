<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use Illuminate\Support\Facades\Http;

/**
 * Meta Muse Spark screening (R3) — implementation against a plain JSON
 * contract, pending API verification (see docs/modules/hris.md):
 *
 *   POST {HRIS_AI_URL} {model, input, criteria, format: 'json'}
 *   -> {skor|score 0–100, alasan|reasons[], ringkasan|summary}
 *
 * Refuses with ai_belum_dikonfigurasi until HRIS_AI_URL + HRIS_AI_KEY are
 * set — a guessed provider contract is worse than none. Prompt version
 * HRIS-CV-1; bump it whenever the wording below changes.
 */
final class MetaMuseSparkScreener implements CvScreener
{
    public const VERSI_PROMPT = 'HRIS-CV-1';

    public const MODEL = 'muse-spark-1';

    public function provider(): string
    {
        return 'meta-muse-spark';
    }

    public function screen(string $teksRedaksi, array $kriteria): array
    {
        $url = trim((string) config('hris.ai_url'));
        $key = trim((string) config('hris.ai_key'));
        if ($url === '' || $key === '') {
            throw new HrisError('ai_belum_dikonfigurasi',
                'Skrining AI belum dikonfigurasi (HRIS_AI_URL/HRIS_AI_KEY). HR menilai manual untuk sementara.', 503);
        }
        $kriteria = array_values(array_filter(array_map(fn ($k) => mb_substr(trim((string) $k), 0, 200), $kriteria)));
        try {
            $res = Http::withToken($key)->timeout(60)->acceptJson()->post($url, [
                'model' => self::MODEL,
                'input' => mb_substr($teksRedaksi, 0, 12000),
                'criteria' => $kriteria === [] ? ['Kesesuaian umum untuk posisi ini'] : $kriteria,
                'format' => 'json',
            ]);
        } catch (\Throwable) {
            throw new HrisError('ai_gagal', 'Skrining AI tidak menjawab. Coba lagi atau nilai manual.', 503);
        }
        if (! $res->successful()) {
            throw new HrisError('ai_gagal', 'Skrining AI gagal (HTTP '.$res->status().'). Coba lagi atau nilai manual.', 503);
        }
        $d = $res->json();
        $d = is_array($d) ? ($d['hasil'] ?? $d['result'] ?? $d) : [];
        $skor = (int) ($d['skor'] ?? $d['score'] ?? -1);
        if ($skor < 0 || $skor > 100) {
            throw new HrisError('ai_gagal', 'Skrining AI menjawab di luar kontrak. Coba lagi atau nilai manual.', 503);
        }
        $alasan = $d['alasan'] ?? $d['reasons'] ?? $d['alasan_per_kriteria'] ?? [];
        $ringkasan = trim((string) ($d['ringkasan'] ?? $d['summary'] ?? ''));

        return ['skor' => $skor, 'alasan' => is_array($alasan) ? array_values($alasan) : [],
            'ringkasan' => mb_substr($ringkasan, 0, 2000), 'model' => self::MODEL];
    }

    public static function prompt(string $teksRedaksi, array $kriteria): string
    {
        return 'Nilai CV terredaksi berikut untuk kriteria: '.implode('; ', $kriteria)
            .". Jawab JSON {skor 0-100, alasan[], ringkasan}.\n\n".$teksRedaksi;
    }
}
