<?php

declare(strict_types=1);

namespace App\Erp\Master\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Models\Karyawan;
use App\Erp\Master\Models\Klien;
use App\Erp\Master\Models\Kol;
use App\Erp\Master\Models\PekerjaHarian;
use App\Erp\Master\Models\PekerjaHarianDivisi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\PihakRekening;
use App\Erp\Master\Models\Talent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ERP v2 people and Divisi (docs/erp/orang-divisi.md) from legacy HR, DW,
 * EMS, Marketing, Konten and BD. Run after `core:import account` (Users and
 * the shift Divisi come from there). Idempotent: keyed by legacy_id, so a
 * second run changes nothing.
 *
 * Nothing is guessed: a person or Divisi that matches nothing keeps its legacy
 * text in the `*_impor` column and is listed in issues().
 */
final class OrangImporter implements Importer, ReportsIssues
{
    /**
     * The one Divisi list (assumption L4, plus BD's own two units seen in the
     * data). kode => [nama, jenis]. Shift codes already exist (identity import).
     */
    public const DIVISI = [
        'bar' => ['Bar', 'shift'],
        'kitchen' => ['Kitchen', 'shift'],
        'floor' => ['Floor', 'shift'],
        'cashier' => ['Cashier', 'shift'],
        'event' => ['Event', 'kantor'],
        'finance' => ['Finance', 'kantor'],
        'hr_ga_legal' => ['HR/GA/Legal', 'kantor'],
        'marketing_digital' => ['Marketing & Digital', 'kantor'],
        'management' => ['Management', 'kantor'],
        'business_development' => ['Business Development', 'kantor'],
        'purchasing' => ['Purchasing', 'kantor'],
    ];

    /** Legacy division names (HR, Akademi, BD, Marketing) => kode; '*' = split per person. */
    public const PETA_DIVISI = [
        'bar' => 'bar', 'galangan bar' => 'bar',
        'kitchen' => 'kitchen', 'galangan dapur' => 'kitchen',
        'floor' => 'floor', 'cashier' => 'cashier',
        'store / service' => '*', 'service / foh' => '*', 'store/service' => '*', 'service/foh' => '*',
        'event' => 'event', 'sales & event' => 'event',
        'finance' => 'finance', 'finance & admin' => 'finance',
        'hr / ga / legal' => 'hr_ga_legal', 'hr/ga/legal' => 'hr_ga_legal',
        'marketing & digital' => 'marketing_digital', 'marketing' => 'marketing_digital',
        'management' => 'management',
        'business development' => 'business_development', 'purchasing' => 'purchasing',
    ];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, string> kode => divisi id */
    private array $divisi = [];

    public function __construct(private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-orang';
    }

