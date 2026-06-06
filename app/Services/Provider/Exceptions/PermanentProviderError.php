<?php

namespace App\Services\Provider\Exceptions;

/**
 * Provider returned a 4xx other than 429 (auth, validation, gone) or the body
 * was malformed JSON. Retrying won't help — job fails terminally to `failed_jobs`.
 */
class PermanentProviderError extends ProviderError {}
