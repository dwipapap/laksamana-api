<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * The `data` LONGTEXT column that is the source of truth in almost every
 * legacy table ("a few indexed columns + the full JSON document").
 *
 * The one rule that must never be broken: an empty OBJECT stays an object.
 * json_decode($x, true) turns `{}` into `[]`, the frontend then writes it
 * back as an array, and keyed maps (kpiActuals, heads, bayarLunas…) silently
 * become lists. Legacy code decodes WITHOUT the assoc flag for exactly this
 * reason (hr, howandi, jadwal `jdw_peta_objek`, kompas `(object)`), so this
 * class does too. Use toArray() only when you need to read nested values and
 * will not write the result back.
 *
 * Usable as an Eloquent cast:  protected $casts = ['data' => JsonDoc::class];
 */
final class JsonDoc implements CastsAttributes
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Decode keeping objects as stdClass. null/''/invalid -> $fallback (default: empty object). */
    public static function decode(?string $json, mixed $fallback = null): mixed
    {
        if ($json === null || $json === '') {
            return $fallback ?? new stdClass;
        }
        $v = json_decode($json);

        return (json_last_error() === JSON_ERROR_NONE) ? $v : ($fallback ?? new stdClass);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /** Lossy deep conversion to arrays — for reading only, never write the result back. */
    public static function toArray(mixed $value): array
    {
        $a = json_decode(json_encode($value, self::FLAGS), true);

        return is_array($a) ? $a : [];
    }

    // --- Eloquent cast -------------------------------------------------

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return self::decode($value === null ? null : (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return is_string($value) ? $value : self::encode($value);
    }
}
