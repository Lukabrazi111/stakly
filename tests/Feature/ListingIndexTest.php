<?php

use App\Enums\TimeControl;
use App\Models\Listing;
use App\Models\User;

test('listings index is publicly accessible', function () {
    $response = $this->get('/listings');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('listings/index'));
});

test('only open + non-expired listings appear by default', function () {
    Listing::factory()->open()->count(3)->create();
    Listing::factory()->taken()->count(2)->create();
    Listing::factory()->expired()->count(2)->create();
    Listing::factory()->cancelled()->count(1)->create();

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->component('listings/index')
        ->has('listings.data', 3)
    );
});

test('pagination is server-controlled at 12 per page', function () {
    Listing::factory()->open()->count(20)->create();

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->has('listings.data', 12)
        ->where('listings.meta.total', 20)
    );

    $page2 = $this->get('/listings?page=2');

    $page2->assertInertia(fn ($page) => $page->has('listings.data', 8));
});

test('user-supplied per_page in the URL is ignored', function () {
    Listing::factory()->open()->count(20)->create();

    $response = $this->get('/listings?per_page=1000');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 12));
});

test('stake_min filter narrows results', function () {
    Listing::factory()->open()->state(['stake_amount' => 10])->create();
    Listing::factory()->open()->state(['stake_amount' => 50])->create();
    Listing::factory()->open()->state(['stake_amount' => 500])->create();

    $response = $this->get('/listings?filter[stake_min]=100');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 1));
});

