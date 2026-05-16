<?php

use App\Enums\ListingStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

function validPayload(array $overrides = []): array
{
    return array_merge([
        'game' => 'chess',
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
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/listings/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/create')
        ->where('balance', '500.000000')
        ->has('regions')
        ->has('languages')
        ->has('durations')
    );
});

// ─── Store happy path (8.4) ───────────────────────────────────────────────

test('store creates the listing AND writes the escrow hold ledger row', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['stake_amount', 'time_control', 'duration_hours', 'game']);
});

test('time_control must be a non-empty array of valid enum values', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload(['stake_amount' => 100.456]))
        ->assertJsonValidationErrors('stake_amount');
});

// ─── Mass-assignment safety (8.7) ─────────────────────────────────────────

test('attacker-supplied user_id, status, and expires_at in the request body have no effect', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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

// ─── Toast flash (light sanity check) ─────────────────────────────────────

test('successful store flashes a success toast', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/listings', validPayload())
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Listing created.',
        ]);
});
