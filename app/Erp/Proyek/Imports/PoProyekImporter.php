<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Erp\Master\Imports\OrangImporter;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Satuan;
use App\Erp\Proyek\Models\PengajuanPembelian;
use App\Erp\Proyek\Models\PengajuanPembelianPenyetuju;
use App\Erp\Proyek\Models\PoProyek;
use App\Erp\Proyek\Models\Proyek;
use App\Erp\Proyek\Models\ProyekPic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Proyek & PO Proyek (docs/erp/po-proyek.md "Pemetaan lama → v2") from
 * the legacy BD blobs: projects, weekly purchase requests with their
 * approvers, and PO lines. Run after `erp-orang` and `erp-barang`. Idempotent.
 */
final class PoProyekImporter implements Importer, ReportsIssues
{
    private const STATUS_PO = ['Draft' => 'draf', 'Diajukan' => 'diajukan', 'Approved' => 'disetujui', 'Dibeli' => 'dibeli', 'Diterima' => 'diterima'];

    private const STATUS_PR = ['Draft' => 'draf', 'Diajukan' => 'diajukan', 'Disetujui' => 'disetujui', 'Selesai' => 'selesai'];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, string> */
    private array $divisi = [];

    public function __construct(private readonly BdPeople $people) {}

    public function module(): string
    {
        return 'erp-po-proyek';
    }

    public function legacyConnections(): array
    {
        return ['legacy_bd'];
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
        $this->people->reset();
        $bd = DB::connection('legacy_bd');
        $count = 0;

        DB::connection('core')->transaction(function () use ($bd, &$count): void {
            $this->divisi = DB::connection('core')->table('divisi')->pluck('id', 'kode')->all();
            $outlet = Lokasi::withTrashed()->firstOrCreate(['kode' => 'outlet'], ['nama' => 'Outlet', 'jenis' => 'outlet']);
            $proyek = [];
            foreach ($bd->table('projects')->orderBy('id')->get() as $p) {
                $proyek[(string) $p->id] = $this->importProyek($this->json($p->data), (string) $p->id);
                $count++;
            }
            $pr = [];
            foreach ($bd->table('purchase_requests')->orderBy('id')->get() as $r) {
                $pr[(string) $r->id] = $this->importPr($this->json($r->data), (string) $r->id);
                $count++;
            }
            foreach ($bd->table('purchase_orders')->orderBy('id')->get() as $o) {
                $this->importPo($this->json($o->data), (string) $o->id, $outlet, $proyek, $pr);
                $count++;
            }
        });

        return $count;
    }

    /** @param array<string, mixed> $d */
    private function importProyek(array $d, string $id): Proyek
    {
        [$divId, $divImpor] = $this->div($d['div'] ?? null, "proyek {$id}");
        $mulai = $this->tanggal($d['start'] ?? null);
        $selesai = $this->tanggal($d['end'] ?? null);
        if ($mulai && $selesai && $selesai < $mulai) {
            $this->issue('proyek_selesai_sebelum_mulai', "{$id}: {$mulai} > {$selesai}");
            $selesai = null;
        }
        $tahap = strtolower((string) ($d['stage'] ?? 'Idea'));
        $health = str_replace('-', '_', strtolower((string) ($d['health'] ?? '')));
        $row = $this->upsert(Proyek::withTrashed()->firstOrNew(['legacy_id' => $id]), [
            'nama' => trim((string) ($d['name'] ?? '')) ?: '-',
            'jenis' => $this->text($d['type'] ?? null, 60),
            'tahap' => in_array($tahap, ['idea', 'planning', 'running', 'review', 'completed'], true) ? $tahap : 'idea',
            'kesehatan' => in_array($health, ['on_track', 'at_risk', 'off_track'], true) ? $health : null,
            'divisi_id' => $divId, 'divisi_impor' => $divImpor,
            'mulai' => $mulai, 'selesai' => $selesai,
            'anggaran' => (string) (int) round((float) ($d['budget'] ?? 0)),
            'deskripsi' => $this->text($d['desc'] ?? null, 65000),
        ]);
        $pics = array_values(array_unique(array_filter(array_map('strval', (array) ($d['pics'] ?? [])) ?: [(string) ($d['pic'] ?? '')])));
        $keep = [];
        foreach ($pics as $i => $pid) {
            [$user, $impor] = $this->people->user($pid);
            if (! $user) {
                $this->issue('pic_tanpa_user', "proyek {$id}: {$impor}");

                continue;
            }
            $keep[] = $user;
            $this->upsert(ProyekPic::firstOrNew(['proyek_id' => $row->id, 'user_id' => $user]), ['urutan' => $i]);
        }
        ProyekPic::where('proyek_id', $row->id)->whereNotIn('user_id', $keep ?: ['-'])->delete();

        return $row;
    }

