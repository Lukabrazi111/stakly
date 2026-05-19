<?php

use App\Actions\Message\SendMessageAction;
use App\Enums\MatchStatus;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3 Slice 1 — chat image attachments. Covers upload validation, the
 * empty-caption-with-file case (image-only messages), and the second rate
 * limit bucket scoped to attachment-bearing sends.
 *
 * The streaming endpoint that serves uploaded files has its own test file
 * (AttachmentStreamingTest) — keeping upload tests focused on the inbound
 * shape and persistence keeps each file under a clean conceptual umbrella.
 */

/**
 * Helper mirroring the one in SendMessageTest — a Pending match with
 * verified creator + taker, ready for chat. Returns [creator, taker, match].
 */
function attachmentMatch(): array
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
    // Isolate media writes per-test on the same `local` disk the Message
    // model writes to. Storage::fake clears the underlying tmpfs between tests.
    Storage::fake('local');

    // Burn both rate-limit buckets between tests so the limiter doesn't
    // bleed state across cases.
    foreach (range(1, 8) as $i) {
        RateLimiter::clear("chat:{$i}");
        RateLimiter::clear("chat-upload:{$i}");
    }
});

// ─── Happy path ─────────────────────────────────────────────────────────────

test('image upload with caption persists message + media', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    $response = $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'check out my game',
            'file' => UploadedFile::fake()->image('game.jpg', 800, 600),
        ]);

    $response->assertRedirect();

    $message = Message::query()->where('match_id', $match->id)->firstOrFail();

    expect($message->content)->toBe('check out my game')
        ->and($message->getMedia(Message::ATTACHMENTS_COLLECTION))->toHaveCount(1);

    $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

    expect($media->mime_type)->toBe('image/jpeg')
        ->and($media->disk)->toBe('local');

    Event::assertDispatched(MessageSent::class);
});

test('image-only message (empty caption) is accepted', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ])
        ->assertRedirect();

    $message = Message::query()->where('match_id', $match->id)->firstOrFail();

    expect($message->content)->toBeNull()
        ->and($message->getMedia(Message::ATTACHMENTS_COLLECTION))->toHaveCount(1);
});

test('whitespace-only caption with file is treated as empty caption', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => "   \n  ",
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ])
        ->assertRedirect();

    $message = Message::query()->where('match_id', $match->id)->firstOrFail();

    expect($message->content)->toBeNull()
        ->and($message->getMedia(Message::ATTACHMENTS_COLLECTION))->toHaveCount(1);
});

// ─── Validation ─────────────────────────────────────────────────────────────

test('rejects request with neither content nor file', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [])
        ->assertJsonValidationErrors(['content', 'file']);

    expect(Message::count())->toBe(0);
});

test('rejects empty content with no file', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", ['content' => ''])
        ->assertJsonValidationErrors(['content', 'file']);

    expect(Message::count())->toBe(0);
});

test('rejects PDF file as wrong MIME', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])
        ->assertJsonValidationErrors('file');

    expect(Message::count())->toBe(0);
});

test('rejects file over 5 MB', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [
            // 5121 KB = 5 MB + 1 KB. Boundary check.
            'file' => UploadedFile::fake()->image('huge.jpg')->size(5121),
        ])
        ->assertJsonValidationErrors('file');

    expect(Message::count())->toBe(0);
});

test('accepts file exactly at 5 MB cap', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->image('cap.jpg')->size(5120),
        ])
        ->assertRedirect();

    expect(Message::query()->where('match_id', $match->id)->count())->toBe(1);
});

// ─── Match status gate ─────────────────────────────────────────────────────

test('cannot upload to a Settled match', function () {
    [$creator, , $match] = attachmentMatch();
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->image('late.jpg'),
        ])
        ->assertJsonValidationErrors('content');

    expect(Message::count())->toBe(0);
});

test('can upload to a Disputed match — chat is evidence record', function () {
    [$creator, , $match] = attachmentMatch();
    $match->update(['status' => MatchStatus::Disputed]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'my proof',
            'file' => UploadedFile::fake()->image('proof.jpg'),
        ])
        ->assertRedirect();

    expect(Message::query()->where('match_id', $match->id)->count())->toBe(1);
});

