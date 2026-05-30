<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\EmailVerificationNotificationSentResponse;
use App\Http\Responses\PasswordResetResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, EmailVerificationNotificationSentResponse::class);
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponseContract::class, SuccessfulPasswordResetLinkRequestResponse::class);
        $this->app->singleton(PasswordResetResponseContract::class, PasswordResetResponse::class);
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Entry-point views (login, register, forgot-password) redirect to `/`
     * with `?auth=` so the AuthModal opens there. Email verification has no
     * page either — unverified users see the chip in the header, and resend
     * POSTs to Fortify's verification.send directly. Destinations users land
     * on from email links / post-auth redirects (reset, 2fa, confirm) stay
     * as pages.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => redirect('/?auth=login'));

        Fortify::registerView(fn () => redirect('/?auth=register'));

        Fortify::requestPasswordResetLinkView(fn () => redirect('/?auth=forgot-password'));

        Fortify::resetPasswordView(function (Request $request) {
            $email = (string) $request->query('email', '');
            $token = (string) $request->route('token');

            $user = $email !== '' ? User::where('email', $email)->first() : null;

            if (! $user || ! Password::tokenExists($user, $token)) {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => 'This password reset link is invalid or has expired.',
                ]);

                return redirect('/');
            }

            return Inertia::render('auth/reset-password', [
                'email' => $email,
                'token' => $token,
            ]);
        });

        Fortify::verifyEmailView(fn () => redirect('/'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
