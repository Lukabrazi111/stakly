<?php

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

function validPayload(array $overrides = []): array
{
    return array_merge([
        'game' => 'chess',
        // Default to lichess so the payload matches the default
        // `->withLichess()` user the helpers/tests construct.
        'platform' => 'lichess',
        'stake_amount' => 100,
        'time_control' => ['blitz'],
        'region' => 'Global',
        'duration_hours' => 24,
    ], $overrides);
}

// ─── Create form access (8.3) ─────────────────────────────────────────────

test('guests are redirected to login when hitting the create form', function () {
    $this->get('/listings/create')->assertRedirect(route('login'));
});

test('unverified users are blocked from the create form by the verified middleware', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/listings/create')
        ->assertRedirect(route('verification.notice'));
});

test('verified users see the create form with balance + option lists', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/listings/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/create')
        ->where('balance', '500.000000')
        ->has('regions')
        ->has('languages')
        ->has('durations')
        // M40 — fee rate drives the live Deal summary; single source = config.
        ->where('feeRate', (float) config('stakly.platform_fee_rate'))
    );
});

test('the create form ships allowed_team_sizes per game so the Format picker is server-driven', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->get('/listings/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requirementsByGame.chess.allowed_team_sizes', [1])
            ->where('requirementsByGame.cs2.allowed_team_sizes', [2, 5])
            ->where('requirementsByGame.dota2.allowed_team_sizes', [1]),
        );
});

// ─── Store happy path (8.4) ───────────────────────────────────────────────

test('store creates the listing AND writes the escrow hold ledger row', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload([
        'stake_amount' => 100,
        'time_control' => ['blitz', 'rapid'],
    ]));

    $listing = Listing::query()->where('user_id', $user->id)->firstOrFail();

    $response->assertRedirect(route('listings.mine'));

    expect($listing->status)->toBe(ListingStatus::Open)
        ->and($listing->user_id)->toBe($user->id)
        ->and((float) $listing->stake_amount)->toBe(100.0)
        ->and($listing->time_control->map->value->all())->toBe(['blitz', 'rapid']);

    $hold = WalletTransaction::query()
        ->where('user_id', $user->id)
        ->where('type', WalletTransactionType::EscrowHold)
        ->firstOrFail();

    expect($hold->amount)->toBe('-100.000000')
        ->and($hold->related_listing_id)->toBe($listing->id)
        ->and($hold->reference_id)->toBe("listing-create:{$listing->id}");

    expect((string) $user->fresh()->usdt_balance)->toBe('400.000000');
});

// ─── Validation failures (8.5, 8.6) ───────────────────────────────────────

test('stake exceeding the user balance returns 422 keyed on stake_amount with no listing written', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '50', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload([
        'stake_amount' => 100,
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['stake_amount']);

    expect(Listing::count())->toBe(0)
        ->and(WalletTransaction::where('type', WalletTransactionType::EscrowHold)->count())->toBe(0)
        ->and((string) $user->fresh()->usdt_balance)->toBe('50.000000');
});

test('missing required fields produce field-level 422 errors', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    // `time_control` is no longer required when `game` is missing — the
    // conditional rule (M15 Phase 3 Slice 3) only adds `required` when game
    // resolves to chess.
    $this->actingAs($user)
        ->postJson('/listings', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['stake_amount', 'duration_hours', 'game']);
});

test('missing time_control with game=chess still produces a required error', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['time_control' => null]))
        ->assertJsonValidationErrors('time_control');
});

test('CS2 listing without time_control is accepted and stored as empty', function () {
    platformUser();
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    // CS2 listings are team-play 5v5 only (no 1v1 mode); team_size + creator_side required.
    $payload = validPayload([
        'game' => 'cs2',
        'platform' => 'faceit',
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
    ]);
    unset($payload['time_control']);

    $this->actingAs($user)->postJson('/listings', $payload)->assertRedirect();

    $listing = Listing::query()->where('user_id', $user->id)->firstOrFail();
    expect($listing->time_control->all())->toBe([]);
});

test('team-play creators are redirected to the lobby (listing detail), not /listings/mine', function () {
    // M34 P5 Slice 4 — team-play creators are auto-soft-joined into slot 0,
    // so they should land directly on the lobby to ready up / share the
    // invite link. 1v1 creators keep bouncing to /listings/mine.
    platformUser();
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '500', reference: "test:p5-slice4:{$user->id}");

    $payload = validPayload([
        'game' => 'cs2',
        'platform' => 'faceit',
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
    ]);
    unset($payload['time_control']);

    $response = $this->actingAs($user)->postJson('/listings', $payload);

    $listing = Listing::query()->where('user_id', $user->id)->firstOrFail();
    expect($listing->team_size)->toBe(5);

    $response->assertRedirect(route('listings.show', $listing));
});

