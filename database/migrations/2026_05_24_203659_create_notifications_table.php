<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's notifications table — used by Filament's database notifications
 * (bell icon dropdown in the admin panel header, M17 Phase 2). `data` is
 * declared as `jsonb` (not Laravel's default `text`) because Filament's
 * bell-icon query reads `data->>'format'` to filter Filament-shaped
 * notifications from any other Notifiable payloads. Postgres requires a
 * jsonb column for the `->>` operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
