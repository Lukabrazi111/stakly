<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderUnavailableException;

/**
 * Shared contract for profile fetches. Implementations MUST translate
 * transport-level failures into `ProfileNotFoundException` (404) or
 * `ProviderUnavailableException` (5xx / network / timeout). Anything else
 * is a real bug and should bubble.
 */
interface ProfileClient
{
    /**
     * @throws ProfileNotFoundException
     * @throws ProviderUnavailableException
     */
    public function fetchProfile(string $username): ProfileFetchResult;
}
