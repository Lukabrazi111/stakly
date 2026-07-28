<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-out requests (M9 Phase 0b). One row per user-initiated withdrawal.
     *
     * This table is NOT the ledger — `wallet_transactions` is. Every money
     * movement here has a corresponding Wallet entry linked via
     * `debit_transaction_id` (the debit) and the `wd-reversal:{id}` /
     * `wd-margin:{id}` reference keys (the credit-back and the platform cut).
     *
     * There is no `review_until` column: the anti-abuse hold lives on the
     * payout (`wallet_transactions.clears_at`), not on the withdrawal, so
     * money that reaches this table has already cleared.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Gross amount debited from the user's balance. The user receives
            // this minus `platform_fee` minus the on-chain `network_fee`.
            $table->decimal('amount', 18, 6);

            // Stakly's flat cut (config `stakly.withdrawal_margin`), snapshotted
            // at request time so a later config change can't retroactively
            // rewrite what this withdrawal charged. Booked to the platform user
            // at SEND time, never at request — a rejected withdrawal must not
            // record phantom revenue.
            $table->decimal('platform_fee', 18, 6)->default(0);

            // Actual gas, filled in from the gateway once the payout confirms.
            $table->decimal('network_fee', 18, 6)->nullable();

            // 42 chars rather than the 34 a TRC20 address needs, leaving room
            // for BEP20/ERC20 (0x + 40 hex) without a migration.
            $table->string('destination_address', 42);

            // App\Enums\WithdrawalStatus cast on the model.
            $table->string('status')->index();

            // The `Wallet::withdraw` row this withdrawal debited. Nullable
            // because the row is created first, then linked inside the same
            // transaction. nullOnDelete is belt-and-braces: ledger rows are
            // restrict-delete, so this should never fire.
            $table->foreignId('debit_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            // Which PaymentGateway driver handled the send ('mock' today).
            $table->string('provider')->nullable();

            // Provider's payout id. UNIQUE so a webhook replay or a duplicated
            // send can never map to two withdrawals.
            $table->string('provider_payout_id')->nullable()->unique();

            $table->string('tx_hash')->nullable();

            $table->string('rejected_reason')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Serves "my withdrawals, newest first" and the admin status filter.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
