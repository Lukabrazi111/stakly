<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Public handle derived from `name` at registration via `CreateNewUser`
            // (slugified, collision-safe). Drives the `/users/{username}` profile
            // route (M5). Length cap 30 matches the registration regex
            // `/^[a-z0-9-]{3,30}$/`. UNIQUE constraint is the authority for
            // collision handling — `CreateNewUser` retries on violation.
            $table->string('username', 30)->unique();

            // Optional 280-char bio shown on the public profile (M5). Bounded
            // length, not text — bio is never queried/filtered.
            $table->string('bio', 280)->nullable();

            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Platform-revenue user flag. One seeded row has is_platform=true
            // (`platform@stakly.internal`) — destination for `Wallet::fee(...)`
            // ledger entries. See milestones.md M3.5.
            $table->boolean('is_platform')->default(false);

            // Global "Active Mode" toggle (M6 Phase 6.5). When false, ALL the
            // user's Open listings are hidden from the public marketplace AND
            // public profile views. Default `false` — new users are inactive
            // until they explicitly opt in on /listings/mine. Matches Bybit's
            // "you're currently offline" first-visit experience and prevents
            // a freshly-registered (but unverified-feeling) user from being
            // immediately takeable while they're still exploring.
            $table->boolean('is_active_mode')->default(false);

            // Spendable USDT balance. NEVER written outside `App\Services\Wallet`.
            // Invariant (asserted in tests): equals SUM(wallet_transactions.amount)
            // for this user at all times. decimal(18, 6) matches Tron's USDT precision.
            $table->decimal('usdt_balance', 18, 6)->default(0);

            // Mock TRC20 deposit address (M7). 34 chars matches a real Tron
            // address ('T' prefix + 33 base58 chars). Generated at registration
            // via `App\Support\MockTronAddress`; nothing on-chain accepts funds
            // here in v1. Real HD-derived addresses replace this at the
            // pre-launch chain integration gate. UNIQUE because real HD
            // derivation guarantees per-index uniqueness — we enforce it at
            // the DB level so mock collisions surface immediately.
            $table->string('tron_address', 34)->unique()->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
