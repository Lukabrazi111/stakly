<?php

namespace App\Filament\Widgets;

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\ProviderCircuitBreaker;
use Filament\Widgets\Widget;

/**
 * M14 Slice 2d — dashboard banner that renders only when a provider's
 * circuit breaker has tripped. `canView()` short-circuits when all chess
 * providers are closed so the dashboard isn't cluttered by an always-on
 * "all healthy" row.
 *
 * Limited to chess providers (Lichess + chess.com) — the only ones with
 * shipped auto-fetch jobs today. When M15 ships per-game adapters with
 * their own breakers, this list extends.
 */
class ProviderCircuitBanner extends Widget
{
    protected string $view = 'filament.widgets.provider-circuit-banner';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    /**
     * Providers whose breaker state we surface here. Hardcoded chess
     * providers to match the shipped auto-fetch pipeline; widens with M15.
     *
     * @var list<LinkedAccountProvider>
     */
    private const TRACKED_PROVIDERS = [
        LinkedAccountProvider::Lichess,
        LinkedAccountProvider::ChessCom,
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
