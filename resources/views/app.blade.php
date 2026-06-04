<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Stakly is dark-only. Inline background prevents flash before app.css loads. --}}
        <style>
            html {
                background-color: #0a0710;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @php
            // Always-present structural head tags. Live OUTSIDE the
            // `<x-inertia::head>` slot because Inertia's SSR replaces the
            // slot's contents entirely with the page's React <Head> output
            // (anything inside the slot acts as a fallback used only when
            // SSR is off). The structural defaults below — site_name,
            // og:type=website, canonical/url, brand og:image, twitter:card
            // — are then always emitted regardless of SSR state.
            //
            // Per-page React <Head> (via the PageMeta component) overrides
            // og:title / og:description / og:image when needed. og:image
            // can end up with both this default and a page-specific
            // version; crawlers handle the duplicate gracefully (Facebook
            // accepts multiple og:image entries; Twitter uses the first).
            $appName = config('app.name', 'Stakly');
            $ogImage = asset('og-image.png');
            $canonical = request()->url();
        @endphp
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $ogImage }}">
        <x-inertia::head>
            {{-- Fallback content used ONLY when SSR is off. With SSR on
                 (the project default), Inertia's slot mechanism replaces
                 everything inside this block with the React <Head> output
                 from the current page. Keep these defaults sensible so
                 turning SSR off (e.g. for debugging) still emits a usable
                 title + description. --}}
            <title>{{ $appName }}</title>
            <meta name="description" content="Peer-to-peer chess staking marketplace. Post a listing, escrow your stake, play your opponent on chess.com or Lichess, and get paid when you win.">
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        @include('partials.impersonation-banner')
        <x-inertia::app />
    </body>
</html>