test('time_control must be a non-empty array of valid enum values', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['time_control' => []]))
        ->assertJsonValidationErrors('time_control');

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['time_control' => ['bogus']]))
        ->assertJsonValidationErrors('time_control.0');

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['time_control' => ['blitz', 'blitz']]))
        ->assertJsonValidationErrors('time_control.0');
});

test('stake_amount with more than 2 decimal places is rejected', function () {
    // The listings column is decimal(12, 2); allowing more decimals would
    // let `Wallet::hold` debit at scale 6 while the listing stores a rounded
    // 2-decimal value, drifting on cancel/release. The `decimal:0,2` rule
    // pins precision at the request boundary.
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['stake_amount' => 100.456]))
        ->assertJsonValidationErrors('stake_amount');
});

// ─── Mass-assignment safety (8.7) ─────────────────────────────────────────

test('attacker-supplied user_id, status, and expires_at in the request body have no effect', function () {
    $user = User::factory()->withLichess()->create();
    $victim = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $tamperedExpiry = now()->addYears(5)->toIso8601String();

    $this->actingAs($user)->postJson('/listings', validPayload([
        'user_id' => $victim->id,
        'status' => 'taken',
        'expires_at' => $tamperedExpiry,
        'duration_hours' => 24,
    ]));

    $listing = Listing::query()->firstOrFail();

    expect($listing->user_id)->toBe($user->id)
        ->and($listing->user_id)->not->toBe($victim->id)
        ->and($listing->status)->toBe(ListingStatus::Open);

    // expires_at is computed from duration_hours (≈ +24h), not the +5y tamper.
    expect($listing->expires_at->isBefore(now()->addDays(2)))->toBeTrue()
        ->and($listing->expires_at->isAfter(now()->addHours(23)))->toBeTrue();
});

// ─── Max-active-listings cap (M6 Phase 6.5) ───────────────────────────────

test('a user at the active-listings cap cannot create another listing', function () {
    // Defense-in-depth: frontend disables the Post button at cap, but a stale
    // tab could still submit. `StoreListingRequest::withValidator` counts the
    // user's Open listings and attaches an `active_listings_cap` error if at
    // or over the MAX_ACTIVE_LISTINGS constant.
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    Listing::factory()->open()->for($user)->count(2)->create();

    $response = $this->actingAs($user)->postJson('/listings', validPayload());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('active_listings_cap');

    // The new listing was NOT written — count stays at 2.
    expect(Listing::where('user_id', $user->id)->count())->toBe(2);
});

test('only Open listings count toward the cap (Taken / Expired / Cancelled are free)', function () {
    // If a user has settled / expired / cancelled listings in their history,
    // those should NOT block them from creating new ones. The cap is about
    // "listings currently holding capital + slot," not lifetime count.
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    Listing::factory()->open()->for($user)->create();
    Listing::factory()->taken()->for($user)->count(3)->create();
    Listing::factory()->expired()->for($user)->count(3)->create();
    Listing::factory()->cancelled()->for($user)->count(3)->create();

    // Only 1 Open → still room for 1 more (cap = 2).
    $this->actingAs($user)
        ->postJson('/listings', validPayload(['stake_amount' => 50]))
        ->assertRedirect(route('listings.mine'));

    expect(Listing::where('user_id', $user->id)
        ->where('status', ListingStatus::Open)
        ->count()
    )->toBe(2);
});

// ─── Linked-account gate (M8 Phase 5 create-gate) ──────────────────────────

test('unlinked user hitting the create form sees the link-CTA notice instead of the form', function () {
    // Server-side: the page still renders (route auth + verified middleware
    // pass), but the frontend swaps the form for a notice card based on
    // `auth.user.has_chess_link`. We assert the page renders + the user
    // truly lacks a verified provider.
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/listings/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/create')
        ->where('balance', '500.000000')
    );

    expect($user->fresh()->hasVerifiedChessLink())->toBeFalse();
});

