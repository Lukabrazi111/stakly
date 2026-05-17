<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderUnavailableException;

/**
 * Shared contract for chess.com / Lichess profile fetches. Future game
 * adapters (Dota 2 OpenDota, FACEIT, etc.) layer on the same shape when
 * they land (M14).
 *
 * Implementations MUST translate transport-level failures into
 * `ProfileNotFoundException` (404 — username doesn't exist) or
 * `ProviderUnavailableException` (5xx / network / timeout — try later).
 * Anything else is a real bug and should bubble.
 */
interface ProfileClient
{
    /**
     * @throws ProfileNotFoundException
     * @throws ProviderUnavailableException
     */
    public function fetchProfile(string $username): ProfileFetchResult;
}