    /** @param array<string, mixed> $d */
    private function importPr(array $d, string $id): PengajuanPembelian
    {
        [$pengaju, $pengajuImpor] = $this->people->user($d['nama'] ?? null);
        [$divId, $divImpor] = $this->div($d['dept'] ?? null, "PR {$id}");
        $tanggal = $this->tanggal($d['tanggal'] ?? null) ?? $this->tanggal($d['createdAt'] ?? null) ?? now()->toDateString();
        $senin = Carbon::parse($this->tanggal($d['weekStart'] ?? null) ?? $tanggal)->startOfWeek(Carbon::MONDAY)->toDateString();
        $status = self::STATUS_PR[(string) ($d['status'] ?? 'Draft')] ?? 'draf';
        $approvals = (array) ($d['approvals'] ?? []);
        $diajukan = $status !== 'draf' ? ($this->waktuAwal($approvals) ?? $tanggal.' 00:00:00') : null;

        $pr = $this->upsert(PengajuanPembelian::firstOrNew(['legacy_id' => $id]), [
            'nomor' => 'PR-'.(trim((string) ($d['no'] ?? '')) ?: $id),
            'pengaju_id' => $pengaju, 'pengaju_impor' => $pengajuImpor,
            'divisi_id' => $divId, 'divisi_impor' => $divImpor,
            'tanggal' => $tanggal, 'minggu_mulai' => $senin, 'status' => $status, 'diajukan_at' => $diajukan,
            'catatan' => $this->text($d['catatan'] ?? null, 500),
        ]);
        $urutan = 0;
        $keep = [];
        foreach ($approvals as $pid => $a) {
            $a = (array) $a;
            $urutan++;
            [$user, $impor] = $this->people->user((string) $pid);
            [$oleh] = $this->people->user($a['oleh'] ?? null);
            $at = $this->tanggal($a['at'] ?? null);
            $row = $user ? PengajuanPembelianPenyetuju::firstOrNew(['pengajuan_pembelian_id' => $pr->id, 'user_id' => $user])
                : PengajuanPembelianPenyetuju::firstOrNew(['pengajuan_pembelian_id' => $pr->id, 'nama_impor' => $impor ?? (string) ($a['nama'] ?? $pid)]);
            $this->upsert($row, ['urutan' => $urutan, 'disetujui_at' => $at ? $at.' 00:00:00' : null, 'disetujui_oleh' => $at ? $oleh : null]);
            $keep[] = $row->id;
        }
        PengajuanPembelianPenyetuju::where('pengajuan_pembelian_id', $pr->id)->whereNotIn('id', $keep ?: ['-'])->delete();

        return $pr;
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, Proyek>  $proyek
     * @param  array<string, PengajuanPembelian>  $pr
     */
    private function importPo(array $d, string $id, Lokasi $outlet, array $proyek, array $pr): void
    {
        $item = trim((string) ($d['item'] ?? '')) ?: '-';
        $vendor = trim((string) ($d['vendor'] ?? ''));
        $vendorId = $vendor !== '' ? Pihak::whereRaw('LOWER(nama) = ?', [mb_strtolower($vendor)])->value('id') : null;
        $unit = trim((string) ($d['unit'] ?? ''));
        $satuanId = $unit !== '' ? Satuan::withTrashed()->where('nama', $unit)->value('id') : null;
        [$pic, $picImpor] = $this->people->user(($d['by'] ?? '') ?: ($d['byName'] ?? null));
        [$proses] = $this->people->user($d['prosesBy'] ?? null);
        [$divId, $divImpor] = $this->div($d['div'] ?? null, "PO {$id}");
        $qty = (float) ($d['qty'] ?? 0);
        $real = $d['realisasi'] ?? '';
        $status = self::STATUS_PO[(string) ($d['status'] ?? 'Draft')] ?? 'draf';
        $prId = (string) ($d['prId'] ?? '');
        if ($prId !== '' && ! isset($pr[$prId])) {
            $this->issue('po_pr_tidak_ditemukan', "{$id}: {$prId}");
        }

        $this->upsert(PoProyek::firstOrNew(['legacy_id' => $id]), [
            'nomor' => 'PO-'.$id, 'lokasi_id' => $outlet->id,
            'pengajuan_pembelian_id' => ($pr[$prId] ?? null)?->id,
            'proyek_id' => ($proyek[(string) ($d['project'] ?? '')] ?? null)?->id,
            'barang_id' => Barang::withTrashed()->where('legacy_id', $item)->value('id'),
            'nama_barang' => mb_substr($item, 0, 190),
            'qty' => $qty > 0 ? number_format($qty, 4, '.', '') : null,
            'satuan_id' => $satuanId, 'satuan_impor' => $satuanId || $unit === '' ? null : mb_substr($unit, 0, 32),
            'nominal' => (string) (int) round((float) ($d['amount'] ?? 0)),
            'realisasi' => $real === '' || $real === null ? null : (string) max(0, (int) round((float) $real)),
            'vendor_id' => $vendorId, 'vendor_impor' => $vendorId || $vendor === '' ? null : mb_substr($vendor, 0, 190),
            'divisi_id' => $divId, 'divisi_impor' => $divImpor,
            'pic_id' => $pic, 'pic_impor' => $picImpor,
            'butuh_tanggal' => $this->tanggal($d['needBy'] ?? null),
            'status' => $status, 'sumber' => strtolower((string) ($d['sumber'] ?? '')) === 'marketing' ? 'marketing' : 'bd',
            'diproses_at' => ! empty($d['proses']) ? $this->waktu($d['prosesAt'] ?? null) : null,
            'diproses_oleh' => ! empty($d['proses']) ? $proses : null,
            'catatan' => $this->text($d['note'] ?? null, 500),
        ]);
        if ($vendor !== '' && ! $vendorId) {
            $this->issue('po_vendor_tidak_dikenal', "{$id}: {$vendor}");
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function div(mixed $nama, string $label): array
    {
        $nama = trim((string) $nama);
        if ($nama === '') {
            return [null, null];
        }
        $kode = OrangImporter::PETA_DIVISI[mb_strtolower($nama)] ?? null;
        $id = $kode && $kode !== '*' ? ($this->divisi[$kode] ?? null) : null;
        if (! $id) {
            $this->issue('divisi_tidak_dikenal', "{$label}: {$nama}");
        }

        return [$id, $id ? null : mb_substr($nama, 0, 64)];
    }

    /** @param array<string, mixed> $approvals */
    private function waktuAwal(array $approvals): ?string
    {
        $t = array_filter(array_map(fn ($a) => $this->tanggal(((array) $a)['at'] ?? null), $approvals));
        sort($t);

        return $t ? $t[0].' 00:00:00' : null;
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function upsert(Model $row, array $values): Model
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    private function tanggal(mixed $v): ?string
    {
        if (is_numeric($v) && (float) $v > 1e11) {
            return Carbon::createFromTimestampMs((int) $v, 'Asia/Jakarta')->toDateString();
        }
        $v = substr(trim((string) $v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    private function waktu(mixed $v): ?string
    {
        if (is_numeric($v) && (float) $v > 0) {
            return Carbon::createFromTimestampMs((int) $v, 'UTC')->format('Y-m-d H:i:s');
        }
        try {
            return $v ? Carbon::parse((string) $v)->utc()->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @return array<string, mixed> */
    private function json(?string $raw): array
    {
        $d = json_decode((string) $raw, true);

        return is_array($d) ? $d : [];
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
