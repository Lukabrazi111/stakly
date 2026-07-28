<?php

use App\Enums\WithdrawalStatus;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\User;
use App\Models\UserModerationLog;
use App\Models\Withdrawal;
use App\Services\Wallet;
use App\Services\Withdrawals;
use App\Support\WalletReferenceParser;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M9 Phase 0b admin surfaces: the withdrawal queue's Reject action and the
 * user Freeze toggle. There is deliberately NO approve action — the hold
 * lives on the payout's clearing window, not on the withdrawal.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->platform = platformUser();
    actingAs($this->admin);
});

function pendingWithdrawalFor(string $balance = '500', string $amount = '100'): Withdrawal
{
    Queue::fake();

    $user = User::factory()->create();
    Wallet::deposit($user, $balance, reference: "test:deposit:{$user->id}");

    return Withdrawals::request($user->fresh(), $amount, 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE');
}

// ─── Reject action ─────────────────────────────────────────────────────────

test('reject action credits the player back and records the reason', function () {
    $withdrawal = pendingWithdrawalFor();
    $user = $withdrawal->user;

    expect(Wallet::balanceFor($user))->toBe('400.000000');

    Livewire::test(ListWithdrawals::class)
        ->callTableAction('reject', $withdrawal, ['reason' => 'Suspected collusion'])
        ->assertHasNoTableActionErrors();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe(WithdrawalStatus::Rejected);
    expect($withdrawal->rejected_reason)->toBe('Suspected collusion');
    expect($withdrawal->reviewed_by)->toBe($this->admin->id);
    expect(Wallet::balanceFor($user))->toBe('500.000000');
});

test('reject action requires a reason', function () {
    $withdrawal = pendingWithdrawalFor();

    Livewire::test(ListWithdrawals::class)
        ->callTableAction('reject', $withdrawal, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($withdrawal->refresh()->status)->toBe(WithdrawalStatus::Pending);
});

test('reject action is hidden once the withdrawal is terminal', function () {
    $withdrawal = pendingWithdrawalFor();
    Withdrawals::send($withdrawal);

    Livewire::test(ListWithdrawals::class)
        ->assertTableActionHidden('reject', $withdrawal->fresh());
});

test('no approve action exists — the clearing window is the only gate', function () {
    $withdrawal = pendingWithdrawalFor();

    Livewire::test(ListWithdrawals::class)
        ->assertTableActionDoesNotExist('approve', record: $withdrawal);
});

// ─── Freeze toggle ─────────────────────────────────────────────────────────

test('freeze action blocks the user and writes an audit row', function () {
    $target = User::factory()->create();

    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('toggle_freeze', ['reason' => 'Awaiting chess.com fair-play review'])
        ->assertHasNoActionErrors();

    $target->refresh();
    expect($target->isFrozen())->toBeTrue();
    expect($target->frozen_reason)->toBe('Awaiting chess.com fair-play review');

    $log = UserModerationLog::where('user_id', $target->id)->sole();
    expect($log->action)->toBe(UserModerationLog::ACTION_FREEZE);
    expect($log->admin_user_id)->toBe($this->admin->id);
});

test('unfreeze action clears the block and its reason', function () {
    $target = User::factory()->create(['frozen_at' => now(), 'frozen_reason' => 'Under review']);

    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('toggle_freeze', ['reason' => 'Cleared on appeal'])
        ->assertHasNoActionErrors();

    $target->refresh();
    expect($target->isFrozen())->toBeFalse();
    expect($target->frozen_reason)->toBeNull();

    expect(UserModerationLog::where('user_id', $target->id)->sole()->action)
        ->toBe(UserModerationLog::ACTION_UNFREEZE);
});

test('freezing does not ban, and banning does not freeze', function () {
    $target = User::factory()->create();

    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('toggle_freeze', ['reason' => 'Under review']);

    $target->refresh();
    expect($target->isFrozen())->toBeTrue();
    expect($target->isBanned())->toBeFalse();
});

// ─── Ledger deep-links ─────────────────────────────────────────────────────

test('withdrawal ledger references resolve to a labelled admin link', function () {
    foreach (['wd', 'wd-reversal', 'wd-margin'] as $prefix) {
        $parsed = WalletReferenceParser::parse("{$prefix}:42");

        expect($parsed)->not->toBeNull();
        expect($parsed['label'])->toBe('Withdrawal #42');
        expect($parsed['url'])->toContain('withdrawals');
    }
});
