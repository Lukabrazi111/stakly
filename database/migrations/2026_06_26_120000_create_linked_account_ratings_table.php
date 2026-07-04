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
 * `is_provisional` is computed at capture time from the account's GAME COUNT
 * for that time control (`games < services.<provider>.provisional_min_games`,
 * default 20) for BOTH providers — NOT Lichess's `prov` flag or chess.com's
 * `rd` (M41 P4 revision, after a 537-game rating was wrongly hidden). A
 * provisional rating still displays its number with a "?" marker; it is never
 * hidden. "Unrated" means only that no row exists for that time control.
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

            // Glicko rating deviation, stored for reference/display only — it no
            // longer drives provisional inference (that's game-count based; see
            // is_provisional). Nullable defensively; both providers return it for
            // a played time control.
            $table->integer('rd')->nullable();

            // True when the account has played fewer than the configured
            // `provisional_min_games` (default 20) for this time control. The UI
            // shows the rating number with a "?" marker (never hidden).
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
