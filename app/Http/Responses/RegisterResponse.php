<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $email = $request->user()?->email;

        Inertia::flash([
            'toast' => [
                'type' => 'success',
                'message' => $email
                    ? "We've sent a verification link to {$email}."
                    : "We've sent you a verification email.",
            ],
            'verify_cooldown_seconds' => 60,
        ]);

        return redirect()->intended(config('fortify.home'));
    }
}
