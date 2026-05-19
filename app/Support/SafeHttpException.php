<?php

namespace App\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Thrown by `SafeHttpClient` when SSRF checks reject a URL or the inner
 * transport fails. Implements PSR-18's marker interface so the embed
 * library's `try/catch` paths catch it cleanly.
 */
class SafeHttpException extends RuntimeException implements ClientExceptionInterface {}
