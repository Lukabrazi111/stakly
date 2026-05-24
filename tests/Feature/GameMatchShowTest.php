<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// ─── Authorization (participant-only, 404 for outsiders) ────────────────────

test('listing creator can view the match', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;

    $this->actingAs($creator)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('match/show')
            ->where('match.id', $match->id)
        );
});

test('match taker can view the match', function () {
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('match/show'));
});

test('non-participant gets 404 (not 403 — never leak match existence)', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('matches.show', $match))
        ->assertNotFound();
});

test('guest is redirected to login', function () {
    $match = GameMatch::factory()->create();

    $this->get(route('matches.show', $match))
        ->assertRedirect(route('login'));
});

test('unverified user is redirected to verification notice', function () {
    $match = GameMatch::factory()->create();
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get(route('matches.show', $match))
        ->assertRedirect(route('verification.notice'));
});

// ─── Resource shape (PII safety) ────────────────────────────────────────────

test('the match resource exposes the participant + listing summary, never PII', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;

    $this->actingAs($creator)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->component('match/show')
            ->where('match.id', $match->id)
            ->where('match.status', 'pending')
            ->has('match.creator', fn ($p) => $p
                ->where('id', $creator->id)
                ->where('name', $creator->name)
                ->where('username', $creator->username)
                ->etc()
                // PII assertions: these MUST NOT appear in the resource.
                ->missing('email')
                ->missing('usdt_balance')
                ->missing('tron_address')
            )
            ->has('match.taker', fn ($p) => $p
                ->etc()
                ->missing('email')
                ->missing('usdt_balance')
                ->missing('tron_address')
            )
            ->has('match.listing', fn ($p) => $p
                ->where('id', $match->listing->id)
                ->where('game', 'chess')
                ->etc()
            )
        );
});

test('a freshly-created match has null winner and null settled_at', function () {
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.winner', null)
            ->where('match.settled_at', null)
        );
});

// ─── M16 snapshots shape ────────────────────────────────────────────────────

test('match resource exposes snapshotted usernames scoped to listing.platform', function () {
    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();
    $listing = Listing::factory()->forLichess()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    // Snapshot rows are normally inserted by TakeListingAction; create them
    // directly so we test the resource shape in isolation.
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'alice-lichess',
    ]);
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'bob-lichess',
    ]);

    $this->actingAs($taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.snapshots.creator_username', 'alice-lichess')
            ->where('match.snapshots.taker_username', 'bob-lichess')
        );
});

test('snapshots shape returns nulls when the relevant platform snapshot is missing', function () {
    // chess.com listing but only Lichess snapshots present — the resource
    // scopes to listing.platform so both sides come back null.
    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();
    $listing = Listing::factory()->forChessCom()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'alice-lichess',
    ]);

    $this->actingAs($taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.snapshots.creator_username', null)
            ->where('match.snapshots.taker_username', null)
        );
});

// ─── Cancellation shape on GameMatchResource ────────────────────────────────

test('fresh match emits a cancellation block with all-null fields', function () {
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->has('match.cancellation')
            ->where('match.cancellation.requested_by_id', null)
            ->where('match.cancellation.requested_at', null)
            ->where('match.cancellation.reason', null)
            ->where('match.cancellation.rejected_at', null)
            ->where('match.cancellation.cancelled_at', null)
        );
});

test('open cancellation request surfaces requester id + reason on the resource', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
        'cancellation_reason' => 'Opponent went AFK',
    ]);

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.cancellation.requested_by_id', $creator->id)
            ->where('match.cancellation.reason', 'Opponent went AFK')
            ->whereNot('match.cancellation.requested_at', null)
        );
});

test('Cancelled match emits cancelled_at on the resource', function () {
    $match = GameMatch::factory()->cancelled()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.status', 'cancelled')
            ->whereNot('match.cancellation.cancelled_at', null)
        );
});

// ─── M16 Phase 2 — page-visit auto-fetch trigger ────────────────────────────

test('visiting a Pending Lichess match dispatches AutoFetchLichessGameJob', function () {
    Queue::fake();

    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();
    $listing = Listing::factory()->forLichess()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    foreach ([GameMatch::SIDE_CREATOR => 'alice-lichess', GameMatch::SIDE_TAKER => 'bob-lichess'] as $side => $u) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'side' => $side,
            'provider' => LinkedAccountProvider::Lichess,
            'username' => $u,
        ]);
    }

    $this->actingAs($taker)
        ->get(route('matches.show', $match))
        ->assertOk();

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

test('visiting a Pending chess.com match dispatches AutoFetchChessComGameJob', function () {
    Queue::fake();

    $creator = User::factory()->active()->withChessCom('alice-cc')->create();
    $taker = User::factory()->withChessCom('bob-cc')->create();
    $listing = Listing::factory()->forChessCom()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    foreach ([GameMatch::SIDE_CREATOR => 'alice-cc', GameMatch::SIDE_TAKER => 'bob-cc'] as $side => $u) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'side' => $side,
            'provider' => LinkedAccountProvider::ChessCom,
            'username' => $u,
        ]);
    }

    $this->actingAs($taker)
        ->get(route('matches.show', $match))
        ->assertOk();

    Queue::assertPushed(
        AutoFetchChessComGameJob::class,
        fn (AutoFetchChessComGameJob $job) => $job->match->id === $match->id,
    );
});

test('visiting a Settled match does NOT dispatch the auto-fetch job', function () {
    Queue::fake();

    $match = GameMatch::factory()->create();
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertOk();

    Queue::assertNothingPushed();
});

test('visiting a Pending match without snapshots does NOT dispatch', function () {
    Queue::fake();

    // Default factory match — no provider snapshots.
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertOk();

    Queue::assertNothingPushed();
});
