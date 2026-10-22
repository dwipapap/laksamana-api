<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisPengumumanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HrisPengumumanController extends HrisBaseController
{
    public function __construct(OfficeAccess $access, private readonly HrisPengumumanService $pengumuman)
    {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $r->boolean('kelola') ? $this->pengumuman->kelola($me) : $this->pengumuman->untuk($me));
    }

    public function show(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->show($me, $id, $r->boolean('kelola')));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['judul' => ['required', 'string', 'max:180'], 'isi' => ['required', 'string', 'max:20000'],
            'semua' => ['required', 'boolean'], 'divisi_ids' => ['nullable', 'array', 'max:100'], 'divisi_ids.*' => ['string', 'max:26']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->create($me, $d), 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['judul' => ['required', 'string', 'max:180'], 'isi' => ['required', 'string', 'max:20000'],
            'semua' => ['required', 'boolean'], 'divisi_ids' => ['nullable', 'array', 'max:100'], 'divisi_ids.*' => ['string', 'max:26'],
            'berubah' => ['required', 'integer', 'min:1']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->update($me, $id, $d));
    }

    public function publish(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['berubah' => ['required', 'integer', 'min:1']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->publish($me, $id, $d));
    }

    public function archive(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['berubah' => ['required', 'integer', 'min:1']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->archive($me, $id, $d));
    }

    public function options(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->options($me));
    }

    public function grant(Request $r): JsonResponse
    {
        $d = $r->validate(['user_id' => ['required', 'string', 'max:64'], 'semua_divisi' => ['required', 'boolean'],
            'divisi_ids' => ['nullable', 'array', 'max:100'], 'divisi_ids.*' => ['string', 'max:26']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengumuman->setGrant($me, $d['user_id'], $d), 201);
    }

    public function revoke(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $userId) {
            $this->pengumuman->revokeGrant($me, $userId);

            return ['dicabut' => true];
        });
    }
}
