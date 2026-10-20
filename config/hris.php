<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HRIS AI screening (B5)
|--------------------------------------------------------------------------
| The Muse Spark key lives ONLY here (server .env, never logged, never
| served). HRIS_AI_URL points at the screening endpoint once the API
| research in docs/modules/hris.md lands; until then screening is refused
| with a clear error instead of guessing a provider contract.
*/

return [
    'ai_url' => env('HRIS_AI_URL', ''),
    'ai_key' => env('HRIS_AI_KEY', ''),

    /*
    | Signed face proof (S3). The VPS signs the on-device embedding; the
    | endpoints accept the descriptor from a valid proof instead of the
    | client field. Empty secret = no proof is ever accepted and the
    | mandatory flag counts as false (legacy client-descriptor behaviour).
    */
    'face_secret' => env('HRIS_FACE_SECRET', ''),
    'face_kid' => env('HRIS_FACE_KID', ''),
    'face_secret_lama' => env('HRIS_FACE_SECRET_LAMA', ''),
    'face_kid_lama' => env('HRIS_FACE_KID_LAMA', ''),
    'face_bukti_wajib' => filter_var(env('HRIS_FACE_BUKTI_WAJIB', false), FILTER_VALIDATE_BOOL),

    /*
    | E10 (ADR-0012): HRIS as Akses Bawaan only for Users with a Karyawan
    | record. An env switch, not an HR setting, so the per-request auth path
    | costs no query while it is off. Turn on after HR has reviewed the
    | `hris:impor-karyawan` report.
    */
    'hanya_karyawan' => filter_var(env('HRIS_HANYA_KARYAWAN', false), FILTER_VALIDATE_BOOL),
];
