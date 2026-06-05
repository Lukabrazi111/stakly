<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stricter rate limit on the verification-email resend endpoint.
 *
 * Fortify's default throttle on `verification.send` is 6/min. We tighten it to
 * 1/min per user — Stakly is a custodial money platform and inbox spam is a real
 * reputational risk to our mail sender. This runs before Fortify's controller.
 */
class ThrottleVerificationSend
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('POST')
            && $request->is('email/verification-notification')
        ) {
            $key = 'stakly-verify-send:'.($request->user()?->id ?: $request->ip());

            if (RateLimiter::tooManyAttempts($key, 1)) {
                abort(429, __('Please wait before requesting another verification email.'));
            }

            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
