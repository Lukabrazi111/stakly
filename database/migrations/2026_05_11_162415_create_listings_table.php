<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The marketplace listings — each row is one player's open offer to play
     * an opponent for `stake_amount` USDT.
     *
     * `user_id` is restrict-delete (not cascade): a listing may hold escrowed
     * USDT, so Postgres refuses to delete a user who still has listings. Any
     * future account-removal flow must liquidate listings via the proper
     * `CancelListingAction` / `ExpireListingAction` paths first.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            // v1 only chess; column kept for v2 (Dota etc.).
            $table->string('game')->default('chess');

            // The external provider the match must be played on (M8 Phase 5
            // Slice B). Values match `App\Enums\LinkedAccountProvider`
            // ('chess_com' | 'lichess'). Default 'chess_com' so any pre-Slice-B
            // seeded listing stays valid — fresh listings pick at creation
            // via the create-form picker (when the creator has multiple
            // verified providers; otherwise auto-selected to the one they
            // have). The take-gate validates that the taker has THIS
            // provider verified — players on different platforms can't
            // share a match because they literally can't play each other.
            $table->string('platform', 16)->default('chess_com');

            // USDT; 12,2 supports up to ~$10B per listing — far beyond any sane
            // single-match stake. Atomic-unit ledger lives in a separate
            // ledger table (M7) — this column is the display amount.
            $table->decimal('stake_amount', 12, 2);

            // Chess Elo. Both nullable so a listing can mean "any skill".
            $table->unsignedSmallInteger('skill_min')->nullable();
            $table->unsignedSmallInteger('skill_max')->nullable();

            // Single TimeControl enum value (bullet | blitz | rapid), or null
            // for non-chess games (CS2 etc. don't use time controls).
            // M41 P3a: one chess listing = exactly one time control, so this is
            // a scalar string (PHP enum cast on the model), not the old jsonb
            // array — one listing maps to exactly one verified rating.
            $table->string('time_control', 16)->nullable();

            $table->string('region')->nullable();

            // Array of language strings (subset of StoreListingRequest::LANGUAGES),
            // or null = "no language restriction".
            $table->jsonb('language')->nullable();

            $table->timestamp('expires_at');

            // open | taken | expired | cancelled (PHP enum casts in model).
            $table->string('status')->default('open');

            // M34 — team play + lobbies.
            //
            // `team_size` drives lobby size: total participants = 2 × team_size.
            // Chess listings stay 1 (handled by existing TakeListingAction flow);
            // CS2 = 5; Wingman = 2. When `> 1`, listing skips the Open→Taken
            // transition through TakeListingAction and instead routes through
            // the lobby pipeline (JoinLobbyAction → ToggleReadyAction →
            // LobbyLockAction).
            $table->unsignedSmallInteger('team_size')->default(1);

            // Side the creator joins in their own lobby. 'a' or 'b'. Null for
            // team_size = 1 listings (chess doesn't use the lobby concept).
            $table->string('creator_side', 1)->nullable();

            // Lifecycle state for team-play lobbies. Null for team_size = 1.
            // recruiting → ready_checking → locked → cancelled / expired.
            // Drives UI affordances + cron sweep targeting.
            $table->string('lobby_state', 16)->nullable();

            // Stamped when `lobby_state` transitions to `ready_checking`
            // (lobby just reached max soft-joined). The 5-min cron sweep
            // reads `WHERE lobby_state = 'ready_checking' AND
            // lobby_ready_check_deadline < now()` to fire
            // LobbyReadyCheckTimeoutAction. Cleared on lock, vacate, or
            // soft-joined count dropping below max.
            $table->timestamp('lobby_ready_check_deadline')->nullable();

            // Public listings appear in the /listings marketplace; private
            // listings reach players only via `/lobbies/{invite_token}`.
            $table->boolean('is_public')->default(true);

            // Opaque URL-safe token for private listings. Null for public.
            // 32 chars from Str::random(32). Token effectively expires when
            // the match goes Pending or the listing cancels (resolution
            // happens at LobbyController::show, not via column expiry).
            $table->string('invite_token', 32)->nullable()->unique();

            $table->timestamps();

            $table->index('status');
            $table->index('stake_amount');
            $table->index('expires_at');
            $table->index('created_at');
            $table->index('lobby_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
