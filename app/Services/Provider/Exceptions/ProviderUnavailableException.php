<?php

namespace App\Services\Provider\Exceptions;

use RuntimeException;

/**
 * Provider API failure (5xx, connection error, timeout). Distinct from
 * `ProfileNotFoundException`: unavailable means "try again later." Callers
 * must NOT mark a match `ManualReview` on this — transient failure.
 */
class ProviderUnavailableException extends RuntimeException {}
