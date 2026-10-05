<?php

declare(strict_types=1);

namespace App\Core\Imports;

/**
 * An importer that leaves some rows for a person to decide instead of guessing
 * (ERP v2 imports). core:import prints these after the run.
 */
interface ReportsIssues
{
    /** @return array<string, list<string>> issue kind => one line per affected row */
    public function issues(): array;
}
