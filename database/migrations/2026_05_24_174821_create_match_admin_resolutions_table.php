<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12 Phase 2 — admin resolution audit log. Append-only: every Settle to
 * Creator / Settle to Taker / Settle as Draw click an admin makes in the
 * Filament panel writes one row here, alongside the underlying Wallet call.
 * Used for internal review + post-incident reconstruction; never mutated
 * after insert (no updated_at column).
 *
 * `match_id` cascades on match delete (matches don't get deleted in
 * production, but RefreshDatabase tests blow them away). `admin_user_id`
 * and `winner_user_id` restrict — we never delete a user who appears on an
 * audit row; their trail must remain intact even after deactivation.
 *
 * `winner_user_id` is null for draw resolutions; otherwise FK to the user
 * receiving the payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_admin_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')
                ->constrained('game_matches')
                ->cascadeOnDelete();
            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('action');
            $table->foreignId('winner_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['match_id', 'created_at']);
            $table->index('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_admin_resolutions');
    }
};
