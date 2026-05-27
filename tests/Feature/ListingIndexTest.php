<?php

use App\Enums\TimeControl;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    Listing::factory()->open()->state(['time_control' => [TimeControl::Blitz->value]])->create();
    Listing::factory()->open()->state(['time_control' => [TimeControl::Rapid->value]])->create();
    Listing::factory()->open()->state(['time_control' => [TimeControl::Classical->value]])->create();

    $response = $this->get('/listings?filter[time_control]=blitz,rapid');

    $response->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

test('time_control filter matches listings offering any of the requested controls', function () {
    // Listing offering both Blitz and Rapid — should match either filter alone or both.
    Listing::factory()->open()->state(['time_control' => [TimeControl::Blitz->value, TimeControl::Rapid->value]])->create();
    Listing::factory()->open()->state(['time_control' => [TimeControl::Classical->value]])->create();

    $this->get('/listings?filter[time_control]=blitz')
        ->assertInertia(fn ($page) => $page->has('listings.data', 1));

    $this->get('/listings?filter[time_control]=rapid')
        ->assertInertia(fn ($page) => $page->has('listings.data', 1));

    $this->get('/listings?filter[time_control]=classical')
        ->assertInertia(fn ($page) => $page->has('listings.data', 1));
});

test('language filter matches listings offering that language OR no restriction', function () {
    Listing::factory()->open()->state(['language' => ['Russian']])->create();
    Listing::factory()->open()->state(['language' => ['English', 'Spanish']])->create();
    Listing::factory()->open()->state(['language' => null])->create();
    Listing::factory()->open()->state(['language' => ['German']])->create();

    // Russian filter → matches the Russian-only listing + the null (any-language) listing.
    $this->get('/listings?filter[language]=Russian')
        ->assertInertia(fn ($page) => $page->has('listings.data', 2));

    // English filter → matches the English/Spanish listing + the null listing.
    $this->get('/listings?filter[language]=English')
        ->assertInertia(fn ($page) => $page->has('listings.data', 2));
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
    $user = User::factory()->active()->create([
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

test('creator carries avatar_thumb_url for the marketplace cards (M18)', function () {
    $user = User::factory()->active()->create();
    Listing::factory()->open()->for($user)->create();

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page
            ->has('listings.data.0.creator', fn ($creator) => $creator
                ->where('avatar_thumb_url', null)
                ->etc()
            )
        );
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

// ─── Active Mode visibility (M6 Phase 6.5) ────────────────────────────────

test('listings of an inactive owner are hidden from the public marketplace', function () {
    // `scopeOnPublicMarketplace` filters by `user.is_active_mode = true`.
    // When the owner toggles Inactive on /listings/mine, the listings stay
    // Open in the DB (so escrow is preserved) but they vanish from the
    // public board.
    $active = User::factory()->active()->create();
    $inactive = User::factory()->inactive()->create();

    Listing::factory()->open()->for($active)->count(2)->create();
    Listing::factory()->open()->for($inactive)->count(3)->create();

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

test('flipping the owner back to active republishes their listings to the marketplace', function () {
    // Closed-loop check: the visibility is a live filter (no cache, no
    // status change), so toggling Inactive then Active immediately re-exposes
    // every Open listing the user holds.
    $owner = User::factory()->inactive()->create();
    Listing::factory()->open()->for($owner)->count(2)->create();

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page->has('listings.data', 0));

    $owner->update(['is_active_mode' => true]);

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page->has('listings.data', 2));
});

// ─── Seller trust signals on the row (M22 Phase 1) ────────────────────────

test('creator payload exposes completion_rate_30d + settled_lifetime', function () {
    $creator = User::factory()->active()->create();
    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->has('listings.data.0.creator.completion_rate_30d')
        ->has('listings.data.0.creator.settled_lifetime')
    );
});

test('creator with no match history shows settled_lifetime=0 + null rate', function () {
    // Fresh user, no matches — chip should hide on the frontend, but the
    // backend payload must still be well-formed.
    $creator = User::factory()->active()->create();
    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->where('listings.data.0.creator.settled_lifetime', 0)
        ->where('listings.data.0.creator.completion_rate_30d', null)
    );
});

test('completion_rate_30d reflects settled matches in the 30-day window', function () {
    $creator = User::factory()->active()->create();

    // Three settled wins inside the 30-day window for `$creator` as creator.
    foreach (range(1, 3) as $_) {
        $opponent = User::factory()->create();
        $listing = Listing::factory()->taken()->for($creator)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($opponent, 'taker')
            ->settled($creator)
            ->create();
    }

    // The open listing under test (separate from the historical ones).
    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page
            ->where('listings.data.0.creator.completion_rate_30d', 100)
            ->where('listings.data.0.creator.settled_lifetime', 3)
        );
});

test('3-free cancellation buffer matches the UserController formula', function () {
    // Mirror of the `3-free buffer absorbs` scenario in UserShowTest —
    // proves the listings-index trust calculation stays in sync with the
    // profile page when the formula changes.
    $creator = User::factory()->active()->create();

    // 5 settled matches.
    foreach (range(1, 5) as $_) {
        $opponent = User::factory()->create();
        $listing = Listing::factory()->taken()->for($creator)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($opponent, 'taker')
            ->settled($creator)
            ->create();
    }

    // 3 cancellations initiated by the creator — all absorbed by the buffer.
    foreach (range(1, 3) as $_) {
        $opponent = User::factory()->create();
        $listing = Listing::factory()->cancelled()->for($creator)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($opponent, 'taker')
            ->cancelled($creator)
            ->create();
    }

    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')
        ->assertInertia(fn ($page) => $page
            ->where('listings.data.0.creator.completion_rate_30d', 100)
            ->where('listings.data.0.creator.settled_lifetime', 5)
        );
});

test('creator carries verified_providers list for the cross-platform badge', function () {
    $creator = User::factory()->active()->withChessCom()->withLichess()->create();
    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->where('listings.data.0.creator.verified_providers', function ($providers) {
            // Inertia's `where` callback passes the value as a Collection
            // when the underlying prop is an array. Set semantics — order
            // depends on the linked-accounts insert order, just check
            // contents.
            $values = collect($providers)->values()->all();

            return count($values) === 2
                && in_array('chess_com', $values, true)
                && in_array('lichess', $values, true);
        })
    );
});

test('creator with only one linked provider lists just that one', function () {
    $creator = User::factory()->active()->withChessCom()->create();
    Listing::factory()->open()->for($creator)->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->where('listings.data.0.creator.verified_providers', ['chess_com'])
    );
});

test('seller trust does NOT N+1 — one aggregation query regardless of listing count', function () {
    // 20 distinct creators, each with one open listing — would N+1 to 20
    // separate trust queries without batching. Page size is 12 so /listings
    // returns at most 12 listings; we just need enough unique creators on
    // the same page to detect the N+1.
    User::factory()
        ->count(12)
        ->active()
        ->create()
        ->each(fn (User $u) => Listing::factory()->open()->for($u)->create());

    DB::enableQueryLog();
    $this->get('/listings')->assertOk();
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // The trust aggregation is a single query that joins `game_matches` and
    // `listings` and reads aggregation columns from both. Anchored by
    // detecting the join shape that's unique to the SellerTrust helper —
    // a SELECT against `game_matches` that joins `listings` and reads
    // `creator_id` / `taker_user_id` / `settled_at` / `cancelled_at`.
    $trustQueries = $queries->filter(function ($q) {
        $sql = strtolower($q['query']);

        return str_contains($sql, 'from "game_matches"')
            && str_contains($sql, 'join "listings"')
            && str_contains($sql, 'creator_id');
    });

    expect($trustQueries)->toHaveCount(1);
});
