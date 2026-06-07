<?php

namespace App\Http\Controllers;

use App\Actions\LinkedAccount\LinkFaceitAccountAction;
use App\Services\Provider\Exceptions\TransientProviderError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * OAuth dance for linking a FACEIT account (M15 Phase 2).
 *
 * `redirect()` is locale-prefixed (inside the `{locale}` group in
 * routes/web.php) so the user's current locale is captured in session
 * before bouncing to FACEIT's authorize endpoint.
 *
 * `callback()` is locale-agnostic (registered OUTSIDE the locale group)
 * because FACEIT only supports a single redirect URI per OAuth app — we
 * keep that URI stable across locales and restore the user's locale from
 * session before redirecting back to the linked-accounts settings page.
 */
class FaceitLinkController extends Controller
{
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $request->session()->put('faceit_oauth_locale', app()->getLocale());

        return Socialite::driver('faceit')->redirect();
    }

    public function callback(Request $request, LinkFaceitAccountAction $action): RedirectResponse
    {
        $locale = $request->session()->pull(
            'faceit_oauth_locale',
            config('stakly.default_locale', 'en'),
        );
        $editUrl = route('linked-accounts.edit', ['locale' => $locale]);

        // FACEIT returns `?error=access_denied` when the user declines consent
        // on the authorize page. No state to verify in that path — just bounce
        // them back with an info toast.
        if ($request->query('error')) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('FACEIT link cancelled.'),
            ]);

            return redirect($editUrl);
        }

        try {
            $oauthUser = Socialite::driver('faceit')->user();
        } catch (InvalidStateException) {
            Inertia::flash('toast', [
                'type' => 'destructive',
                'message' => __('FACEIT link session expired. Please try again.'),
            ]);

            return redirect($editUrl);
        }

        try {
            $result = $action->handle($request->user(), $oauthUser);
        } catch (TransientProviderError) {
            Inertia::flash('toast', [
                'type' => 'destructive',
                'message' => __('FACEIT is temporarily unavailable. Please try again in a moment.'),
            ]);

            return redirect($editUrl);
        }

        $toast = match ($result) {
            'linked' => ['type' => 'success', 'message' => __('FACEIT account linked.')],
            'profile-not-found' => ['type' => 'destructive', 'message' => __('We could not load your FACEIT profile. Please try again.')],
            'username-claimed' => ['type' => 'destructive', 'message' => __('That FACEIT account is already linked to another Stakly user.')],
        };

        Inertia::flash('toast', $toast);

        return redirect($editUrl);
    }
}
