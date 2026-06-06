<?php

namespace App\Services\Provider\Exceptions;

use RuntimeException;

/**
 * Base for provider HTTP failures from the Lichess / chess.com clients.
 * Subclasses encode retry semantics so Slice 2b's job middleware can branch
 * without re-parsing status codes.
 */
abstract class ProviderError extends RuntimeException {}
