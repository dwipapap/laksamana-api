<?php

declare(strict_types=1);

namespace App\Modules\Automation\Maps;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Signed, self-expiring denah tokens (option A: temporary link, no storage).
 *
 * Current format (v2, compact — ~23 characters): the payload is 8 raw bytes
 * (days since epoch, minutes since midnight, unix expiry) so the whole token
 * fits comfortably in a WhatsApp message:
 *
 *     token = base64url(pack('nnN', days, minutes, exp)) . '.' . base64url(HMAC-SHA256(...)[0..7])
 *
 * The payload carries ONLY the viewing context — date, time, expiry — never
 * guest names, phones or any PII. Anything past expiry is refused; anything
 * tampered with fails the signature. There is nothing to delete afterwards
 * because nothing is ever stored.
 *
 * The older v1 format (base64url(JSON payload) + full 32-byte HMAC) is still
 * ACCEPTED on verify so links minted before this change keep working until
 * they expire; nothing mints it anymore.
 *
 * No env() here (config:cache would freeze it); the key comes from config.
 */
final class DenahLink
{
    public const TTL_SECONDS = 1800;

    /** Raw payload bytes in the compact (v2) format. */
    private const PACK_BYTES = 8;

    /** HMAC bytes kept in the compact format (64-bit tag is ample for a 30-minute link). */
    private const SIG_BYTES = 8;

    /** Days between 1970-01-01 and the epoch we count from (unix epoch here). */
    private const SECONDS_PER_DAY = 86400;

    /** @return array{token:string,expiresAt:int}|null null when the payload is invalid */
    public static function mint(string $date, string $time, int $now): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($d === false) {
            return null;
        }
        $days = intdiv($d->getTimestamp(), self::SECONDS_PER_DAY);
        $minutes = ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        if ($days < 0 || $days > 0xFFFF || $minutes < 0 || $minutes > 0xFFFF) {
            return null;
        }
        $exp = $now + self::TTL_SECONDS;

        $body = self::b64urlEncode(pack('nnN', $days, $minutes, $exp));

        return ['token' => $body.'.'.self::b64urlEncode(self::tag($body)), 'expiresAt' => $exp];
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

        $raw = self::b64urlDecode($parts[0]);
        $compact = $raw !== null && strlen($raw) === self::PACK_BYTES;

        $sigLen = $compact ? self::SIG_BYTES : 32;
        $expect = substr(hash_hmac('sha256', $parts[0], self::key(), true), 0, $sigLen);
        $given = self::b64urlDecode($parts[1]);
        if ($given === null || strlen($given) !== $sigLen || ! hash_equals($expect, $given)) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        if ($compact) {
            return self::verifyCompact($raw, $now);
        }

        return self::verifyV1($raw, $now);
    }

    /**
     * @param  string  $raw  the already-decoded 8-byte payload
     */
    private static function verifyCompact(string $raw, int $now): array
    {
        $u = unpack('ndays/nminutes/Nexp', $raw);
        if ($u === false || ! isset($u['days'], $u['minutes'], $u['exp'])) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $days = (int) $u['days'];
        $minutes = (int) $u['minutes'];
        $exp = (int) $u['exp'];
        if ($minutes > 1439 || $exp <= 0) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $date = gmdate('Y-m-d', $days * self::SECONDS_PER_DAY);
        $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if ($exp <= $now) {
            return ['ok' => false, 'error' => 'expired'];
        }

        return ['ok' => true, 'date' => $date, 'time' => $time, 'exp' => $exp];
    }

    /**
     * The legacy JSON payload (v1): accepted, never minted. Keeps links already
     * delivered before the compact format was introduced working until expiry.
     */
    private static function verifyV1(?string $raw, int $now): array
    {
        $payload = json_decode((string) $raw, true);
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

    /** Truncated HMAC tag over the encoded body. */
    private static function tag(string $body): string
    {
        return substr(hash_hmac('sha256', $body, self::key(), true), 0, self::SIG_BYTES);
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
}
