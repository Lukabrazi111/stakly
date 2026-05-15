<?php

use App\Models\GameMatch;
use App\Models\User;

// ─── Authorization (participant-only, 404 for outsiders) ────────────────────

test('listing creator can view the match', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;

    $this->actingAs($creator)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('match/show')
            ->where('match.id', $match->id)
        );
});

test('match taker can view the match', function () {
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('match/show'));
});

test('non-participant gets 404 (not 403 — never leak match existence)', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('matches.show', $match))
        ->assertNotFound();
});

test('guest is redirected to login', function () {
    $match = GameMatch::factory()->create();

    $this->get(route('matches.show', $match))
        ->assertRedirect(route('login'));
});

test('unverified user is redirected to verification notice', function () {
    $match = GameMatch::factory()->create();
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get(route('matches.show', $match))
        ->assertRedirect(route('verification.notice'));
});

// ─── Resource shape (PII safety) ────────────────────────────────────────────

test('the match resource exposes the participant + listing summary, never PII', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;

    $this->actingAs($creator)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->component('match/show')
            ->where('match.id', $match->id)
            ->where('match.status', 'pending')
            ->has('match.creator', fn ($p) => $p
                ->where('id', $creator->id)
                ->where('name', $creator->name)
                ->where('username', $creator->username)
                ->etc()
                // PII assertions: these MUST NOT appear in the resource.
                ->missing('email')
                ->missing('usdt_balance')
                ->missing('tron_address')
            )
            ->has('match.taker', fn ($p) => $p
                ->etc()
                ->missing('email')
                ->missing('usdt_balance')
                ->missing('tron_address')
            )
            ->has('match.listing', fn ($p) => $p
                ->where('id', $match->listing->id)
                ->where('game', 'chess')
                ->etc()
            )
        );
});

test('a freshly-created match has null winner and null settled_at', function () {
    $match = GameMatch::factory()->create();

    $this->actingAs($match->taker)
        ->get(route('matches.show', $match))
        ->assertInertia(fn ($page) => $page
            ->where('match.winner', null)
            ->where('match.settled_at', null)
            ->where('match.creator_confirmed_outcome', null)
            ->where('match.taker_confirmed_outcome', null)
        );
});
