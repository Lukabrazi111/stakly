<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M26 Phase 1 — admin-managed CMS pages (About, Privacy, Terms, etc.). Each
 * row is a (slug, locale) pair so the same slug can carry translations later.
 *
 * `published_at`:
 *   - null      → draft (404 publicly, only reachable via signed admin preview)
 *   - past now  → live
 *   - future    → scheduled (also 404 until the timestamp passes)
 *
 * Slug + locale are the lookup key. The unique constraint keeps the resolver
 * deterministic and is the only index needed — the table is read-light (one
 * Redis hit per public page after warm), so we don't pre-index `published_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64);
            $table->string('locale', 5)->default('en');
            $table->string('title', 200);
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
