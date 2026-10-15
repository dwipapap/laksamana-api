<?php

declare(strict_types=1);

namespace App\Modules\Hris\Jobs;

use App\Modules\Hris\Models\HrisPelamar;
use App\Modules\Hris\Services\HrisRekrutmenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Async CV screening on the `database` queue (POST …/screen?async=1).
 * Same result as the sync path, stored the same way. Needs a queue worker
 * (`php artisan queue:work`) — without one the job waits in the jobs table.
 */
final class ScreeningJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $pelamarId,
        public readonly string $by,
    ) {}

    public function handle(HrisRekrutmenService $rekrutmen): void
    {
        $row = HrisPelamar::query()->find($this->pelamarId);
        if (! $row) {
            return;
        }
        $rekrutmen->tapis($row->fresh(), $this->by);
    }
}
