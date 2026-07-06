<?php

use App\Enums\AutoFetchOutcome;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Filament\Resources\GameMatches\Pages\ViewGameMatch;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M42 — the admin panel must survive the multi-game (FACEIT / Steam providers,
 * Cs2 / Dota2 games) + AcIncomplete growth. Each test below would have thrown
 * an UnhandledMatchError (a 500) before the M42 fixes — they're the regression
 * net for the non-exhaustive match() crashes the audit found.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

test('listings index renders FACEIT (CS2 + Dota2) and a legacy Steam listing', function () {
    $cs2 = Listing::factory()->create([
        'game' => Game::Cs2,
        'platform' => LinkedAccountProvider::Faceit,
        'team_size' => 2,
        'status' => ListingStatus::Open,
    ]);
    // Dota 2 verifies via FACEIT as of 2026-07-06 (was Steam).
    $dota = Listing::factory()->create([
        'game' => Game::Dota2,
        'platform' => LinkedAccountProvider::Faceit,
        'status' => ListingStatus::Open,
    ]);
    // Steam is now a vestigial LinkedAccountProvider case — no game requires it —
    // but the enum arm + the table's platform formatStateUsing still handle it.
    // Keep a Steam row so a legacy listing can't reintroduce the non-exhaustive
    // match() 500 the M42 audit fixed.
    $legacySteam = Listing::factory()->create([
        'game' => Game::Dota2,
        'platform' => LinkedAccountProvider::Steam,
        'status' => ListingStatus::Open,
    ]);

    // Forces the table body (incl. the platform column's formatStateUsing) to
    // render — the exact path that 500'd on a FACEIT/Steam enum case.
    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$cs2, $dota, $legacySteam]);
});

test('platform filter offers FACEIT, not just chess providers', function () {
    $cs2 = Listing::factory()->create([
        'game' => Game::Cs2,
        'platform' => LinkedAccountProvider::Faceit,
        'team_size' => 2,
    ]);
    $chess = Listing::factory()->forChessCom()->create();

    Livewire::test(ListListings::class)
        ->filterTable('platform', LinkedAccountProvider::Faceit->value)
        ->assertCanSeeTableRecords([$cs2])
        ->assertCanNotSeeTableRecords([$chess]);
});

test('admin can view a FACEIT (CS2) listing', function () {
    $listing = Listing::factory()->create([
        'game' => Game::Cs2,
        'platform' => LinkedAccountProvider::Faceit,
        'team_size' => 2,
        'status' => ListingStatus::Open,
    ]);

    Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
        ->assertOk()
        ->assertSee('FACEIT');
});

test('dispute view renders with an AC-incomplete auto-fetch attempt', function () {
    [, , , $match] = pendingMatch();

    MatchAutoFetchAttempt::factory()->create([
        'match_id' => $match->id,
        'provider' => LinkedAccountProvider::Faceit,
        'outcome' => AutoFetchOutcome::AcIncomplete,
        'latency_ms' => 88,
    ]);

    Livewire::test(ViewGameMatch::class, ['record' => $match->getKey()])
        ->assertSuccessful()
        ->assertSee('AC incomplete', escape: false);
});

test('1v1 force-cancel is hidden on a team lobby; the team force-cancel shows instead', function () {
    $team = Listing::factory()->teamPlay()->create(['status' => ListingStatus::Open]);

    Livewire::test(ViewListing::class, ['record' => $team->getRouteKey()])
        ->assertActionHidden('force_cancel')
        ->assertActionVisible('force_cancel_team');
});

test('team force-cancel is hidden on a 1v1 listing; the 1v1 force-cancel shows', function () {
    $solo = Listing::factory()->open()->create();

    Livewire::test(ViewListing::class, ['record' => $solo->getRouteKey()])
        ->assertActionVisible('force_cancel')
        ->assertActionHidden('force_cancel_team');
});
