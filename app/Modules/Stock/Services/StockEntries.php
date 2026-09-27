<?php

namespace App\Modules\Stock\Services;

use stdClass;

/**
 * The Usage Panel's records (lib_stock_catat.php): event usage (`usage_events`),
 * product waste (`waste`, one photo), handovers to Kitchen/Bar (`serah_terima`,
 * photo required) and the daily stock opname (`opname`).
 *
 * Rules kept from legacy:
 *  - nothing here touches the `stock` table (it is replaced wholesale by the
 *    next Stock Today upload, so automatic deductions would vanish);
 *  - lists never carry photos (`adaFoto` only); a photo is read one at a time;
 *  - on edit, `foto` NOT sent keeps the old photo, `''` removes it;
 *  - lists of usage / waste / serah are team-scoped (StockTeamScope) in SQL;
 *  - opname stores unfilled numbers as null ("not counted" is not 0) and never
 *    stores `selisih` (always fisik − sistem, computed when shown).
 *
 * `$verified` on the save methods: v1 has already locked the row and checked it
 * exists, so the legacy existence check is skipped (see StockSupport::exists for
 * the serah_terima quirk it would otherwise trip on).
 */
class StockEntries
{
    private static function s(mixed $v): string
    {
        return trim(StockSupport::str($v ?? ''));
    }

    /** SELECT with the team scope, the date range and an optional single id. */
    private static function select(string $sql, ?array $teams, string $dari, string $ke, ?string $id): ?array
    {
        $par = [];
        if (! StockTeamScope::apply($sql, $par, $teams)) {
            return null;
        }
        if ($id !== null) {
            $sql .= StockSupport::q(' AND {id} = ?');
            $par[] = $id;
        }
        StockSupport::dateFilter($sql, $par, $dari, $ke);

        return StockSupport::db()->select($sql.' ORDER BY `tanggal` DESC, `waktu` DESC', $par);
    }

    private static function items(stdClass $d): array
    {
        return isset($d->items) && is_array($d->items) ? $d->items : [];
    }

    private static function catatan(stdClass $d): string
    {
        return isset($d->catatan) ? StockSupport::str($d->catatan) : '';
    }

    private static function deleteRow(string $table, mixed $id): array
    {
        $n = StockSupport::db()->delete(StockSupport::q("DELETE FROM {{$table}} WHERE {id}=?"), [StockSupport::str($id)]);

        return $n ? ['status' => 'success'] : ['status' => 'error', 'message' => 'tidak ditemukan'];
    }

    private static function photo(string $table, string $id): array
    {
        $r = StockSupport::db()->selectOne(StockSupport::q("SELECT `foto`,`foto_nama` FROM {{$table}} WHERE {id}=? LIMIT 1"), [$id]);
        if (! $r || $r->foto === '') {
            return ['status' => 'error', 'message' => 'foto tidak ada'];
        }

        return ['status' => 'success', 'foto' => $r->foto, 'fotoNama' => $r->foto_nama];
    }

    // ─────────────────────────────── usage (event) ──

    public function usageList(?array $teams, string $dari = '', string $ke = '', ?string $id = null): array
    {
        $out = [];
        foreach (self::select(StockSupport::q('SELECT {*usage_events} FROM {usage_events} WHERE 1=1'), $teams, $dari, $ke, $id) ?? [] as $r) {
            $d = StockSupport::obj($r->data);
            $out[] = [
                'id' => $r->id, 'tanggal' => $r->tanggal, 'jenis' => $r->jenis, 'namaEvent' => $r->nama_event,
                'status' => $r->status, 'pic' => $r->pic, 'tim' => $r->tim, 'waktu' => $r->waktu,
                'catatan' => self::catatan($d), 'items' => self::items($d),
            ];
        }

        return $out;
    }

