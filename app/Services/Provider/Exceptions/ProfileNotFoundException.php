<?php

namespace App\Services\Provider\Exceptions;

use RuntimeException;

/**
 * Provider returned 404 — username doesn't exist. Callers translate to a
 * user-facing "we couldn't find that username" message.
 */
class ProfileNotFoundException extends RuntimeException {}
