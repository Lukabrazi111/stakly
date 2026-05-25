<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transient pending bio-code state for the linked-account verification
 * flow. One row per user at most — `RequestLinkVerificationAction`
 * upserts on (user_id) so starting a fresh verification overwrites any
 * in-flight one (e.g. user mistyped, switches providers mid-flow).
 *
 * Distinct table from `linked_accounts` because pending state is
 * fundamentally different: it's TTL-bound (expires_at, default 15 min),
 * carries a plaintext code (we need to display it back to the user, the
 * short TTL keeps the attack window tiny), and isn't a real verified link
 * yet. Keeping it separate also keeps `linked_accounts` clean of NULL
 * verified_at rows.
 *
 * `UNIQUE(user_id)` because a user has at most one verification in flight;
 * the upsert pattern relies on this constraint. `provider` + `username`
 * are sized to match the `linked_accounts` schema so a successful
 * verification copies cleanly. `code` is plaintext, 32 chars allows for
 * 'stakly-' prefix + ~24-char alphabet body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('username', 64);
            $table->string('code', 32);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_verifications');
    }
};
