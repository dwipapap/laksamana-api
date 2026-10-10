<?php

declare(strict_types=1);

namespace App\Modules\Automation\Maps;

/**
 * Signed, self-expiring denah tokens (option A: temporary link, no storage).
 *
 * Token = base64url(payload) + '.' + base64url(HMAC-SHA256(payload, app.key)).
 * Payload carries ONLY the viewing context — date, time, expiry — never guest
 * names, phones or any PII. Anything past expiry is refused; anything
 * tampered with fails the signature. There is nothing to delete afterwards
 * because nothing is ever stored.
 *
 * No env() here (config:cache would freeze it); the key comes from config.
 */
final class DenahLink
{
    public const TTL_SECONDS = 1800;

    /** @return array{token:string,expiresAt:int}|null null when the payload is invalid */
    public static function mint(string $date, string $time, int $now): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        $payload = json_encode(['v' => 1, 'date' => $date, 'time' => $time, 'exp' => $now + self::TTL_SECONDS]);
        if (! is_string($payload)) {
            return null;
        }
        $body = self::b64urlEncode($payload);

        return ['token' => $body.'.'.self::b64urlEncode(hash_hmac('sha256', $body, self::key(), true)), 'expiresAt' => $now + self::TTL_SECONDS];
    }

    /**
     * @return array{ok:true,date:string,time:string,exp:int}|array{ok:false,error:'invalid'|'expired'}
     */
    public static function verify(string $token, int $now): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $expect = hash_hmac('sha256', $parts[0], self::key(), true);
        $given = self::b64urlDecode($parts[1]);
        if ($given === null || ! hash_equals($expect, $given)) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $payload = json_decode(self::b64urlDecodeToString($parts[0]), true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $date = (string) ($payload['date'] ?? '');
        $time = (string) ($payload['time'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $exp <= 0) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if ($exp <= $now) {
            return ['ok' => false, 'error' => 'expired'];
        }

        return ['ok' => true, 'date' => $date, 'time' => $time, 'exp' => $exp];
    }

    private static function key(): string
    {
        return (string) config('app.key', '');
    }

    private static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $padded = strtr($s, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($padded, true);

        return $out === false ? null : $out;
    }

    private static function b64urlDecodeToString(string $s): string
    {
        return (string) self::b64urlDecode($s);
    }
}
