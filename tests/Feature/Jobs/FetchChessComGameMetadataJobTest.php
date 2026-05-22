<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Events\MessageSent;
use App\Jobs\FetchChessComGameMetadataJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\ChessComGameClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function chessComPasteMatch(?array $snapshots = null): array
{
    $creator = User::factory()->active()->withChessCom('alice-chesscom')->create();
    $taker = User::factory()->withChessCom('bob-chesscom')->create();

    $listing = Listing::factory()->open()->forChessCom()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'alice-chesscom'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'bob-chesscom'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create(['match_id' => $match->id, ...$row]);
    }

    return [$creator, $taker, $match->fresh(['listing.user', 'taker', 'providerSnapshots'])];
}

function runChessComPasteJob(Message $message, string $url): void
{
    (new FetchChessComGameMetadataJob($message, $url))
        ->handle(app(ChessComGameClient::class));
}

test('verified chess.com card is appended when both players match snapshot', function () {
    [$creator, , $match] = chessComPasteMatch();

    $gameUrl = 'https://www.chess.com/game/live/12345678901';
    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'check '.$gameUrl,
    ]);

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture(['url' => $gameUrl]),
            ]),
            200,
        ),
    ]);
    Event::fake([MessageSent::class]);

    runChessComPasteJob($message, $gameUrl);

    $fresh = $message->fresh();
    expect($fresh->attachments_json)->toHaveCount(1);

    $card = $fresh->attachments_json[0];
    expect($card['type'])->toBe('game_card')
        ->and($card['provider'])->toBe('chess_com')
        ->and($card['source'])->toBe('paste')
        ->and($card['url'])->toBe($gameUrl)
        ->and($card['verified'])->toBeTrue()
        ->and($card['white_username'])->toBe('alice-chesscom')
        ->and($card['winner_username'])->toBe('alice-chesscom');

    Event::assertDispatched(MessageSent::class);
});

test('unverified card is appended when players do not match snapshot', function () {
    [$creator, , $match] = chessComPasteMatch();

    $gameUrl = 'https://www.chess.com/game/live/12345678901';
    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => $gameUrl,
    ]);

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'url' => $gameUrl,
                    'white' => ['username' => 'stranger-a', 'result' => 'win'],
                    'black' => ['username' => 'stranger-b', 'result' => 'checkmated'],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComPasteJob($message, $gameUrl);

    $card = $message->fresh()->attachments_json[0];
    expect($card['verified'])->toBeFalse();
});

test('no card appended when game not in archive', function () {
    [$creator, , $match] = chessComPasteMatch();

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'gg',
    ]);

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(chessComArchiveFixture([]), 200),
    ]);
    Event::fake([MessageSent::class]);

    runChessComPasteJob($message, 'https://www.chess.com/game/live/99999999999');

    expect($message->fresh()->attachments_json)->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

test('no card appended when neither snapshot has chess.com username', function () {
    [$creator, , $match] = chessComPasteMatch(snapshots: []);

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'gg',
    ]);

    Http::preventStrayRequests();

    runChessComPasteJob($message, 'https://www.chess.com/game/live/12345678901');

    expect($message->fresh()->attachments_json)->toBeNull();
});
