<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * M12 Phase 1 — seeds the Stakly `admin` Spatie role + the first admin
 * user. Idempotent on re-runs (firstOrCreate on both the role + user).
 *
 * Credentials are read from `.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD`,
 * `ADMIN_NAME`) so production deploys can set their own values without
 * code changes. Dev fallbacks land at `admin@stakly.test / password`
 * which is fine inside Sail but should never reach a production host —
 * the deploy checklist needs to set these env vars before first seed.
 *
 * The admin user is intentionally NOT a `is_platform = true` user — the
 * platform user holds the rake balance and must never log in (rejected
 * by `User::canAccessPanel`). The admin is a distinct human-operated
 * account, just one with elevated permissions.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $email = env('ADMIN_EMAIL', 'admin@stakly.test');
        $password = env('ADMIN_PASSWORD', 'password');
        $name = env('ADMIN_NAME', 'Stakly Admin');

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => 'admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                // M30 P3 — admin-panel gate redirects unenrolled admins to
                // `/settings/security`. Stamping `two_factor_confirmed_at`
                // here keeps local dev + CI smooth. For production, the
                // operator should re-enroll for real 2FA after first login
                // (admin → Settings → Security → Enable two-factor auth).
                'two_factor_confirmed_at' => now(),
            ],
        );

        $admin->assignRole($role);
    }
}
