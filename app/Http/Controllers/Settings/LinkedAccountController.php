<?php

namespace App\Http\Controllers\Settings;

use App\Actions\LinkedAccount\RequestLinkVerificationAction;
use App\Actions\LinkedAccount\VerifyLinkedAccountAction;
use App\Enums\LinkedAccountProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestLinkVerificationRequest;
use App\Services\Provider\Exceptions\ProviderError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings page for chess.com / Lichess bio-code account linking.
 * The pending code is persisted on the user (not flash) so a refresh
 * after starting verification still shows the code.
 */
class LinkedAccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user()->load(['linkedAccounts', 'pendingVerification']);
        $pending = $user->pendingVerification;

        return Inertia::render('settings/linked-accounts', [
            'providers' => [
                [
                    'value' => LinkedAccountProvider::ChessCom->value,
                    'displayName' => LinkedAccountProvider::ChessCom->displayName(),
                    'username' => $user->chess_com_username,
                    'verifiedAt' => $user->chess_com_verified_at?->toIso8601String(),
                    'targetFieldLabel' => 'Location',
                    'targetFieldInstructions' => 'Settings → Profile → Location',
                ],
                [
                    'value' => LinkedAccountProvider::Lichess->value,
                    'displayName' => LinkedAccountProvider::Lichess->displayName(),
                    'username' => $user->lichess_username,
                    'verifiedAt' => $user->lichess_verified_at?->toIso8601String(),
                    'targetFieldLabel' => 'Bio',
                    'targetFieldInstructions' => 'Settings → Edit Profile → Bio',
                ],
            ],
            'pending' => $pending ? [
                'provider' => $pending->provider->value,
                'username' => $pending->username,
                'code' => $pending->code,
                'expiresAt' => $pending->expires_at->toIso8601String(),
            ] : null,
        ]);
    }

    public function store(
        RequestLinkVerificationRequest $request,
        RequestLinkVerificationAction $action,
    ): RedirectResponse {
        $action->handle(
            user: $request->user(),
            provider: $request->provider(),
            username: $request->username(),
        );

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Verification code generated. Paste it on your profile, then verify.'),
        ]);

        return to_route('linked-accounts.edit');
    }

    public function update(
        Request $request,
        VerifyLinkedAccountAction $action,
    ): RedirectResponse {
        try {
            $sentinel = $action->handle($request->user());
        } catch (ProviderError) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __("We couldn't reach the provider right now. Please try again in a moment."),
            ]);

            return to_route('linked-accounts.edit');
        }

        Inertia::flash('toast', $this->toastForSentinel($sentinel));

        return to_route('linked-accounts.edit');
    }

    public function destroy(Request $request, LinkedAccountProvider $provider): RedirectResponse
    {
        $request->user()
            ->linkedAccounts()
            ->where('provider', $provider->value)
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':provider account unlinked.', ['provider' => $provider->displayName()]),
        ]);

        return to_route('linked-accounts.edit');
    }

    /**
     * Cancel an in-flight verification — lets the user fix a mistyped
     * handle without waiting for the 15-minute TTL.
     */
    public function cancelPending(Request $request): RedirectResponse
    {
        $request->user()->pendingVerification()->delete();

        return to_route('linked-accounts.edit');
    }

    /**
     * @return array{type: string, message: string}
     */
    private function toastForSentinel(string $sentinel): array
    {
        return match ($sentinel) {
            'verified' => [
                'type' => 'success',
                'message' => __('Account linked! You can safely remove the code from your profile now — we only check it once.'),
            ],
            'expired' => [
                'type' => 'error',
                'message' => __('Verification code expired. Generate a new one.'),
            ],
            'profile-not-found' => [
                'type' => 'error',
                'message' => __("We couldn't find that username on the provider. Double-check it and try again."),
            ],
            'code-not-found' => [
                'type' => 'error',
                'message' => __("We didn't find your code in the target field. Make sure you saved your changes on the provider site."),
            ],
            'username-claimed' => [
                'type' => 'error',
                'message' => __('This account was just linked by another Stakly user.'),
            ],
        };
    }
}
