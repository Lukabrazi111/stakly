<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming FACEIT webhook deliveries (M15 P4 Slice 4).
 *
 * Defense-in-depth posture: FACEIT webhook auth is a static shared secret
 * with NO HMAC and no body signature (verified via Context7 + Phase 0
 * research). A leaked secret is full impersonation at the receiver layer —
 * but the webhook is never trusted as proof of outcome. The dispatched
 * `AutoFetchFaceitGameJob` re-fetches via the Data API and runs the AC +
 * opposing-roster + winner-resolution checks before settlement. A forged
 * webhook can burn FACEIT API quota at most; it cannot pay a wrong winner.
 *
 * Layers protecting the endpoint:
 *   1. Shared-secret header check (this middleware) — constant-time `hash_equals`.
 *   2. Per-IP `throttle:60,1` on the route — bounds spam from a leaked secret.
 *   3. IP allowlist (planned — pending FACEIT support reply with egress IPs).
 *   4. Job-level outcome re-verification — the source of truth.
 */
class VerifyFaceitWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.faceit.webhook_secret');

        if ($expected === '') {
            // Refuse rather than silently accepting unauthenticated webhooks.
            // Producing a 5xx here so FACEIT's retry behavior (assumed —
            // confirm via support) kicks in once the secret is configured.
            Log::warning('FACEIT webhook received but no shared secret configured', [
                'ip' => $request->ip(),
            ]);

            abort(503, 'Webhook receiver not configured.');
        }

        $provided = (string) $request->header('X-Faceit-Webhook-Secret', '');

        if (! hash_equals($expected, $provided)) {
            Log::warning('FACEIT webhook rejected — shared secret mismatch', [
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid webhook signature.');
        }

        // TODO (Slice 4 post-answer): once `services.faceit.webhook_egress_ips`
        // is populated from FACEIT support's reply, add an IP allowlist check
        // here as the second authentication layer.

        return $next($request);
    }
}
