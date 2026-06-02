<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchAdminResolutionAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\Pages\ListGameMatches;
use App\Filament\Resources\GameMatches\Pages\ViewGameMatch;
use App\Models\MatchAdminResolution;
use App\Models\MatchAutoFetchAttempt;
use App\Models\User;
use App\Models\WalletTransaction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M12 Phase 2 — Filament `GameMatchResource` end-to-end. Covers:
 *   - List page: default filter shows Disputed + ManualReview, hides other
 *     statuses; admin can lift the filter to see all.
 *   - View page: renders for a Disputed match; resolve action buttons are
 *     visible on Disputed / ManualReview, hidden on Settled / Cancelled.
 *   - Each of the three resolve actions, end-to-end: required-reason form
 *     validation, audit row written, match status flipped, money moved.
 *
 * Uses `pendingMatch()` helper to spin up real matches with the wallet
 * holds in place so the underlying Settle actions can fire cleanly.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// ─── List page ─────────────────────────────────────────────────────────────

test('list page shows Disputed and ManualReview matches by default', function () {
    [, , , $disputed] = pendingMatch();
    $disputed->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    [, , , $manualReview] = pendingMatch();
    $manualReview->update(['status' => MatchStatus::ManualReview]);

    [, , , $pending] = pendingMatch(); // status defaults to Pending

    Livewire::test(ListGameMatches::class)
        ->assertCanSeeTableRecords([$disputed, $manualReview])
        ->assertCanNotSeeTableRecords([$pending]);
});

test('list page can show all statuses when filter is cleared', function () {
    [, , , $disputed] = pendingMatch();
    $disputed->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    [, , , $settled] = pendingMatch();
    $settled->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    Livewire::test(ListGameMatches::class)
        ->filterTable('status', [])
        ->assertCanSeeTableRecords([$disputed, $settled]);
});

test('list page sorts matches newest-first by created_at (latest disputes at top)', function () {
    [, , , $oldest] = pendingMatch();
    $oldest->forceFill(['created_at' => now()->subDays(2)])->save();
    $oldest->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(10)]);

    [, , , $middle] = pendingMatch();
    $middle->forceFill(['created_at' => now()->subDay()])->save();
    $middle->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(5)]);

    [, , , $newest] = pendingMatch();
    $newest->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHour()]);

    Livewire::test(ListGameMatches::class)
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

// ─── View page render ──────────────────────────────────────────────────────

test('view page renders for a Disputed match', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertSuccessful();
});

// ─── M14 P1 — Auto-fetch history section ────────────────────────────────────

test('auto-fetch history section appears when attempts exist', function () {
    [, , , $match] = pendingMatch();
    MatchAutoFetchAttempt::factory()->matched('alice-lichess')->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Lichess,
        'latency_ms' => 142,
    ]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertSuccessful()
        ->assertSee('Auto-fetch history')
        ->assertSee('matched', escape: false)
        ->assertSee('alice-lichess')
        ->assertSee('142ms');
});

test('auto-fetch history section is hidden when no attempts exist', function () {
    [, , , $match] = pendingMatch();

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertSuccessful()
        ->assertDontSee('Auto-fetch history');
});

test('auto-fetch history surfaces skipped reasons + error messages', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->skipped('not_pending')->create(['match_id' => $match->id]);
    MatchAutoFetchAttempt::factory()->error('Lichess returned 503.')->create(['match_id' => $match->id]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertSuccessful()
        ->assertSee('not_pending')
        ->assertSee('Lichess returned 503.');
});

test('resolve actions are visible for Disputed match', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertActionVisible('settle_to_creator')
        ->assertActionVisible('settle_to_taker')
        ->assertActionVisible('settle_draw');
});

test('resolve actions are visible for ManualReview match', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::ManualReview]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertActionVisible('settle_to_creator')
        ->assertActionVisible('settle_to_taker')
        ->assertActionVisible('settle_draw');
});

test('resolve actions are hidden for Settled match', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertActionHidden('settle_to_creator')
        ->assertActionHidden('settle_to_taker')
        ->assertActionHidden('settle_draw');
});

test('resolve actions are hidden for Cancelled match', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Cancelled, 'cancelled_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertActionHidden('settle_to_creator')
        ->assertActionHidden('settle_to_taker')
        ->assertActionHidden('settle_draw');
});

// ─── Resolve action end-to-end ─────────────────────────────────────────────

test('settle_to_creator action settles + writes audit row', function () {
    [$creator, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->callAction('settle_to_creator', data: [
            'reason' => 'Lichess card confirms creator won; taker provided no counter-evidence.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($match->fresh()->status)->toBe(MatchStatus::Settled)
        ->and($match->fresh()->winner_user_id)->toBe($creator->id);

    $resolution = MatchAdminResolution::where('match_id', $match->id)->first();
    expect($resolution)->not->toBeNull()
        ->and($resolution->action)->toBe(MatchAdminResolutionAction::SettleToCreator)
        ->and($resolution->admin_user_id)->toBe($this->admin->id)
        ->and($resolution->winner_user_id)->toBe($creator->id);

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeTrue();
});

test('settle_to_taker action settles + writes audit row', function () {
    [, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::ManualReview]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->callAction('settle_to_taker', data: [
            'reason' => 'chess.com card confirms taker won.',
        ])
        ->assertHasNoActionErrors();

    expect($match->fresh()->status)->toBe(MatchStatus::Settled)
        ->and($match->fresh()->winner_user_id)->toBe($taker->id);

    expect(MatchAdminResolution::where('match_id', $match->id)->first()->action)
        ->toBe(MatchAdminResolutionAction::SettleToTaker);
});

test('settle_draw action refunds both + writes audit row with null winner', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->callAction('settle_draw', data: [
            'reason' => 'Both screenshots inconclusive.',
        ])
        ->assertHasNoActionErrors();

    expect($match->fresh()->status)->toBe(MatchStatus::Settled)
        ->and($match->fresh()->winner_user_id)->toBeNull();

    $resolution = MatchAdminResolution::where('match_id', $match->id)->first();
    expect($resolution->action)->toBe(MatchAdminResolutionAction::SettleDraw)
        ->and($resolution->winner_user_id)->toBeNull();

    expect(WalletTransaction::query()->where('reference_id', "match-draw-creator:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-draw-taker:{$match->id}")->exists())->toBeTrue();
});

// ─── Form validation ───────────────────────────────────────────────────────

test('settle_to_creator requires a reason', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->callAction('settle_to_creator', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    // Status unchanged + no audit row written.
    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);
    expect(MatchAdminResolution::where('match_id', $match->id)->exists())->toBeFalse();
});

test('settle_draw requires a reason', function () {
    [, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->callAction('settle_draw', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);
});
