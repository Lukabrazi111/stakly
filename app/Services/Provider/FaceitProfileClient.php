<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\TransientProviderError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for FACEIT's Data API (M15 Phase 2).
 *
 * Endpoint: `GET https://open.faceit.com/data/v4/players/{player_id}`.
 * Auth: server-side API key from `config('services.faceit.api_key')` via
 * `Authorization: Bearer {key}` header. FACEIT's OAuth user tokens are
 * scoped to identity (openid) and do NOT grant Data API access — those
 * requests come back 403. The Phase 0 research initially claimed OAuth
 * tokens worked here; production behaviour proved otherwise.
 *
 * For Phase 2 we only need ELO + skill level for CS2 — the response also
 * carries other game blocks (`games.dota2`, `games.csgo`, etc.) but
 * they're not surfaced into the `FaceitProfile` value object until the
 * adapter for that game lands.
 *
 * Used by `LinkFaceitAccountAction`. `Http::fake()`-able from tests.
 * Returns `null` if no API key is configured — useful in dev when the
 * key hasn't been set yet; the link still creates the LinkedAccount
 * row, just with `skill_rating = null`.
 */
class FaceitProfileClient
{
    public function fetchPlayer(string $playerId): ?FaceitProfile
    {
        $apiKey = config('services.faceit.api_key');

        if (empty($apiKey)) {
            return null;
        }

        $url = "https://open.faceit.com/data/v4/players/{$playerId}";

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new TransientProviderError(
                "FACEIT unreachable for player '{$playerId}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            throw new ProfileNotFoundException("FACEIT player '{$playerId}' not found.");
        }

        if (! $response->successful()) {
            throw new TransientProviderError(
                "FACEIT returned status {$response->status()} for player '{$playerId}'.",
            );
        }

        $data = $response->json();

        return new FaceitProfile(
            playerId: $data['player_id'] ?? $playerId,
            nickname: $data['nickname'] ?? '',
            cs2Elo: $data['games']['cs2']['faceit_elo'] ?? null,
            cs2SkillLevel: $data['games']['cs2']['skill_level'] ?? null,
        );
    }
}
