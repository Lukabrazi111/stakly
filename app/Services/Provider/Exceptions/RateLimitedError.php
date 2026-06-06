<?php

namespace App\Services\Provider\Exceptions;

use Carbon\CarbonInterface;
use Throwable;

/**
 * Provider returned 429. `$retryAt` carries the provider-supplied "try after"
 * timestamp parsed from `Retry-After` / `X-RateLimit-Reset` (Slice 2c). Null
 * = no schedule in the response; callers fall back to bounded retry.
 */
class RateLimitedError extends ProviderError
{
    public function __construct(
        string $message = '',
        private readonly ?CarbonInterface $retryAt = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryAt(): ?CarbonInterface
    {
        return $this->retryAt;
    }
}
