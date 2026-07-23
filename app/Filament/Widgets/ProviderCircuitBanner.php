<?php

namespace App\Filament\Widgets;

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\ProviderCircuitBreaker;
use Filament\Widgets\Widget;

/**
 * M14 Slice 2d — dashboard banner that renders only when a provider's
 * circuit breaker has tripped. `canView()` short-circuits when all tracked
 * providers are closed so the dashboard isn't cluttered by an always-on
 * "all healthy" row.
 *
 * Tracks every provider with a shipped auto-fetch job — chess.com, Lichess,
 * and FACEIT (CS2). A tripped breaker on any of these freezes settlement for
 * that provider's Pending matches, so all three must be visible here. Steam
 * is deliberately excluded: it has no auto-fetch job and therefore no breaker
 * traffic to surface.
 */
class ProviderCircuitBanner extends Widget
{
    protected string $view = 'filament.widgets.provider-circuit-banner';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    /**
     * Providers whose breaker state we surface here — one entry per provider
     * backed by an `AutoFetch*GameJob` (the work a tripped breaker freezes).
     * Steam is excluded because it has no auto-fetch job. Keep this in sync
     * with the auto-fetch pipeline when a new provider's job ships.
     *
     * @var list<LinkedAccountProvider>
     */
    private const TRACKED_PROVIDERS = [
        LinkedAccountProvider::ChessCom,
        LinkedAccountProvider::Lichess,
        LinkedAccountProvider::Faceit,
    ];

    public static function canView(): bool
    {
        $breaker = app(ProviderCircuitBreaker::class);

        foreach (self::TRACKED_PROVIDERS as $provider) {
            if ($breaker->isOpen($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $breaker = app(ProviderCircuitBreaker::class);
        $open = [];

        foreach (self::TRACKED_PROVIDERS as $provider) {
            if (! $breaker->isOpen($provider)) {
                continue;
            }

            $openUntil = $breaker->openUntil($provider);
            $open[] = [
                'name' => $provider->displayName(),
                'openUntil' => $openUntil,
                'remainingSeconds' => $openUntil
                    ? max(0, $openUntil->getTimestamp() - now()->getTimestamp())
                    : 0,
            ];
        }

        return ['openProviders' => $open];
    }
}
