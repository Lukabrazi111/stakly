<?php

use App\Console\Commands\LichessStreamCommand;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessProfileClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * M25 — Lichess OAuth integration. Asserts the `Authorization: Bearer`
 * header is sent on every Lichess HTTP call when `services.lichess.token`
 * is configured, and omitted entirely when the token is unset (anonymous
 * fallback path — same wire shape as pre-M25 so existing fakes still
 * match).
 *
 * The header is the only M25-visible behavior; rate-limit headroom and
 * stream stability are downstream effects that only show up against real
 * Lichess servers.
 */
function fakeLichessOk(): void
{
    // Catch-all OK response so any Lichess URL the clients hit returns
    // something parseable. The specific URL match is asserted via
    // Http::assertSent below.
    Http::fake([
        'lichess.org/*' => Http::response(json_encode([
            'id' => 'a1b2c3d4',
            'username' => 'someuser',
            'players' => [],
            'profile' => ['bio' => 'hello'],
        ]), 200),
    ]);
}

// ─── LichessProfileClient ──────────────────────────────────────────────────

test('LichessProfileClient sends Authorization Bearer when token is configured', function () {
    config()->set('services.lichess.token', 'lich_test_token_123');
    fakeLichessOk();

    app(LichessProfileClient::class)->fetchProfile('someuser');

    Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer lich_test_token_123'));
});

test('LichessProfileClient omits Authorization when token is null', function () {
    config()->set('services.lichess.token', null);
    fakeLichessOk();

    app(LichessProfileClient::class)->fetchProfile('someuser');

    Http::assertSent(fn (Request $req) => ! $req->hasHeader('Authorization'));
});

test('LichessProfileClient omits Authorization when token is an empty string', function () {
    // Edge case: env var present but blank (e.g. someone `LICHESS_API_TOKEN=`).
    // We treat blank same as missing — never send a literal `Bearer ` header.
    config()->set('services.lichess.token', '');
    fakeLichessOk();

    app(LichessProfileClient::class)->fetchProfile('someuser');

    Http::assertSent(fn (Request $req) => ! $req->hasHeader('Authorization'));
});

// ─── LichessGameClient::fetchGame ──────────────────────────────────────────

test('LichessGameClient::fetchGame sends Authorization Bearer when token is configured', function () {
    config()->set('services.lichess.token', 'lich_game_token_456');
    fakeLichessOk();

    app(LichessGameClient::class)->fetchGame('a1b2c3d4');

    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/game/export/')
        && $req->hasHeader('Authorization', 'Bearer lich_game_token_456'));
});

test('LichessGameClient::fetchGame omits Authorization when token is null', function () {
    config()->set('services.lichess.token', null);
    fakeLichessOk();

    app(LichessGameClient::class)->fetchGame('a1b2c3d4');

    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/game/export/')
        && ! $req->hasHeader('Authorization'));
});

// ─── LichessGameClient::searchGamesBetween ─────────────────────────────────

test('LichessGameClient::searchGamesBetween sends Authorization Bearer when token is configured', function () {
    config()->set('services.lichess.token', 'lich_search_token_789');
    fakeLichessOk();

    app(LichessGameClient::class)->searchGamesBetween('alice', 'bob', CarbonImmutable::now()->subHour());

    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/api/games/user/')
        && $req->hasHeader('Authorization', 'Bearer lich_search_token_789'));
});

test('LichessGameClient::searchGamesBetween omits Authorization when token is null', function () {
    config()->set('services.lichess.token', null);
    fakeLichessOk();

    app(LichessGameClient::class)->searchGamesBetween('alice', 'bob', CarbonImmutable::now()->subHour());

    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/api/games/user/')
        && ! $req->hasHeader('Authorization'));
});

// ─── LichessStreamCommand ──────────────────────────────────────────────────

test('LichessStreamCommand::buildStreamHeaders includes Authorization when token is configured', function () {
    config()->set('services.lichess.token', 'lich_stream_token_xyz');

    $headers = (new LichessStreamCommand)->buildStreamHeaders();

    expect($headers)->toContain('Content-Type: text/plain')
        ->and($headers)->toContain('Authorization: Bearer lich_stream_token_xyz');
});

test('LichessStreamCommand::buildStreamHeaders omits Authorization when token is null', function () {
    config()->set('services.lichess.token', null);

    $headers = (new LichessStreamCommand)->buildStreamHeaders();

    expect($headers)->toContain('Content-Type: text/plain')
        ->and($headers)->not->toContain(
            fn (string $h) => str_starts_with($h, 'Authorization'),
        );
});

test('LichessStreamCommand::buildStreamHeaders omits Authorization when token is an empty string', function () {
    config()->set('services.lichess.token', '');

    $headers = (new LichessStreamCommand)->buildStreamHeaders();

    expect($headers)->toContain('Content-Type: text/plain');

    // No header should start with "Authorization" — even an empty bearer
    // would identify us as a misconfigured client to Lichess.
    foreach ($headers as $h) {
        expect(str_starts_with($h, 'Authorization'))->toBeFalse();
    }
});

// ─── Cross-call consistency ────────────────────────────────────────────────

test('all Lichess HTTP surfaces read the token from services.lichess.token', function () {
    // Single token configured. Every surface should send the same header
    // verbatim — no per-client divergence in how the token gets read.
    config()->set('services.lichess.token', 'unified_token');
    fakeLichessOk();

    app(LichessProfileClient::class)->fetchProfile('user');
    app(LichessGameClient::class)->fetchGame('a1b2c3d4');
    app(LichessGameClient::class)->searchGamesBetween('a', 'b', CarbonImmutable::now()->subHour());

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer unified_token'));

    $streamHeaders = (new LichessStreamCommand)->buildStreamHeaders();
    expect($streamHeaders)->toContain('Authorization: Bearer unified_token');
});
