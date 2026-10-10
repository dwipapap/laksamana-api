<?php

namespace App\Modules\Finance\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;

/**
 * Finance → Kas Kecil › Tagihan Rutin — port of finance-mysql/lib_tagihan.php
 * for /api/v1/finance/tagihan (module `finance`).
 *
 * TWO tables, and the split is what keeps the totals trustworthy:
 *   kk_tagihan        the subscriptions (name, nominal, every-how-many-months,
 *                     first due date). What CHANGES.
 *   kk_tagihan_bayar  one row per payment that really happened, with the
 *                     nominal AS OF THEN. What must never change.
 *
 * Rules kept from legacy (each one fixed or prevents a real problem):
 *  - The nominal is COPIED onto the payment row, never re-read from the
 *    master: a price hike must not rewrite history.
 *  - Due dates are NOT stored. The client computes them from `mulai` +
 *    `siklus`; the server only guards that one due date is not paid twice
 *    (transaction + FOR UPDATE on the tagihan row, exactly like tg_bayar —
 *    a screen-side check is stale the moment a second person presses Bayar).
 *    Cancelled payments do not hold the slot.
 *  - NO DELETE for payments. A wrong entry is CANCELLED (reason required,
 *    once); an unused tagihan is DEACTIVATED, never deleted.
 *  - The tables are born at runtime via tg_pastikan(), never via a migration:
 *    the repo migrations routinely lag behind production. Until they exist
 *    the endpoints answer 503 `tagihan_unavailable` (information_schema is
 *    only READ).
 *  - Closed cycle list (1, 2, 3, 6, 12 months): a free number drifts due
 *    dates into months no provider uses, noticed only after the bill is late.
 *  - Dates are checked with checkdate(), not just the pattern: 2026-02-31
 *    passes a regex but MySQL stores 0000-00-00 silently, and the row then
 *    vanishes from every due-date computation.
 *  - Every placeholder is used ONCE per statement (EMULATE_PREPARES=false
 *    binds by position) — positional `?`, one per value.
 */
class TagihanRutin
{
    public const SIKLUS = [1, 2, 3, 6, 12];

    public function db(): ConnectionInterface
    {
        return Modules::db('finance');
    }

    // ───────────────────────────── availability ──

