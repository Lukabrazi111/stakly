<?php

use App\Enums\MatchStatus;
use App\Jobs\FetchChessComGameMetadataJob;
use App\Jobs\FetchLichessGameMetadataJob;
use App\Jobs\FetchLinkMetadataJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wiring test: when a chat message lands and the content carries a URL,
 * `SendMessageAction` queues `FetchLinkMetadataJob` with the message
 * and the de-duplicated URL list. Doesn't exercise the actual fetch —
 * that's the job's responsibility and is tested separately under
 * `FetchLinkMetadataJobTest`.
 */

/**
 * Pending match between two players, mirrors the helpers in the other
 * Slice tests. Returns [creator, taker, match].
 */
function linkPreviewMatch(): array
{
    $creator = User::factory()->active()->create();
    $taker = User::factory()->create();

    $listing = Listing::factory()->open()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    return [$creator, $taker, $match->fresh(['listing.user', 'taker'])];
}

beforeEach(function () {
    foreach (range(1, 8) as $i) {
        RateLimiter::clear("chat:{$i}");
        RateLimiter::clear("chat-upload:{$i}");
    }
});

test('message with a url dispatches the metadata job', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'check this https://lichess.org/abc',
        ])
        ->assertRedirect();

    $message = Message::query()->where('match_id', $match->id)->firstOrFail();

    Bus::assertDispatched(
        FetchLinkMetadataJob::class,
        fn (FetchLinkMetadataJob $job) => $job->message->is($message)
            && $job->urls === ['https://lichess.org/abc'],
    );
});

test('message with multiple urls passes the full list to the job', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'see https://lichess.org/a and https://chess.com/b',
        ])
        ->assertRedirect();

    Bus::assertDispatched(
        FetchLinkMetadataJob::class,
        fn (FetchLinkMetadataJob $job) => $job->urls === [
            'https://lichess.org/a',
            'https://chess.com/b',
        ],
    );
});

test('text-only message without urls does not dispatch the job', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'just a plain chat message',
        ])
        ->assertRedirect();

    Bus::assertNotDispatched(FetchLinkMetadataJob::class);
});

test('image-only message (no content) does not dispatch the job', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->image('shot.jpg'),
        ])
        ->assertRedirect();

    Bus::assertNotDispatched(FetchLinkMetadataJob::class);
});

// ─── Phase 4 — Lichess game URL routing ─────────────────────────────────────

test('lichess game URL dispatches the verified-card job, not the OG fetcher', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class, FetchLichessGameMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'gg https://lichess.org/abcdefgh/white',
        ])
        ->assertRedirect();

    $message = Message::query()->where('match_id', $match->id)->firstOrFail();

    Bus::assertDispatched(
        FetchLichessGameMetadataJob::class,
        fn (FetchLichessGameMetadataJob $job) => $job->message->is($message)
            && $job->gameId === 'abcdefgh',
    );
    Bus::assertNotDispatched(FetchLinkMetadataJob::class);
});

test('mixed URLs in one message: lichess goes to verified job, others to OG fetcher', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class, FetchLichessGameMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'see https://lichess.org/abcdefgh and https://example.com/x',
        ])
        ->assertRedirect();

    Bus::assertDispatched(
        FetchLichessGameMetadataJob::class,
        fn (FetchLichessGameMetadataJob $job) => $job->gameId === 'abcdefgh',
    );
    Bus::assertDispatched(
        FetchLinkMetadataJob::class,
        fn (FetchLinkMetadataJob $job) => $job->urls === ['https://example.com/x'],
    );
});

test('duplicate lichess URLs in one message dispatch the job once per game ID', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLichessGameMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'https://lichess.org/abcdefgh and again https://lichess.org/abcdefgh#5',
        ])
        ->assertRedirect();

    Bus::assertDispatchedTimes(FetchLichessGameMetadataJob::class, 1);
});

test('chess.com game URL dispatches FetchChessComGameMetadataJob, not the OG fetcher', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class, FetchChessComGameMetadataJob::class]);

    $url = 'https://www.chess.com/game/live/12345678901';

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => "gg {$url}",
        ])
        ->assertRedirect();

    Bus::assertDispatched(
        FetchChessComGameMetadataJob::class,
        fn (FetchChessComGameMetadataJob $job) => $job->gameUrl === $url,
    );
    Bus::assertNotDispatched(FetchLinkMetadataJob::class);
});

test('mixed Lichess + chess.com URLs each route to their own job', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([
        FetchLichessGameMetadataJob::class,
        FetchChessComGameMetadataJob::class,
        FetchLinkMetadataJob::class,
    ]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'lichess https://lichess.org/abcdefgh and chesscom https://www.chess.com/game/live/12345678901',
        ])
        ->assertRedirect();

    Bus::assertDispatched(
        FetchLichessGameMetadataJob::class,
        fn (FetchLichessGameMetadataJob $job) => $job->gameId === 'abcdefgh',
    );
    Bus::assertDispatched(
        FetchChessComGameMetadataJob::class,
        fn (FetchChessComGameMetadataJob $job) => str_contains($job->gameUrl, '12345678901'),
    );
    Bus::assertNotDispatched(FetchLinkMetadataJob::class);
});

test('lichess non-game URL (e.g. /training) routes to the OG fetcher', function () {
    [$creator, , $match] = linkPreviewMatch();
    Bus::fake([FetchLinkMetadataJob::class, FetchLichessGameMetadataJob::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'puzzle prep https://lichess.org/training',
        ])
        ->assertRedirect();

    Bus::assertNotDispatched(FetchLichessGameMetadataJob::class);
    Bus::assertDispatched(
        FetchLinkMetadataJob::class,
        fn (FetchLinkMetadataJob $job) => $job->urls === ['https://lichess.org/training'],
    );
});
