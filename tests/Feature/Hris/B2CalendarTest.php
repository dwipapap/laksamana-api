<?php

use App\Modules\Hris\Models\HrisKalender;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisCalendarService;

/*
 * Holiday calendar (L8): HR CRUD + file/paste import; reads open to crew.
 * Wiring HARI_LIBUR into the punch path ends the file (B1 left a stub).
 */

function hrisHoliday(string $tgl, string $nama = 'Libur uji'): void
{
    app(HrisCalendarService::class)->save(['tanggal' => $tgl, 'nama' => $nama, 'jenis' => 'NASIONAL'], 'test');
}

it('manages the calendar as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/calendar', $h)->assertOk()->assertJsonPath('data', []);

    $id = test()->postJson('/api/v1/hris/calendar',
        ['tanggal' => '2026-12-25', 'nama' => 'Natal', 'jenis' => 'NASIONAL'], $h)
        ->assertCreated()->json('data.id');
    expect(HrisKalender::query()->find($id))->not->toBeNull();

    // The same date twice is refused, not duplicated.
    test()->postJson('/api/v1/hris/calendar', ['tanggal' => '2026-12-25', 'nama' => 'Natal 2'], $h)
        ->assertStatus(422);
    test()->postJson('/api/v1/hris/calendar', ['tanggal' => '2026-12-26', 'nama' => 'X', 'jenis' => 'SESAAT'], $h)
        ->assertStatus(422);

    $list = test()->getJson('/api/v1/hris/calendar?dari=2026-12-01&sampai=2026-12-31', $h)->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['tanggal'])->toBe('2026-12-25');

    test()->deleteJson("/api/v1/hris/calendar/{$id}", [], $h)->assertOk();
    test()->deleteJson("/api/v1/hris/calendar/{$id}", [], $h)->assertStatus(404);
});

it('keeps crew out of calendar writes', function () {
    hrisHoliday('2026-12-25');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/calendar', $h)->assertOk();
    test()->postJson('/api/v1/hris/calendar', ['tanggal' => '2026-12-26', 'nama' => 'X'], $h)->assertStatus(403);
    test()->postJson('/api/v1/hris/calendar/import', ['libur' => []], $h)->assertStatus(403);
});

it('imports pasted rows idempotently', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $body = ['libur' => [
        ['tanggal' => '2026-12-25', 'nama' => 'Natal'],
        ['tanggal' => '2026-12-26', 'nama' => 'Cuti bersama', 'jenis' => 'PERUSAHAAN'],
        ['tanggal' => 'salah', 'nama' => 'Dilewat'],
    ]];

    $satu = test()->postJson('/api/v1/hris/calendar/import', $body, $h)->assertOk()->json('data');
    expect($satu)->toMatchArray(['dibuat' => 2, 'diperbarui' => 0]);
    $dua = test()->postJson('/api/v1/hris/calendar/import', $body, $h)->assertOk()->json('data');
    expect($dua)->toMatchArray(['dibuat' => 0, 'diperbarui' => 2]);
});

it('imports an uploaded CSV file', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $csv = "tanggal,nama,jenis\n2027-01-01,Tahun Baru,NASIONAL\n2027-03-17,Hari Raya,\n";
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('libur.csv', $csv);

    $r = test()->post('/api/v1/hris/calendar/import', ['file' => $file],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r)->toMatchArray(['dibuat' => 2, 'diperbarui' => 0]);
});

it('queues HARI_LIBUR for holiday work', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    $tgl = hrisDate(2026, 12, 25);
    hrisHoliday($tgl, 'Natal');
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');

    $out = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'alasan' => 'piket natal'], hrisMs(2026, 12, 25, 9, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['HARI_LIBUR']]);
    hrisClearShift('u-dwipa', $tgl);
});
