<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Imports\MenulisImpor;
use App\Erp\Master\Imports\PencocokUser;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Talent;
use App\Erp\Tamu\Models\AturanJadwalTalent;
use App\Erp\Tamu\Models\Buyer;
use App\Erp\Tamu\Models\BuyerSesi;
use App\Erp\Tamu\Models\CheckinTiket;
use App\Erp\Tamu\Models\Event;
use App\Erp\Tamu\Models\EventAnggaran;
use App\Erp\Tamu\Models\EventIde;
use App\Erp\Tamu\Models\EventSponsor;
use App\Erp\Tamu\Models\EventVendor;
use App\Erp\Tamu\Models\JadwalTalent;
use App\Erp\Tamu\Models\KelasTiket;
use App\Erp\Tamu\Models\Kursi;
use App\Erp\Tamu\Models\PembayaranTalent;
use App\Erp\Tamu\Models\PesananTiket;
use App\Erp\Tamu\Models\PesananTiketBaris;
use App\Erp\Tamu\Models\RefundTiket;
use App\Erp\Tamu\Models\Tiket;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Event & Tiket (docs/erp/event-tiket.md "Pemetaan lama → v2") from
 * the legacy EMS database (event + public ticket shop). Run after
 * `erp-orang` (talent). Idempotent.
 *
 * Legacy tickets point at order items by an id the items never had, so a
 * ticket is linked to the order line holding its seat; one that matches no
 * line is reported, not guessed.
 */
final class EventImporter implements Importer, ReportsIssues
{
    use MenulisImpor;

    private const STATUS_EVENT = ['Planning' => 'planning', 'Draft' => 'planning', 'Prospect' => 'prospect', 'Approval' => 'approval',
        'Upcoming' => 'upcoming', 'Today' => 'upcoming', 'Confirmed' => 'upcoming', 'Ongoing' => 'upcoming', 'Event Done' => 'selesai', 'Finished' => 'selesai'];

    private const STATUS_TIKET = ['Valid' => 'valid', 'Checked-In' => 'digunakan', 'Used' => 'digunakan', 'Cancelled' => 'batal', 'Refunded' => 'refund'];

    private const STATUS_BAYAR_TALENT = ['Waiting' => 'pending', 'Pending' => 'pending', 'Confirmed' => 'confirmed', 'Paid' => 'paid', 'Rejected' => 'rejected'];

    public function __construct(private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-event';
    }

