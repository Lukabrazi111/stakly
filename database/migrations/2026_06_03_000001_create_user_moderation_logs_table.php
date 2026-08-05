<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_moderation_logs', function (Blueprint $table) {
            $table->id();

            // The user the action was performed on.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The admin who took the action. `restrictOnDelete` mirrors
            // `admin_impersonations` — never lose attribution silently.
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();

            // 'ban' | 'unban' | 'freeze' | 'unfreeze' | 'kyc' — the constants
            // on `App\Models\UserModerationLog`. Append-only. Kept a plain
            // string rather than a DB enum so adding a moderation surface
            // doesn't need a migration against an audit table; the write side
            // is a small closed set of class constants, and nothing reads
            // `action` back into a typed enum.
            $table->string('action', 16);

            // Required at the UI layer in both directions. Capped at the
            // same 1000 chars as `admin_impersonations.reason` + the
            // dispute-settle reason field — consistent across audit
            // surfaces.
            $table->string('reason', 1000);

            // Append-only — no `updated_at`. Pattern mirrors
            // `MatchAdminResolution` and `WalletTransaction`.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_moderation_logs');
    }
};