test('stake_max filter narrows results', function () {
    Listing::factory()->open()->state(['stake_amount' => 10])->create();
    Listing::factory()->open()->state(['stake_amount' => 50])->create();
    Listing::factory()->open()->state(['stake_amount' => 500])->create();

    $response = $this->get('/listings?filter[stake_max]=100');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

test('time_control filter accepts multiple values (CSV)', function () {
    Listing::factory()->open()->state(['time_control' => TimeControl::Blitz])->create();
    Listing::factory()->open()->state(['time_control' => TimeControl::Rapid])->create();
    Listing::factory()->open()->state(['time_control' => TimeControl::Classical])->create();

    $response = $this->get('/listings?filter[time_control]=blitz,rapid');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

test('skill range overlap matches listings that intersect the filter', function () {
    Listing::factory()->open()->state(['skill_min' => 1400, 'skill_max' => 1600])->create();
    Listing::factory()->open()->state(['skill_min' => 1700, 'skill_max' => 2000])->create();
    Listing::factory()->open()->state(['skill_min' => 1900, 'skill_max' => 2200])->create();
    Listing::factory()->open()->state(['skill_min' => null, 'skill_max' => null])->create();

    $response = $this->get('/listings?filter[skill_min]=1500&filter[skill_max]=1800');

    // matches: [1400-1600] (overlaps), [1700-2000] (overlaps), null/null (any skill) = 3
    $response->assertInertia(fn ($page) => $page->has('listings.data', 3));
});

test('region filter matches exact value', function () {
    Listing::factory()->open()->state(['region' => 'EU'])->count(2)->create();
    Listing::factory()->open()->state(['region' => 'NA'])->create();

    $response = $this->get('/listings?filter[region]=EU');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

test('sort=newest is the default (newest first)', function () {
    $older = Listing::factory()->open()->create(['created_at' => now()->subDays(2)]);
    $newer = Listing::factory()->open()->create(['created_at' => now()->subDay()]);

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->where('listings.data.0.id', $newer->id)
        ->where('listings.data.1.id', $older->id)
    );
});

test('sort=highest_stake orders by stake descending', function () {
    Listing::factory()->open()->state(['stake_amount' => 10])->create();
    Listing::factory()->open()->state(['stake_amount' => 500])->create();
    Listing::factory()->open()->state(['stake_amount' => 100])->create();

    $response = $this->get('/listings?sort=highest_stake');

    $response->assertInertia(fn ($page) => $page
        ->where('listings.data.0.stake_amount', 500)
        ->where('listings.data.1.stake_amount', 100)
        ->where('listings.data.2.stake_amount', 10)
    );
});

test('sort=ending_soon orders by expires_at ascending', function () {
    $later = Listing::factory()->open()->create(['expires_at' => now()->addDays(2)]);
    $sooner = Listing::factory()->open()->create(['expires_at' => now()->addHour()]);

    $response = $this->get('/listings?sort=ending_soon');

    $response->assertInertia(fn ($page) => $page
        ->where('listings.data.0.id', $sooner->id)
        ->where('listings.data.1.id', $later->id)
    );
});

test('invalid query params redirect to clean /listings (no error wall)', function () {
    Listing::factory()->open()->count(3)->create();

    $this->get('/listings?filter[stake_min]=not-a-number')->assertRedirect('/listings');
    $this->get('/listings?sort=DROP_TABLE_USERS')->assertRedirect('/listings');
    $this->get('/listings?filter[time_control]=bogus')->assertRedirect('/listings');
    $this->get('/listings?filter[skill_min]=99999')->assertRedirect('/listings');
});

test('unknown filter keys are rejected by validation (redirect to clean /listings)', function () {
    $this->get('/listings?filter[admin]=1')->assertRedirect('/listings');
    $this->get('/listings?filter[user_id]=42')->assertRedirect('/listings');
});

test('creator data is whitelisted — no email or sensitive fields ship', function () {
    $user = User::factory()->create([
        'email' => 'private@example.com',
    ]);
    Listing::factory()->open()->for($user)->create();

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->has('listings.data.0.creator', fn ($creator) => $creator
            ->has('id')
            ->has('name')
            ->etc()
        )
    );

    // Hard assertion: the email must not appear anywhere in the response body.
    $response->assertDontSee('private@example.com');
});

test('filters are echoed back in props for URL → form hydration', function () {
    Listing::factory()->open()->count(2)->create();

    $response = $this->get('/listings?filter[stake_min]=10&sort=highest_stake&filter[time_control]=blitz');

    $response->assertInertia(fn ($page) => $page
        ->where('filters.stake_min', 10)
        ->where('filters.sort', 'highest_stake')
        ->where('filters.time_control', ['blitz'])
    );
});

test('game filter defaults to chess', function () {
    Listing::factory()->open()->count(3)->create();

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->where('filters.game', 'chess')
        ->has('listings.data', 3)
    );
});

test('unknown game in query redirects to clean /listings', function () {
    $this->get('/listings?filter[game]=fortnite')->assertRedirect('/listings');
});

test('paginator meta exposes current_page + last_page for the frontend', function () {
    Listing::factory()->open()->count(30)->create();

    $response = $this->get('/listings');

    // 30 listings / 12 per page = 3 pages
    $response->assertInertia(fn ($page) => $page
        ->where('listings.meta.current_page', 1)
        ->where('listings.meta.last_page', 3)
        ->where('listings.meta.per_page', 12)
        ->where('listings.meta.total', 30)
    );
});

test('page=2 returns the second slice', function () {
    Listing::factory()->open()->count(20)->create();

    $response = $this->get('/listings?page=2');

    $response->assertInertia(fn ($page) => $page
        ->where('listings.meta.current_page', 2)
        ->has('listings.data', 8)
    );
});

test('filters apply consistently across pages (filter narrows total + pagination)', function () {
    // 15 listings at $50 (matching), 10 at $500 (not matching).
    Listing::factory()->open()->count(15)->state(['stake_amount' => 50])->create();
    Listing::factory()->open()->count(10)->state(['stake_amount' => 500])->create();

    // stake_max=100 filter → 15 matches → 2 pages (12 + 3).
    $page1 = $this->get('/listings?filter[stake_max]=100');
    $page1->assertInertia(fn ($page) => $page
        ->where('listings.meta.total', 15)
        ->where('listings.meta.last_page', 2)
        ->has('listings.data', 12)
    );

    $page2 = $this->get('/listings?filter[stake_max]=100&page=2');
    $page2->assertInertia(fn ($page) => $page
        ->where('listings.meta.current_page', 2)
        ->has('listings.data', 3)
    );
});

test('invalid page param redirects to clean /listings (no error wall)', function () {
    $this->get('/listings?page=abc')->assertRedirect('/listings');
    $this->get('/listings?page=-1')->assertRedirect('/listings');
    $this->get('/listings?page=999999')->assertRedirect('/listings');
});
