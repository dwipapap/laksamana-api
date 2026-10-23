<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/* GET /api/v1/kompas/esb/sales — one page of POS ESB sales (port of esbHalaman). u-wandi is superadmin. */

const ESB_TEST_TOKEN = 'tok-test-123';

function esbGet(string $query): Illuminate\Testing\TestResponse
{
    return test()->withToken(loginAs(officeUser('u-wandi')))->getJson('/api/v1/kompas/esb/sales?'.$query);
}

it('says the ESB token is missing instead of calling ESB', function () {
    config(['services.esb.token' => '']);
    Http::fake();
    esbGet('dari=2026-09-01&sampai=2026-09-30&page=1')->assertStatus(503)->assertJsonPath('error.code', 'esb_not_configured');
    Http::assertNothingSent();
});

it('rejects a bad date, a reversed range and a bad page before calling ESB', function () {
    config(['services.esb.token' => ESB_TEST_TOKEN]);
    Http::fake();
    esbGet('dari=2026-02-31&sampai=2026-03-01&page=1')->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
    Http::assertNothingSent();
});

it('returns a bare list with the pagination headers, and never echoes the token', function () {
    config(['services.esb.token' => 'Bearer '.ESB_TEST_TOKEN, 'services.esb.url' => 'https://esb.test/sales']);
    Http::fake(['esb.test/*' => Http::response([['salesNum' => 'A1'], ['salesNum' => 'A2']], 200, [
        'X-Pagination-Page-Count' => '270', 'X-Pagination-Total-Count' => '5400', 'X-Pagination-Per-Page' => '20',
    ])]);
    $res = esbGet('dari=2026-09-01&sampai=2026-09-30&page=3')->assertOk();
    expect($res->json('data'))->toMatchArray(['page' => 3, 'pageCount' => 270, 'totalCount' => 5400, 'perPage' => 20])
        ->and($res->json('data.bills'))->toHaveCount(2)
        ->and($res->getContent())->not->toContain(ESB_TEST_TOKEN);
    // "Bearer " in the config is stripped, then sent once as the Authorization header
    Http::assertSent(fn (HttpRequest $q) => $q->hasHeader('Authorization', 'Bearer '.ESB_TEST_TOKEN)
        && str_contains($q->url(), 'salesDateFrom=2026-09-01') && str_contains($q->url(), 'page=3'));
});

it('reads a wrapped body and leaves unknown counts null', function () {
    config(['services.esb.token' => ESB_TEST_TOKEN, 'services.esb.url' => 'https://esb.test/sales']);
    Http::fake(['esb.test/*' => Http::response(['data' => [['salesNum' => 'B1']], '_meta' => ['pageCount' => 4]])]);
    $res = esbGet('dari=2026-09-01&sampai=2026-09-01&page=1')->assertOk();
    expect($res->json('data.pageCount'))->toBe(4)->and($res->json('data.totalCount'))->toBeNull()->and($res->json('data.bills.0.salesNum'))->toBe('B1');
});

it('tells a rejected token apart from other ESB errors', function () {
    config(['services.esb.token' => ESB_TEST_TOKEN, 'services.esb.url' => 'https://esb.test/sales']);
    Http::fake(['esb.test/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    esbGet('dari=2026-09-01&sampai=2026-09-01&page=1')->assertStatus(502)->assertJsonPath('error.code', 'esb_token_rejected');
});

it('reports any other ESB failure with its HTTP status and message', function () {
    config(['services.esb.token' => ESB_TEST_TOKEN, 'services.esb.url' => 'https://esb.test/sales']);
    Http::fake(['esb.test/*' => Http::response(['message' => 'down'], 500)]);
    esbGet('dari=2026-09-01&sampai=2026-09-01&page=1')->assertStatus(502)->assertJsonPath('error.code', 'esb_error')
        ->assertJsonPath('error.message', 'ESB menjawab HTTP 500: down');
});