    public function legacyConnections(): array
    {
        return ['legacy_hr', 'legacy_dw', 'legacy_ems', 'legacy_marketing', 'legacy_konten', 'legacy_bd'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function issues(): array
    {
        return $this->issues;
    }

    public function import(): int
    {
        $this->issues = [];
        $this->users->reset();
        $count = 0;

        DB::connection('core')->transaction(function () use (&$count): void {
            $count += $this->importDivisi();
            $count += $this->importKaryawan();
            $count += $this->importPekerjaHarian();
            $count += $this->importTalent();
            $count += $this->importKlien();
            $count += $this->importKol();
        });

        return $count;
    }

    private function importDivisi(): int
    {
        $db = DB::connection('core');
        $now = now('UTC')->format('Y-m-d H:i:s');
        $urutan = (int) $db->table('divisi')->max('urutan');
        foreach (self::DIVISI as $kode => [$nama, $jenis]) {
            $row = $db->table('divisi')->where('kode', $kode)->first();
            if (! $row) {
                $db->table('divisi')->insert([
                    'id' => strtolower((string) Str::ulid()), 'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis,
                    'urutan' => ++$urutan, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif ($row->nama !== $nama || $row->jenis !== $jenis) {
                $db->table('divisi')->where('id', $row->id)->update([
                    'nama' => $nama, 'jenis' => $jenis, 'version' => $row->version + 1, 'updated_at' => $now,
                ]);
            }
        }
        $this->divisi = $db->table('divisi')->pluck('id', 'kode')->all();

        return count(self::DIVISI);
    }

    /** Legacy division name => divisi id; '*' (Store/Service) follows the person's Penempatan Divisi. */
    private function divisiId(string $nama, ?string $userId, string $label): ?string
    {
        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }
        $kode = self::PETA_DIVISI[mb_strtolower($nama)] ?? null;
        if ($kode === '*') {
            $kode = $userId ? DB::connection('core')->table('penempatan_divisi as p')
                ->join('divisi as d', 'd.id', '=', 'p.divisi_id')
                ->where('p.user_id', $userId)->whereIn('d.kode', ['floor', 'cashier'])->value('d.kode') : null;
            if (! $kode) {
                $this->issue('divisi_store_service_tanpa_penempatan', "{$label}: {$nama}");

                return null;
            }
        }
        if (! $kode || ! isset($this->divisi[$kode])) {
            $this->issue('divisi_tidak_dikenal', "{$label}: {$nama}");

            return null;
        }

        return $this->divisi[$kode];
    }

    private function importKaryawan(): int
    {
        $legacy = DB::connection('legacy_hr');
        $divisions = $legacy->table('divisions')->pluck('nama', 'id')->all();
        // BD keeps the org chart: people.boss_id -> people.office_user_id
        $bd = DB::connection('legacy_bd')->table('people')->get(['id', 'office_user_id', 'boss_id'])->keyBy('id');
        $atasan = [];
        foreach ($bd as $p) {
            $boss = $bd[$p->boss_id] ?? null;
            if ((string) $p->office_user_id !== '' && $boss && (string) $boss->office_user_id !== '') {
                $atasan[(string) $p->office_user_id] = (string) $boss->office_user_id;
            }
        }

        $n = 0;
        foreach ($legacy->table('employees')->orderBy('id')->get() as $e) {
            $d = $this->json($e->data);
            $label = trim((string) $e->nama) ?: (string) $e->id;
            $userId = $this->users->id((string) $e->id)
                ?? $this->userByTalenta((string) ($d->talentaId ?? ''))
                ?? $this->users->cocok(null, (string) $e->nama);
            if (! $userId) {
                $this->issue('karyawan_tanpa_user', $label);

                continue;
            }
            $divNama = (string) ($divisions[(string) $e->div_id] ?? '');
            $divisiId = $this->divisiId($divNama, $userId, $label);
            $bossLegacy = $atasan[(string) $e->id] ?? null;

            $k = Karyawan::withTrashed()->firstOrNew(['user_id' => $userId]);
            $this->save($k, [
                'legacy_id' => (string) $e->id,
                'divisi_id' => $divisiId,
                'divisi_impor' => $divisiId ? null : ($divNama !== '' ? $divNama : null),
                'atasan_id' => $bossLegacy ? $this->users->id($bossLegacy) : null,
                'email' => $this->text($d->email ?? null, 190),
                'tanggal_lahir' => $this->tanggal($d->birthDate ?? null, $label, 'tanggal_lahir'),
                'akhir_kontrak' => $this->tanggal($d->contractEnd ?? null, $label, 'akhir_kontrak'),
                'akhir_percobaan' => $this->tanggal($d->probationEnd ?? null, $label, 'akhir_percobaan'),
                'catatan' => $this->text($d->notes ?? null, 500),
            ]);
            $this->bandingkanRoster($userId, $e, $label);
            $n++;
        }

        return $n;
    }

    private function userByTalenta(string $talenta): ?string
    {
        $talenta = trim($talenta);

        return $talenta === '' ? null
            : DB::connection('core')->table('user')->where('talenta_id', $talenta)->value('id');
    }

    /** HR fields the Roster on `user` also holds: the Roster stays the source, differences are reported. */
    private function bandingkanRoster(string $userId, object $e, string $label): void
    {
        $u = DB::connection('core')->table('user')->where('id', $userId)->first(['jabatan', 'tanggal_bergabung']);
        $jabatan = trim((string) $e->jabatan);
        if ($jabatan !== '' && mb_strtolower($jabatan) !== mb_strtolower(trim((string) $u->jabatan))) {
            $this->issue('roster_beda_jabatan', "{$label}: HR {$jabatan}, Roster ".((string) $u->jabatan ?: '-'));
        }
        $join = $this->tanggal($e->join_date, $label, 'join_date');
        if ($join && $join !== (string) $u->tanggal_bergabung) {
            $this->issue('roster_beda_tanggal_bergabung', "{$label}: HR {$join}, Roster ".((string) $u->tanggal_bergabung ?: '-'));
        }
    }

    private function importPekerjaHarian(): int
    {
        $n = 0;
        foreach (DB::connection('legacy_dw')->table('dw_pekerja')->orderBy('id')->get() as $p) {
            $label = trim((string) $p->nama).' ('.$p->id.')';
            $role = PekerjaHarian::withTrashed()->where('legacy_id', (string) $p->id)->first();
            $pihak = $this->pihak($role?->pihak_id, [
                'nama' => trim((string) $p->nama),
                'telepon' => $this->text($p->no_hp, 40),
                'catatan' => $this->text($p->catatan, 255),
            ]);
            $role ??= new PekerjaHarian(['pihak_id' => $pihak->id]);
            $this->save($role, [
                'legacy_id' => (string) $p->id,
                'no_hp' => trim((string) $p->no_hp),
                'jenis_kelamin' => $this->text($p->gender, 10),
                'area' => $this->text($p->area, 80),
                'posisi' => $this->text($p->posisi, 240),
                'keahlian' => $this->text($p->skill, 255),
                'aktif' => strtoupper((string) $p->status) !== 'NONAKTIF',
            ]);

            $keep = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $p->divisi))) as $kode) {
                $id = $this->divisi[mb_strtolower($kode)] ?? null;
                if (! $id) {
                    $this->issue('divisi_tidak_dikenal', "{$label}: {$kode}");

                    continue;
                }
                $keep[] = $id;
                PekerjaHarianDivisi::firstOrCreate(['pihak_id' => $pihak->id, 'divisi_id' => $id]);
            }
            PekerjaHarianDivisi::where('pihak_id', $pihak->id)->whereNotIn('divisi_id', $keep ?: ['-'])->delete();

            $nomor = trim((string) $p->bayar_nomor);
            if ($nomor !== '') {
                $bank = trim((string) $p->bayar_jenis) === 'BANK' ? (trim((string) $p->bayar_bank) ?: '-') : trim((string) $p->bayar_jenis);
                $this->rekening($pihak, $bank, $nomor, trim((string) $p->bayar_nama) ?: $pihak->nama);
            }
            $n++;
        }

        return $n;
    }

