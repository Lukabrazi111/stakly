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

            // Array of TimeControl enum values (blitz | rapid | classical).
            // jsonb (not json) so `whereJsonContains` uses the `@>` operator
            // and can be GIN-indexed if filter volume warrants it later.
            $table->jsonb('time_control');

            $table->string('region')->nullable();

            // Array of language strings (subset of StoreListingRequest::LANGUAGES),
            // or null = "no language restriction".
            $table->jsonb('language')->nullable();

            $table->timestamp('expires_at');

            // open | taken | expired | cancelled (PHP enum casts in model).
            $table->string('status')->default('open');

            $table->timestamps();

            $table->index('status');
            $table->index('stake_amount');
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
