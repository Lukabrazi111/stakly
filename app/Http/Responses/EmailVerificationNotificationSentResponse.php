<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

class EmailVerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Inertia::flash([
            'toast' => [
                'type' => 'success',
                'message' => 'Verification email sent. Check your inbox.',
            ],
            'verify_cooldown_seconds' => 60,
        ]);

        return back();
    }
}
