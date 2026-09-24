<?php

namespace App\Support\Legacy;

use Illuminate\Http\Response;

/**
 * Response shapes of the old backends, reproduced byte-for-byte in spirit:
 * json_encode(..., JSON_UNESCAPED_UNICODE) and HTTP 200 unless the legacy
 * module used real status codes (hr, howandi, stock).
 *
 *  okData      {ok:true,data:…}           most modules
 *  error       {ok:false,error:"…"}        most modules
 *  flat        {ok:true,user:…} etc.       account-api (whatever array is given)
 *  statusError {status:'error',message}    stock-mysql, with its HTTP code
 */
final class Envelope
{
    public static function json(mixed $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function okData(mixed $data, int $status = 200): Response
    {
        return self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 200, array $extra = []): Response
    {
        return self::json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    /** account-api style: the service already returns the complete top-level array. */
    public static function flat(array $payload, int $status = 200): Response
    {
        return self::json($payload, $status);
    }

    public static function statusError(string $message, int $status = 400): Response
    {
        return self::json(['status' => 'error', 'message' => $message], $status);
    }
}
