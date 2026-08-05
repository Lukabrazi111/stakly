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

            // Last time the user changed their username. Drives the 30-day
            // cooldown enforced in `User::canChangeUsername()` /
            // `ChangeUsernameAction`. Null on accounts that have never
            // renamed (post-registration default).
            $table->timestamp('username_changed_at')->nullable();

            // Optional 500-char bio shown on the public profile (M5 → M18).
            // Bounded length, not text — bio is never queried/filtered. Plain
            // text with line breaks; React's JSX interpolation escapes on
            // render, and `whitespace-pre-line` preserves the newlines.
            $table->string('bio', 500)->nullable();

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

            // Admin-set suspension flag (M30 P2). Null = active. Timestamp
            // set when the admin flips the toggle. Enforced across four
            // surfaces: `ListingController` create/store + `ProfileController`
            // update + `ChangeUsernameAction` blocker + public marketplace
            // scopes filter (banned creator's listings hide). Chat-send +
            // take-listing enforcement is M21's scope on the same column.
            $table->timestamp('banned_at')->nullable();

            // Money-level freeze (M9 Phase 0b), distinct from `banned_at`.
            // A ban is a product-access flag; a freeze blocks DEBITS only —
            // `Wallet::withdraw` + `Wallet::hold` throw, so a frozen user can
            // neither cash out nor stake into a new match. Credits still flow
            // so in-flight matches settle and refunds land, which keeps a
            // freeze from stranding an opponent's escrowed money.
            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();

            // Identity verification (M9 Phase 0c). Inert unless
            // `stakly.kyc_enabled` is on, which it is NOT by default — nothing
            // in the current provider model requires KYC. The column exists so
            // enabling verification later is a config flip plus an admin
            // action, not a migration against a live money table. Transitions
            // are admin-driven; there is no document-upload flow.
            $table->string('kyc_status', 16)->default('unverified');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->string('kyc_note')->nullable();

            // Bell-dropdown badge anchor: bumped on bell open, decoupled from
            // per-item `notifications.read_at`.
            $table->timestamp('notifications_last_seen_at')->nullable();

            // Notification sound choice. Null = system default. Special value
            // 'off' silences all sounds. Other values map to /sounds/{value}.mp3.
            $table->string('notification_sound', 16)->nullable();

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

            // External game-account links normalised out of `users` into
            // `linked_accounts` (M18 Phase 3 prep) — see
            // 2026_05_25_create_linked_accounts_table for the new shape.
            // Same migration also adds `pending_verifications` for the
            // in-flight bio-code state that used to live inline here.

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
