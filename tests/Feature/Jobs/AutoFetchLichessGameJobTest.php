<?php

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\LichessGameClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Behaviour for the auto-fetch path. The decision logic (single-decisive-
 * candidate filter, snapshot cross-check, idempotency) is exercised here;
 * the underlying API parsing is covered in `LichessGameClientTest`.
 */
/**
 * @param  list<array{side?: string, provider?: LinkedAccountProvider, username?: string}>|null  $snapshots
 *                                                                                                           Null = default both-sides Lichess. Empty array = no snapshots.
 */
function autoFetchMatch(?array $snapshots = null): GameMatch
{
    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();

    $listing = Listing::factory()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            ...$row,
        ]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runAutoFetch(GameMatch $match): void
{
    (new AutoFetchLichessGameJob($match))
        ->handle(app(LichessGameClient::class), app(PostSystemMessageAction::class));
}

// ─── Happy path ─────────────────────────────────────────────────────────────

test('single decisive game posts a verified system game_card', function () {
    $match = autoFetchMatch();
    $body = json_encode(lichessGameFixture(['id' => 'abcdefgh']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($body, 200),
    ]);
    Event::fake([MessageSent::class]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->content)->toBe('Verified Lichess game record.')
        ->and($system->attachments_json)->toHaveCount(1);

    $card = $system->attachments_json[0];
    expect($card['type'])->toBe('game_card')
        ->and($card['provider'])->toBe('lichess')
        ->and($card['source'])->toBe('auto_fetch')
        ->and($card['game_id'])->toBe('abcdefgh')
        ->and($card['verified'])->toBeTrue()
        ->and($card['white_username'])->toBe('alice-lichess')
        ->and($card['black_username'])->toBe('bob-lichess')
        ->and($card['winner_username'])->toBe('alice-lichess');

    Event::assertDispatched(MessageSent::class);
});

// ─── Single-candidate-or-skip heuristic ─────────────────────────────────────

test('no games found → no system message posted', function () {
    $match = autoFetchMatch();

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 200),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

test('multiple decisive games → no system message posted (ambiguous)', function () {
    $match = autoFetchMatch();
    $g1 = json_encode(lichessGameFixture(['id' => 'game0001']));
    $g2 = json_encode(lichessGameFixture(['id' => 'game0002', 'winner' => 'black']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($g1."\n".$g2, 200),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

test('non-decisive games (draw, aborted) → no system message posted', function () {
    $match = autoFetchMatch();

    $drawn = lichessGameFixture(['status' => 'draw']);
    unset($drawn['winner']);
    $aborted = lichessGameFixture(['status' => 'aborted']);
    unset($aborted['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($drawn)."\n".json_encode($aborted),
            200,
        ),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

test('one decisive + one drawn → posts the decisive one (drawn filtered out)', function () {
    $match = autoFetchMatch();

    $decisive = lichessGameFixture(['id' => 'winnergg']);
    $drawn = lichessGameFixture(['id' => 'drawnone', 'status' => 'draw']);
    unset($drawn['winner']);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode($decisive)."\n".json_encode($drawn),
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['game_id'])->toBe('winnergg');
});

// ─── Failure modes ──────────────────────────────────────────────────────────

test('provider 5xx → silent log + no post', function () {
    $match = autoFetchMatch();

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 503),
    ]);

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

test('missing snapshot username → no-op', function () {
    // Only taker snapshot present — creator's Lichess unlink/never-linked
    // means we have nothing to cross-check the white side against.
    $match = autoFetchMatch(snapshots: [
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ]);

    // Don't even fake — if the job tries to call out, the missing fake will
    // throw and the test will flag the bug.
    Http::preventStrayRequests();

    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(0);
});

// ─── Idempotency ───────────────────────────────────────────────────────────

test('re-running the job does not double-post (idempotency via attachments_json scan)', function () {
    $match = autoFetchMatch();
    $body = json_encode(lichessGameFixture(['id' => 'abcdefgh']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($body, 200),
    ]);

    runAutoFetch($match);
    runAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())
        ->toBe(1);
});

test('paste-source game_card does NOT block a later auto-fetch post', function () {
    // A user pasting a Lichess URL first creates a card with source=paste
    // on their own Text message. Auto-fetch should still fire when the
    // first confirm happens — the idempotency check is scoped to system
    // messages with source=auto_fetch specifically.
    $match = autoFetchMatch();

    Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $match->taker_user_id,
        'content' => 'pasted',
        'attachments_json' => [[
            'type' => 'game_card',
            'source' => 'paste',
            'game_id' => 'pasteddd',
        ]],
    ]);

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            json_encode(lichessGameFixture(['id' => 'autoabcd'])),
            200,
        ),
    ]);

    runAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json[0]['source'])->toBe('auto_fetch')
        ->and($system->attachments_json[0]['game_id'])->toBe('autoabcd');
});
