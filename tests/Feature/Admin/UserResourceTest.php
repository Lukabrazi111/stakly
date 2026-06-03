<?php

use App\Enums\ListingStatus;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Listing;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M30 Phase 1 — `UserResource` index + view page. Covers access gates,
 * `is_platform` hidden, search + filters + sort.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// ─── Access gates ──────────────────────────────────────────────────────────

test('non-admin cannot reach the users resource', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/admin/users')
        ->assertForbidden();
});

test('guest is redirected to admin login', function () {
    auth()->logout();

    $this->get('/admin/users')
        ->assertRedirect('/admin/login');
});

test('admin can list users', function () {
    $alice = User::factory()->create(['username' => 'alice-test']);
    $bob = User::factory()->create(['username' => 'bob-test']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$alice, $bob]);
});

test('admin can view a user', function () {
    $target = User::factory()->create(['username' => 'target-user']);

    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->assertOk();
});

// ─── is_platform hidden ────────────────────────────────────────────────────

test('platform user is hidden from the index', function () {
    $platform = User::factory()->create([
        'is_platform' => true,
        'username' => 'stakly-platform',
    ]);
    $regular = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$regular])
        ->assertCanNotSeeTableRecords([$platform]);
});

// ─── Search ────────────────────────────────────────────────────────────────

test('search hits username', function () {
    $target = User::factory()->create(['username' => 'searchable-target']);
    $other = User::factory()->create(['username' => 'other-handle']);

    Livewire::test(ListUsers::class)
        ->searchTable('searchable-target')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('search hits email', function () {
    $target = User::factory()->create(['email' => 'find-me@stakly.test']);
    $other = User::factory()->create(['email' => 'other-person@stakly.test']);

    Livewire::test(ListUsers::class)
        ->searchTable('find-me@stakly.test')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('search hits name', function () {
    $target = User::factory()->create(['name' => 'Distinctive Searchname']);
    $other = User::factory()->create(['name' => 'Different Person']);

    Livewire::test(ListUsers::class)
        ->searchTable('Distinctive Searchname')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

// ─── Filters ───────────────────────────────────────────────────────────────

test('verified filter narrows to verified users', function () {
    $verified = User::factory()->create(['email_verified_at' => now()]);
    $unverified = User::factory()->unverified()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('email_verified_at', true)
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords([$unverified]);
});

test('banned filter narrows to banned users', function () {
    $banned = User::factory()->create(['banned_at' => now()]);
    $active = User::factory()->create(['banned_at' => null]);

    Livewire::test(ListUsers::class)
        ->filterTable('banned_at', true)
        ->assertCanSeeTableRecords([$banned])
        ->assertCanNotSeeTableRecords([$active]);
});

test('has-2FA filter narrows to enrolled users', function () {
    $enrolled = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $unenrolled = User::factory()->create(['two_factor_confirmed_at' => null]);

    Livewire::test(ListUsers::class)
        ->filterTable('two_factor_confirmed_at', true)
        ->assertCanSeeTableRecords([$enrolled])
        ->assertCanNotSeeTableRecords([$unenrolled]);
});

test('has-active-listing filter narrows to users with an open listing', function () {
    $withListing = User::factory()->create();
    Listing::factory()->create([
        'user_id' => $withListing->id,
        'status' => ListingStatus::Open,
    ]);

    $withoutListing = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('has_active_listing', true)
        ->assertCanSeeTableRecords([$withListing])
        ->assertCanNotSeeTableRecords([$withoutListing]);
});

// ─── Sort ──────────────────────────────────────────────────────────────────

test('default sort is newest registrations first', function () {
    $oldest = User::factory()->create(['created_at' => now()->subDays(3)]);
    $middle = User::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = User::factory()->create(['created_at' => now()->subDay()]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

// ─── Resource hardening ────────────────────────────────────────────────────

test('admin cannot reach a create form for users', function () {
    $this->get('/admin/users/create')
        ->assertNotFound();
});
