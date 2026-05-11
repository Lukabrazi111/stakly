<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;

class SuccessfulPasswordResetLinkRequestResponse implements SuccessfulPasswordResetLinkRequestResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $email = $request->input('email');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $email
                ? "Password reset link sent to {$email}."
                : 'Password reset link sent.',
        ]);

        return redirect(config('fortify.home'));
    }
}
