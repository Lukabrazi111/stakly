<?php

use App\Enums\GameStatus;
use App\Filament\Resources\Games\Pages\ManageGames;
use App\Models\Game;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M42 — the Games "Active" gate requires a wired settlement adapter
 * (`Game::hasArbitrationDriver()`), not just enum membership. Stops an admin
 * advertising an adapter-less game (Dota2) as live + playable on the homepage.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

test('cannot set an adapter-less game (dota2) to Active', function () {
    Livewire::test(ManageGames::class)
        ->callAction('create', data: [
            'display_name' => 'Dota 2',
            'slug' => 'dota2',
            'status' => GameStatus::Active->value,
        ])
        ->assertHasActionErrors(['slug']);

    expect(Game::query()->where('slug', 'dota2')->exists())->toBeFalse();
});

test('cannot set a non-enum game (valorant) to Active', function () {
    Livewire::test(ManageGames::class)
        ->callAction('create', data: [
            'display_name' => 'Valorant',
            'slug' => 'valorant',
            'status' => GameStatus::Active->value,
        ])
        ->assertHasActionErrors(['slug']);
});

test('can set CS2 (has a settlement adapter) to Active', function () {
    Livewire::test(ManageGames::class)
        ->callAction('create', data: [
            'display_name' => 'CS2',
            'slug' => 'cs2',
            'status' => GameStatus::Active->value,
        ])
        ->assertHasNoActionErrors(['slug']);

    expect(Game::query()->where('slug', 'cs2')->where('status', GameStatus::Active)->exists())->toBeTrue();
});

test('adapter-less game can still be saved as Coming soon', function () {
    Livewire::test(ManageGames::class)
        ->callAction('create', data: [
            'display_name' => 'Dota 2',
            'slug' => 'dota2',
            'status' => GameStatus::ComingSoon->value,
        ])
        ->assertHasNoActionErrors(['slug']);

    expect(Game::query()->where('slug', 'dota2')->where('status', GameStatus::ComingSoon)->exists())->toBeTrue();
});
