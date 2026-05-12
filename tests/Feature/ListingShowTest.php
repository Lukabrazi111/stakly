<?php

use App\Models\Listing;
use App\Models\User;

test('public listing detail page renders for any visitor', function () {
    $listing = Listing::factory()->open()->create();

    $response = $this->get("/listings/{$listing->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/show')
        ->where('listing.id', $listing->id)
        ->where('listing.status', 'open')
        ->has('listing.creator', fn ($creator) => $creator
            ->where('id', $listing->user_id)
            ->where('name', $listing->user->name)
            ->etc()
        )
    );
});

test('show returns 404 for a non-existent listing id', function () {
    $this->get('/listings/999999')->assertNotFound();
});

test('non-open listings (taken / expired / cancelled) still render with their status', function () {
    $taken = Listing::factory()->taken()->create();
    $expired = Listing::factory()->expired()->create();
    $cancelled = Listing::factory()->cancelled()->create();

    $this->get("/listings/{$taken->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'taken'));

    $this->get("/listings/{$expired->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'expired'));

    $this->get("/listings/{$cancelled->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'cancelled'));
});

test('show resource never leaks the creator email or other sensitive fields', function () {
    $owner = User::factory()->create(['email' => 'leak-check@example.com']);
    $listing = Listing::factory()->open()->for($owner)->create();

    $response = $this->get("/listings/{$listing->id}");

    $response->assertDontSee('leak-check@example.com');
});
