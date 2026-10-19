<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Signed face proof (S3): the VPS signs the 128-float descriptor computed
 * on-device, and the punch/enrol/cek/tambah seams use the descriptor from a
 * valid proof instead of the client field.
 *
 * Token: base64url(canonical payload JSON) + "." + base64url(raw
 * HMAC-SHA256 over the payload bytes). The payload keys run
 * v,kid,model,descriptor,fotoSha256,iat with no spaces. A proof binds the
 * request `model` field and, when a photo is sent, the photo bytes
 * (fotoSha256 = SHA-256 hex of the decoded base64). Proofs live 120 s with
 * a 30 s future clock-skew tolerance; server time rules, never the phone.
 *
 * Without HRIS_FACE_SECRET every proof is refused and the mandatory flag
 * counts as false, so old clients keep working.
 */
final class BuktiWajah
{
    /** A proof is usable for 120 s after its iat. */
    public const UMUR_MAKS = 120;

    /** Future clock-skew tolerance in seconds (a slightly fast VPS clock). */
    public const TOLERANSI_MASA_DEPAN = 30;

    /** Whether a proof is required: flag on AND a signing secret present. */
    public static function wajib(): bool
    {
        if (trim((string) config('hris.face_secret', '')) === '') {
            return false;
        }

        return (bool) config('hris.face_bukti_wajib', false);
    }

    /**
     * Verify a proof and return its descriptor (validated through the same
     * cleaner as client descriptors). Throws bukti_wajah_tidak_valid (422)
     * on any failure: unknown format, bad signature, unknown kid, model or
     * photo mismatch, expiry, or no signing secret configured.
     *
     * @return array<int,float>
     */
    public static function verifikasi(string $token, string $model, ?string $fotoBase64 = null, ?int $sekarang = null): array
    {
        $secret = (string) config('hris.face_secret', '');
        $kid = (string) config('hris.face_kid', '');
        $secretLama = (string) config('hris.face_secret_lama', '');
        $kidLama = (string) config('hris.face_kid_lama', '');
        if (trim($secret) === '') {
            self::tolak();
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            self::tolak();
        }
        $payloadJson = self::b64UrlDecode($parts[0]);
        $sig = self::b64UrlDecode($parts[1]);
        if ($payloadJson === null || $sig === null) {
            self::tolak();
        }
        /** @var mixed $payload */
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            self::tolak();
        }
        $tokenKid = isset($payload['kid']) && is_string($payload['kid']) ? $payload['kid'] : '';
        $kunci = null;
        if ($tokenKid !== '' && $kid !== '' && hash_equals($kid, $tokenKid)) {
            $kunci = $secret;
        } elseif ($tokenKid !== '' && $kidLama !== '' && hash_equals($kidLama, $tokenKid)) {
            $kunci = $secretLama;
        }
        if ($kunci === null) {
            self::tolak();
        }
        if (! hash_equals(hash_hmac('sha256', $payloadJson, $kunci, true), $sig)) {
            self::tolak();
        }
        $payloadModel = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : '';
        if ($payloadModel === '' || ! hash_equals(trim($model), $payloadModel)) {
            self::tolak();
        }
        $iat = $payload['iat'] ?? null;
        if (is_string($iat) && ctype_digit($iat)) {
            $iat = (int) $iat;
        }
        if (! is_int($iat)) {
            self::tolak();
        }
        $umur = ($sekarang ?? time()) - $iat;
        if ($umur < -self::TOLERANSI_MASA_DEPAN || $umur > self::UMUR_MAKS) {
            self::tolak();
        }
        $foto = is_string($fotoBase64) ? trim($fotoBase64) : '';
        if ($foto !== '') {
            $bytes = self::fotoBytes($foto);
            $sha = isset($payload['fotoSha256']) && is_string($payload['fotoSha256'])
                ? strtolower($payload['fotoSha256']) : '';
            if ($bytes === null || $sha === '' || ! hash_equals($sha, hash('sha256', $bytes))) {
                self::tolak();
            }
        }
        try {
            return HrisAttendanceService::bersihkanDeskriptor($payload['descriptor'] ?? null);
        } catch (HrisError) {
            self::tolak();
        }
    }

    private static function tolak(): never
    {
        throw new HrisError('bukti_wajah_tidak_valid', 'Bukti wajah tidak valid. Ambil ulang foto wajah.', 422);
    }

    private static function b64UrlDecode(string $s): ?string
    {
        if ($s === '' || preg_match('/[^A-Za-z0-9\-_]/', $s) === 1) {
            return null;
        }
        $std = strtr($s, '-_', '+/');
        $pad = strlen($std) % 4;
        if ($pad !== 0) {
            $std .= str_repeat('=', 4 - $pad);
        }
        $b = base64_decode($std, true);

        return $b === false ? null : $b;
    }

    /** Decoded photo bytes (same data: prefix rule as the stored photo). */
    private static function fotoBytes(string $foto): ?string
    {
        if (str_starts_with($foto, 'data:')) {
            $pos = strpos($foto, ',');
            $foto = $pos === false ? '' : substr($foto, $pos + 1);
        }
        $b = base64_decode($foto, true);

        return $b === false || $b === '' ? null : $b;
    }
}
