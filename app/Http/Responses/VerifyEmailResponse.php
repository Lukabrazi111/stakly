<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Email verified.',
        ]);

        return redirect(config('fortify.home'));
    }
}
