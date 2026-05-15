<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\GameMatch\ConfirmRequest;
use App\Http\Requests\GameMatch\TakeRequest;
use App\Http\Resources\GameMatchResource;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Services\MatchSettlement;
use App\Services\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameMatchController extends Controller
{
    /**
     * Take an open listing — escrows the taker's stake and creates the match
     * row, all inside one DB transaction with a row lock on the listing.
     *
     * Three failure modes are distinguished on purpose:
     *   1. Self-take attempt → abort(403). Should never happen since the UI
     *      hides the Take button for owners; this is defense in depth against
     *      hand-crafted requests.
     *   2. Race lost (listing changed state between the form-request pre-check
     *      and our row lock — most commonly someone else just took it) →
     *      transaction returns null, controller flashes an info toast and
     *      redirects back to the listing page. Not a ValidationException
     *      because the user did everything right; the listing just disappeared.
     *   3. Insufficient balance race (balance dropped between pre-check and
     *      our row-locked Wallet::hold) → InsufficientBalanceException →
     *      ValidationException keyed on `amount`. Same shape as
     *      `ListingController::store`'s race handling.
     *
     * Idempotency: `match-take:{$listing->id}` reference on the Wallet::hold.
     * A successful Take followed by a retry POST hits the race-lost branch
     * (listing is now Taken) and gets the friendly redirect, so double-clicks
     * are safe.
     */
    public function take(TakeRequest $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();

        try {
            $match = DB::transaction(function () use ($listing, $user) {
                $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

                // Hard 403: trying to take your own listing.
                abort_if($locked->user_id === $user->id, 403, 'You cannot take your own listing.');

                // Race-check: state may have changed (listings:expire scheduled
                // command, another player's take, owner's cancel) between when
                // the page rendered and when our request hit this lock. Bail
                // out of the transaction with a null sentinel for the controller
                // to translate to a friendly redirect.
                if ($locked->status !== ListingStatus::Open || $locked->expires_at->isPast()) {
                    return null;
                }

                // Throws InsufficientBalanceException for the rare race where
                // the balance dropped between TakeRequest's pre-check and now.
                Wallet::hold(
                    user: $user,
                    amount: (string) $locked->stake_amount,
                    listing: $locked,
                    reference: "match-take:{$locked->id}",
                    description: 'Stake escrowed on match take.',
                );

                $locked->update(['status' => ListingStatus::Taken]);

                return GameMatch::create([
                    'listing_id' => $locked->id,
                    'taker_user_id' => $user->id,
                    'status' => MatchStatus::Pending,
                ]);
            });
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages([
                'amount' => __('Stake exceeds your available balance.'),
            ]);
        }

        if ($match === null) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This listing is no longer available.'),
            ]);

            return to_route('listings.show', $listing);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Match started.'),
        ]);

        return to_route('matches.show', $match);
    }

    /**
     * Match detail page. Renders confirm UI (Pending), settlement summary
     * (Settled), or dispute banner (Disputed / ManualReview) based on status.
     *
     * Non-participants get 404 (not 403) to avoid leaking match existence.
     */
    public function show(GameMatch $match): Response
    {
        $match->load([
            'listing:id,user_id,game,stake_amount,time_control,status',
            'listing.user:id,name,username',
            'taker:id,name,username',
            'winner:id,name,username',
        ]);

        // 404 for non-participants — `cannot('view')` returns true if the
        // policy denies. We don't use `Gate::authorize` because that throws
        // 403, and we don't want to leak match existence to outsiders.
        abort_if(request()->user()->cannot('view', $match), 404);

        return Inertia::render('match/show', [
            'match' => (new GameMatchResource($match))->resolve(),
        ]);
    }

    /**
     * Record a player's outcome confirmation. Players can change their
     * confirmation freely while the match is `Pending` — the "lock" is
     * implicit via match status (once both confirm, the match resolves
     * to Settled / Disputed and the policy blocks further changes).
     *
     * Resolution paths once both players have confirmed:
     *   - Mirror images (one Won + one Lost) → agreement → settle, real money
     *     moves via `MatchSettlement::settle($winner)`.
     *   - Both Won (or both Lost) → disagreement → status flips to Disputed,
     *     full game-API resolution lands in Phase 4.
     *
     * Same-outcome submissions (clicking the already-selected button) are
     * silent no-ops — no DB write, friendly toast.
     */
    public function confirm(ConfirmRequest $request, GameMatch $match): RedirectResponse
    {
        $user = $request->user();
        $newOutcome = MatchOutcome::from($request->validated('outcome'));

        // 403 for non-participants OR if match is no longer Pending. The
        // in-transaction status re-check handles the race where status
        // flipped between this gate and the row lock.
        abort_if($user->cannot('confirm', $match), 403);

        $resolution = DB::transaction(function () use ($match, $user, $newOutcome) {
            $locked = GameMatch::query()
                ->with(['listing.user', 'taker'])
                ->lockForUpdate()
                ->findOrFail($match->id);

            // Race-check: opponent may have just confirmed and resolved the
            // match between our policy gate above and this lock.
            if ($locked->status !== MatchStatus::Pending) {
                return 'too-late';
            }

            $isCreator = $locked->listing->user_id === $user->id;
            $outcomeColumn = $isCreator
                ? 'creator_confirmed_outcome'
                : 'taker_confirmed_outcome';
            $currentOutcome = $locked->{$outcomeColumn};

            // Same outcome → no-op (no DB write, friendly toast).
            if ($currentOutcome === $newOutcome) {
                return 'no-change';
            }

            $locked->{$outcomeColumn} = $newOutcome;
            $locked->save();

            // Both confirmed? Resolve.
            if ($locked->creator_confirmed_outcome !== null
                && $locked->taker_confirmed_outcome !== null) {
                return $this->resolveBothConfirmed($locked);
            }

            return 'recorded';
        });

        // Auto-dispute path → trigger API resolution OUTSIDE the confirm
        // transaction. Keeps the call structure flat (no nested savepoints)
        // and lets `resolveDispute` run its own row lock cleanly.
        //
        // With the mock driver this is synchronous and fast. When real
        // chess.com / Lichess adapters land in M8, this becomes a queued
        // job and the user sees a "Dispute opened — awaiting resolution"
        // toast immediately, with the page polling for the final state.
        if ($resolution === 'disputed') {
            MatchSettlement::resolveDispute($match->fresh());
            $resolution = $this->postDisputeResolutionSentinel($match->fresh());
        }

        return $this->confirmRedirect($match, $resolution);
    }

    /**
     * Both players have confirmed. If their claims are mirror images
     * (one Won + one Lost), agreement → settle. If they're the same
     * (both Won or both Lost), disagreement → flip to Disputed for
     * Phase 4 game-API resolution.
     *
     * Returns a sentinel string the controller maps to a flash toast.
     */
    private function resolveBothConfirmed(GameMatch $match): string
    {
        $creatorOutcome = $match->creator_confirmed_outcome;
        $takerOutcome = $match->taker_confirmed_outcome;

        if ($creatorOutcome === $takerOutcome) {
            // Same outcome (both Won or both Lost) → disagreement → dispute.
            $match->update([
                'status' => MatchStatus::Disputed,
                'dispute_opened_at' => now(),
            ]);

            return 'disputed';
        }

        // Mirror images → agreement → settle.
        $winner = $creatorOutcome === MatchOutcome::Won
            ? $match->listing->user
            : $match->taker;

        MatchSettlement::settle($match, $winner);

        return 'settled';
    }

    /**
     * Manual escalation to game-API resolution during the player-confirm
     * window. Either participant can open a dispute — the API winner is
     * authoritative and overrides player self-reports.
     *
     * Note on the abuse vector: opening a dispute before the opponent has
     * had a chance to confirm IS allowed (the 4h timeout would otherwise
     * be the only path forward). The mitigation is that the API is the
     * source of truth — escalating early doesn't bias the outcome.
     * Spammy / malicious dispute behaviour falls under future anti-abuse
     * tooling (deferred per CLAUDE.md).
     *
     * Race-safety:
     *   - Opponent's confirm landing first → match flips to Settled or
     *     Disputed before our row lock; we return 'too-late' toast.
     *   - Two players opening dispute simultaneously → second caller's
     *     row lock waits, sees status=Disputed, returns 'too-late'.
     *   - Race with the timeout job (Phase 7) → same lockForUpdate +
     *     status guard; whichever runs second is a no-op.
     */
    public function openDispute(Request $request, GameMatch $match): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->cannot('openDispute', $match), 403);

        $opened = DB::transaction(function () use ($match, $user) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return false;
            }

            $locked->update([
                'status' => MatchStatus::Disputed,
                'dispute_opened_at' => now(),
                'dispute_opened_by' => $user->id,
            ]);

            return true;
        });

        if (! $opened) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ]);

            return back();
        }

        // Resolve via API outside the dispute-flip transaction. Mock is sync;
        // M8 swaps in queued jobs for real chess.com / Lichess calls.
        MatchSettlement::resolveDispute($match->fresh());
        $resolution = $this->postDisputeResolutionSentinel($match->fresh());

        Inertia::flash('toast', match ($resolution) {
            'settled-by-api' => ['type' => 'success', 'message' => __('Dispute resolved — game API determined the winner.')],
            'manual-review' => ['type' => 'warning', 'message' => __('Dispute opened — game API could not determine a winner. Match flagged for admin review.')],
            default => ['type' => 'warning', 'message' => __('Dispute opened — awaiting resolution.')],
        });

        return back();
    }

    /**
     * Translate a post-`resolveDispute` match status into the toast sentinel
     * the controller will flash. Mock driver always returns Confirmed (so we
     * land on `settled-by-api`); the `manual-review` and fallback `disputed`
     * branches will start firing once real adapters are in (M8).
     */
    private function postDisputeResolutionSentinel(GameMatch $match): string
    {
        return match ($match->status) {
            MatchStatus::Settled => 'settled-by-api',
            MatchStatus::ManualReview => 'manual-review',
            default => 'disputed',
        };
    }

    /**
     * Map a transaction-resolution sentinel to a flash toast + redirect.
     */
    private function confirmRedirect(GameMatch $match, string $resolution): RedirectResponse
    {
        if ($resolution === 'too-late') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This match has already been resolved.'),
            ]);

            return back();
        }

        Inertia::flash('toast', match ($resolution) {
            'settled' => ['type' => 'success', 'message' => __('Both players agreed — match settled.')],
            'settled-by-api' => ['type' => 'success', 'message' => __('Players disagreed — game API resolved the match.')],
            'manual-review' => ['type' => 'warning', 'message' => __('Game API could not determine a winner — match flagged for admin review.')],
            'disputed' => ['type' => 'warning', 'message' => __('Both players disagree — match flagged for review.')],
            'no-change' => ['type' => 'info', 'message' => __("You've already chosen that outcome.")],
            default => ['type' => 'success', 'message' => __('Confirmation recorded.')],
        });

        return back();
    }
}
