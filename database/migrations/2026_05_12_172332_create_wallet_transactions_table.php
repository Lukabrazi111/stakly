<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only ledger of every money state change. Source of truth for
     * `users.usdt_balance` — the invariant `usdt_balance == SUM(amount)`
     * must hold for every user at all times (asserted in tests).
     *
     * Mutated only by `App\Services\Wallet` methods. Direct writes from
     * controllers, seeders, or tinker violate the M3.5 contract.
     *
     * No `updated_at`. No soft deletes. Rows are immutable INSERTs.
     *
     * TODO (post-launch): `user_id` cascade-delete loses ledger history if a
     * user is removed. Switch to restrict / soft-delete the user once real
     * money flows through this table.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // App\Enums\WalletTransactionType cast on the model.
            $table->string('type');

            // Signed. Credits (Deposit, EscrowRelease, Payout, Fee) are positive;
            // debits (Withdrawal, EscrowHold) are negative. 18,6 matches Tron's
            // USDT precision.
            $table->decimal('amount', 18, 6);

            // Snapshot of `users.usdt_balance` immediately after this row was
            // written. Lets us audit historical state without replaying the
            // full ledger.
            $table->decimal('balance_after', 18, 6);

            // Optional listing tied to this entry (EscrowHold / EscrowRelease /
            // Payout / Fee reference a listing; Deposit / Withdrawal don't).
            $table->foreignId('related_listing_id')
                ->nullable()
                ->constrained('listings')
                ->nullOnDelete();

            // Idempotency key set by the caller (e.g. chain webhook tx hash,
            // seeder's `seed:dev-deposit:42`). Repeat calls with the same
            // reference_id are a no-op — the existing row is returned silently.
            $table->string('reference_id')->nullable()->unique();

            // Optional human-readable context for debugging / audit.
            $table->text('description')->nullable();

            // Immutable creation timestamp. No `updated_at` — rows never change.
            $table->timestamp('created_at')->useCurrent();

            // Postgres does not auto-index FK referencing columns; index manually.
            $table->index('user_id');
            $table->index('type');
            $table->index('related_listing_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
