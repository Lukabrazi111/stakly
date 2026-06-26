<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-time-control chess ratings for a linked account (M41 P3b). One row per
 * (linked_account, time_control) — a chess player has separate bullet / blitz /
 * rapid ratings, so they can't live in the scalar `linked_accounts.skill_rating`
 * the way FACEIT's single CS2 ELO does (FACEIT keeps that column).
 *
 * A row exists only for a time control the player has actually played (the
 * provider returned a rating); a MISSING row = "Unrated" for that TC. The
 * refresh path UPSERTS rows and NEVER deletes them, so a transient API hiccup
 * keeps the last-known rating rather than wiping it (display-only invariant —
 * settlement rides the match-time snapshot + the game API, never this cache).
 *
 * `is_provisional` is computed at capture time per provider: Lichess sends a
 * literal `prov:true`; chess.com has no flag, so we derive it from a high
 * Glicko rating deviation (`rd`). Provisional ratings still render "Unrated".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_account_ratings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('linked_account_id')
                ->constrained()
                ->cascadeOnDelete();

            // bullet | blitz | rapid (App\Enums\TimeControl). Sized to match the
            // listings.time_control column.
            $table->string('time_control', 16);

            // The provider's current rating for this time control.
            $table->integer('rating');

            // Glicko rating deviation — drives provisional inference for
            // chess.com (no `prov` flag) and corroborates Lichess's. Nullable
            // defensively; both providers return it for a played time control.
            $table->integer('rd')->nullable();

            // True when the rating is low-confidence (Lichess `prov`, or
            // chess.com `rd` over the configured threshold). Rendered "Unrated".
            $table->boolean('is_provisional')->default(false);

            // Last successful provider sync for THIS row. The account-level
            // freshness gate uses `linked_accounts.skill_rating_synced_at`; this
            // is per-row audit granularity.
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One rating per (account, time control).
            $table->unique(['linked_account_id', 'time_control']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_account_ratings');
    }
};
