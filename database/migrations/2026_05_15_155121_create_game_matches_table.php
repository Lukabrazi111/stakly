<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Match between two players for a single Taken listing. 1:1 with the
     * listing — enforced at the DB level via UNIQUE on `listing_id`.
     *
     * State machine (full diagram in milestones.md M6 + M10):
     *   Pending → Settled (both confirm same winner, or one confirms + 4h timeout)
     *   Pending → Disputed (mismatch, dispute opened, or 4h timeout with no confirmations)
     *   Pending → Cancelled (one player requests cancellation, the other accepts —
     *                        both stakes refunded, no fee)
     *   Disputed → Settled (game-API returned a winner)
     *   Disputed → ManualReview (game-API couldn't determine — terminal, admin
     *                            resolves manually post-launch)
     *
     * Money flows entirely through `App\Services\Wallet` — the matches table
     * is metadata, never a money source of truth.
     */
    public function up(): void
    {
        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();

            // 1:1 with listing. Locked decision in milestones.md M6.
            $table->foreignId('listing_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // The user who took the listing. Listing creator is on
            // listings.user_id and reached via `match->listing->user`.
            $table->foreignId('taker_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // App\Enums\MatchStatus cast on the model.
            $table->string('status');

            // App\Enums\MatchOutcome — each player's self-reported claim.
            // Nullable until the player confirms. Players can change their
            // claim freely while match is Pending (the "lock" is implicit
            // via status — once both confirm, the match resolves and the
            // status guard in GameMatchPolicy::confirm blocks further changes).
            $table->string('creator_confirmed_outcome')->nullable();
            $table->string('taker_confirmed_outcome')->nullable();

            // Set at settlement. SET NULL on user delete keeps match history
            // alive even if a user account is somehow removed (account
            // deletion isn't currently exposed, but defensive for future).
            $table->foreignId('winner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Dispute metadata (set when status transitions to Disputed).
            $table->timestamp('dispute_opened_at')->nullable();
            $table->foreignId('dispute_opened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Explicit settlement audit trail — separate from updated_at so
            // we can answer "when did this match resolve" without depending
            // on Eloquent's update timestamp semantics.
            $table->timestamp('settled_at')->nullable();

            // Audit trail for game-API resolution (Phase 4 onwards). Stores
            // the raw response from the GameApi driver — mock today, real
            // chess.com / Lichess in M8. Useful for admin review of
            // ManualReview matches and for debugging disputed settlements.
            $table->jsonb('api_response')->nullable();
            $table->timestamp('api_resolved_at')->nullable();

            // Cancellation metadata (M10 — mutual match cancellation).
            //
            //   No request:      all cols null.
            //   Open request:    cancellation_requested_by + ..._requested_at set;
            //                    others null.
            //   Rejected:        cancellation_requested_by (the rejected requester,
            //                    kept for cooldown enforcement) + ..._rejected_at
            //                    set; ..._requested_at + ..._reason cleared.
            //   Cancelled:       cancelled_at set; status → Cancelled; request cols
            //                    preserved as historical audit trail.
            //
            // 30-min cooldown after rejection: the same user can't re-request
            // until `cancellation_rejected_at + 30 minutes < now`. Enforced
            // in `GameMatchPolicy::requestCancellation`. Request expires
            // alongside the match's 4h confirmation window — the regular
            // timeout resolver runs if neither side responds in time.
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancellation_requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancellation_rejected_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();

            $table->timestamps();

            // Postgres doesn't auto-index FK referencing columns; index manually.
            $table->index('status');
            $table->index('taker_user_id');
            $table->index('winner_user_id');
            $table->index('settled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_matches');
    }
};
