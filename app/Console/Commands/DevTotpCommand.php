<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/**
 * Prints the current TOTP code for a seeded dev user, so local withdrawals
 * stay testable with the 2FA step-up gate on (M9 Phase 0d) without keeping an
 * authenticator app to hand.
 *
 * Local only — refuses to run in production.
 */
class DevTotpCommand extends Command
{
    protected $signature = 'stakly:dev-totp {email=test@example.com}';

    protected $description = 'Print the current 2FA code for a seeded dev user (local only)';

    public function handle(Google2FA $engine): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to print 2FA codes in production.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null) {
            $this->error("User [{$email}] has no 2FA secret. Run `artisan migrate:fresh --seed`.");

            return self::FAILURE;
        }

        $secret = decrypt($user->two_factor_secret);

        $this->line('Secret: <comment>'.$secret.'</comment>');
        $this->line('Code:   <info>'.$engine->getCurrentOtp($secret).'</info>');

        // Deliberately NOT verified here. Fortify's provider caches every code
        // it accepts to block replay, so validating it would consume the code
        // and the withdraw form would then reject it.

        return self::SUCCESS;
    }
}