    public function usageSave(stdClass $b, bool $verified = false): array
    {
        $id = self::s($b->id ?? '');
        $tanggal = StockSupport::fit('usage_events', 'tanggal', self::s($b->tanggal ?? ''));
        $jenis = StockSupport::fit('usage_events', 'jenis', self::s($b->jenis ?? ''));
        $nama = StockSupport::fit('usage_events', 'nama_event', self::s($b->namaEvent ?? ''));
        if ($tanggal === '') {
            return ['status' => 'error', 'message' => 'tanggal wajib diisi'];
        }
        if ($jenis === '') {
            return ['status' => 'error', 'message' => 'jenis event wajib dipilih'];
        }
        // lines without a name are dropped; a record without any line is refused
        $items = [];
        foreach ((array) ($b->items ?? []) as $it) {
            if (! is_object($it)) {
                continue;
            }
            $nm = self::s($it->item ?? '');
            if ($nm === '') {
                continue;
            }
            $items[] = (object) [
                'item' => $nm,
                'qty' => isset($it->qty) ? (float) $it->qty : 0,
                'unit' => self::s($it->unit ?? ''),
                'note' => self::s($it->note ?? ''),
            ];
        }
        if (! $items) {
            return ['status' => 'error', 'message' => 'minimal satu bahan harus diisi'];
        }
        $status = StockSupport::str($b->status ?? '') === 'Selesai' ? 'Selesai' : 'Rencana';
        $json = StockSupport::enc((object) ['catatan' => self::s($b->catatan ?? ''), 'items' => $items]);
        $pic = StockSupport::fit('usage_events', 'pic', self::s($b->pic ?? ''));
        $tim = StockSupport::fit('usage_events', 'tim', self::s($b->tim ?? ''));
        $db = StockSupport::db();

        if ($id !== '') {
            $db->update(StockSupport::q('UPDATE {usage_events}
                SET `tanggal`=?, `jenis`=?, `nama_event`=?, `status`=?, `pic`=?, `tim`=?, `data`=?
                WHERE {id}=?'), [$tanggal, $jenis, $nama, $status, $pic, $tim, $json, $id]);
            if (! $verified && ! StockSupport::exists('usage_events', $id)) {
                return ['status' => 'error', 'message' => 'catatan tidak ditemukan'];
            }

            return ['status' => 'success', 'id' => $id];
        }
        $id = StockSupport::uid('USE');
        $db->insert(StockSupport::q('INSERT INTO {usage_events}
            ({id},`tanggal`,`jenis`,`nama_event`,`status`,`pic`,`tim`,`waktu`,`data`)
            VALUES (?,?,?,?,?,?,?,?,?)'), [$id, $tanggal, $jenis, $nama, $status, $pic, $tim, StockSupport::now(), $json]);

        return ['status' => 'success', 'id' => $id];
    }

    public function usageStatus(mixed $id, mixed $status): array
    {
        $id = StockSupport::str($id);
        $status = $status === 'Selesai' ? 'Selesai' : 'Rencana';
        StockSupport::db()->update(StockSupport::q('UPDATE {usage_events} SET `status`=? WHERE {id}=?'), [$status, $id]);
        if (! StockSupport::exists('usage_events', $id)) {
            return ['status' => 'error', 'message' => 'tidak ditemukan'];
        }

        return ['status' => 'success'];
    }

    public function usageDelete(mixed $id): array
    {
        return self::deleteRow('usage_events', $id);
    }

    // ─────────────────────────────── waste ──

    public function wasteList(?array $teams, string $dari = '', string $ke = '', ?string $id = null): array
    {
        $out = [];
        $sql = StockSupport::q("SELECT {id} AS `id`,`tanggal`,`item`,`qty`,`unit`,`sebab`,`pic`,`tim`,`waktu`,
                       `foto_nama`, (`foto` <> '') AS ada_foto, `data`
                  FROM {waste} WHERE 1=1");
        foreach (self::select($sql, $teams, $dari, $ke, $id) ?? [] as $r) {
            $d = StockSupport::obj($r->data);
            $out[] = [
                'id' => $r->id, 'tanggal' => $r->tanggal, 'item' => $r->item, 'qty' => (float) $r->qty,
                'unit' => $r->unit, 'sebab' => $r->sebab, 'pic' => $r->pic, 'tim' => $r->tim, 'waktu' => $r->waktu,
                'fotoNama' => $r->foto_nama, 'adaFoto' => (bool) $r->ada_foto, 'catatan' => self::catatan($d),
            ];
        }

        return $out;
    }

    public function wastePhoto(string $id): array
    {
        return self::photo('waste', $id);
    }

    public function wasteSave(stdClass $b, bool $verified = false): array
    {
        $id = self::s($b->id ?? '');
        $tanggal = StockSupport::fit('waste', 'tanggal', self::s($b->tanggal ?? ''));
        $item = StockSupport::fit('waste', 'item', self::s($b->item ?? ''));
        $qty = isset($b->qty) ? (float) $b->qty : 0;
        if ($tanggal === '') {
            return ['status' => 'error', 'message' => 'tanggal wajib diisi'];
        }
        if ($item === '') {
            return ['status' => 'error', 'message' => 'nama produk wajib diisi'];
        }
        if ($qty <= 0) {
            return ['status' => 'error', 'message' => 'jumlah harus lebih dari 0'];
        }
        $json = StockSupport::enc((object) ['catatan' => self::s($b->catatan ?? '')]);
        $unit = StockSupport::fit('waste', 'unit', self::s($b->unit ?? ''));
        $sebab = StockSupport::fit('waste', 'sebab', self::s($b->sebab ?? ''));
        $pic = StockSupport::fit('waste', 'pic', self::s($b->pic ?? ''));
        $tim = StockSupport::fit('waste', 'tim', self::s($b->tim ?? ''));
        // foto not sent (null) = keep the old one; '' = remove it on purpose
        $fotoBaru = $b->foto ?? null;
        $db = StockSupport::db();

        if ($id !== '') {
            if ($fotoBaru === null) {
                $db->update(StockSupport::q('UPDATE {waste}
                    SET `tanggal`=?,`item`=?,`qty`=?,`unit`=?,`sebab`=?,`pic`=?,`tim`=?,`data`=?
                    WHERE {id}=?'), [$tanggal, $item, $qty, $unit, $sebab, $pic, $tim, $json, $id]);
            } else {
                $db->update(StockSupport::q('UPDATE {waste}
                    SET `tanggal`=?,`item`=?,`qty`=?,`unit`=?,`sebab`=?,`pic`=?,`tim`=?,`data`=?,`foto`=?,`foto_nama`=?
                    WHERE {id}=?'), [$tanggal, $item, $qty, $unit, $sebab, $pic, $tim, $json,
                    StockSupport::str($fotoBaru), self::s($b->fotoNama ?? ''), $id]);
            }
            if (! $verified && ! StockSupport::exists('waste', $id)) {
                return ['status' => 'error', 'message' => 'catatan tidak ditemukan'];
            }

            return ['status' => 'success', 'id' => $id];
        }
        $id = StockSupport::uid('WST');
        $db->insert(StockSupport::q('INSERT INTO {waste}
            ({id},`tanggal`,`item`,`qty`,`unit`,`sebab`,`pic`,`tim`,`waktu`,`foto`,`foto_nama`,`data`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'), [$id, $tanggal, $item, $qty, $unit, $sebab, $pic, $tim, StockSupport::now(),
            StockSupport::str($fotoBaru ?? ''), self::s($b->fotoNama ?? ''), $json]);

        return ['status' => 'success', 'id' => $id];
    }

    public function wasteDelete(mixed $id): array
    {
        return self::deleteRow('waste', $id);
    }

    // ─────────────────────────────── serah terima (handover) ──

    public function handoverList(?array $teams, string $dari = '', string $ke = '', ?string $id = null): array
    {
        $out = [];
        $sql = StockSupport::q("SELECT {id} AS `id`,`tanggal`,`tujuan`,`penerima`,`pic`,`tim`,`waktu`,
                       `foto_nama`, (`foto` <> '') AS ada_foto, `data`
                  FROM {serah_terima} WHERE 1=1");
        foreach (self::select($sql, $teams, $dari, $ke, $id) ?? [] as $r) {
            $d = StockSupport::obj($r->data);
            $out[] = [
                'id' => $r->id, 'tanggal' => $r->tanggal, 'tujuan' => $r->tujuan, 'penerima' => $r->penerima,
                'pic' => $r->pic, 'tim' => $r->tim, 'waktu' => $r->waktu, 'fotoNama' => $r->foto_nama,
                'adaFoto' => (bool) $r->ada_foto, 'catatan' => self::catatan($d), 'items' => self::items($d),
            ];
        }

        return $out;
    }

    public function handoverPhoto(string $id): array
    {
        return self::photo('serah_terima', $id);
    }

    /**
     * pur_serah_simpan(). Legacy quirk kept: an edit runs the UPDATE and then asks
     * pur_ada_baris() about `serah_terima`, which is not on its allow-list, so the
     * edit is saved but answered with a 500 (#113). v1 passes $verified.
     */
    public function handoverSave(stdClass $b, bool $verified = false): array
    {
        $id = self::s($b->id ?? '');
        $tanggal = StockSupport::fit('serah_terima', 'tanggal', self::s($b->tanggal ?? ''));
        $tujuan = StockSupport::fit('serah_terima', 'tujuan', self::s($b->tujuan ?? ''));
        if ($tanggal === '') {
            return ['status' => 'error', 'message' => 'tanggal wajib diisi'];
        }
        if ($tujuan === '') {
            return ['status' => 'error', 'message' => 'tujuan (Kitchen/Bar) wajib dipilih'];
        }
        $items = [];
        foreach ((array) ($b->items ?? []) as $it) {
            if (! is_object($it)) {
                continue;
            }
            $nm = self::s($it->item ?? '');
            $q = isset($it->qty) ? (float) $it->qty : 0;
            if ($nm === '' || $q <= 0) {
                continue;
            }
            $items[] = (object) ['item' => $nm, 'qty' => $q, 'unit' => self::s($it->unit ?? '')];
        }
        if (! $items) {
            return ['status' => 'error', 'message' => 'minimal satu item harus diisi'];
        }
        $json = StockSupport::enc((object) ['catatan' => self::s($b->catatan ?? ''), 'items' => $items]);
        $penerima = StockSupport::fit('serah_terima', 'penerima', self::s($b->penerima ?? ''));
        $pic = StockSupport::fit('serah_terima', 'pic', self::s($b->pic ?? ''));
        $tim = StockSupport::fit('serah_terima', 'tim', self::s($b->tim ?? ''));
        $fotoBaru = $b->foto ?? null;
        $db = StockSupport::db();

        if ($id !== '') {
            if ($fotoBaru === null) {
                $db->update(StockSupport::q('UPDATE {serah_terima}
                    SET `tanggal`=?,`tujuan`=?,`penerima`=?,`pic`=?,`tim`=?,`data`=? WHERE {id}=?'),
                    [$tanggal, $tujuan, $penerima, $pic, $tim, $json, $id]);
            } else {
                $db->update(StockSupport::q('UPDATE {serah_terima}
                    SET `tanggal`=?,`tujuan`=?,`penerima`=?,`pic`=?,`tim`=?,`data`=?,`foto`=?,`foto_nama`=? WHERE {id}=?'),
                    [$tanggal, $tujuan, $penerima, $pic, $tim, $json, StockSupport::str($fotoBaru), self::s($b->fotoNama ?? ''), $id]);
            }
            if (! $verified && ! StockSupport::exists('serah_terima', $id)) {
                return ['status' => 'error', 'message' => 'catatan tidak ditemukan'];
            }

            return ['status' => 'success', 'id' => $id];
        }
        // a new handover needs its photo (the proof of who took the goods)
        if ($fotoBaru === null || StockSupport::str($fotoBaru) === '') {
            return ['status' => 'error', 'message' => 'foto bukti wajib diunggah'];
        }
        $id = StockSupport::uid('SRH');
        $db->insert(StockSupport::q('INSERT INTO {serah_terima}
            ({id},`tanggal`,`tujuan`,`penerima`,`pic`,`tim`,`waktu`,`foto`,`foto_nama`,`data`)
            VALUES (?,?,?,?,?,?,?,?,?,?)'), [$id, $tanggal, $tujuan, $penerima, $pic, $tim, StockSupport::now(),
            StockSupport::str($fotoBaru), self::s($b->fotoNama ?? ''), $json]);

        return ['status' => 'success', 'id' => $id];
    }

    public function handoverDelete(mixed $id): array
    {
        return self::deleteRow('serah_terima', $id);
    }

    // ─────────────────────────────── opname ──

    /** Opname is not team-scoped (legacy passes no scope). */
    public function opnameList(string $dari = '', string $ke = '', ?string $id = null): array
    {
        $out = [];
        foreach (self::select(StockSupport::q('SELECT {*opname} FROM {opname} WHERE 1=1'), null, $dari, $ke, $id) as $r) {
            $d = StockSupport::obj($r->data);
            $out[] = [
                'id' => $r->id, 'tanggal' => $r->tanggal, 'pic' => $r->pic, 'tim' => $r->tim,
                'status' => $r->status, 'waktu' => $r->waktu, 'catatan' => self::catatan($d), 'items' => self::items($d),
            ];
        }

        return $out;
    }

    public function opnameSave(stdClass $b, bool $verified = false): array
    {
        $id = self::s($b->id ?? '');
        $tanggal = StockSupport::fit('opname', 'tanggal', self::s($b->tanggal ?? ''));
        if ($tanggal === '') {
            return ['status' => 'error', 'message' => 'tanggal wajib diisi'];
        }
        // lines are kept as they are, unfilled numbers included (opname is done in stages)
        $num = fn ($v) => ($v !== '' && $v !== null) ? (float) $v : null;
        $items = [];
        foreach ((array) ($b->items ?? []) as $it) {
            if (! is_object($it)) {
                continue;
            }
            $nm = self::s($it->item ?? '');
            if ($nm === '') {
                continue;
            }
            $items[] = (object) [
                'item' => $nm,
                'unit' => self::s($it->unit ?? ''),
                'opening' => $num($it->opening ?? null),
                'masuk' => $num($it->masuk ?? null),
                'sistem' => $num($it->sistem ?? null),
                'fisik' => $num($it->fisik ?? null),
                'note' => self::s($it->note ?? ''),
            ];
        }
        if (! $items) {
            return ['status' => 'error', 'message' => 'minimal satu produk harus diisi'];
        }
        $status = StockSupport::str($b->status ?? '') === 'Selesai' ? 'Selesai' : 'Draft';
        $json = StockSupport::enc((object) ['catatan' => self::s($b->catatan ?? ''), 'items' => $items]);
        $pic = StockSupport::fit('opname', 'pic', self::s($b->pic ?? ''));
        $tim = StockSupport::fit('opname', 'tim', self::s($b->tim ?? ''));
        $db = StockSupport::db();

        if ($id !== '') {
            $db->update(StockSupport::q('UPDATE {opname} SET `tanggal`=?,`pic`=?,`tim`=?,`status`=?,`data`=? WHERE {id}=?'),
                [$tanggal, $pic, $tim, $status, $json, $id]);
            if (! $verified && ! StockSupport::exists('opname', $id)) {
                return ['status' => 'error', 'message' => 'opname tidak ditemukan'];
            }

            return ['status' => 'success', 'id' => $id];
        }
        $id = StockSupport::uid('OPN');
        $db->insert(StockSupport::q('INSERT INTO {opname} ({id},`tanggal`,`pic`,`tim`,`status`,`waktu`,`data`) VALUES (?,?,?,?,?,?,?)'),
            [$id, $tanggal, $pic, $tim, $status, StockSupport::now(), $json]);

        return ['status' => 'success', 'id' => $id];
    }

    public function opnameDelete(mixed $id): array
    {
        return self::deleteRow('opname', $id);
    }
}
