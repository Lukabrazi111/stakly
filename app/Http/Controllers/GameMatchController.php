<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\GameMatch\TakeRequest;
use App\Http\Resources\GameMatchResource;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Http\RedirectResponse;
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
     * Match detail page. Phase 2 renders a minimal view (opponent, stakes,
     * status) — Phase 3 adds the confirm / dispute action UI on top.
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
}
