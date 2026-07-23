<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Jobs\AutoFetchChessComGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function chessComAutoFetchMatch(?array $snapshots = null): GameMatch
{
    platformUser();

    $creator = User::factory()->active()->withChessCom('alice-chesscom')->create();
    $taker = User::factory()->withChessCom('bob-chesscom')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    // M14 Slice 3d — TC catch-all so happy-path tests pass deterministically;
    // tests that exercise TC mismatch override this back to a single value.
    $listing = Listing::factory()->taken()->forChessCom()->for($creator)
        ->state(['stake_amount' => '100', 'time_control' => 'blitz'])
        ->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    // Backdate `created_at` so the search-since window (= match.created_at)
    // sits comfortably before "now" — fixture end_times of `now() - 5min`
    // then fall inside the window. Without this the timing race ("now"
    // moves by microseconds between factory create + Http::fake match)
    // intermittently filters the fixture game out.
    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'alice-chesscom'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'bob-chesscom'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create(['match_id' => $match->id, ...$row]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runChessComAutoFetch(GameMatch $match): void
{
    (new AutoFetchChessComGameJob($match))
        ->handle(
            app(ChessComGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
            app(ProviderCircuitBreaker::class),
        );
}

// ─── Happy path ─────────────────────────────────────────────────────────────

test('single decisive chess.com game posts a verified system game_card AND settles', function () {
    $match = chessComAutoFetchMatch();

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/55555555555',
                    // end_time must be AFTER match.created_at — the
                    // searchGamesBetween `since` filter drops older games.
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified chess.com game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json)->toHaveCount(1);

    $card = $system->attachments_json[0];
    expect($card['provider'])->toBe('chess_com')
        ->and($card['source'])->toBe('auto_fetch')
        ->and($card['verified'])->toBeTrue()
        ->and($card['winner_username'])->toBe('alice-chesscom');

    // M16 — card-then-settle. Fixture has alice = white = win → creator wins.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($match->listing->user_id);
});

test('drawn chess.com game posts a card AND settles as draw', function () {
    $match = chessComAutoFetchMatch();

    // chess.com draw shape: neither side has `result === 'win'`, both have
    // a draw result string (`agreed` / `stalemate` / `repetition` / etc.).
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                    'white' => [
                        'username' => 'alice-chesscom',
                        'rating' => 1500,
                        'result' => 'agreed',
                    ],
                    'black' => [
                        'username' => 'bob-chesscom',
                        'rating' => 1495,
                        'result' => 'agreed',
                    ],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified chess.com game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['winner_color'])->toBeNull()
        ->and($system->attachments_json[0]['winner_username'])->toBeNull();

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBeNull();
});

test('abandoned chess.com game posts a card AND settles as draw (M14 Slice 3b — cooperative-exit refund)', function () {
    $match = chessComAutoFetchMatch();

    // chess.com abandoned shape: both sides have `result === 'abandoned'`.
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                    'white' => [
                        'username' => 'alice-chesscom',
                        'rating' => 1500,
                        'result' => 'abandoned',
                    ],
                    'black' => [
                        'username' => 'bob-chesscom',
                        'rating' => 1495,
                        'result' => 'abandoned',
                    ],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified chess.com game record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['status'])->toBe('abandoned')
        ->and($system->attachments_json[0]['winner_color'])->toBeNull()
        ->and($system->attachments_json[0]['winner_username'])->toBeNull();

    // Settled as draw — both stakes refunded, no winner.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBeNull();
});

// ─── Retry-on-empty (chess.com eventual consistency) ───────────────────────

test('empty archive does not post a system message (would retry in real queue)', function () {
    // The retry-on-empty path runs `$this->release(5)` to re-queue with
    // delay. Outside an actual queue worker, the release is a no-op — we
    // verify the surface-level behavior: no system message is posted on
    // an empty-result attempt. The release-call itself is exercised in
    // staging where a real Redis-backed queue is present.
    $match = chessComAutoFetchMatch();

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(chessComArchiveFixture([]), 200),
    ]);

    runChessComAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

// ─── Snapshot guards ───────────────────────────────────────────────────────

