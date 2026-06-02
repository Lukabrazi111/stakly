<?php

use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use Illuminate\Http\UploadedFile;

const DISPUTE_REASON = 'opponent claims they won but the game shows me winning';

function validDisputeBody(array $overrides = []): array
{
    return array_merge(['reason' => DISPUTE_REASON], $overrides);
}

/**
 * Helper: a Pending match with both stakes already escrowed — the state in
 * which `openDispute` is allowed. Mirrors `pendingMatch` in GameMatchConfirmTest
 * but local-named to keep the two test files self-contained.
 */
function disputableMatch(string $stake = '100'): array
{
    platformUser();

    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($creator)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $creator,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    Wallet::hold(
        user: $taker,
        amount: $stake,
        listing: $listing,
        reference: "match-take:{$listing->id}",
    );

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    return [$creator, $taker, $listing, $match];
}

// ─── Authorization ──────────────────────────────────────────────────────────

test('guest cannot open dispute — redirected to login', function () {
    [, , , $match] = disputableMatch();

    $this->post(route('matches.openDispute', $match), validDisputeBody())
        ->assertRedirect(route('login'));
});

test('non-participant cannot open dispute (403)', function () {
    [, , , $match] = disputableMatch();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertForbidden();
});

test('cannot open dispute on a Settled match (403 via policy)', function () {
    [$creator, , , $match] = disputableMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertForbidden();
});

test('cannot open dispute on a ManualReview match (403 via policy)', function () {
    [$creator, , , $match] = disputableMatch();
    $match->update([
        'status' => MatchStatus::ManualReview,
        'dispute_opened_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertForbidden();
});

// ─── Happy path: dispute opens → Disputed status, money stays escrowed ─────
// M12 Phase 3 — OpenDisputeAction no longer auto-resolves via the game API.
// It flips the match to Disputed and surfaces it in the admin queue
// (Filament panel). Money stays escrowed until admin clicks Settle/Draw.

test('creator opens dispute → status flips to Disputed, money stays escrowed', function () {
    [$creator, $taker, , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->dispute_opened_by)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();

    // Both stakes still escrowed — nothing moves until admin resolves.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

test('taker opens dispute → status flips to Disputed, dispute_opened_by is taker', function () {
    [, $taker, , $match] = disputableMatch();

    $this->actingAs($taker)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->dispute_opened_by)->toBe($taker->id);
});

// ─── Race / idempotency ─────────────────────────────────────────────────────

test('second openDispute on the same match is blocked by policy (already Disputed)', function () {
    [$creator, , , $match] = disputableMatch();

    // First call flips to Disputed.
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody());

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);

    // Second call: policy blocks (openDispute policy requires Pending).
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertForbidden();
});

// ─── Toast assertion ────────────────────────────────────────────────────────

test('opening a dispute flashes the admin-review toast', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody())
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Dispute opened — an admin will review and resolve this match.',
        ]);
});

// ─── Reason + evidence (M27 P3 opener-claim slice) ──────────────────────────

test('opening a dispute requires either reason or evidence — empty body returns 422', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertJsonValidationErrors(['reason', 'evidence']);
});

test('opening a dispute with only an evidence file (no reason) is allowed', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), [
            'evidence' => UploadedFile::fake()->image('proof.jpg', 200, 200),
        ])
        ->assertRedirect();

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('user_id', $creator->id)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_opening']])
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBeNull()
        ->and($message->getMedia(Message::ATTACHMENTS_COLLECTION))->toHaveCount(1);
});

test('opening a dispute posts the reason as a user message tagged dispute_opening', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody());

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('user_id', $creator->id)
        ->where('type', MessageType::Text)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_opening']])
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe(DISPUTE_REASON);
});

test('opening a dispute with an evidence image attaches it to the opener message', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody([
            'evidence' => UploadedFile::fake()->image('proof.jpg', 200, 200),
        ]));

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('user_id', $creator->id)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_opening']])
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->getMedia(Message::ATTACHMENTS_COLLECTION))->toHaveCount(1);
});

test('opening a dispute with a PDF evidence file attaches it to the opener message', function () {
    [$creator, , , $match] = disputableMatch();

    // createWithContent so the file has a real PDF magic header — Spatie
    // re-detects mime from the file body, not the claimed type.
    $pdfBody = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n%%EOF\n";

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody([
            'evidence' => UploadedFile::fake()->createWithContent('proof.pdf', $pdfBody),
        ]));

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('user_id', $creator->id)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_opening']])
        ->first();

    expect($message)->not->toBeNull();

    $media = $message->getMedia(Message::ATTACHMENTS_COLLECTION);
    expect($media)->toHaveCount(1)
        ->and($media->first()->mime_type)->toBe('application/pdf');
});

test('opening a dispute rejects an unsupported evidence type (text)', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match), validDisputeBody([
            'evidence' => UploadedFile::fake()->create('proof.txt', 5, 'text/plain'),
        ]))
        ->assertJsonValidationErrors('evidence');
});
