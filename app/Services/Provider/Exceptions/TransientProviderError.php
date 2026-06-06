<?php

namespace App\Services\Provider\Exceptions;

/**
 * Provider returned a 5xx, the connection failed, or the request timed out.
 * Retried with exponential backoff (Slice 2b).
 */
class TransientProviderError extends ProviderError {}
