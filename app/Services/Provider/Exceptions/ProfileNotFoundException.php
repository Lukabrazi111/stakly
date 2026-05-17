<?php

namespace App\Services\Provider\Exceptions;

use RuntimeException;

/**
 * Thrown when the external provider responds 404 for the requested username
 * (account doesn't exist). Callers translate this to a user-facing "we
 * couldn't find that username on chess.com / Lichess" message.
 */
class ProfileNotFoundException extends RuntimeException {}
