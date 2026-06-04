<?php

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Listing;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M32 Phase 1 — read-only listings Filament resource. Covers access gates,
 * read-only posture (no create / edit / delete), listing behavior, default
 * sort, and the six filters (status / platform / creator / stake range /
 * region / language contains).
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// ─── Access gates ──────────────────────────────────────────────────────────

test('non-admin cannot reach the listings resource', function () {
    actingAs(User::factory()->create())
        ->get('/admin/listings')
        ->assertForbidden();
});

test('guest is redirected to admin login', function () {
    auth()->logout();

    $this->get('/admin/listings')
        ->assertRedirect('/admin/login');
});

test('admin can list listings', function () {
    $a = Listing::factory()->open()->create();
    $b = Listing::factory()->taken()->create();

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$a, $b]);
});

// ─── Read-only posture ─────────────────────────────────────────────────────

test('resource disables create / edit / delete', function () {
    $row = Listing::factory()->create();

    expect(ListingResource::canCreate())->toBeFalse();
    expect(ListingResource::canEdit($row))->toBeFalse();
    expect(ListingResource::canDelete($row))->toBeFalse();
});

// ─── Filters ───────────────────────────────────────────────────────────────

test('status filter narrows to the selected statuses', function () {
    $open = Listing::factory()->open()->create();
    $cancelled = Listing::factory()->cancelled()->create();

    Livewire::test(ListListings::class)
        ->filterTable('status', [ListingStatus::Open->value])
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$cancelled]);
});

test('platform filter narrows to the selected platform', function () {
    $lichess = Listing::factory()->forLichess()->create();
    $chesscom = Listing::factory()->forChessCom()->create();

    Livewire::test(ListListings::class)
        ->filterTable('platform', 'lichess')
        ->assertCanSeeTableRecords([$lichess])
        ->assertCanNotSeeTableRecords([$chesscom]);
});

test('creator filter narrows to the selected user', function () {
    $alice = User::factory()->create(['username' => 'alice-listings']);
    $bob = User::factory()->create(['username' => 'bob-listings']);
    $aliceListing = Listing::factory()->for($alice)->create();
    $bobListing = Listing::factory()->for($bob)->create();

    Livewire::test(ListListings::class)
        ->filterTable('user_id', $alice->id)
        ->assertCanSeeTableRecords([$aliceListing])
        ->assertCanNotSeeTableRecords([$bobListing]);
});

test('stake range filter narrows by stake_amount', function () {
    $small = Listing::factory()->create(['stake_amount' => 10]);
    $big = Listing::factory()->create(['stake_amount' => 500]);

    Livewire::test(ListListings::class)
        ->filterTable('stake_amount', ['min' => 100])
        ->assertCanSeeTableRecords([$big])
        ->assertCanNotSeeTableRecords([$small]);
});

test('region filter narrows by region', function () {
    $eu = Listing::factory()->create(['region' => 'EU']);
    $na = Listing::factory()->create(['region' => 'NA']);

    Livewire::test(ListListings::class)
        ->filterTable('region', 'EU')
        ->assertCanSeeTableRecords([$eu])
        ->assertCanNotSeeTableRecords([$na]);
});

test('language contains filter narrows by language code', function () {
    $multi = Listing::factory()->create(['language' => ['en', 'ru']]);
    $other = Listing::factory()->create(['language' => ['de']]);

    Livewire::test(ListListings::class)
        ->filterTable('language', ['contains' => 'ru'])
        ->assertCanSeeTableRecords([$multi])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Sort ──────────────────────────────────────────────────────────────────

test('default sort is newest first', function () {
    $old = Listing::factory()->create(['created_at' => now()->subDays(5)]);
    $new = Listing::factory()->create(['created_at' => now()]);

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true);
});