    private function importTalent(): int
    {
        $n = 0;
        foreach (DB::connection('legacy_ems')->table('talents')->orderBy('id')->get() as $t) {
            $d = $this->json($t->data);
            $label = trim((string) $t->name).' ('.$t->id.')';
            $role = Talent::withTrashed()->where('legacy_id', (string) $t->id)->first();
            $pihak = $this->pihak($role?->pihak_id, [
                'nama' => trim((string) $t->name),
                'telepon' => $this->text(($t->phone ?? '') !== '' ? $t->phone : ($d->whatsapp ?? null), 40),
                'email' => $this->text($d->email ?? null, 190),
                'alamat' => $this->text($d->address ?? null, 500),
                'instagram' => $this->text($d->instagram ?? null, 120),
                'catatan' => $this->text($d->notes ?? null, 255),
            ]);
            $fee = (float) ($t->default_fee ?? 0);
            if ($fee !== floor($fee)) {
                $this->issue('tarif_dibulatkan', "{$label}: {$fee}");
            }
            $role ??= new Talent(['pihak_id' => $pihak->id]);
            $this->save($role, [
                'legacy_id' => (string) $t->id,
                'kategori' => $this->text($t->category, 60),
                'npwp' => $this->text($d->npwp ?? null, 32),
                'tarif_bawaan' => $fee > 0 ? (string) round($fee) : null,
                'status_kontrak' => $this->text($t->contract_status, 32),
                'manajer_nama' => $this->text($d->manager_name ?? null, 120),
                'manajer_telepon' => $this->text($d->manager_phone ?? null, 40),
            ]);
            $nomor = trim((string) ($d->bank_account ?? ''));
            if ($nomor !== '') {
                $this->rekening($pihak, trim((string) ($d->bank_name ?? '')) ?: '-', $nomor, trim((string) ($d->account_holder ?? '')) ?: $pihak->nama);
            }
            $n++;
        }

        return $n;
    }

