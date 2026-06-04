<?php

use App\Enums\WalletTransactionType;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\Pages\ViewWalletTransaction;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\User;
use App\Models\WalletTransaction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M31 Phase 1 — read-only wallet ledger Filament resource. Covers access
 * gates, read-only posture (no create / edit / delete actions), listing
 * behavior, and the four filters (type / user / date range / amount range)
 * plus the reference-contains text filter.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// ─── Access gates ──────────────────────────────────────────────────────────

test('non-admin cannot reach the wallet ledger', function () {
    actingAs(User::factory()->create())
        ->get('/admin/wallet-transactions')
        ->assertForbidden();
});

test('guest is redirected to admin login', function () {
    auth()->logout();

    $this->get('/admin/wallet-transactions')
        ->assertRedirect('/admin/login');
});

test('admin can list transactions', function () {
    $user = User::factory()->create();
    $tx1 = WalletTransaction::factory()->deposit()->for($user)->create();
    $tx2 = WalletTransaction::factory()->withdrawal()->for($user)->create();

    Livewire::test(ListWalletTransactions::class)
        ->assertCanSeeTableRecords([$tx1, $tx2]);
});

// ─── Read-only posture ─────────────────────────────────────────────────────

test('resource disables create / edit / delete', function () {
    $resource = WalletTransactionResource::class;
    $row = WalletTransaction::factory()->deposit()->create();

    expect($resource::canCreate())->toBeFalse();
    expect($resource::canEdit($row))->toBeFalse();
    expect($resource::canDelete($row))->toBeFalse();
});

// ─── Filters ───────────────────────────────────────────────────────────────

test('type filter narrows to the selected types', function () {
    $user = User::factory()->create();
    $deposit = WalletTransaction::factory()->deposit()->for($user)->create();
    $fee = WalletTransaction::factory()->fee()->for($user)->create();

    Livewire::test(ListWalletTransactions::class)
        ->filterTable('type', [WalletTransactionType::Deposit->value])
        ->assertCanSeeTableRecords([$deposit])
        ->assertCanNotSeeTableRecords([$fee]);
});

test('user filter narrows to the selected user', function () {
    $alice = User::factory()->create(['username' => 'alice-ledger']);
    $bob = User::factory()->create(['username' => 'bob-ledger']);
    $aliceTx = WalletTransaction::factory()->deposit()->for($alice)->create();
    $bobTx = WalletTransaction::factory()->deposit()->for($bob)->create();

    Livewire::test(ListWalletTransactions::class)
        ->filterTable('user_id', $alice->id)
        ->assertCanSeeTableRecords([$aliceTx])
        ->assertCanNotSeeTableRecords([$bobTx]);
});

test('date range filter narrows by created_at', function () {
    $user = User::factory()->create();
    $old = WalletTransaction::factory()->deposit()->for($user)->create([
        'created_at' => now()->subDays(10),
    ]);
    $recent = WalletTransaction::factory()->deposit()->for($user)->create([
        'created_at' => now()->subDay(),
    ]);

    Livewire::test(ListWalletTransactions::class)
        ->filterTable('created_at', [
            'from' => now()->subDays(3)->toDateString(),
        ])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});

test('amount range filter narrows by amount', function () {
    $user = User::factory()->create();
    $small = WalletTransaction::factory()->for($user)->create(['amount' => '10.000000']);
    $big = WalletTransaction::factory()->for($user)->create(['amount' => '500.000000']);

    Livewire::test(ListWalletTransactions::class)
        ->filterTable('amount', ['min' => 100])
        ->assertCanSeeTableRecords([$big])
        ->assertCanNotSeeTableRecords([$small]);
});

test('reference contains filter narrows by substring', function () {
    $user = User::factory()->create();
    $matching = WalletTransaction::factory()->for($user)->create([
        'reference_id' => 'listing-create:42',
    ]);
    $other = WalletTransaction::factory()->for($user)->create([
        'reference_id' => 'match-payout:7',
    ]);

    Livewire::test(ListWalletTransactions::class)
        ->filterTable('reference_id', ['contains' => 'listing-create'])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Sort ──────────────────────────────────────────────────────────────────

test('default sort is newest first', function () {
    $user = User::factory()->create();
    $old = WalletTransaction::factory()->for($user)->create(['created_at' => now()->subDays(5)]);
    $new = WalletTransaction::factory()->for($user)->create(['created_at' => now()]);

    Livewire::test(ListWalletTransactions::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true);
});

// ─── View page (Phase 2) ──────────────────────────────────────────────────

test('admin can view a transaction', function () {
    $tx = WalletTransaction::factory()->deposit()->create();

    Livewire::test(ViewWalletTransaction::class, [
        'record' => $tx->getRouteKey(),
    ])->assertOk();
});

test('view page renders type and amount', function () {
    $tx = WalletTransaction::factory()->payout()->create([
        'amount' => '180.000000',
        'balance_after' => '280.000000',
    ]);

    Livewire::test(ViewWalletTransaction::class, [
        'record' => $tx->getRouteKey(),
    ])
        ->assertSeeText('Payout')
        ->assertSeeText('$180.00')
        ->assertSeeText('$280.00');
});

test('view page renders contextual link for a known reference prefix', function () {
    $user = User::factory()->create();
    $tx = WalletTransaction::factory()->fee()->for($user)->create([
        'reference_id' => 'match-fee:7',
    ]);

    Livewire::test(ViewWalletTransaction::class, [
        'record' => $tx->getRouteKey(),
    ])
        ->assertSeeText('match-fee:7')
        ->assertSeeText('Match #7');
});

test('sibling section lists other rows referencing the same entity', function () {
    $user = User::factory()->create();
    // Two rows with DIFFERENT prefixes pointing at the same match #99 —
    // the realistic "settle" pattern. reference_id is unique per row;
    // siblings share the entity, not the prefix.
    $payout = WalletTransaction::factory()->payout()->for($user)->create(['reference_id' => 'match-payout:99']);
    $fee = WalletTransaction::factory()->fee()->for($user)->create(['reference_id' => 'match-fee:99']);
    $unrelated = WalletTransaction::factory()->deposit()->for($user)->create(['reference_id' => 'match-payout:1000']);

    Livewire::test(ViewWalletTransaction::class, [
        'record' => $payout->getRouteKey(),
    ])
        ->assertSeeHtml("#{$fee->id}")
        ->assertDontSeeHtml("#{$unrelated->id}");
});

test('sibling section is hidden when reference is unparseable', function () {
    $tx = WalletTransaction::factory()->deposit()->create([
        'reference_id' => 'unknown-prefix:1',
    ]);

    Livewire::test(ViewWalletTransaction::class, [
        'record' => $tx->getRouteKey(),
    ])
        ->assertDontSeeText('Sibling transactions');
});

// ─── Model formatter (used by table + infolist) ────────────────────────────

test('formatAmount handles positive, negative, and BCMath precision', function () {
    expect(WalletTransaction::formatAmount('100.000000'))->toBe('$100.00');
    expect(WalletTransaction::formatAmount('-25.500000'))->toBe('-$25.50');
    expect(WalletTransaction::formatAmount('0.000000'))->toBe('$0.00');
    expect(WalletTransaction::formatAmount('1234567.890000'))->toBe('$1,234,567.89');
    expect(WalletTransaction::formatAmount(''))->toBe('$0.00');
});
