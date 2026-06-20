<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('username_history', function (Blueprint $table) {
            $table->id();

            // The user that previously held this handle. Nullable so we can
            // keep history rows around if the user is hard-deleted (rare),
            // without losing the reservation timer.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The released handle. Lookups go through this column on every
            // profile-show miss (M*: old-URL redirect) and on every rename
            // validation pass (reservation gate), so it must be indexed.
            $table->string('username', 30);

            // Reservation expiry. Until this date passes, no one (including
            // the original holder) can claim the handle. 30-day default set
            // in `ChangeUsernameAction`. Indexed so the validator's
            // `where released_at > now` filter stays fast at scale.
            $table->timestamp('released_at');

            $table->timestamps();

            $table->index('username');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('username_history');
    }
};
