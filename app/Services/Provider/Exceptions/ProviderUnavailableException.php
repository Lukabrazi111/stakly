<?php

namespace App\Services\Provider\Exceptions;

use RuntimeException;

/**
 * Thrown when the external provider's API itself fails (5xx response,
 * connection error, timeout). Distinct from `ProfileNotFoundException`
 * (which is a 404 — username doesn't exist there): unavailable means
 * "try again later," not found means "that username isn't real."
 *
 * Callers should NOT mark a match `ManualReview` on this — it's a transient
 * failure. M12's admin dispute path handles the user-facing fallback.
 */
class ProviderUnavailableException extends RuntimeException {}