test('missing chess.com snapshot → no-op (no API call, no post)', function () {
    $match = chessComAutoFetchMatch(snapshots: [
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'bob-chesscom'],
    ]);

    Http::preventStrayRequests();

    runChessComAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

// ─── Idempotency ───────────────────────────────────────────────────────────

test('idempotency check uses provider-scoped attachments_json query', function () {
    $match = chessComAutoFetchMatch();

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);
    runChessComAutoFetch($match);

    expect(Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->whereJsonContains('attachments_json', [['source' => 'auto_fetch']])
        ->count())
        ->toBe(1);
});

// ─── M35 P1 — self-throttle wiring ─────────────────────────────────────────

test('AutoFetchChessComGameJob declares the chess-com-api RateLimited middleware', function () {
    $match = chessComAutoFetchMatch();
    $job = new AutoFetchChessComGameJob($match);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class);
});

test('chess-com-api rate limiter reflects services.chess_com.requests_per_minute config', function () {
    config(['services.chess_com.requests_per_minute' => 7]);

    $resolver = RateLimiter::limiter('chess-com-api');
    expect($resolver)->not->toBeNull();

    $limit = $resolver(new stdClass);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(7);
});

// ─── M46 P2 — started-after-creation guard (SECURITY: pre-play reuse) ───────

test('chess.com game that STARTED before match creation is rejected as stale (not settled)', function () {
    $match = chessComAutoFetchMatch(); // created_at backdated to now()->subHour()

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    // Started 2h ago — BEFORE the match (created 1h ago). end_time
                    // is recent so the `since` filter passes; only the
                    // started-after guard should reject it.
                    'start_time' => CarbonImmutable::now()->subHours(2)->timestamp,
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    // A pre-play game must never auto-settle — no card, match stays Pending.
    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())->toBe(0)
        ->and($match->fresh()->status)->toBe(MatchStatus::Pending);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch)
        ->and($attempt->outcome_reason)->toBe('stale_game_rejected')
        ->and($attempt->candidates_count)->toBe(1);
});

test('chess.com drops a pre-stake game and settles the real one played after (mixed response)', function () {
    $match = chessComAutoFetchMatch(); // created_at backdated to now()->subHour()

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                // Pre-play game — STARTED 2h ago (before the stake) but ended
                // after it, so searchGamesBetween returns it; bob wins. The
                // guard must DROP it. Without the PGN-start fix it would look
                // fresh (createdAt = end_time) and the picker would settle it,
                // paying the taker for a game that predates the stake.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/stale',
                    'start_time' => CarbonImmutable::now()->subHours(2)->timestamp,
                    'end_time' => CarbonImmutable::now()->subMinutes(50)->timestamp,
                    'white' => ['username' => 'alice-chesscom', 'rating' => 1500, 'result' => 'checkmated'],
                    'black' => ['username' => 'bob-chesscom', 'rating' => 1495, 'result' => 'win'],
                ]),
                // Real staked game — STARTED 20min ago (after the stake); alice wins.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/real',
                    'start_time' => CarbonImmutable::now()->subMinutes(20)->timestamp,
                    'end_time' => CarbonImmutable::now()->subMinutes(15)->timestamp,
                    'white' => ['username' => 'alice-chesscom', 'rating' => 1500, 'result' => 'win'],
                    'black' => ['username' => 'bob-chesscom', 'rating' => 1495, 'result' => 'checkmated'],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    // Only the real (post-stake) game survives → creator wins. A no-op guard
    // would keep both and settle the earliest-started = the stale one = taker.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($match->listing->user_id);
});

test('chess.com picks the FIRST game started after match creation among a rematch', function () {
    $match = chessComAutoFetchMatch();

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                // Later game — bob wins. Must NOT be picked.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/222',
                    'start_time' => CarbonImmutable::now()->subMinutes(10)->timestamp,
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                    'white' => ['username' => 'alice-chesscom', 'rating' => 1500, 'result' => 'checkmated'],
                    'black' => ['username' => 'bob-chesscom', 'rating' => 1495, 'result' => 'win'],
                ]),
                // Earlier game (first played after the stake) — alice wins. Picked.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/111',
                    'start_time' => CarbonImmutable::now()->subMinutes(40)->timestamp,
                    'end_time' => CarbonImmutable::now()->subMinutes(35)->timestamp,
                    'white' => ['username' => 'alice-chesscom', 'rating' => 1500, 'result' => 'win'],
                    'black' => ['username' => 'bob-chesscom', 'rating' => 1495, 'result' => 'checkmated'],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAutoFetch($match);

    // Earliest-started game (alice = creator wins) settled.
    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($match->listing->user_id);
});
