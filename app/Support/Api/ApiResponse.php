<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * The ONE envelope of the new /api/v1 surface. Every v1 controller uses it,
 * so new apps never have to learn the eight legacy envelope styles.
 *
 *   success: { "data": …, "meta": { … } }
 *   failure: { "error": { "code": "…", "message": "…", "details": { … } } }
 */
final class ApiResponse
{
    public static function ok(mixed $data, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status, $headers, JSON_UNESCAPED_UNICODE);
    }

    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        $err = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $err['details'] = $details;
        }

        return response()->json(['error' => $err], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
