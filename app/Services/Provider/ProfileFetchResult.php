<?php

namespace App\Services\Provider;

/**
 * `$bioFieldValue` holds the bio-code verification target — chess.com
 * `location`, Lichess `profile.bio`. Null when the user has never set it.
 *
 * `$username` is the provider's canonical username — used to cross-check
 * casing (user typed "Alice" but chess.com canonical is "alice").
 */
final readonly class ProfileFetchResult
{
    public function __construct(
        public string $username,
        public ?string $bioFieldValue,
    ) {}
}
