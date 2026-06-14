<?php

use App\Models\User;

/*
 * M34 P3.1 Slice A.2 — SSR cookie for the rows ↔ grid view toggle.
 * Shared via `HandleInertiaRequests` as `listingsViewLayout`. Whitelisted
 * to 'rows' | 'grid' so a tampered cookie falls back to the safe default.
 */

it('defaults listingsViewLayout to "grid" when no cookie is set', function () {
    $this->get(route('listings.index', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('listingsViewLayout', 'grid'),
        );
});

it('honors a valid "grid" cookie', function () {
    $this->withUnencryptedCookie('listings_view_layout', 'grid')
        ->get(route('listings.index', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('listingsViewLayout', 'grid'),
        );
});

it('honors a valid "rows" cookie', function () {
    $this->withUnencryptedCookie('listings_view_layout', 'rows')
        ->get(route('listings.index', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('listingsViewLayout', 'rows'),
        );
});

it('falls back to "grid" when the cookie holds an unknown value', function () {
    $this->withUnencryptedCookie('listings_view_layout', 'masonry')
        ->get(route('listings.index', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('listingsViewLayout', 'grid'),
        );
});

it('shares listingsViewLayout on /listings/mine too', function () {
    $user = User::factory()->active()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('listings_view_layout', 'grid')
        ->get(route('listings.mine', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('listingsViewLayout', 'grid'),
        );
});
