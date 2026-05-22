<?php

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Jobs\AutoFetchChessComGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\ChessComGameClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function chessComAutoFetchMatch(?array $snapshots = null): GameMatch
{
    $creator = User::factory()->active()->withChessCom('alice-chesscom')->create();
    $taker = User::factory()->withChessCom('bob-chesscom')->create();

    $listing = Listing::factory()->taken()->forChessCom()->for($creator)->create();
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
        ->handle(app(ChessComGameClient::class), app(PostSystemMessageAction::class));
}

// ─── Happy path ─────────────────────────────────────────────────────────────

test('single decisive chess.com game posts a verified system game_card', function () {
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
        ->latest('id')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->content)->toBe('Verified chess.com game record.')
        ->and($system->attachments_json)->toHaveCount(1);

    $card = $system->attachments_json[0];
    expect($card['provider'])->toBe('chess_com')
        ->and($card['source'])->toBe('auto_fetch')
        ->and($card['verified'])->toBeTrue()
        ->and($card['winner_username'])->toBe('alice-chesscom');
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

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(1);
});
