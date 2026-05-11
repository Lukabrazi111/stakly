<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The marketplace listings — each row is one player's open offer to play
     * an opponent for `stake_amount` USDT. M3 scope is the index/browse UI;
     * "take listing" + escrow are M4/M6.
     *
     * TODO (M6/M7 escrow): switch `user_id` foreign key from cascade-delete
     * to restrict-delete or soft-delete the listing, because a listing
     * holding escrowed USDT cannot silently disappear when a user is removed.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // v1 only chess; column kept for v2 (Dota etc.).
            $table->string('game')->default('chess');

            // USDT; 12,2 supports up to ~$10B per listing — far beyond any sane
            // single-match stake. Atomic-unit ledger lives in a separate
            // ledger table (M7) — this column is the display amount.
            $table->decimal('stake_amount', 12, 2);

            // Chess Elo. Both nullable so a listing can mean "any skill".
            $table->unsignedSmallInteger('skill_min')->nullable();
            $table->unsignedSmallInteger('skill_max')->nullable();

            // blitz | rapid | classical (PHP enum casts in Listing model).
            $table->string('time_control');

            $table->string('region')->nullable();
            $table->string('language')->nullable();

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
