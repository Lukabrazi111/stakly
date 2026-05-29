<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial set of admin-managed CMS pages. About lands published so
 * `/en/about` works on a fresh `migrate:fresh --seed`. Privacy, Terms, and
 * Support land as drafts (`published_at = null`) — they appear in the
 * Filament admin so admin can write the copy and hit Publish when ready,
 * but the public routes 404 until then.
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

        Page::updateOrCreate(
            ['slug' => 'privacy', 'locale' => Page::DEFAULT_LOCALE],
            [
                'title' => 'Privacy Policy',
                'body' => $this->privacyStarterBody(),
                'published_at' => null,
            ],
        );

        Page::updateOrCreate(
            ['slug' => 'terms', 'locale' => Page::DEFAULT_LOCALE],
            [
                'title' => 'Terms of Service',
                'body' => $this->termsStarterBody(),
                'published_at' => null,
            ],
        );

        Page::updateOrCreate(
            ['slug' => 'support', 'locale' => Page::DEFAULT_LOCALE],
            [
                'title' => 'Support',
                'body' => $this->supportStarterBody(),
                'published_at' => null,
            ],
        );
    }

    private function aboutBody(): string
    {
        return <<<'MARKDOWN'
## What is Stakly?

Stakly is a peer-to-peer platform for skill-based competitive matches with
real money on the line. Players post listings, opponents take them, and
both sides put up USDT before the match starts. Whoever wins takes the pot,
minus a small platform fee.

There are no smart contracts, no on-chain game logic, and nothing to install.
The blockchain is the deposit and payout rail; the rest is a normal web app.

## How a match works

1. **Create a listing.** Pick your stake amount (minimum **$5 USDT**), your
   skill range, and your preferred time control. Your stake is escrowed the
   moment the listing goes live — no bait listings.
2. **Get matched.** Another player takes your listing, their stake gets
   escrowed too, and the match begins on the game's official platform.
3. **Play.** Use your linked game account. The match runs on the platform
   you both know.
4. **Settle.** Stakly reads the result directly from the game's official API
   and pays out the winner automatically.

## Verified accounts only

Before you can stake anything, you link your game account on each supported
platform and prove you own it. This stops impersonation and keeps ratings
honest — the person stake-matching at 1800 is the person actually rated 1800.

## Fair settlement

The official game API is the source of truth on every match outcome. The
fast path is both players confirming the result; on any disagreement, the
API decides. Screenshots and manual review exist only as a last resort.

## Fees

Stakly takes a **5–10%** commission on the winning pot. The exact rate is
shown on every listing before you stake. No hidden fees, no withdrawal
surprises.

## Questions?

This page will grow as the platform does. For now: if something is unclear,
the team is reachable through the support channels in your account.
MARKDOWN;
    }

    private function privacyStarterBody(): string
    {
        return <<<'MARKDOWN'
_This page is a draft. Replace this body in `/admin/pages` and hit Publish when ready._

## What we collect

_TODO: list the personal data Stakly collects (email, username, payout address, KYC fields when ready)._

## How we use it

_TODO: describe usage (auth, match resolution, fraud prevention)._

## Sharing

_TODO: third parties (chess.com / Lichess for verification, payment rail for USDT, etc.)._

## Your rights

_TODO: access / deletion / portability._

## Contact

_TODO: privacy contact email._
MARKDOWN;
    }

    private function termsStarterBody(): string
    {
        return <<<'MARKDOWN'
_This page is a draft. Replace this body in `/admin/pages` and hit Publish when ready._

## Acceptance

_TODO: by creating an account, the user agrees to these terms._

## Eligibility

_TODO: age, jurisdiction, account ownership._

## Matches and stakes

_TODO: how stakes are escrowed, settled, refunded. Reference the fee range._

## Conduct

_TODO: prohibited behavior (cheating, multi-accounting, abusive chat, etc.)._

## Account suspension and termination

_TODO: grounds, appeals._

## Disclaimers and limitations

_TODO: standard disclaimers._

## Changes

_TODO: how / when the terms change, notice period._
MARKDOWN;
    }

    private function supportStarterBody(): string
    {
        return <<<'MARKDOWN'
_This page is a draft. Replace this body in `/admin/pages` and hit Publish when ready._

## Need help?

_TODO: contact email or support channel (Discord, Telegram, etc.)._

## Common questions

_TODO: replace with real FAQ entries — verified-account onboarding, deposits, withdrawals, disputes, fees._

## Reporting an issue

_TODO: how to report a match dispute, a chat-abuse incident, or a security issue._
MARKDOWN;
    }
}
