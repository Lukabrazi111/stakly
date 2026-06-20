<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\TransientProviderError;

/**
 * Shared contract for profile fetches. Implementations MUST translate
 * transport-level failures into `ProfileNotFoundException` (404) or
 * `TransientProviderError` (5xx / network / timeout — profile flow doesn't
 * currently differentiate further; that's M14 Slice 2a Game-clients-only).
 * Anything else is a real bug and should bubble.
 */
interface ProfileClient
{
    /**
     * @throws ProfileNotFoundException
     * @throws TransientProviderError
     */
    public function fetchProfile(string $username): ProfileFetchResult;
}
