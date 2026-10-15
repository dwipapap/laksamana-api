<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * AI CV screening behind an interface (R3): the score is advice with
 * reasons — HR decides. The provider is Meta Muse Spark, but its HTTP API
 * is NOT yet verified (no research access from here): the implementation
 * reads HRIS_AI_URL/HRIS_AI_KEY and speaks a plain JSON contract, and
 * refuses loudly until both are set. Research notes live in
 * docs/modules/hris.md (R3). The key lives only in the server .env and is
 * never logged; every result is stored with its prompt and model version.
 */
interface CvScreener
{
    public function provider(): string;

    /**
     * @param  array<int,string>  $kriteria
     * @return array{skor:int,alasan:array,ringkasan:string,model:string}
     */
    public function screen(string $teksRedaksi, array $kriteria): array;
}
