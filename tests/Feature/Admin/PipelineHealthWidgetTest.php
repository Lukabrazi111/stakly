<?php

use App\Enums\LinkedAccountProvider;
use App\Filament\Widgets\PipelineHealth;
use App\Models\MatchAutoFetchAttempt;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M14 Phase 1 — `PipelineHealth` dashboard widget. Four stats covering
 * auto-fetch settlement health: matched count, error count, avg latency,
 * total volume. Per-stat assertion clusters below — each one stages the
 * minimum data needed to exercise the underlying query.
 */
beforeEach(function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);
});

// ─── Settlements stat ──────────────────────────────────────────────────────

test('settlements stat counts only matched rows from today', function () {
    [, , , $match] = pendingMatch();

    // Today: 2 matched, 1 no_match (should be excluded)
    MatchAutoFetchAttempt::factory()->count(2)->matched()->create(['match_id' => $match->id]);
    MatchAutoFetchAttempt::factory()->create(['match_id' => $match->id]);

    // Yesterday: 1 matched (should be excluded)
    MatchAutoFetchAttempt::factory()->matched()->create([
        'match_id' => $match->id,
        'created_at' => now()->subDay(),
    ]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Auto-settlements (24h)')
        ->assertSeeText('2');
});

test('settlements stat shows zero copy when none today', function () {
    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('No auto-settlements today');
});

// ─── Errors stat ───────────────────────────────────────────────────────────

test('errors stat success-colors when zero', function () {
    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('No provider failures today');
});

test('errors stat warning-colors when between 1 and threshold', function () {
    [, , , $match] = pendingMatch();
    MatchAutoFetchAttempt::factory()->count(3)->error()->create(['match_id' => $match->id]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Pipeline errors (24h)')
        ->assertSeeText('3 provider failures today');
});

test('errors stat danger-colors at or above threshold', function () {
    [, , , $match] = pendingMatch();
    MatchAutoFetchAttempt::factory()->count(12)->error()->create(['match_id' => $match->id]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('12 provider failures — investigate');
});

// ─── Avg latency stat ──────────────────────────────────────────────────────

test('avg latency averages provider-call rows only and excludes skipped', function () {
    [, , , $match] = pendingMatch();

    // Provider-call rows in 7d window: avg of 100, 200, 300 = 200ms
    MatchAutoFetchAttempt::factory()->matched()->create(['match_id' => $match->id, 'latency_ms' => 100]);
    MatchAutoFetchAttempt::factory()->create(['match_id' => $match->id, 'latency_ms' => 200]);
    MatchAutoFetchAttempt::factory()->error()->create(['match_id' => $match->id, 'latency_ms' => 300]);

    // Skipped row with no latency (should be excluded from avg by both
    // outcome filter AND `whereNotNull('latency_ms')`).
    MatchAutoFetchAttempt::factory()->skipped('already_posted')->create(['match_id' => $match->id]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Avg provider latency (7d)')
        ->assertSeeText('200ms');
});

test('avg latency shows dash when no provider calls in window', function () {
    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('No provider calls in 7d window');
});

// ─── Volume stat ───────────────────────────────────────────────────────────

test('volume stat shows total count + outcome breakdown in description', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->count(2)->matched()->create(['match_id' => $match->id]);
    MatchAutoFetchAttempt::factory()->count(5)->create(['match_id' => $match->id]); // no_match default
    MatchAutoFetchAttempt::factory()->error()->create(['match_id' => $match->id]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Pipeline attempts (24h)')
        ->assertSeeText('8')
        ->assertSeeText('2 matched')
        ->assertSeeText('1 error')
        ->assertSeeText('5 no_match');
});

test('volume stat shows zero copy when no attempts today', function () {
    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('No attempts today');
});

// ─── Cross-provider sanity ─────────────────────────────────────────────────

test('chess.com and Lichess rows both count toward the same stats', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->matched()->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Lichess,
    ]);
    MatchAutoFetchAttempt::factory()->matched()->chessCom()->create([
        'match_id' => $match->id,
    ]);

    // Settlements aggregate stays as the headline number; the per-provider
    // rollup is appended in the description (asserted in the breakdown tests
    // below). M14 P1 was provider-agnostic at the widget level; M15 P5
    // Item 2 surfaced the per-provider split alongside.
    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Auto-settlements (24h)')
        ->assertSeeText('2');
});

// ─── M15 P5 Item 2 — per-provider breakdown (settlements + errors) ─────────

test('settlements description includes per-provider breakdown (FACEIT alongside chess)', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->matched()->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::ChessCom,
    ]);
    MatchAutoFetchAttempt::factory()->matched()->count(2)->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Lichess,
    ]);
    MatchAutoFetchAttempt::factory()->matched()->count(3)->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Faceit,
    ]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Auto-settlements (24h)')
        ->assertSeeText('6')
        ->assertSeeText('chess_com 1 / lichess 2 / faceit 3');
});

test('errors description includes per-provider breakdown', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->error()->count(2)->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Lichess,
    ]);
    MatchAutoFetchAttempt::factory()->error()->count(4)->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Faceit,
    ]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('Pipeline errors (24h)')
        ->assertSeeText('6 provider failures today · lichess 2 / faceit 4');
});

test('FACEIT-only failures surface even when chess providers are quiet', function () {
    // The interesting case for M15 launch — early FACEIT traffic shouldn't
    // get hidden in an aggregate that's dominated by chess volume.
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->error()->count(3)->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Faceit,
    ]);

    Livewire::test(PipelineHealth::class)
        ->assertSuccessful()
        ->assertSeeText('3 provider failures today · faceit 3');
});
