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
     * Carries `username` (display handle), `provider_user_id` (stable ID
     * for providers that expose one — FACEIT guid, Steam ID, Riot PUUID;
     * NULL for chess providers), and `skill_rating_snapshot` (Faceit ELO,
     * future MMR; NULL until the provider's adapter populates). M15 adds
     * extra columns here as needed (e.g. Riot region) rather than widening
     * the game_matches table.
     *
     * Populated by `App\Actions\GameMatch\TakeListingAction`. Read by
     * `App\Jobs\FetchLichessGameMetadataJob` (paste-path verification),
     * the auto-fetch jobs (`AutoFetchLichessGameJob` / `AutoFetchChessComGameJob`)
     * for username pairs, and `App\Actions\GameMatch\SettleFromCardAction`
     * (M16) for mapping the card's winner_username back to a participant.
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

            // M15 — stable identifier + skill rating snapshot at match
            // creation, where the provider exposes them. Chess providers
            // leave both null.
            $table->string('provider_user_id', 128)->nullable();
            $table->integer('skill_rating_snapshot')->nullable();

            // M34 — slot identity within the team. Stores 0..team_size-1.
            // For 1v1 chess matches, slot_index = 0 (only one player per
            // side). For team-play, distinct per side player. Defaulted to 0
            // so the chess flow (`TakeListingAction::snapshotProviderAccounts`)
            // doesn't need to be aware of the column — the team-play flow
            // (`LobbyLockAction`) overrides explicitly.
            $table->unsignedSmallInteger('slot_index')->default(0);

            $table->timestamps();

            // One snapshot per (match, side, slot, provider). Extended from
            // M8's (match_id, side, provider) to include slot_index so
            // M34's 5v5 case (multiple players on the same side) doesn't
            // collide. For chess 1v1 matches where slot_index defaults to 0,
            // the original (match, side, provider) uniqueness still holds.
            $table->unique(['match_id', 'side', 'slot_index', 'provider']);

            // Lookup "all matches involving this provider username" —
            // useful for admin / abuse review surfaces (M12 onward) without
            // joining through users.
            $table->index(['provider', 'username']);

            // Same abuse-review lookup on the provider's stable ID (M15) —
            // separate from the username index because display handles can
            // change but the stable ID survives renames.
            $table->index(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_provider_snapshots');
    }
};
