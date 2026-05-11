<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;

class PasswordResetResponse implements PasswordResetResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Password reset. You can now log in with your new password.',
        ]);

        return redirect('/?auth=login');
    }
}
