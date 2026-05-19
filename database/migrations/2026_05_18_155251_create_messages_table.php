<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat messages scoped to a single match (M8 Phase 2).
     *
     * Messages are append-only — no updated_at, no soft deletes. Dispute
     * resolution (M12) depends on truthful logs, so we never let messages
     * mutate after insert.
     *
     * `user_id` is nullable so we can post system messages (M8 Phase 5):
     * dispute-opened prompts, evidence-submission hints, etc. They carry
     * `type = system` and are produced by a separate `PostSystemMessageAction`
     * — the HTTP path used by humans always sets `type = text` and `user_id`
     * to the authenticated user, so a system message can never be impersonated.
     *
     * `attachments_json` is reserved for Phase 3 image uploads (shape: array
     * of `{url, mime, size}`) and Phase 4 link enrichment (OG title/image,
     * verified-game-result cards). Empty for text messages.
     *
     * Composite index on `(match_id, id)` matches the only access pattern —
     * "all messages in this match, chronologically." `id` is bigserial so
     * id order equals insert order.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('match_id')
                ->constrained('game_matches')
                ->cascadeOnDelete();

            // Null for system messages. nullOnDelete preserves the audit
            // trail if a user is ever removed (account-deletion isn't
            // currently exposed, but defensive for future).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // App\Enums\MessageType cast on the model. 'text' from the HTTP
            // path; 'system' from internal Actions (M8 Phase 5).
            $table->string('type', 16);

            // text (not varchar) — chat content is unbounded at the DB level;
            // the Action enforces the 2000-char product cap. Nullable since
            // M8 Phase 3 Slice 1: an image-only message (screenshot with no
            // caption) is a valid chat post.
            $table->text('content')->nullable();

            // Reserved for Phase 3 (image uploads) and Phase 4 (link cards
            // including verified-game evidence). jsonb (not json) for `@>`
            // operator support and GIN-index potential later.
            $table->jsonb('attachments_json')->nullable();

            // No updated_at — messages are immutable. `useCurrent` so the
            // timestamp is set at INSERT regardless of Eloquent's
            // model-level timestamp handling.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['match_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
