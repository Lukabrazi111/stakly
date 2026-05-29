<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial set of admin-managed CMS pages so a fresh `migrate:fresh
 * --seed` always boots with an About page reachable at `/en/about`. Privacy
 * and Terms follow the same pattern when their copy is ready — adding a row
 * here makes the page live without a code push.
 *
 * `updateOrCreate` so re-seeding doesn't fail on the unique (slug, locale)
 * constraint and so manual edits via Filament aren't blown away unless the
 * seeder is re-run intentionally (`migrate:fresh --seed`).
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        Page::updateOrCreate(
            ['slug' => 'about', 'locale' => Page::DEFAULT_LOCALE],
            [
                'title' => 'About Stakly',
                'body' => $this->aboutBody(),
                'published_at' => now(),
            ],
        );
    }

    private function aboutBody(): string
    {
        return <<<'MARKDOWN'
## What is Stakly?

Stakly is a peer-to-peer platform for skill-based chess matches with real
stakes. Players post listings, opponents take them, and both sides put up
USDT before the match starts. Whoever wins the game takes the pot, minus a
small platform fee.

There are no smart contracts, no on-chain game logic, and nothing to install.
The blockchain is the deposit and payout rail; the rest is a normal web app.

## How a match works

1. **Create a listing.** Pick your stake amount, your skill range, and your
   preferred time control. Your stake is escrowed the moment the listing
   goes live — no bait listings.
2. **Get matched.** Another player on Stakly takes your listing, their stake
   gets escrowed too, and the match begins on chess.com or Lichess.
3. **Play.** Use your linked chess.com or Lichess account. The game runs on
   the platform you both know.
4. **Settle.** Stakly reads the result directly from the game's official API
   and pays out the winner automatically.

## Verified accounts only

Before you can stake anything, you link your chess.com or Lichess account and
prove you own it. This stops impersonation and keeps ratings honest — the
person stake-matching at 1800 is the person actually rated 1800.

## Fair settlement

The official game API is the source of truth on every match outcome. The
fast path is both players confirming the result; on any disagreement, the
API decides. Screenshots and manual review exist only as a last resort.

## Fees

Stakly takes a small commission on the winning pot. The exact rate is shown
on every listing before you stake. No hidden fees, no withdrawal surprises.

## Questions?

This page will grow as the platform does. For now: if something is unclear,
the team is reachable through the support channels in your account.
MARKDOWN;
    }
}
