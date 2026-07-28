<?php

namespace Database\Factories;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 *
 * Produces raw withdrawal rows for model / read-path tests. The factory does
 * NOT debit the user or write ledger entries — use `App\Services\Withdrawals`
 * in any test that needs the balance and the ledger to agree.
 */
class WithdrawalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => '50.000000',
            'platform_fee' => '0.500000',
            'network_fee' => null,
            'destination_address' => 'T'.$this->faker->regexify('[1-9A-HJ-NP-Za-km-z]{33}'),
            'status' => WithdrawalStatus::Pending,
            'debit_transaction_id' => null,
            'provider' => null,
            'provider_payout_id' => null,
            'tx_hash' => null,
            'rejected_reason' => null,
            'reviewed_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => WithdrawalStatus::Pending]);
    }

    public function sending(): static
    {
        return $this->state(fn () => [
            'status' => WithdrawalStatus::Sending,
            'provider' => 'mock',
            'provider_payout_id' => 'mock-payout:'.$this->faker->unique()->numerify('wd-####'),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => WithdrawalStatus::Completed,
            'provider' => 'mock',
            'provider_payout_id' => 'mock-payout:'.$this->faker->unique()->numerify('wd-####'),
            'tx_hash' => 'MOCK-'.strtoupper($this->faker->regexify('[A-F0-9]{40}')),
            'network_fee' => '1.500000',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => WithdrawalStatus::Rejected,
            'rejected_reason' => 'Suspected collusion',
            'reviewed_by' => User::factory(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WithdrawalStatus::Failed,
            'provider' => 'mock',
            'rejected_reason' => 'Provider rejected the payout',
        ]);
    }
}
