<?php

use App\Enums\GameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('display_name', 120);
            $table->string('poster_path')->nullable();
            $table->unsignedInteger('position')->default(0)->index();
            $table->string('status', 32)->default(GameStatus::ComingSoon->value)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
