<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only snapshot of each match participant's verified external
     * accounts at match creation time (M8 Phase 4 "snapshot, don't link"
     * architectural decision).
     *
     * One row per (match, side, provider). Sibling to `game_matches` rather
     * than columns on it so the per-provider identifier shape can grow
     * without per-game migrations on the wide `game_matches` table.
     *
     * Today the only identifier carried is `username` (single string —
     * sufficient for chess.com + Lichess). When M15 adds CS2 / Dota 2 /
     * Valorant — each of which identifies players by multiple fields
     * (Steam ID + Faceit handle, Riot ID + region, etc.) — extra columns
     * land here (or a nullable `provider_data` jsonb), not on game_matches.
     *
     * Populated by `App\Actions\GameMatch\TakeListingAction`. Read by
     * `App\Jobs\FetchLichessGameMetadataJob` (paste-path verification),
     * `App\Jobs\AutoFetchLichessGameJob` (auto-fetch usernames),
     * `App\Actions\GameMatch\ConfirmOutcomeAction` (gate on snapshot
     * presence before dispatching auto-fetch).
     *
     * Cross-platform abuse defense: snapshot survives a mid-match unlink.
     * A player can't `DELETE /settings/linked-accounts/lichess` to escape
     * a losing game's evidence trail — the snapshotted handle is still
     * the cross-check anchor.
     */
    public function up(): void
    {
        Schema::create('match_provider_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('match_id')
                ->constrained('game_matches')
                ->cascadeOnDelete();

            // 'creator' | 'taker' — see GameMatch::SIDE_* constants. Kept
            // as a varchar (not an enum type) to avoid a Postgres-level
            // type migration if a future team-match shape adds 'player_3'
            // or similar.
            $table->string('side', 8);

            // Matches `App\Enums\LinkedAccountProvider` values
            // ('lichess', 'chess_com', and future cases). 16 chars is
            // generous for the value strings.
            $table->string('provider', 16);

            // Primary identifier on the provider. For chess.com (max 25)
            // and Lichess (max 20) this is the username. Width covers
            // future longer-handle providers (Riot ID `name#tag` up to
            // 16+5 chars; Steam vanity URLs up to 32).
            $table->string('username', 64);

            $table->timestamps();

            // One snapshot per (match, side, provider). A second insert
            // for the same triple should be a conflict, not a duplicate.
            $table->unique(['match_id', 'side', 'provider']);

            // Lookup "all matches involving this provider username" —
            // useful for admin / abuse review surfaces (M12 onward) without
            // joining through users.
            $table->index(['provider', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_provider_snapshots');
    }
};
