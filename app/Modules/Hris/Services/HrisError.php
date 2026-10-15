<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * A service-layer error with a machine code and an HTTP status, so
 * controllers stay thin: catch it and answer the v1 envelope directly.
 */
final class HrisError extends \RuntimeException
{
    public function __construct(
        public readonly string $code,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
