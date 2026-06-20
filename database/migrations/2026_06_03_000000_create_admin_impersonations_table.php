<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_impersonations', function (Blueprint $table) {
            $table->id();

            // The admin who started the impersonation. `restrictOnDelete` so
            // we never lose attribution — if an admin leaves, audit rows
            // pin the deletion to a manual data decision.
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();

            // The user being impersonated. `cascadeOnDelete` — if the target
            // user is hard-deleted later, the audit rows go with them; the
            // admin's responsibility for the action is recorded against the
            // admin's row regardless.
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();

            // Free-text reason the admin entered at start. Required at the
            // UI layer; the audit log is the answer to "why did admin X
            // impersonate user Y three weeks ago?" — timestamp alone is too
            // thin. 1000 char cap mirrors the dispute-settle reason cap.
            $table->string('reason', 1000);

            // Session boundaries. `started_at` is set at insert; `ended_at`
            // stays null while the session is live and stamps when the admin
            // exits (or middleware force-exits past the 30-min cap).
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            // Origin metadata — useful for cross-referencing if an account
            // is later flagged for unusual admin activity.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            // The "is there a live session for this admin right now?" query
            // hits these together.
            $table->index(['admin_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonations');
    }
};
