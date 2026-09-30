<?php

namespace App\Modules\Reservasi\Services;

/**
 * The narrow door into the Reservasi contract for holders of `cashier` or
 * `finance` who do not hold `reservasi` / `service_excellent` (G-14).
 *
 * The old Office let them in through `deploy/reservasi/?embed=finance`: the SSO
 * guard accepted `cashier`/`finance` for that door only and locked navigation to
 * the Dana Masuk page (laksamana-office deploy/reservasi/index.html:21-42).
 * Cashier and the Kas Kecil panel embed that page as is, so `finance` gets the
 * same rights as a cashier there: it VERIFIES and REJECTS transfers, it does not
 * just read. Master Data, Audit Log, Input and Hapus stay closed.
 *
 * The legacy backend never enforced that lock (saveAll took the whole state
 * from whoever sent it). v1 does: a PATCH from this door may only touch the DP
 * instalments and their flat mirrors, which is exactly what the Dana Masuk
 * screen writes (laksamana-office-vue app/components/DanaMasukPanel.vue,
 * tulisTransfer + cerminDp + cerminTfUtama).
 */
final class DanaMasukGate
{
    /** Modules that open this door on top of the regular reservasi|service_excellent gate. */
    public const OPENERS = ['cashier', 'finance'];

    /** Master sections the Dana Masuk screen reads (the DP destination accounts). */
    public const SECTIONS = ['dpMethods'];

    /** DP fields, besides every `tf*` transfer field. */
    private const DP_FIELDS = ['dps', 'dpStatus', 'dpMethod', 'dpAmount', 'dpProofData', 'dpProofName'];

    public static function isDpField(string $key): bool
    {
        // `tf` + an upper-case letter: tfDate, tfStatus, tfOcrText, tfOcrSaran, …
        // (a plain `tf` prefix would also match a future field like `tfoo`).
        return in_array($key, self::DP_FIELDS, true) || preg_match('/^tf[A-Z]/', $key) === 1;
    }

    /**
     * Keys of a PATCH body this door may not write, given the current row.
     *
     * Allowed: DP / transfer fields; any key whose value is unchanged (a client
     * that sends back what it read writes nothing); and `status` only for the one
     * move the Dana Masuk screen owns — money in flips Pending to Confirmed
     * (DanaMasukPanel.vue, "Money in flips a Pending booking to Confirmed").
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $current
     * @return array<int,string>
     */
    public static function rejectedFields(array $body, array $current): array
    {
        $out = [];
        foreach ($body as $key => $value) {
            $key = (string) $key;
            // id is checked against the URL by the controller; updatedAt and
            // createdAt are set by the server on every write (ReservasiRecords::put).
            if (in_array($key, ['id', 'updatedAt', 'createdAt'], true) || self::isDpField($key)) {
                continue;
            }
            if (array_key_exists($key, $current) && $current[$key] === $value) {
                continue;
            }
            if ($key === 'status' && ($current['status'] ?? null) === 'Pending' && $value === 'Confirmed') {
                continue;
            }
            $out[] = $key;
        }

        return $out;
    }
}
