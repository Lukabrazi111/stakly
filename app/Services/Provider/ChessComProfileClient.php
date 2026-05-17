<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for chess.com's Published Data API.
 *
 * Endpoint: `GET https://api.chess.com/pub/player/{username}` (no auth).
 * chess.com asks API consumers to send a User-Agent that identifies who
 * we are — set via `config('stakly.chess_com_user_agent')` so the contact
 * email lives in env, not in code.
 *
 * For M8 Phase 1 bio-code verification, the target field is `location`
 * (free-text user-editable field, less disruptive than clobbering the
 * `name` display name). chess.com omits the `location` key from the
 * response entirely when it's empty — we surface that as `null`.
 *
 * Used by `VerifyLinkedAccountAction`. `Http::fake()`-able from tests.
 */
class ChessComProfileClient implements ProfileClient
{
    public function fetchProfile(string $username): ProfileFetchResult
    {
        $url = "https://api.chess.com/pub/player/{$username}";

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('stakly.chess_com_user_agent', 'Stakly/1.0'),
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "chess.com unreachable for username '{$username}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            throw new ProfileNotFoundException("chess.com username '{$username}' not found.");
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                "chess.com returned status {$response->status()} for username '{$username}'.",
            );
        }

        $data = $response->json();

        return new ProfileFetchResult(
            username: $data['username'] ?? $username,
            bioFieldValue: $data['location'] ?? null,
        );
    }
}
