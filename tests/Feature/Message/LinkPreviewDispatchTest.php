<?php

use App\Enums\MatchStatus;
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
