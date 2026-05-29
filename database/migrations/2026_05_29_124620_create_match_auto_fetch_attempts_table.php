<?php

use App\Enums\AutoFetchOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Phase 1 — append-only audit log of every auto-fetch attempt. One row
 * per call into the pipeline, whether the call actually reached the
 * provider (`matched` / `no_match` / `ambiguous` / `error`) or stopped at
 * a pre-flight gate (`skipped`).
 *
 * The table is the canonical source for the per-match admin timeline and
 * the `PipelineHealth` dashboard widget. Without it, dispute investigation
 * starts with `grep` against ephemeral logs.
 *
 * `match_id` cascades on match delete — production never deletes matches,
 * but `RefreshDatabase` tests blow them away and we don't want orphan
 * audit rows lingering in test runs.
 *
 * No `updated_at` (immutable by convention + DB shape — Eloquent's
 * `Model::UPDATED_AT = null` enforces this on the model side).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_auto_fetch_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')
                ->constrained('game_matches')
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('outcome', 32);
            // Free-form reason discriminator for `skipped` (`not_pending`,
            // `snapshot_missing`, `already_posted`). Other outcomes may
            // optionally populate it for finer-grained PipelineHealth
            // grouping (e.g. `no_match` after retry exhaustion vs first try).
            $table->string('outcome_reason', 64)->nullable();
            // 1-indexed attempt counter. Chess.com's retry chain emits one
            // row per attempt (up to 4); single-shot Lichess rows stay at 1.
            $table->unsignedSmallInteger('attempt_number')->default(1);
            // Populated on `matched` outcomes — the username the card named
            // as winner. Useful for "who did the API hand the pot to"
            // queries on disputed settlements.
            $table->string('winner_username', 64)->nullable();
            // For `no_match` (0), `ambiguous` (>1), `matched` (1). Null on
            // outcomes that never reached the provider.
            $table->unsignedSmallInteger('candidates_count')->nullable();
            // Provider exception message on `error`. Truncated by the
            // recording Action to fit; full message stays in logs.
            $table->text('error_message')->nullable();
            // Wall-clock latency of the provider call in milliseconds. Null
            // when the attempt never called the provider (skipped paths).
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Per-match chronological reads — the admin timeline query.
            $table->index(['match_id', 'created_at']);
            // PipelineHealth queries: success rate over rolling window,
            // grouped by outcome.
            $table->index(['outcome', 'created_at']);
            // Provider-specific rollups (e.g. Lichess-only success rate).
            $table->index(['provider', 'outcome', 'created_at']);
        });

        // Sanity-check that the enum values we'll store fit the column.
        // Cheap belt-and-suspenders against a future enum rename slipping
        // past the migration.
        $maxOutcomeLength = max(array_map(
            fn (AutoFetchOutcome $case) => strlen($case->value),
            AutoFetchOutcome::cases(),
        ));

        if ($maxOutcomeLength > 32) {
            throw new RuntimeException(
                "AutoFetchOutcome longest value ({$maxOutcomeLength}) exceeds outcome column size (32). Bump the column.",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('match_auto_fetch_attempts');
    }
};