test('unlinked user POSTing /listings is redirected to linked-accounts settings with info toast', function () {
    // Picks lichess as the platform (the validPayload default) — the gate
    // should reject before the action runs.
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload());

    $response->assertRedirect(route('linked-accounts.edit'));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Link a Lichess account before posting a Lichess listing.',
    ]);

    expect(Listing::count())->toBe(0)
        ->and(WalletTransaction::where('type', WalletTransactionType::EscrowHold)->count())->toBe(0);
});

test('lichess-linked user trying to post a chess.com listing is gate-blocked (platform-specific)', function () {
    // Cross-platform check: having one provider doesn't unlock the other.
    // The user must verify the platform they're posting for.
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload([
        'platform' => 'chess_com',
    ]));

    $response->assertRedirect(route('linked-accounts.edit'));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Link a chess.com account before posting a chess.com listing.',
    ]);

    expect(Listing::count())->toBe(0);
});

test('chess.com-linked user CAN create a chess.com listing', function () {
    $user = User::factory()->withChessCom()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['platform' => 'chess_com']))
        ->assertRedirect(route('listings.mine'));

    $listing = Listing::query()->where('user_id', $user->id)->firstOrFail();
    expect($listing->platform->value)->toBe('chess_com');
});

// ─── Toast flash (light sanity check) ─────────────────────────────────────

test('successful store flashes a success toast', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload())
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Listing created.',
        ]);
});

// ─── Per-game gating (M15 Phase 3) ────────────────────────────────────────

test('CS2 listing creation succeeds when the user has FACEIT linked', function () {
    platformUser();
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload([
        'game' => 'cs2',
        'platform' => 'faceit',
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
    ]));

    $listing = Listing::query()->where('user_id', $user->id)->firstOrFail();
    $response->assertRedirect(route('listings.show', $listing));

    expect($listing->game)->toBe(Game::Cs2)
        ->and($listing->platform)->toBe(LinkedAccountProvider::Faceit)
        ->and($listing->team_size)->toBe(5);
});

test('CS2 listing creation is blocked when the user has no FACEIT link', function () {
    platformUser();
    // game + platform pair is valid (cs2 + faceit), so cross-validation
    // passes — but the team-play create action's isVerifiedOn(Faceit) check
    // fires the 'not_linked' sentinel because the user only has chess linked.
    $user = User::factory()->active()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->postJson('/listings', validPayload([
        'game' => 'cs2',
        'platform' => 'faceit',
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
    ]));

    $response->assertRedirect(route('linked-accounts.edit'));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Link a FACEIT account before posting a FACEIT listing.',
    ]);

    expect(Listing::count())->toBe(0);
});

test('CS2 listing with a chess platform is rejected by the cross-game validation', function () {
    // Stale-tab / crafted-request case. User has BOTH providers linked so
    // the rejection can ONLY be the platform-doesn't-fit-game check, not a
    // missing-link gate.
    $user = User::factory()->withFaceit()->withChessCom()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload([
            'game' => 'cs2',
            'platform' => 'chess_com',
        ]))
        ->assertJsonValidationErrors('platform');

    expect(Listing::count())->toBe(0);
});

test('chess listing with a FACEIT platform is rejected by the cross-game validation', function () {
    $user = User::factory()->withFaceit()->withChessCom()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload([
            'game' => 'chess',
            'platform' => 'faceit',
        ]))
        ->assertJsonValidationErrors('platform');

    expect(Listing::count())->toBe(0);
});

// ─── Per-game requirements props (M15 Phase 3 Slice 3) ────────────────────
// Locks the `requirementsByGame` prop the frontend reads to pick the default
// game in `defaultGameFor()` (resources/js/pages/listings/create.tsx). The
// helper itself is small/pure TS; correctness flows from the backend props.

test('FACEIT-only user lands on the create form with CS2 verified and Chess unverified', function () {
    $user = User::factory()->withFaceit()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->get('/listings/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('listings/create')
            ->where('requirementsByGame.cs2.verified', true)
            ->where('requirementsByGame.chess.verified', false)
            ->where('linkedPlatforms', ['faceit'])
        );
});

test('chess-only user lands on the create form with Chess verified and CS2 unverified', function () {
    $user = User::factory()->withLichess()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->get('/listings/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('listings/create')
            ->where('requirementsByGame.chess.verified', true)
            ->where('requirementsByGame.cs2.verified', false)
            ->where('linkedPlatforms', ['lichess'])
        );
});

test('unlinked user lands on the create form with neither game verified', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->get('/listings/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('listings/create')
            ->where('requirementsByGame.chess.verified', false)
            ->where('requirementsByGame.cs2.verified', false)
            ->where('linkedPlatforms', [])
        );
});