// ─── Upload rate limit (5 per 30s) ──────────────────────────────────────────

test('6th upload within 30s is throttled', function () {
    [$creator, , $match] = attachmentMatch();

    foreach (range(1, 5) as $i) {
        $this->actingAs($creator)
            ->post("/matches/{$match->id}/messages", [
                'file' => UploadedFile::fake()->image("img-{$i}.jpg"),
            ])
            ->assertRedirect();
    }

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [
            'file' => UploadedFile::fake()->image('img-6.jpg'),
        ])
        ->assertStatus(429);

    expect(Message::query()->where('match_id', $match->id)->count())->toBe(5);
});

test('text rate limit and upload rate limit are independent buckets', function () {
    [$creator, , $match] = attachmentMatch();

    // Burn the upload bucket (5/30s).
    foreach (range(1, 5) as $i) {
        $this->actingAs($creator)
            ->post("/matches/{$match->id}/messages", [
                'file' => UploadedFile::fake()->image("u-{$i}.jpg"),
            ])
            ->assertRedirect();
    }

    // Text-only sends still pass (text bucket is 10/10s, fresh).
    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", ['content' => 'still room for text'])
        ->assertRedirect();

    expect(Message::query()->where('match_id', $match->id)->count())->toBe(6);
});

// ─── Broadcast + resource payload ──────────────────────────────────────────

test('broadcast payload includes the image attachment entry', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    app(SendMessageAction::class)->handle(
        $creator,
        $match,
        'with image',
        UploadedFile::fake()->image('shot.jpg', 600, 400),
    );

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        $payload = $event->broadcastWith();
        $attachments = $payload['attachments'] ?? [];

        return is_array($attachments)
            && count($attachments) === 1
            && $attachments[0]['type'] === 'image'
            && str_contains($attachments[0]['url'], '/attachments/')
            && str_contains($attachments[0]['thumb_url'], 'conversion=thumb');
    });
});

test('text-only message broadcasts an empty attachments array (not null)', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    app(SendMessageAction::class)->handle($creator, $match, 'no image here');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        $payload = $event->broadcastWith();

        return is_array($payload['attachments'] ?? null)
            && $payload['attachments'] === [];
    });
});

test('image attachment includes width and height in the payload', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    app(SendMessageAction::class)->handle(
        $creator,
        $match,
        null,
        UploadedFile::fake()->image('dims.jpg', 640, 480),
    );

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        $attachments = $event->broadcastWith()['attachments'] ?? [];

        return count($attachments) === 1
            && $attachments[0]['width'] === 640
            && $attachments[0]['height'] === 480;
    });
});

// ─── Correlation id (optimistic-UI round-trip) ─────────────────────────────

test('correlation_id is echoed in the broadcast payload', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    $cid = '550e8400-e29b-41d4-a716-446655440000';

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", [
            'content' => 'with correlation',
            'correlation_id' => $cid,
        ])
        ->assertRedirect();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($cid) {
        return ($event->broadcastWith()['correlation_id'] ?? null) === $cid;
    });
});

test('correlation_id is null in payload when sender omits it', function () {
    [$creator, , $match] = attachmentMatch();
    Event::fake([MessageSent::class]);

    $this->actingAs($creator)
        ->post("/matches/{$match->id}/messages", ['content' => 'no cid'])
        ->assertRedirect();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        $payload = $event->broadcastWith();

        return array_key_exists('correlation_id', $payload)
            && $payload['correlation_id'] === null;
    });
});

test('malformed correlation_id is rejected as 422', function () {
    [$creator, , $match] = attachmentMatch();

    $this->actingAs($creator)
        ->postJson("/matches/{$match->id}/messages", [
            'content' => 'hi',
            'correlation_id' => 'not-a-uuid',
        ])
        ->assertJsonValidationErrors('correlation_id');

    expect(Message::count())->toBe(0);
});
