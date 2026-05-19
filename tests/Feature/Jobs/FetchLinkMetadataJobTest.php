<?php

use App\Enums\MatchStatus;
use App\Events\MessageSent;
use App\Jobs\FetchLinkMetadataJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3 Slice 2 — link metadata job behavior.
 *
 * The actual outbound HTTP fetch (oscarotero/embed → CurlClient) is
 * deliberately not exercised here: it would need either a real network
 * round-trip (flaky) or a vendor mock that defeats the security-relevant
 * parts of the wrapper. Instead, the tests cover the deterministic
 * branches by seeding the per-URL cache with pre-built entries, which is
 * the same shape the job builds internally after a successful fetch.
 * That covers the persist + race-safe append + re-broadcast logic — the
 * parts the rest of Stakly depends on.
 *
 * The fetch path itself (regex extraction → cache miss → embed library
 * call → image proxy) gets manual QA on the staging environment + a
 * single end-to-end smoke test once the slice ships.
 */
function jobMatch(): array
{
    $creator = User::factory()->active()->create();
    $taker = User::factory()->create();

    $listing = Listing::factory()->open()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    return [$creator, $match->fresh(['listing.user', 'taker'])];
}

/**
 * Realistic shape of the cached link entry (the post-fetch
 * representation written to `attachments_json`). Tests seed this into
 * the cache; the job picks it up on the cache-hit path.
 *
 * @return array<string, mixed>
 */
function fakeLinkEntry(string $url, ?string $imagePath = null): array
{
    return [
        'type' => 'link',
        'url' => $url,
        'canonical_url' => $url,
        'title' => 'Example title',
        'description' => 'Example description',
        'site_name' => 'example.com',
        'image_path' => $imagePath,
        'image_mime' => $imagePath ? 'image/jpeg' : null,
    ];
}

beforeEach(function () {
    Cache::flush();
});

test('cached entry is appended to attachments_json and rebroadcast', function () {
    [$creator, $match] = jobMatch();

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'check this https://lichess.org/abc',
    ]);

    $url = 'https://lichess.org/abc';
    $entry = fakeLinkEntry($url);

    Cache::put('link-preview:'.hash('sha256', $url), $entry, 3600);
    Event::fake([MessageSent::class]);

    (new FetchLinkMetadataJob($message, [$url]))->handle();

    $fresh = $message->fresh();

    expect($fresh->attachments_json)->toBeArray()
        ->and($fresh->attachments_json)->toHaveCount(1)
        ->and($fresh->attachments_json[0]['type'])->toBe('link')
        ->and($fresh->attachments_json[0]['url'])->toBe($url)
        ->and($fresh->attachments_json[0]['title'])->toBe('Example title');

    Event::assertDispatched(MessageSent::class);
});

test('multiple cached entries are appended in order', function () {
    [$creator, $match] = jobMatch();

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'one https://a.com and https://b.com',
    ]);

    Cache::put('link-preview:'.hash('sha256', 'https://a.com'), fakeLinkEntry('https://a.com'), 3600);
    Cache::put('link-preview:'.hash('sha256', 'https://b.com'), fakeLinkEntry('https://b.com'), 3600);

    Event::fake([MessageSent::class]);

    (new FetchLinkMetadataJob($message, ['https://a.com', 'https://b.com']))->handle();

    $fresh = $message->fresh();

    expect($fresh->attachments_json)->toHaveCount(2)
        ->and($fresh->attachments_json[0]['url'])->toBe('https://a.com')
        ->and($fresh->attachments_json[1]['url'])->toBe('https://b.com');
});

test('does not broadcast when no entries were extracted', function () {
    // No cached entries and no URLs — every URL produces null.
    [$creator, $match] = jobMatch();

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'placeholder',
    ]);

    Event::fake([MessageSent::class]);

    (new FetchLinkMetadataJob($message, []))->handle();

    Event::assertNotDispatched(MessageSent::class);

    expect($message->fresh()->attachments_json)->toBeNull();
});

test('appends to existing attachments_json without overwriting prior entries', function () {
    // Race-safe behavior: if attachments_json already has a Slice 1 image
    // entry (or a prior link entry from a separate fetch), the job must
    // merge rather than replace.
    [$creator, $match] = jobMatch();

    $priorEntry = [
        'type' => 'link',
        'url' => 'https://other.com',
        'title' => 'Prior',
    ];

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'see https://lichess.org/abc',
        'attachments_json' => [$priorEntry],
    ]);

    $url = 'https://lichess.org/abc';
    Cache::put('link-preview:'.hash('sha256', $url), fakeLinkEntry($url), 3600);
    Event::fake([MessageSent::class]);

    (new FetchLinkMetadataJob($message, [$url]))->handle();

    $fresh = $message->fresh();

    expect($fresh->attachments_json)->toHaveCount(2)
        ->and($fresh->attachments_json[0]['url'])->toBe('https://other.com')
        ->and($fresh->attachments_json[1]['url'])->toBe($url);
});

test('rebroadcast payload carries link entry through MessageAttachmentsPayload', function () {
    [$creator, $match] = jobMatch();

    $message = Message::factory()->create([
        'match_id' => $match->id,
        'user_id' => $creator->id,
        'content' => 'see https://lichess.org/abc',
    ]);

    $url = 'https://lichess.org/abc';
    Cache::put('link-preview:'.hash('sha256', $url), fakeLinkEntry($url, 'link-images/'.hash('sha256', 'img').'.jpg'), 3600);

    Event::fake([MessageSent::class]);
    Storage::fake('local');

    (new FetchLinkMetadataJob($message, [$url]))->handle();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        $payload = $event->broadcastWith();
        $attachments = $payload['attachments'] ?? [];

        return count($attachments) === 1
            && $attachments[0]['type'] === 'link'
            && $attachments[0]['title'] === 'Example title'
            && str_contains($attachments[0]['image_url'] ?? '', '/link-images/');
    });
});
