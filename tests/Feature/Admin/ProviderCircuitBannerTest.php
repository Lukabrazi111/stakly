<?php

use App\Enums\LinkedAccountProvider;
use App\Filament\Widgets\ProviderCircuitBanner;
use App\Models\User;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
});

test('canView returns false when no provider circuit is open', function () {
    expect(ProviderCircuitBanner::canView())->toBeFalse();
});

test('canView returns true when Lichess circuit is open', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(ProviderCircuitBanner::canView())->toBeTrue();
});

test('canView returns true when chess.com circuit is open', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure(LinkedAccountProvider::ChessCom);
    }

    expect(ProviderCircuitBanner::canView())->toBeTrue();
});

test('widget renders the open provider list when at least one is tripped', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure(LinkedAccountProvider::Lichess);
    }

    actingAs($this->admin);

    Livewire::test(ProviderCircuitBanner::class)
        ->assertSee('Provider circuit open')
        ->assertSee(LinkedAccountProvider::Lichess->displayName());
});
