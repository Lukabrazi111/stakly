<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user × external game-account provider. Normalised out of
 * `users` columns (M18 Phase 3 prep) so M15's multi-game expansion (FACEIT,
 * Riot, Steam, OpenDota, etc.) can each slot in as a new `provider` enum
 * value without widening the `users` table again.
 *
 * Two UNIQUE constraints encode the matchmaking-trust invariants:
 *   - (user_id, provider)     — a Stakly user has at most one verified
 *                                account per external provider.
 *   - (provider, username)    — an external account belongs to at most
 *                                one Stakly user; duplicate verify
 *                                attempts hit this and are translated to
 *                                a friendly error by VerifyLinkedAccountAction.
 *
 * `verified_at` exists because we only insert rows after the bio-code
 * verification completes — pending state lives in `pending_verifications`,
 * never here. Storing the verified timestamp makes "linked since" UX
 * cheap and supports future stale-link policies if we ever introduce them.
 *
 * `provider` stored as snake_case (chess_com, lichess, …) matching
 * `App\Enums\LinkedAccountProvider`. Sized 16 chars — comfortable for the
 * planned roster (chess_com, lichess, faceit, riot, steam, opendota).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('username', 64);
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->unique(['provider', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_accounts');
    }
};