    public function legacyConnections(): array
    {
        return ['legacy_ems'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function import(): int
    {
        $this->issues = [];
        $this->users->reset();
        $ems = DB::connection('legacy_ems');
        $n = 0;

        DB::connection('core')->transaction(function () use ($ems, &$n): void {
            $outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
            $ide = [];
            foreach ($ems->table('ideas')->orderBy('id')->get() as $r) {
                $ide[(string) $r->id] = $this->importIde($this->json($r->data), (string) $r->id);
                $n++;
            }
            $event = [];
            foreach ($ems->table('events')->orderBy('id')->get() as $r) {
                $event[(string) $r->id] = $this->importEvent($this->json($r->data), $r, $outlet, $ide);
                $n++;
            }
            foreach ($ems->table('event_details')->get() as $r) {
                if (isset($event[(string) $r->event_id])) {
                    $this->importDetail($event[(string) $r->event_id], $this->json($r->data));
                }
            }
            $kelas = [];
            foreach ($ems->table('ticket_classes')->orderBy('id')->get() as $r) {
                $d = $this->json($r->data);
                if (! isset($event[(string) $r->event_id])) {
                    $this->issue('kelas_tanpa_event', (string) $r->id);

                    continue;
                }
                $kelas[(string) $r->id] = $this->upsert(KelasTiket::withTrashed()->firstOrNew(['legacy_id' => (string) $r->id]), [
                    'event_id' => $event[(string) $r->event_id]->id, 'nama' => $this->text($r->name, 120) ?? '-',
                    'harga' => $this->rp($r->price), 'kuota' => (int) $r->quota > 0 ? (int) $r->quota : null,
                    'manfaat' => $this->text($d['benefit'] ?? null, 500), 'deskripsi' => $this->text($d['description'] ?? null, 65000),
                    'bertempat' => (bool) $r->is_seated, 'jual_mulai' => $this->waktu($d['sale_start'] ?? null), 'jual_selesai' => $this->waktu($d['sale_end'] ?? null),
                ]);
                $n++;
            }
            $kursi = [];
            foreach ($ems->table('seats')->orderBy('id')->cursor() as $r) {
                $d = $this->json($r->data);
                if (! isset($event[(string) $r->event_id])) {
                    continue;
                }
                $label = trim((string) ($d['seat_no'] ?? '')) ?: trim((string) $r->table_no);
                $kursi[(string) $r->id] = $this->upsert(Kursi::firstOrNew(['legacy_id' => (string) $r->id]), [
                    'event_id' => $event[(string) $r->event_id]->id, 'kelas_tiket_id' => ($kelas[(string) $r->ticket_class_id] ?? null)?->id,
                    'kode' => (string) $r->id, // labels repeat inside an event; the legacy id is unique
                    'jenis' => in_array($d['kind'] ?? 'seat', ['seat', 'table', 'area'], true) ? ($d['kind'] ?? 'seat') : 'seat',
                    'zona' => $this->text($r->zone, 32), 'tier' => $this->text($d['tier'] ?? null, 32), 'lantai' => $this->text($d['floor'] ?? null, 16),
                    'kapasitas' => max(1, (int) ($d['capacity'] ?? 1)),
                    'status' => ['Available' => 'tersedia', 'Locked' => 'terkunci', 'Sold' => 'terjual'][(string) $r->status] ?? 'tersedia',
                    'tata_letak' => array_intersect_key($d, array_flip(['x', 'y', 'w', 'h', 'shape', 'pola', 'petak'])) + ['label' => $label],
                ]);
                $n++;
            }
            $buyer = $this->importBuyer($ems);
            $n += count($buyer);
            [$baris, $pesanan, $jumlah] = $this->importPesanan($ems, $event, $kelas, $kursi, $buyer);
            $n += $jumlah;
            $n += $this->importTiket($ems, $kelas, $kursi, $baris);
            $n += $this->importRefund($ems, $pesanan);
            $n += $this->importTalent($ems, $event);
        });

        return $n;
    }

    /** @param array<string, mixed> $d */
    private function importIde(array $d, string $id): EventIde
    {
        return $this->upsert(EventIde::withTrashed()->firstOrNew(['legacy_id' => $id]), [
            'nama' => $this->text($d['name'] ?? null, 190) ?? '-', 'kategori' => $this->text($d['category'] ?? null, 60),
            'frekuensi' => $this->text($d['frequency'] ?? null, 32), 'kesulitan' => $this->text($d['difficulty'] ?? null, 32),
            'target_pasar' => $this->text($d['target_market'] ?? null, 190),
            'anggaran' => isset($d['budget']) && $d['budget'] !== '' ? $this->rp($d['budget']) : null,
            'potensi_pendapatan' => isset($d['potential_revenue']) && $d['potential_revenue'] !== '' ? $this->rp($d['potential_revenue']) : null,
            'pernah_dijalankan' => ! empty($d['ever_executed']), 'deskripsi' => $this->text($d['description'] ?? null, 65000),
            'evaluasi' => ! empty($d['evaluations']) ? (array) $d['evaluations'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, EventIde>  $ide
     */
    private function importEvent(array $d, object $r, Lokasi $outlet, array $ide): Event
    {
        $status = self::STATUS_EVENT[(string) ($d['status'] ?? $r->status)] ?? null;
        $peng = (array) ($d['pengajuan'] ?? []);
        if ((string) ($d['status'] ?? '') === 'Cancelled') { // legacy migration: a cancelled event is Planning with a refused submission
            $status = 'planning';
            $peng += ['status' => 'Ditolak'];
        }
        if (! $status) {
            $this->issue('status_event_tidak_dikenal', "{$r->id}: ".($d['status'] ?? ''));
            $status = 'planning';
        }
        $venue = trim((string) ($d['venue'] ?? $r->venue));
        $milik = $venue === '' || str_contains(mb_strtolower($venue), 'laksamana');
        [$pic, $picImpor] = $this->oleh($d['pic'] ?? $r->pic);
        [$co, $coImpor] = $this->oleh($d['co_pic'] ?? null);
        $mulai = $this->waktu($d['start_datetime'] ?? $r->start_datetime);
        $selesai = $this->waktu($d['end_datetime'] ?? $r->end_datetime);
        if ($mulai && $selesai && $selesai < $mulai) {
            $this->issue('event_selesai_sebelum_mulai', (string) $r->id);
            $selesai = null;
        }
        $keputusan = ['Disetujui' => 'disetujui', 'Ditolak' => 'ditolak'][(string) ($peng['status'] ?? '')] ?? null;
        [$penyetuju] = $this->oleh($peng['penyetuju'] ?? null);

        return $this->upsert(Event::withTrashed()->firstOrNew(['legacy_id' => (string) $r->id]), [
            'nama' => $this->text($d['title'] ?? $r->title, 190) ?? '-', 'tema' => $this->text($d['theme'] ?? null, 190),
            'kategori' => $this->text($d['category'] ?? $r->category, 60), 'status' => $status,
            'lokasi_id' => $milik && $venue !== '' ? $outlet->id : null, 'venue_impor' => $milik ? null : $this->text($venue, 120),
            'event_ide_id' => ($ide[(string) ($d['idea_id'] ?? $r->idea_id)] ?? null)?->id,
            'pic_id' => $pic, 'pic_impor' => $picImpor, 'co_pic_id' => $co, 'co_pic_impor' => $coImpor,
            'mulai_at' => $mulai, 'selesai_at' => $selesai,
            'kapasitas' => (int) ($d['capacity'] ?? $r->capacity) > 0 ? (int) ($d['capacity'] ?? $r->capacity) : null,
            'bertiket' => ! empty($d['is_ticketed'] ?? $r->is_ticketed), 'poster_key' => $this->text($d['poster_img'] ?? $d['poster'] ?? null, 190),
            'deskripsi' => $this->text($d['description'] ?? null, 65000),
            'diajukan_at' => $this->waktu($peng['pada'] ?? null),
            'keputusan' => $keputusan, 'diputuskan_at' => $keputusan ? ($this->waktu($peng['pada'] ?? null) ?? $this->waktu($d['updatedAt'] ?? null)) : null,
            'diputuskan_oleh' => $keputusan ? $penyetuju : null,
        ]);
    }

    /** @param array<string, mixed> $d legacy event_details */
    private function importDetail(Event $event, array $d): void
    {
        $this->upsert($event, ['rencana' => array_filter([
            'timeline' => $d['timeline'] ?? null, 'rundown' => $d['rundown'] ?? null, 'tasks' => $d['tasks'] ?? null, 'layout' => $d['layout'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '') ?: null]);

        foreach ([[EventVendor::class, 'vendors', 'cost'], [EventSponsor::class, 'sponsors', 'nominal']] as [$model, $key, $uang]) {
            $baru = [];
            foreach ((array) ($d[$key] ?? []) as $v) {
                $v = (array) $v;
                $nama = trim((string) ($v['name'] ?? ''));
                $pihak = $nama !== '' ? Pihak::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->value('id') : null;
                $status = strtolower((string) ($v['status'] ?? 'pending'));
                $baru[] = [
                    'event_id' => $event->id, 'pihak_id' => $pihak, 'nama_impor' => $pihak ? null : $this->text($nama, 190),
                    'keterangan' => $this->text($v['package'] ?? $v['pic'] ?? null, 255), 'nominal' => $this->rp($v[$uang] ?? 0),
                    'status' => in_array($status, ['pending', 'confirmed', 'paid'], true) ? $status : 'pending',
                ];
            }
            // legacy rows have no ids: rebuild only when the list changed, so a re-run writes nothing
            $ada = $model::where('event_id', $event->id)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn ($m) => array_map('strval', $m->only(array_keys($baru[0] ?? ['event_id' => 1]))))->all();
            if ($ada !== array_map(fn ($b) => array_map('strval', $b), $baru)) {
                $model::where('event_id', $event->id)->delete();
                foreach ($baru as $b) {
                    $model::create($b);
                }
            }
        }
        $i = 0;
        foreach ((array) ($d['budget'] ?? []) as $b) {
            $b = (array) $b;
            $this->upsert(EventAnggaran::firstOrNew(['event_id' => $event->id, 'urutan' => ++$i]), [
                'item' => $this->text($b['item'] ?? null, 190) ?? '-', 'jenis' => $this->text($b['type'] ?? null, 40),
                'anggaran' => $this->rp(max(0, (float) ($b['budget'] ?? 0))),
                'realisasi' => ($b['actual'] ?? '') === '' ? null : $this->rp(max(0, (float) $b['actual'])),
            ]);
        }
        EventAnggaran::where('event_id', $event->id)->where('urutan', '>', $i)->delete();
    }

    /** @return array<string, Buyer> */
    private function importBuyer($ems): array
    {
        $out = [];
        foreach ($ems->table('tix_users')->orderBy('id')->get() as $u) {
            $out[(string) $u->id] = $this->upsert(Buyer::firstOrNew(['legacy_id' => (string) $u->id]), [
                'email' => mb_strtolower(trim((string) $u->email)), 'pass_hash' => $u->pass_hash ?: null,
                'nama' => $this->text($u->name, 120), 'telepon' => $this->text($u->phone, 32),
            ]);
        }
        foreach ($ems->table('tix_sessions')->get() as $s) {
            $b = $out[(string) $s->user_id] ?? null;
            $exp = $this->waktu($s->expires_at);
            if ($b && $exp) {
                $this->upsert(BuyerSesi::firstOrNew(['token_hash' => hash('sha256', (string) $s->token)]), ['buyer_id' => $b->id, 'berakhir_at' => $exp]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, Event>  $event
     * @param  array<string, KelasTiket>  $kelas
     * @param  array<string, Kursi>  $kursi
     * @param  array<string, Buyer>  $buyer
     * @return array{0: array<string, PesananTiketBaris>, 1: array<string, PesananTiket>, 2: int} seat legacy id => line, order id => order
     */
    private function importPesanan($ems, array $event, array $kelas, array $kursi, array $buyer): array
    {
        $baris = $pesanan = [];
        $n = 0;
        foreach ($ems->table('orders')->orderBy('id')->get() as $o) {
            $d = $this->json($o->data);
            $ev = $event[(string) ($d['event_id'] ?? $o->event_id)] ?? null;
            if (! $ev) {
                $this->issue('pesanan_tanpa_event', (string) $o->id);

                continue;
            }
            $status = strtolower((string) ($d['payment_status'] ?? $o->payment_status));
            $status = in_array($status, ['pending', 'paid', 'expired', 'cancelled', 'refunded'], true) ? $status : 'pending';
            $sub = (int) $this->rp($d['subtotal'] ?? $o->total);
            $fee = (int) $this->rp($d['fee'] ?? 0);
            if ($sub + $fee !== (int) $this->rp($d['total'] ?? $o->total)) {
                $this->issue('total_bukan_subtotal_plus_biaya', (string) $o->id);
            }
            $bayar = null;
            if (in_array($status, ['paid', 'refunded'], true)) {
                $bayar = $this->waktu($d['_cek_at'] ?? null) ?? $this->waktu($o->updated_at);
                $this->issue('waktu_bayar_dari_cek_terakhir', (string) $o->id);
            }
            [$pencatat] = $this->oleh($d['recorded_by'] ?? null);
            $p = $this->upsert(PesananTiket::firstOrNew(['legacy_id' => (string) $o->id]), [
                'nomor' => mb_substr((string) ($d['payment_ref'] ?? '') ?: (string) $o->id, 0, 40), 'event_id' => $ev->id,
                'buyer_id' => ($buyer[(string) ($d['user_id'] ?? '')] ?? null)?->id,
                'nama_pembeli' => $this->text($d['buyer_name'] ?? $o->buyer_name, 120) ?? '-',
                'email' => $this->text($d['email'] ?? $o->email, 191), 'telepon' => $this->text($d['phone'] ?? $o->phone, 32),
                'tanggal_lahir' => $this->tanggal($d['birthdate'] ?? null), 'kanal' => $this->text($d['channel'] ?? $d['recorded_via'] ?? null, 20),
                'subtotal' => (string) $sub, 'biaya' => (string) $fee, 'total' => (string) ($sub + $fee), 'status_bayar' => $status,
                'xendit_ref' => $this->text($d['payment_ref'] ?? $o->payment_ref, 64),
                'akses_token_hash' => ! empty($d['access_token']) ? hash('sha256', (string) $d['access_token']) : null,
                'berakhir_at' => $this->waktu($d['expires_at'] ?? null), 'dibayar_at' => $bayar,
                'dicatat_oleh' => $pencatat, 'catatan' => $this->text($d['notes'] ?? null, 500),
            ]);
            $pesanan[(string) $o->id] = $p;
            $i = 0;
            foreach ((array) ($d['items'] ?? []) as $it) {
                $it = (array) $it;
                $k = $kelas[(string) ($it['class_id'] ?? '')] ?? null;
                if (! $k) {
                    $this->issue('item_tanpa_kelas', "{$o->id}");

                    continue;
                }
                $row = $this->upsert(PesananTiketBaris::firstOrNew(['legacy_id' => "{$o->id}#".(++$i)]), [
                    'pesanan_tiket_id' => $p->id, 'kelas_tiket_id' => $k->id, 'qty' => 1, 'harga' => $this->rp(max(0, (float) ($it['price'] ?? 0))),
                ]);
                if (($it['seat_id'] ?? '') !== '') {
                    $baris[(string) $it['seat_id']] = $row;
                }
            }
            PesananTiketBaris::where('pesanan_tiket_id', $p->id)->where('legacy_id', 'like', "{$o->id}#%")
                ->get()->filter(fn ($r) => (int) substr((string) $r->legacy_id, strlen((string) $o->id) + 1) > $i)->each->delete();
            $n++;
        }

        return [$baris, $pesanan, $n];
    }

    /**
     * @param  array<string, KelasTiket>  $kelas
     * @param  array<string, Kursi>  $kursi
     * @param  array<string, PesananTiketBaris>  $baris  seat legacy id => order line
     */
    private function importTiket($ems, array $kelas, array $kursi, array $baris): int
    {
        $n = 0;
        foreach ($ems->table('tickets')->orderBy('id')->get() as $t) {
            $line = $baris[(string) $t->seat_id] ?? null;
            if (! $line || ! isset($kelas[(string) $t->ticket_class_id])) {
                $this->issue('tiket_tanpa_baris_pesanan', (string) $t->id);

                continue;
            }
            $d = $this->json($t->data);
            $tiket = $this->upsert(Tiket::firstOrNew(['legacy_id' => (string) $t->id]), [
                'nomor' => $this->text($t->ticket_number, 40) ?? (string) $t->id, 'pesanan_tiket_baris_id' => $line->id,
                'kelas_tiket_id' => $kelas[(string) $t->ticket_class_id]->id, 'kursi_id' => ($kursi[(string) $t->seat_id] ?? null)?->id,
                'qr_token' => $this->text($t->qr_token, 64) ?? hash('sha256', (string) $t->id),
                'status' => self::STATUS_TIKET[(string) $t->status] ?? 'valid',
                'diterbitkan_at' => $this->waktu($d['createdAt'] ?? null), 'pdf_key' => $this->text($d['pdf_url'] ?? null, 190),
            ]);
            foreach ($ems->table('checkins')->where('ticket_id', $t->id)->get() as $c) {
                $at = $this->waktu($c->checked_in_at);
                if (! $at) {
                    continue;
                }
                [$petugas, $impor] = $this->oleh($c->staff);
                $this->upsert(CheckinTiket::firstOrNew(['legacy_id' => (string) $c->id]), [
                    'tiket_id' => $tiket->id, 'jenis' => 'checkin', 'gerbang' => $this->text($c->gate, 32),
                    'petugas_id' => $petugas, 'petugas_impor' => $impor, 'dipindai_at' => $at,
                    'alasan' => $c->result === 'Valid' ? null : $this->text($c->result, 255),
                ]);
            }
            $n++;
        }

        return $n;
    }

    /** @param array<string, PesananTiket> $pesanan */
    private function importRefund($ems, array $pesanan): int
    {
        $n = 0;
        foreach ($ems->table('refunds')->orderBy('id')->get() as $r) {
            $d = $this->json($r->data);
            $p = $pesanan[(string) $r->order_id] ?? null;
            if (! $p) {
                $this->issue('refund_tanpa_pesanan', (string) $r->id);

                continue;
            }
            $status = ['Approved' => 'disetujui', 'Rejected' => 'ditolak'][(string) $r->status] ?? 'diminta';
            $minta = $this->waktu($d['requested_at'] ?? $d['createdAt'] ?? null) ?? $this->waktu($r->updated_at);
            [$putus, $putusImpor] = $this->oleh($d['penyetuju'] ?? null);
            $this->upsert(RefundTiket::firstOrNew(['legacy_id' => (string) $r->id]), [
                'pesanan_tiket_id' => $p->id, 'alasan' => $this->text($d['reason'] ?? null, 500), 'status' => $status,
                'diminta_at' => $minta,
                'diputuskan_at' => $status === 'diminta' ? null : ($this->waktu($d['decided_at'] ?? $d['approved_at'] ?? $d['updatedAt'] ?? null) ?? $minta),
                'diputuskan_oleh' => $status === 'diminta' ? null : $putus, 'penyetuju_impor' => $status === 'diminta' ? null : $putusImpor,
            ]);
            $n++;
        }

        return $n;
    }

    /** @param array<string, Event> $event */
    private function importTalent($ems, array $event): int
    {
        $n = 0;
        $talent = Talent::withTrashed()->pluck('pihak_id', 'legacy_id')->all();
        $aturan = [];
        foreach ($ems->table('recurring_rules')->orderBy('id')->get() as $r) {
            $d = $this->json($r->data);
            $tid = $talent[(string) ($d['talent_id'] ?? '')] ?? null;
            $mask = array_sum(array_map(fn ($h) => 1 << ((int) $h % 7), array_unique((array) ($d['days_of_week'] ?? []))));
            if (! $tid || $mask < 1 || ! $this->tanggal($d['valid_from'] ?? $r->valid_from)) {
                $this->issue('aturan_jadwal_tidak_lengkap', (string) $r->id);

                continue;
            }
            $aturan[(string) $r->id] = $this->upsert(AturanJadwalTalent::firstOrNew(['legacy_id' => (string) $r->id]), [
                'talent_id' => $tid, 'hari' => $mask, 'mulai' => $this->jam($d['start_time'] ?? null), 'selesai' => $this->jam($d['end_time'] ?? null),
                'jenis_tampil' => $this->text($d['performance_type'] ?? null, 60), 'tarif' => isset($d['fee']) ? $this->rp($d['fee']) : null,
                'berlaku_dari' => $this->tanggal($d['valid_from'] ?? $r->valid_from), 'berlaku_sampai' => $this->tanggal($d['valid_to'] ?? $r->valid_to),
            ]);
            $n++;
        }
        $bayar = [];
        foreach ($ems->table('talent_payments')->orderBy('id')->get() as $r) {
            $d = $this->json($r->data);
            $tid = $talent[(string) $r->talent_id] ?? null;
            $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $r->period_month) ? $r->period_month.'-01' : null;
            if (! $tid || ! $bulan) {
                $this->issue('pembayaran_talent_tidak_lengkap', (string) $r->id);

                continue;
            }
            $status = self::STATUS_BAYAR_TALENT[(string) $r->status] ?? 'pending';
            $paid = $status === 'paid' ? ($this->waktu($d['paid_at'] ?? null) ?? $this->waktu($d['updatedAt'] ?? null)) : null;
            [$pic, $picImpor] = $this->oleh($d['pic_finance'] ?? null);
            $bayar[(string) $r->id] = $this->upsert(PembayaranTalent::firstOrNew(['talent_id' => $tid, 'bulan' => $bulan]), [
                'legacy_id' => (string) $r->id, 'jumlah_tampil' => (int) $r->show_count, 'total' => $this->rp($r->total_amount),
                'status' => $status, 'dibayar_at' => $paid, 'pic_finance_id' => $pic, 'pic_finance_impor' => $picImpor,
                'bukti_key' => $this->text($d['transfer_proof'] ?? null, 190),
            ]);
            $n++;
        }
        foreach ($ems->table('schedules')->orderBy('id')->cursor() as $r) {
            $d = $this->json($r->data);
            $tid = $talent[(string) $r->talent_id] ?? null;
            $tgl = $this->tanggal($d['date'] ?? $r->tanggal);
            if (! $tid || ! $tgl) {
                $this->issue('jadwal_talent_tidak_lengkap', (string) $r->id);

                continue;
            }
            $status = strtolower((string) ($d['status'] ?? $r->status));
            $bulan = substr($tgl, 0, 7).'-01';
            $this->upsert(JadwalTalent::firstOrNew(['legacy_id' => (string) $r->id]), [
                'talent_id' => $tid, 'event_id' => ($event[(string) ($d['event_id'] ?? $r->event_id)] ?? null)?->id,
                'aturan_jadwal_talent_id' => ($aturan[(string) ($d['recurring_rule_id'] ?? '')] ?? null)?->id,
                'pembayaran_talent_id' => $status === 'done'
                    ? PembayaranTalent::where('talent_id', $tid)->where('bulan', $bulan)->value('id') : null,
                'tanggal' => $tgl, 'mulai' => $this->jam($d['start_time'] ?? $r->start_time), 'selesai' => $this->jam($d['end_time'] ?? $r->end_time),
                'jenis_tampil' => $this->text($d['performance_type'] ?? $r->performance_type, 60), 'tarif' => $this->rp($d['fee'] ?? $r->fee),
                'status' => in_array($status, ['scheduled', 'confirmed', 'done', 'cancelled'], true) ? $status : 'scheduled',
                'sumber' => $this->text($d['source'] ?? $r->source, 16),
            ]);
            $n++;
        }

        return $n;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function oleh(mixed $nama): array
    {
        $nama = trim((string) $nama);
        $id = $nama === '' ? null : $this->users->cocok($nama, $nama);

        return [$id, $id || $nama === '' ? null : mb_substr($nama, 0, 120)];
    }
}
