<?php

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchFaceitGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.faceit.webhook_secret' => 'test-webhook-secret-xyz']);
    Cache::flush();
    Queue::fake();
});

/**
 * Build a webhook envelope mirroring the assumed FACEIT shape (envelope +
 * inner `payload.teams.{faction}.roster[].player_id`). The receiver's
 * `extractPlayerGuids()` is defensive against shape drift — if FACEIT's
 * actual schema is flatter (no envelope) it still works.
 *
 * @param  list<string>  $creatorGuids
 * @param  list<string>  $takerGuids
 * @return array<string, mixed>
 */
function faceitWebhookFixture(array $creatorGuids = ['guid-a1'], array $takerGuids = ['guid-b1']): array
{
    return [
        'event' => 'match_status_finished',
        'payload' => [
            'id' => '1-webhook-match',
            'teams' => [
                'faction1' => [
                    'roster' => array_map(
                        fn (string $g, int $i) => ['player_id' => $g, 'nickname' => 'p'.$i],
                        $creatorGuids,
                        array_keys($creatorGuids),
                    ),
                ],
                'faction2' => [
                    'roster' => array_map(
                        fn (string $g, int $i) => ['player_id' => $g, 'nickname' => 'q'.$i],
                        $takerGuids,
                        array_keys($takerGuids),
                    ),
                ],
            ],
        ],
    ];
}

/**
 * Slimmer Pending CS2 FACEIT match — only the bits the receiver reads
 * (snapshots + status). Skips the wallet setup `faceitAutoFetchMatch`
 * needs because these tests don't exercise settlement.
 */
function pendingFaceitMatch(string $creatorGuid = 'guid-a1', string $takerGuid = 'guid-b1'): GameMatch
{
    $creator = User::factory()->withFaceit('alice-faceit-'.uniqid(), $creatorGuid)->create();
    $taker = User::factory()->withFaceit('bob-faceit-'.uniqid(), $takerGuid)->create();

    $listing = Listing::factory()
        ->taken()
        ->forGame(Game::Cs2)
        ->for($creator)
        ->state(['platform' => LinkedAccountProvider::Faceit])
        ->create();

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Faceit,
        'username' => 'alice-faceit',
        'provider_user_id' => $creatorGuid,
    ]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => LinkedAccountProvider::Faceit,
        'username' => 'bob-faceit',
        'provider_user_id' => $takerGuid,
    ]);

    return $match;
}

// ─── Authentication layer ─────────────────────────────────────────────────

test('rejects requests with no shared-secret header (401)', function () {
    $this->postJson('/webhooks/faceit', faceitWebhookFixture())
        ->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('rejects requests with a wrong shared-secret header (401)', function () {
    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'wrong-value',
    ])->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('responds 503 when the receiver is not configured (empty env)', function () {
    config(['services.faceit.webhook_secret' => '']);

    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'anything',
    ])->assertStatus(503);

    Queue::assertNothingPushed();
});

// ─── CSRF exemption ───────────────────────────────────────────────────────

test('CSRF is excluded — POST without a CSRF token still reaches the controller', function () {
    // The fact that the previous tests pass with `postJson` (which doesn't
    // attach a CSRF token) already proves this. The explicit test pins the
    // exemption so a regression on the `bootstrap/app.php` `validateCsrfTokens`
    // exclusion fails this test, not eight others with confusing messages.
    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();
});

// ─── Dispatch behavior ────────────────────────────────────────────────────

test('valid request with no candidate Stakly Pending match returns 200 + no dispatch', function () {
    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertNothingPushed();
});

test('valid request with one matching Pending match dispatches AutoFetchFaceitGameJob', function () {
    $match = pendingFaceitMatch();

    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertPushed(AutoFetchFaceitGameJob::class, fn ($job) => $job->match->is($match));
});

test('payload listing only one Stakly player still dispatches the matching match (defensive)', function () {
    // Receiver doesn't require BOTH players to appear in the payload — even
    // if FACEIT's envelope only exposes one team's roster, finding either
    // player on a Pending Stakly match is enough to trigger re-fetch. The
    // job's strict opposing-roster check then catches false positives.
    $match = pendingFaceitMatch();

    $this->postJson('/webhooks/faceit', faceitWebhookFixture(
        creatorGuids: ['guid-a1'],
        takerGuids: ['unknown-guid'],
    ), [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertPushed(AutoFetchFaceitGameJob::class, fn ($job) => $job->match->is($match));
});

test('matches with non-Pending status are not re-dispatched (Settled / Disputed / etc.)', function () {
    $match = pendingFaceitMatch();
    $match->update(['status' => MatchStatus::Settled]);

    $this->postJson('/webhooks/faceit', faceitWebhookFixture(), [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertNothingPushed();
});

test('malformed payload (no rosters / unknown envelope shape) returns 200 with no dispatch', function () {
    $this->postJson('/webhooks/faceit', [
        'event' => 'something-unrelated',
        'data' => 'garbage',
        'nested' => ['no_rosters_here' => true],
    ], [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertNothingPushed();
});

test('flat payload (rosters at the root, no `payload` envelope) is also accepted', function () {
    // Defensive against the alternative envelope shape — FACEIT might POST
    // the match record directly without wrapping it in `payload`. Receiver
    // tries both paths via `extractPlayerGuids()`.
    $match = pendingFaceitMatch();

    $this->postJson('/webhooks/faceit', [
        'teams' => [
            'faction1' => ['roster' => [['player_id' => 'guid-a1']]],
            'faction2' => ['roster' => [['player_id' => 'guid-b1']]],
        ],
    ], [
        'X-Faceit-Webhook-Secret' => 'test-webhook-secret-xyz',
    ])->assertOk();

    Queue::assertPushed(AutoFetchFaceitGameJob::class, fn ($job) => $job->match->is($match));
});
