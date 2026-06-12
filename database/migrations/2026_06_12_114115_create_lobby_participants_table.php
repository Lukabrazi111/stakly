<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soft-join lobby participation for `team_size > 1` listings (M34 P0).
     *
     * One row per (listing, user, lifetime) — a kicked user who later rejoins
     * gets a NEW row; the prior kicked row stays for forensics + the 5-min
     * same-listing rejoin cooldown.
     *
     * `kicked_at` is the discriminator between "live" rows (kicked_at IS NULL,
     * subject to slot + per-user uniqueness) and "audit" rows (kicked_at set,
     * exempt from uniqueness so the slot can be reclaimed and the user can
     * rejoin post-cooldown). Partial unique indexes encode this — Postgres-
     * specific syntax via DB::statement since Laravel's schema builder has
     * no first-class API for them.
     *
     * `user_id` is RESTRICT-delete because rows may have an open `Wallet::hold`
     * referencing the listing. Same posture as `listings.user_id`.
     */
    public function up(): void
    {
        Schema::create('lobby_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('listing_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            // 'a' or 'b' — the team. Matches `listings.creator_side`.
            $table->string('side', 1);

            // 0..team_size-1 within the side. UNIQUE(listing_id, side, slot_index)
            // WHERE kicked_at IS NULL prevents two live players claiming the
            // same slot.
            $table->unsignedSmallInteger('slot_index');

            // Toggled by ToggleReadyAction. Flipping true triggers a
            // `Wallet::hold`; flipping false (pre-lock) triggers a
            // `Wallet::release`.
            $table->boolean('is_ready')->default(false);

            // Audit + invariant: when did this player's stake actually escrow?
            // Null while soft-joined (no stake held). Set on Ready toggle.
            // Cleared back to null on un-Ready / leave (when refund posts).
            $table->timestamp('stake_held_at')->nullable();

            // Set when KickParticipantAction removes this player. Row stays
            // as audit + cooldown anchor (`kicked_at + 5 min > now()` blocks
            // rejoin to THIS listing only — M21 owns cross-listing blocking).
            // Partial unique indexes treat any non-null `kicked_at` row as
            // exempt, so the slot is freed and the user can rejoin post-cooldown.
            $table->timestamp('kicked_at')->nullable();

            // When the soft-join row was first inserted. Distinct from
            // `created_at` only conceptually — kept for forward-compat if we
            // later resurrect rows or run cron sweeps on join age.
            $table->timestamp('joined_at')->useCurrent();

            $table->timestamps();

            $table->index(['listing_id', 'side']);
            $table->index('user_id');
            $table->index('kicked_at');
        });

        // Partial unique indexes — Postgres-specific via raw SQL.
        //
        //   `slot_unique` — only one LIVE participant per (listing, side, slot).
        //   `user_unique` — only one LIVE participation per (listing, user).
        //
        // Kicked rows (kicked_at IS NOT NULL) are excluded from both, which
        // is what lets a new joiner reclaim a freed slot AND lets a kicked
        // user rejoin (creating a fresh live row alongside the old kicked one)
        // after the 5-min cooldown expires.
        DB::statement(
            'CREATE UNIQUE INDEX lobby_participants_listing_side_slot_active_unique '
            .'ON lobby_participants (listing_id, side, slot_index) '
            .'WHERE kicked_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX lobby_participants_listing_user_active_unique '
            .'ON lobby_participants (listing_id, user_id) '
            .'WHERE kicked_at IS NULL'
        );
    }

    public function down(): void
    {
        // Index drops are implicit on `dropIfExists` but harmless to be
        // explicit; survives a partial down().
        DB::statement('DROP INDEX IF EXISTS lobby_participants_listing_side_slot_active_unique');
        DB::statement('DROP INDEX IF EXISTS lobby_participants_listing_user_active_unique');

        Schema::dropIfExists('lobby_participants');
    }
};
