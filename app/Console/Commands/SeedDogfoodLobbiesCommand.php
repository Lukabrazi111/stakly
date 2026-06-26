<?php

namespace App\Console\Commands;

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\LeaveLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Dev tooling for M34 P6 dogfooding — creates one CS2 5v5 + one Wingman 2v2
 * lobby, each with all-but-one slot filled and Ready'd, so the named test
 * account can join the open slot, click Ready, and exercise the
 * LobbyLockAction → Pending → cancellation/dispute paths end-to-end.
 *
 * Idempotent in the only way that matters: the test account's existing
 * live lobby participation is released via `LeaveLobbyAction` before
 * seeding, so the single-lobby-per-user rule never trips. Old dogfood
 * lobbies from previous runs are NOT cleaned up (they're harmless cruft
 * — `migrate:fresh --seed` to wipe).
 */
class SeedDogfoodLobbiesCommand extends Command
{
    protected $signature = 'stakly:seed-dogfood-lobbies '
        .'{--user=testuser : Username of the test account that should remain free to join}';

    protected $description = 'Seed one 5v5 + one Wingman 2v2 lobby with N-1 Ready slots filled, leaving one slot open for the test account.';

    private const STAKE = '50';

    public function handle(): int
    {
        $username = (string) $this->option('user');
        $testUser = User::query()->where('username', $username)->first();

        if ($testUser === null) {
            $this->error("User '{$username}' not found. Run `sail artisan migrate:fresh --seed` first.");

            return self::FAILURE;
        }

        $this->releaseFromExistingLobby($testUser);

        $fiveVfive = $this->seedLobby(teamSize: 5);
        $wingman = $this->seedLobby(teamSize: 2);

        $this->renderInstructions($testUser, $fiveVfive, $wingman);

        return self::SUCCESS;
    }

    private function releaseFromExistingLobby(User $testUser): void
    {
        $existing = LobbyParticipant::query()
            ->where('user_id', $testUser->id)
            ->live()
            ->first();

        if ($existing === null) {
            return;
        }

        $this->warn("'{$testUser->username}' is currently in lobby #{$existing->listing_id} — releasing via LeaveLobbyAction.");
        app(LeaveLobbyAction::class)->handle($testUser, $existing->listing);
    }

    private function seedLobby(int $teamSize): Listing
    {
        $creator = $this->createSeedUser("dogfood{$teamSize}-creator");

        $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
            'game' => Game::Cs2->value,
            'platform' => LinkedAccountProvider::Faceit->value,
            'stake_amount' => self::STAKE,
            'skill_min' => null,
            'skill_max' => null,
            'time_control' => null,
            'region' => null,
            'language' => null,
            'duration_hours' => 24,
            'team_size' => $teamSize,
            'creator_side' => LobbyParticipant::SIDE_A,
            'is_public' => true,
        ]);

        if (! $listing instanceof Listing) {
            throw new RuntimeException("CreateTeamPlayListingAction returned sentinel '{$listing}' for team_size {$teamSize}");
        }

        for ($i = 1; $i < $teamSize; $i++) {
            $user = $this->createSeedUser("dogfood{$teamSize}-a{$i}");
            app(JoinLobbyAction::class)->handle($user, $listing, LobbyParticipant::SIDE_A);
        }

        for ($i = 0; $i < $teamSize - 1; $i++) {
            $user = $this->createSeedUser("dogfood{$teamSize}-b{$i}");
            app(JoinLobbyAction::class)->handle($user, $listing, LobbyParticipant::SIDE_B);
        }

        $listing->refresh();
        foreach ($listing->lobbyParticipants()->live()->with('user')->get() as $p) {
            app(ToggleReadyAction::class)->handle($p->user, $listing);
        }

        return $listing->fresh();
    }

    private function createSeedUser(string $usernamePrefix): User
    {
        $suffix = bin2hex(random_bytes(3));
        $username = "{$usernamePrefix}-{$suffix}";

        $user = User::factory()
            ->active()
            ->withFaceit(skillRating: 1500)
            ->create([
                'username' => $username,
                'name' => $username,
                'email' => "{$username}@dogfood.test",
            ]);

        Wallet::deposit($user, '500', reference: "dogfood-seed:{$user->id}");

        return $user;
    }

    private function renderInstructions(User $testUser, Listing $fiveVfive, Listing $wingman): void
    {
        $fresh = $testUser->fresh();

        $this->newLine();
        $this->info('═════════════════════════════════════════════════════════');
        $this->info(" Test account : {$fresh->username}   email: {$fresh->email}");
        $this->info(' Password     : password   (Laravel factory default)');
        $this->info(" Balance      : \${$fresh->usdt_balance}");
        $this->info('─────────────────────────────────────────────────────────');
        $this->info(" 5v5 lobby      → /listings/{$fiveVfive->id}   (9 Ready'd · 1 open slot on side B)");
        $this->info(" Wingman lobby  → /listings/{$wingman->id}    (3 Ready'd · 1 open slot on side B)");
        $this->info(' Stake          : $'.self::STAKE.' per player');
        $this->info('═════════════════════════════════════════════════════════');
        $this->newLine();
        $this->line(' Flow to dogfood:');
        $this->line("   1. Log in as {$fresh->username}");
        $this->line('   2. Visit one lobby URL → join the open slot on side B');
        $this->line('      → lobby state should flip to `ready_checking` (5-min countdown)');
        $this->line('   3. Click Ready → all slots Ready → lobby locks → match flips to Pending');
        $this->line('   4. Navigate to `/matches/{id}` (you can grab the match ID via Tinker:');
        $this->line('        Listing::find(<lobby_id>)->gameMatch->id)');
        $this->line('   5. From there, exercise dispute/cancellation paths (UI lands in Slice E;');
        $this->line('      for now, use Tinker per the P6 phase notes).');
        $this->newLine();
        $this->warn(' Single-lobby-per-user rule: testuser can only be in ONE lobby at a time.');
        $this->warn(' Re-run this command to release them from one before joining the other.');
        $this->newLine();
    }
}