    /** The runtime-created tables exist; otherwise 503 tagihan_unavailable. */
    public function ready(): void
    {
        $n = (int) ($this->db()->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
            ['kk_tagihan', 'kk_tagihan_bayar']
        )->n ?? 0);
        if ($n < 2) {
            throw new TagihanConflict('unavailable',
                'Tabel Tagihan Rutin belum ada di database. Buka panel Tagihan Rutin di Office lama sekali agar tabelnya dibuat.');
        }
    }

    // ───────────────────────────── read (tg_baca) ──

    /** List tagihan + payments, the tg_baca shape (camelCase, legacy names). */
    public function list(): array
    {
        $this->ready();
        $db = $this->db();
        $tagihan = array_map([$this, 'mapTagihan'],
            $db->select('SELECT * FROM `kk_tagihan` ORDER BY `aktif` DESC, `nama` ASC'));
        $bayar = array_map([$this, 'mapBayar'],
            $db->select('SELECT * FROM `kk_tagihan_bayar` ORDER BY `tgl_bayar` DESC, `id` DESC'));

        return ['tagihan' => $tagihan, 'bayar' => $bayar];
    }

    public function tagihan(int $id): ?array
    {
        $this->ready();
        $r = $this->db()->selectOne('SELECT * FROM `kk_tagihan` WHERE `id` = ?', [$id]);

        return $r ? $this->mapTagihan($r) : null;
    }

    public function payment(int $id): ?array
    {
        $this->ready();
        $r = $this->db()->selectOne('SELECT * FROM `kk_tagihan_bayar` WHERE `id` = ?', [$id]);

        return $r ? $this->mapBayar($r) : null;
    }

    // ───────────────────────────── write: tagihan (tg_simpan) ──

    /** Create (tg_simpan without id); `dibuat_oleh` is the session user. */
    public function create(array $in, string $oleh): array
    {
        unset($in['id']);
        $this->ready();

        return $this->tagihan($this->saveTagihan(null, $in, $oleh)) ?? [];
    }

    /**
     * PATCH a tagihan. Partial bodies are merged over the current row first —
     * legacy defaults (nominal 0, siklus 1, mulai '') would otherwise wipe
     * fields the client did not send.
     */
    public function update(int $id, array $in, string $oleh): array
    {
        $this->ready();
        $cur = $this->tagihan($id);
        if (! $cur) {
            throw new TagihanConflict('not_found', 'Tagihan tidak ditemukan (id '.$id.').');
        }
        foreach (['nama', 'kategori', 'nominal', 'siklus', 'mulai', 'metode', 'catatan'] as $k) {
            if (! array_key_exists($k, $in)) {
                $in[$k] = $cur[$k];
            }
        }

        return $this->tagihan($this->saveTagihan($id, $in, $oleh)) ?? [];
    }

    /** Deactivate (or reactivate); a tagihan is never deleted. */
    public function setActive(int $id, bool $aktif, string $oleh): array
    {
        $this->ready();
        $n = $this->db()->update(
            'UPDATE `kk_tagihan` SET `aktif` = ?, `diubah_oleh` = ?, `diubah_at` = ? WHERE `id` = ?',
            [$aktif ? 1 : 0, mb_substr($oleh, 0, 80, 'UTF-8'), self::ms(), $id]);
        if ($n === 0 && ! $this->tagihan($id)) {
            throw new TagihanConflict('not_found', 'Tagihan tidak ditemukan (id '.$id.').');
        }

        return $this->tagihan($id) ?? [];
    }

    // ───────────────────────────── write: payments (tg_bayar / tg_batal) ──

    /**
     * Record a payment (tg_bayar). The nominal is copied as typed — never
     * re-read from the master later. One due date is paid ONCE: the guard
     * runs in a transaction with the tagihan row locked FOR UPDATE.
     */
    public function pay(int $tagihanId, array $in, string $oleh): array
    {
        $this->ready();
        if ($tagihanId <= 0) {
            throw new TagihanConflict('invalid', 'Tagihan belum dipilih.');
        }
        $periode = self::tanggal($in['periode'] ?? '');
        if ($periode === null) {
            throw new TagihanConflict('invalid', 'Periode (jatuh tempo yang dibayar) wajib diisi.');
        }
        $tgl = self::tanggal($in['tglBayar'] ?? '');
        if ($tgl === null) {
            throw new TagihanConflict('invalid', 'Tanggal bayar wajib diisi.');
        }
        $nominal = self::angka($in['nominal'] ?? 0);
        if ($nominal <= 0) {
            throw new TagihanConflict('invalid', 'Nominal yang dibayar wajib diisi.');
        }
        $catatan = self::teks($in['catatan'] ?? '', 255);

        $db = $this->db();

        return $db->transaction(function () use ($db, $tagihanId, $periode, $tgl, $nominal, $catatan, $oleh) {
            $t = $db->selectOne('SELECT `id`, `nama` FROM `kk_tagihan` WHERE `id` = ? FOR UPDATE', [$tagihanId]);
            if (! $t) {
                throw new TagihanConflict('not_found', 'Tagihan tidak ditemukan (id '.$tagihanId.').');
            }
            $ada = $db->selectOne(
                'SELECT `tgl_bayar`, `oleh` FROM `kk_tagihan_bayar` WHERE `tagihan_id` = ? AND `periode` = ? AND `batal_at` IS NULL LIMIT 1',
                [$tagihanId, $periode]);
            if ($ada) {
                throw new TagihanConflict('invalid', '"'.$t->nama.'" jatuh tempo '.$periode.' sudah tercatat dibayar '
                    .$ada->tgl_bayar.(($ada->oleh ?? '') !== '' ? ' oleh '.$ada->oleh : '')
                    .'. Kalau catatan itu salah, batalkan dulu di Riwayat.');
            }
            $db->insert(
                'INSERT INTO `kk_tagihan_bayar` (`tagihan_id`, `periode`, `tgl_bayar`, `nominal`, `catatan`, `oleh`, `at`) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$tagihanId, $periode, $tgl, $nominal, $catatan, mb_substr($oleh, 0, 80, 'UTF-8'), self::ms()]);
            $id = (int) $db->getPdo()->lastInsertId();
            $r = $db->selectOne('SELECT * FROM `kk_tagihan_bayar` WHERE `id` = ?', [$id]);

            return $this->mapBayar($r);
        });
    }

    /**
     * Cancel a payment (tg_batal). No DELETE: the row stays visible, struck
     * through, and excluded from totals. Reason required, once only.
     */
    public function cancel(int $id, mixed $alasan, string $oleh): array
    {
        $this->ready();
        $alasan = self::teks($alasan ?? '', 255);
        if ($alasan === '') {
            throw new TagihanConflict('invalid', 'Alasan pembatalan wajib diisi.');
        }
        $cur = $this->payment($id);
        if (! $cur) {
            throw new TagihanConflict('not_found', 'Pembayaran tidak ditemukan (id '.$id.').');
        }
        $n = $this->db()->update(
            'UPDATE `kk_tagihan_bayar` SET `batal_at` = ?, `batal_oleh` = ?, `batal_alasan` = ? WHERE `id` = ? AND `batal_at` IS NULL',
            [self::ms(), mb_substr($oleh, 0, 80, 'UTF-8'), $alasan, $id]);
        if ($n === 0) {
            throw new TagihanConflict('invalid', 'Pembayaran tidak ditemukan atau sudah dibatalkan.');
        }

        return $this->payment($id) ?? [];
    }

    // ───────────────────────────── internals ──

    /** Shared insert/update for create() and update(); returns the id. */
    private function saveTagihan(?int $id, array $in, string $oleh): int
    {
        $nama = self::teks($in['nama'] ?? '', 120);
        if ($nama === '') {
            throw new TagihanConflict('invalid', 'Nama tagihan wajib diisi.');
        }
        $nominal = self::angka($in['nominal'] ?? 0);
        if ($nominal < 0) {
            throw new TagihanConflict('invalid', 'Nominal tidak boleh minus.');
        }
        $siklus = (int) ($in['siklus'] ?? 1);
        if (! in_array($siklus, self::SIKLUS, true)) {
            throw new TagihanConflict('invalid', 'Siklus tidak dikenal: '.$siklus.' bulan.');
        }
        // `mulai` MAY be empty — a bill with an unknown date can still be
        // registered and paid; the screen marks "jatuh tempo belum diisi".
        // A filled-but-invalid date is REJECTED, never silently emptied.
        $mulaiMentah = trim((string) ($in['mulai'] ?? ''));
        $mulai = null;
        if ($mulaiMentah !== '') {
            $mulai = self::tanggal($mulaiMentah);
            if ($mulai === null) {
                throw new TagihanConflict('invalid', 'Tanggal jatuh tempo pertama tidak sah: '.$mulaiMentah);
            }
        }
        $kategori = self::teks($in['kategori'] ?? '', 80);
        $metode = self::teks($in['metode'] ?? '', 120);
        $catatan = self::teks($in['catatan'] ?? '', 500);
        $oleh = mb_substr($oleh, 0, 80, 'UTF-8');
        $now = self::ms();
        $db = $this->db();

        if ($id !== null && $id > 0) {
            $db->update(
                'UPDATE `kk_tagihan` SET `nama` = ?, `kategori` = ?, `nominal` = ?, `siklus` = ?, `mulai` = ?, `metode` = ?, `catatan` = ?, `diubah_oleh` = ?, `diubah_at` = ? WHERE `id` = ?',
                [$nama, $kategori, $nominal, $siklus, $mulai, $metode, $catatan, $oleh, $now, $id]);

            return $id;
        }
        $db->insert(
            'INSERT INTO `kk_tagihan` (`nama`, `kategori`, `nominal`, `siklus`, `mulai`, `metode`, `catatan`, `dibuat_oleh`, `dibuat_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nama, $kategori, $nominal, $siklus, $mulai, $metode, $catatan, $oleh, $now]);

        return (int) $db->getPdo()->lastInsertId();
    }

    private function mapTagihan(object $r): array
    {
        return [
            'id' => (int) $r->id, 'nama' => (string) $r->nama, 'kategori' => (string) $r->kategori,
            'nominal' => (int) $r->nominal, 'siklus' => (int) $r->siklus,
            'mulai' => $r->mulai ? (string) $r->mulai : '', 'metode' => (string) $r->metode,
            'catatan' => (string) $r->catatan, 'aktif' => (int) $r->aktif === 1,
            'dibuatOleh' => (string) $r->dibuat_oleh, 'dibuatAt' => (int) $r->dibuat_at,
            'diubahOleh' => (string) $r->diubah_oleh, 'diubahAt' => (int) $r->diubah_at,
        ];
    }

    private function mapBayar(object $r): array
    {
        return [
            'id' => (int) $r->id, 'tagihanId' => (int) $r->tagihan_id,
            'periode' => (string) $r->periode, 'tglBayar' => (string) $r->tgl_bayar,
            'nominal' => (int) $r->nominal, 'catatan' => (string) $r->catatan,
            'oleh' => (string) $r->oleh, 'at' => (int) $r->at,
            'batalAt' => $r->batal_at === null ? null : (int) $r->batal_at,
            'batalOleh' => (string) $r->batal_oleh, 'batalAlasan' => (string) $r->batal_alasan,
        ];
    }

    private static function ms(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function teks(mixed $v, int $max): string
    {
        return mb_substr(trim((string) $v), 0, $max, 'UTF-8');
    }

    /** A real calendar date (checkdate), not just the pattern — null when invalid. */
    private static function tanggal(mixed $v): ?string
    {
        $v = trim((string) $v);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $v;
    }

    private static function angka(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        $s = preg_replace('/[^0-9-]/', '', (string) $v);

        return $s === '' || $s === '-' ? 0 : (int) $s;
    }
}
