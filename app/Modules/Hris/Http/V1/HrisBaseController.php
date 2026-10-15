<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared v1 plumbing for the HRIS controllers: the token holder as a
 * whoami-shaped identity, and the single error mapping (HrisError carries
 * its own code + status; anything else is a 422 rejection).
 */
abstract class HrisBaseController
{
    public function __construct(protected readonly OfficeAccess $access) {}

    /** @return array<string,mixed> */
    protected function me(Request $r): array
    {
        return $this->access->profile($this->access->userById((string) $r->user()->getKey()));
    }

    protected static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    protected function run(callable $fn, int $okStatus = 200): JsonResponse
    {
        try {
            return ApiResponse::ok($fn(), [], $okStatus);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    protected function failure(\Throwable $e): JsonResponse
    {
        if ($e instanceof HrisError) {
            return ApiResponse::error($e->code, $e->getMessage(), $e->status, $e->details);
        }
        if ($e instanceof \RuntimeException) {
            return ApiResponse::error('rejected', $e->getMessage(), 422);
        }

        return ApiResponse::error('server_error', 'Internal server error.', 500);
    }
}
