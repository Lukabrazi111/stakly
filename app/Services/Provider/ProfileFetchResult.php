<?php

namespace App\Services\Provider;

/**
 * Result of `ChessComProfileClient::fetchProfile` / `LichessProfileClient::fetchProfile`.
 *
 * `$bioFieldValue` is the value of the target field for bio-code verification —
 * chess.com `location`, Lichess `profile.bio`. May be `null` if the user has
 * never set it (chess.com omits empty optional fields; Lichess returns null).
 *
 * `$username` is the canonical username as the provider returns it — used to
 * cross-check casing (e.g. user typed "Alice" but chess.com canonical is "alice").
 */
final readonly class ProfileFetchResult
{
    public function __construct(
        public string $username,
        public ?string $bioFieldValue,
    ) {}
}