    private function importKlien(): int
    {
        $n = 0;
        foreach (DB::connection('legacy_marketing')->table('clients')->orderBy('id')->get() as $c) {
            $d = $this->json($c->data);
            $label = trim((string) $c->nama).' ('.$c->id.')';
            $role = Klien::withTrashed()->where('legacy_id', (string) $c->id)->first();
            $pihak = $this->pihak($role?->pihak_id, [
                'nama' => trim((string) $c->nama),
                'telepon' => $this->text($c->hp, 40),
                'email' => $this->text($c->email, 190),
                'alamat' => $this->text($d->alamat ?? null, 500),
                'instagram' => $this->text($d->ig ?? null, 120),
                'catatan' => $this->text($d->catatan ?? null, 255),
            ]);
            $mkt = trim((string) $c->mkt_pic);
            $picId = $this->users->cocok($mkt, $mkt);
            if ($mkt !== '' && ! $picId) {
                $this->issue('pic_marketing_tanpa_user', "{$label}: {$mkt}");
            }
            $role ??= new Klien(['pihak_id' => $pihak->id]);
            $this->save($role, [
                'legacy_id' => (string) $c->id,
                'perusahaan' => $this->text($c->perusahaan, 190),
                'kontak_nama' => $this->text($d->pic ?? null, 120),
                'tanggal_lahir' => $this->tanggal($d->birthday ?? null, $label, 'birthday'),
                'sumber' => $this->text($c->source, 60),
                'pic_marketing_id' => $picId,
                'pic_marketing_impor' => $picId ? null : ($mkt !== '' ? $mkt : null),
            ]);
            $n++;
        }

        return $n;
    }

    private function importKol(): int
    {
        $n = 0;
        foreach (DB::connection('legacy_konten')->table('kols')->orderBy('id')->get() as $k) {
            $d = $this->json($k->data);
            $role = Kol::withTrashed()->where('legacy_id', (string) $k->id)->first();
            $pihak = $this->pihak($role?->pihak_id, [
                'nama' => trim((string) $k->name),
                'telepon' => $this->text($k->whatsapp, 40),
                'instagram' => $this->text($k->instagram, 120),
                'catatan' => $this->text($d->note ?? null, 255),
            ]);
            $role ??= new Kol(['pihak_id' => $pihak->id]);
            $this->save($role, ['legacy_id' => (string) $k->id, 'jenis' => $this->text($k->kol_type, 32)]);
            $n++;
        }

        return $n;
    }

    /** @param array<string, mixed> $values */
    private function pihak(?string $id, array $values): Pihak
    {
        $pihak = $id ? Pihak::withTrashed()->findOrFail($id) : new Pihak;
        $this->save($pihak, $values);

        return $pihak;
    }

    private function rekening(Pihak $pihak, string $bank, string $nomor, string $atasNama): void
    {
        PihakRekening::withTrashed()->firstOrCreate(
            ['pihak_id' => $pihak->id, 'bank' => mb_substr($bank, 0, 60), 'nomor' => mb_substr($nomor, 0, 40)],
            ['atas_nama' => mb_substr($atasNama, 0, 120), 'utama' => true],
        );
    }

    /** @param array<string, mixed> $values */
    private function save(Model $row, array $values): void
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }
    }

    private function tanggal(mixed $v, string $label, string $field): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($v, 0, 10));
        if ($d === false || $d->format('Y-m-d') !== substr($v, 0, 10)) {
            $this->issue('tanggal_tidak_sah', "{$label}: {$field} = {$v}");

            return null;
        }

        return $d->format('Y-m-d');
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function json(?string $raw): object
    {
        $d = json_decode((string) $raw);

        return is_object($d) ? $d : (object) [];
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
