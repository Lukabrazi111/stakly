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

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ThrottleVerificationSend::class,
            HandleImpersonationExpiry::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
