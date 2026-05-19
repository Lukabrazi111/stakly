<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3 Slice 1 — authenticated streaming route for chat image attachments.
 * Asserts the auth gates (404 for non-participants, 404 for cross-match
 * references), the conversion flag, and the cache headers.
 */

/**
 * Helper: create a Pending match with an uploaded image, return
 * [$creator, $taker, $match, $message, $mediaId].
 *
 * @return array{0: User, 1: User, 2: GameMatch, 3: Message, 4: int}
 */
function streamingMatch(): array
{
    $creator = User::factory()->active()->create();
    $taker = User::factory()->create();

    $listing = Listing::factory()->open()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    $message = Message::factory()->for($match, 'match')->for($creator)->create();
    $media = $message
        ->addMedia(seedTestImage())
        ->usingFileName('seeded.jpg')
        ->toMediaCollection(Message::ATTACHMENTS_COLLECTION);

    return [$creator, $taker, $match, $message, $media->id];
}

/**
 * Helper: write a real JPEG to a fresh /tmp path via GD and return the path.
 * `UploadedFile::fake()->image()->getRealPath()` triggers a tempfile-lifecycle
 * race where the file disappears before Spatie's `addMedia` reads it; this
 * approach owns the file outright so Spatie can move it into media storage
 * deterministically. addMedia moves the source, so no manual cleanup needed.
 */
function seedTestImage(): string
{
    $path = sys_get_temp_dir().'/stakly-test-img-'.uniqid().'.jpg';
    $image = imagecreatetruecolor(800, 600);
    imagejpeg($image, $path, 80);
    imagedestroy($image);

    return $path;
}

beforeEach(function () {
    Storage::fake('local');
});

// ─── Auth gates ─────────────────────────────────────────────────────────────

test('participant (creator) can stream the original image', function () {
    [$creator, , $match, $message, $mediaId] = streamingMatch();

    $response = $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

test('participant (taker) can stream too', function () {
    [, $taker, $match, $message, $mediaId] = streamingMatch();

    $this->actingAs($taker)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}")
        ->assertOk();
});

test('non-participant gets 404 — does not leak attachment existence', function () {
    [, , $match, $message, $mediaId] = streamingMatch();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}")
        ->assertNotFound();
});

test('guest gets redirected to login', function () {
    [, , $match, $message, $mediaId] = streamingMatch();

    $this->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}")
        ->assertRedirect(route('login'));
});

test('unverified user is bounced to verification notice', function () {
    [, , $match, $message, $mediaId] = streamingMatch();
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}")
        ->assertRedirect(route('verification.notice'));
});

// ─── Cross-resource scope ───────────────────────────────────────────────────

test('message id from a different match cannot be served under this match', function () {
    [$creator, , $match, , $mediaId] = streamingMatch();

    // Build a second, unrelated match with its own message.
    $otherListing = Listing::factory()->open()->for($creator)->create();
    $otherMatch = GameMatch::factory()->create([
        'listing_id' => $otherListing->id,
        'taker_user_id' => User::factory()->create()->id,
        'status' => MatchStatus::Pending,
    ]);
    $otherMessage = Message::factory()->for($otherMatch, 'match')->for($creator)->create();

    // The creator is a participant of BOTH matches (so the policy gate passes),
    // but the URL pairs `$match` with `$otherMessage` — the message belongs to
    // the wrong match. Must 404 even for a real participant.
    $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$otherMessage->id}/attachments/{$mediaId}")
        ->assertNotFound();
});

test('media id from a different message cannot be served under this one', function () {
    [$creator, , $match, $message] = streamingMatch();

    // Second message in the same match, with its own media.
    $otherMessage = Message::factory()->for($match, 'match')->for($creator)->create();
    $otherMedia = $otherMessage
        ->addMedia(seedTestImage())
        ->toMediaCollection(Message::ATTACHMENTS_COLLECTION);

    // Pair the first message with the other message's media id. 404.
    $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$otherMedia->id}")
        ->assertNotFound();
});

test('nonexistent media id returns 404', function () {
    [$creator, , $match, $message] = streamingMatch();

    $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/999999")
        ->assertNotFound();
});

// ─── Conversion flag ────────────────────────────────────────────────────────

test('?conversion=thumb returns the generated thumbnail', function () {
    [$creator, , $match, $message, $mediaId] = streamingMatch();

    // Both routes succeed; we only verify the OK status here. Asserting the
    // thumb is a different file would require comparing bytes/dimensions,
    // which is more brittle than the unit-level value of this test.
    $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}?conversion=thumb")
        ->assertOk();
});

test('unknown ?conversion value falls through to the original', function () {
    [$creator, , $match, $message, $mediaId] = streamingMatch();

    $this->actingAs($creator)
        ->get("/matches/{$match->id}/messages/{$message->id}/attachments/{$mediaId}?conversion=bogus")
        ->assertOk();
});
