<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Events\MessageSent;
use App\Jobs\FetchLichessGameMetadataJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\LichessGameClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Behaviour for the Lichess paste path. Exercises the cross-check + persist
 * + rebroadcast pipeline by feeding `Http::fake()` responses through the
 * real `LichessGameClient`. The client's own response-parsing edge cases
 * (404, 5xx, malformed) are covered separately under
 * `LichessGameClientTest`.
 */
/**
 * Build a Pending match between alice (creator) + bob (taker). The
 * `$snapshotOverrides` argument controls the `match_provider_snapshots`
 * rows inserted; default seeds both Lichess sides. Pass an empty array to
 * test "no snapshot" branches, or override one side to test asymmetric
 * verification.
 *
 * @param  list<array{side?: string, provider?: LinkedAccountProvider, username?: string}>|null  $snapshots
 *                                                                                                           Null = default both-sides Lichess. Empty array = no snapshots.
 */
function pasteMatch(?array $snapshots = null): array
{
    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();

    $listing = Listing::factory()->open()->for($creator)->create();
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

    return [$creator, $taker, $match->fresh(['listing.user', 'taker', 'providerSnapshots'])];
}

function pasteMessage(GameMatch $match, User $sender, string $content = 'pasted'): Message
{
    return Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $sender->id,
        'content' => $content,
    ]);
}

function runJob(Message $message, string $gameId): void
{
    (new FetchLichessGameMetadataJob($message, $gameId))
        ->handle(app(LichessGameClient::class));
}

// ─── Verified card (happy path) ─────────────────────────────────────────────

test('verified game_card is appended when both players match snapshot', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator, 'gg https://lichess.org/abcdefgh');

    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture([
            'id' => 'abcdefgh',
        ]), 200),
    ]);
    Event::fake([MessageSent::class]);

    runJob($message, 'abcdefgh');

    $fresh = $message->fresh();
    expect($fresh->attachments_json)->toHaveCount(1);

    $card = $fresh->attachments_json[0];
    expect($card['type'])->toBe('game_card')
        ->and($card['provider'])->toBe('lichess')
        ->and($card['source'])->toBe('paste')
        ->and($card['game_id'])->toBe('abcdefgh')
        ->and($card['url'])->toBe('https://lichess.org/abcdefgh')
        ->and($card['verified'])->toBeTrue()
        ->and($card['white_username'])->toBe('alice-lichess')
        ->and($card['black_username'])->toBe('bob-lichess')
        ->and($card['winner_color'])->toBe('white')
        ->and($card['winner_username'])->toBe('alice-lichess')
        ->and($card['status'])->toBe('mate')
        ->and($card['speed'])->toBe('blitz')
        ->and($card['rated'])->toBeTrue();

    Event::assertDispatched(MessageSent::class);
});

test('verified card recognises swapped colors (creator played black)', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    // Bob (taker) plays white, Alice (creator) plays black. Verified should
    // still resolve true — the side-color binding is order-independent.
    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture([
            'id' => 'abcdefgh',
            'players' => [
                'white' => ['user' => ['name' => 'bob-lichess']],
                'black' => ['user' => ['name' => 'alice-lichess']],
            ],
            'winner' => 'black',
        ]), 200),
    ]);

    runJob($message, 'abcdefgh');

    $card = $message->fresh()->attachments_json[0];
    expect($card['verified'])->toBeTrue()
        ->and($card['winner_username'])->toBe('alice-lichess');
});

test('username cross-check is case-insensitive', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    // Snapshot is 'alice-lichess' / 'bob-lichess'; Lichess returns
    // 'Alice-Lichess' / 'BOB-LICHESS'. Should still match.
    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture([
            'players' => [
                'white' => ['user' => ['name' => 'Alice-Lichess']],
                'black' => ['user' => ['name' => 'BOB-LICHESS']],
            ],
        ]), 200),
    ]);

    runJob($message, 'abcdefgh');

    expect($message->fresh()->attachments_json[0]['verified'])->toBeTrue();
});

// ─── Unverified card branches ───────────────────────────────────────────────

test('unverified card appended when one snapshot side is missing', function () {
    // Match created before creator linked Lichess — only the taker's
    // username was snapshotted. Paste still renders a card, just unverified.
    [, , $match] = pasteMatch(snapshots: [
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ]);

    $message = pasteMessage($match, $match->taker);

    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture(), 200),
    ]);

    runJob($message, 'abcdefgh');

    $card = $message->fresh()->attachments_json[0];
    expect($card['type'])->toBe('game_card')
        ->and($card['verified'])->toBeFalse();
});

test('unverified card appended when neither player matches the snapshot', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    // Game between two unrelated users — neither matches Alice/Bob.
    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture([
            'players' => [
                'white' => ['user' => ['name' => 'stranger-a']],
                'black' => ['user' => ['name' => 'stranger-b']],
            ],
        ]), 200),
    ]);

    runJob($message, 'abcdefgh');

    $card = $message->fresh()->attachments_json[0];
    expect($card['verified'])->toBeFalse();
});

test('unverified card appended when only one side matches snapshot', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    // Alice in game; opponent is unrelated. Verified should be false —
    // verification requires BOTH sides anchor.
    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture([
            'players' => [
                'white' => ['user' => ['name' => 'alice-lichess']],
                'black' => ['user' => ['name' => 'stranger']],
            ],
        ]), 200),
    ]);

    runJob($message, 'abcdefgh');

    expect($message->fresh()->attachments_json[0]['verified'])->toBeFalse();
});

// ─── Silent failure modes ───────────────────────────────────────────────────

test('no card appended on 404 (game does not exist)', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    Http::fake([
        'lichess.org/game/export/*' => Http::response('', 404),
    ]);
    Event::fake([MessageSent::class]);

    runJob($message, 'missing01');

    expect($message->fresh()->attachments_json)->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

test('no card appended on provider 5xx (silent log + swallow)', function () {
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    Http::fake([
        'lichess.org/game/export/*' => Http::response('', 503),
    ]);
    Event::fake([MessageSent::class]);

    runJob($message, 'abcdefgh');

    expect($message->fresh()->attachments_json)->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

// ─── Concurrency safety ────────────────────────────────────────────────────

test('append preserves an existing link-card attachment from FetchLinkMetadataJob', function () {
    // Race: an OG fetch for a different URL in the same message wrote a
    // link entry first; the Lichess job arrives shortly after. Both
    // entries should survive in insertion order.
    [$creator, , $match] = pasteMatch();
    $message = pasteMessage($match, $creator);

    $message->update(['attachments_json' => [[
        'type' => 'link',
        'url' => 'https://example.com/x',
        'title' => 'Example',
    ]]]);

    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture(), 200),
    ]);

    runJob($message, 'abcdefgh');

    $fresh = $message->fresh();
    expect($fresh->attachments_json)->toHaveCount(2)
        ->and($fresh->attachments_json[0]['type'])->toBe('link')
        ->and($fresh->attachments_json[1]['type'])->toBe('game_card');
});
