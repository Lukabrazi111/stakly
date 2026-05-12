<?php

namespace Database\Factories;

use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 *
 * Produces raw ledger rows for low-level model tests. The factory does NOT
 * update `users.usdt_balance` or maintain the balance ↔ ledger invariant —
 * use `App\Services\Wallet` methods in tests that need consistent state.
 */
class WalletTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => WalletTransactionType::Deposit,
            'amount' => '100.000000',
            'balance_after' => '100.000000',
            'related_listing_id' => null,
            'reference_id' => null,
            'description' => null,
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::Deposit,
            'amount' => '100.000000',
        ]);
    }

    public function withdrawal(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::Withdrawal,
            'amount' => '-50.000000',
        ]);
    }

    public function escrowHold(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::EscrowHold,
            'amount' => '-25.000000',
            'related_listing_id' => Listing::factory(),
        ]);
    }

    public function escrowRelease(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::EscrowRelease,
            'amount' => '25.000000',
            'related_listing_id' => Listing::factory(),
        ]);
    }

    public function payout(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::Payout,
            'amount' => '45.000000',
            'related_listing_id' => Listing::factory(),
        ]);
    }

    public function fee(): static
    {
        return $this->state(fn () => [
            'type' => WalletTransactionType::Fee,
            'amount' => '5.000000',
            'related_listing_id' => Listing::factory(),
        ]);
    }
}
