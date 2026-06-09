<?php

use App\Http\Middleware\HandleImpersonationExpiry;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectUnprefixedLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleVerificationSend;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `stakly_locale` stays plaintext so a JS LocaleSwitcher (Slice C) can
        // read it without round-tripping Inertia props. Value is a 2-char code,
        // no security implication if tampered with — server re-validates
        // against `config('stakly.locales')` in SetLocale anyway.
        $middleware->encryptCookies(except: ['sidebar_state', SetLocale::COOKIE_NAME]);

        // Registered GLOBALLY (not in the web group): unprefixed paths like
        // `/listings` don't match any route, so the framework 404s before
        // any group middleware runs. Global middleware fires on every
        // request regardless of route match, so the 301 redirect lands
        // before the router gives up.
        $middleware->prepend(RedirectUnprefixedLocale::class);

        // Trust X-Forwarded-* headers from any proxy. Required so Laravel
        // detects HTTPS correctly when running behind an HTTPS-terminating
        // proxy (ngrok in dev, load balancer in prod). Without this, secure
        // cookies + URL scheme detection break behind the tunnel. Tighten
        // to a specific IP/CIDR list once a real prod proxy is in place.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ThrottleVerificationSend::class,
            HandleImpersonationExpiry::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render 403 / 404 / 500 / 503 as Inertia pages so they keep the
        // Stakly chrome (header, footer, dark theme, locale switcher). 419
        // (CSRF) stays on Inertia's default "session expired" toast — a
        // full page would be the wrong UX for an in-flight form expiry.
        // Validation (422) is left untouched: Inertia renders field errors
        // inline. Debug mode also bypasses this so Whoops still works.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (app()->environment('local') && config('app.debug')) {
                return $response;
            }

            if (! $request->header('X-Inertia') && ! $request->wantsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (in_array($status, [403, 404, 500, 503], true)) {
                return Inertia::render('errors/error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
