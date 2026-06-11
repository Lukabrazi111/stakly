<?php

use App\Http\Controllers\ActiveModeController;
use App\Http\Controllers\FaceitLinkController;
use App\Http\Controllers\GameMatchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LinkImageController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\Webhooks\FaceitWebhookController;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyFaceitWebhook;
use App\Models\UsernameHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('{locale}')
    ->whereIn('locale', config('stakly.locales'))
    ->middleware(SetLocale::class)
    ->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

        // Auth-gated mutating routes — must precede the wildcard show route so the
        // `/listings/create` static segment doesn't get treated as a {listing} param.
        Route::middleware(['auth', 'verified'])->group(function () {
            Route::get('/listings/create', [ListingController::class, 'create'])->name('listings.create');
            Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');

            // M6 Phase 6.5 — owner's management dashboard. Listed / All Ads tabs,
            // table layout, per-row pause / resume / cancel actions. Registered
            // before the wildcard `/listings/{listing}` for clarity.
            Route::get('/listings/mine', [ListingController::class, 'mine'])->name('listings.mine');

            Route::delete('/listings/{listing}/cancel', [ListingController::class, 'cancel'])->name('listings.cancel');

            // M6 Phase 6.5 — global Active Mode toggle. Bybit-style "online status."
            // Hides every Open listing of the user when off; reactivating brings
            // them all back instantly. Replaces the per-listing pause/resume model
            // that was originally shipped in Phase 6 (removed in 6.5).
            Route::post('/active-mode', [ActiveModeController::class, 'update'])->name('active-mode.update');

            // M6 — take a listing creates a match + escrows the taker's stake.
            Route::post('/listings/{listing}/take', [GameMatchController::class, 'take'])->name('listings.take');

            // M6 Phase 6 — authenticated player's own matches (creator + taker sides
            // combined). Registered before the wildcard `/matches/{match}` for clarity;
            // Laravel would resolve the static segment first anyway.
            Route::get('/matches', [GameMatchController::class, 'index'])->name('matches.index');

            // M6 — match detail page. Participant-only, enforced inside the controller
            // (404 for non-participants, not 403, to avoid leaking match existence).
            Route::get('/matches/{match}', [GameMatchController::class, 'show'])->name('matches.show');

            // M6 Phase 4 — escalate to game-API resolution. Either participant can
            // open a dispute during Pending; the API winner is authoritative. After
            // M16 this is the only Pending-state escalation (player confirms removed).
            Route::post('/matches/{match}/dispute', [GameMatchController::class, 'openDispute'])->name('matches.openDispute');

            // M10 — mutual match cancellation. Either participant proposes; the
            // other accepts (refund both) or rejects (request closed, requester
            // enters 30-min per-user cooldown). The /accept and /reject paths
            // sit under /cancellation as POST sub-actions on the same resource.
            Route::post('/matches/{match}/cancellation', [GameMatchController::class, 'requestCancellation'])->name('matches.cancellation.request');
            Route::post('/matches/{match}/cancellation/accept', [GameMatchController::class, 'acceptCancellation'])->name('matches.cancellation.accept');
            Route::post('/matches/{match}/cancellation/reject', [GameMatchController::class, 'rejectCancellation'])->name('matches.cancellation.reject');

            // M8 Phase 2 — chat messages on a match. Participant-only (404 for
            // non-participants, matching the show convention). Rate limit + status
            // gate live inside `SendMessageAction`.
            Route::post('/matches/{match}/messages', [MessageController::class, 'store'])->name('matches.messages.store');

            // M8 Phase 3 Slice 1 — authenticated streaming of chat image attachments.
            // Files live on the private `local` disk and are never served directly
            // by the web server; every fetch re-checks the match `view` policy. The
            // `{media}` segment is the integer media id (resolved manually inside
            // the controller against the message's media collection — see comments
            // there for the scope-check rationale).
            Route::get('/matches/{match}/messages/{message}/attachments/{media}', [MessageController::class, 'attachment'])
                ->whereNumber('media')
                ->name('matches.messages.attachment');

            // M8 Phase 3 Slice 2 — authenticated streaming of cached link-preview
            // images. Filename is hash-addressed (sha256-of-source-url.ext); the
            // controller hard-rejects anything outside the expected shape so the
            // segment can't be used as a path-traversal vector.
            Route::get('/link-images/{filename}', [LinkImageController::class, 'show'])
                ->where('filename', '[a-f0-9]{64}\.(jpg|png|webp|gif)')
                ->name('link-images.show');
        });

        Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

        // Public read-only player profile. Resolved by `username` via User's
        // getRouteKeyName override. The platform user is hidden inside the controller.
        // `missing()` callback redirects 301 from a released handle still inside its
        // 30-day `username_history` reservation to the original owner's current
        // profile — keeps old bookmarks + indexed URLs working after a rename.
        Route::get('/users/{user:username}', [UserController::class, 'show'])
            ->name('users.show')
            ->missing(function (Request $request) {
                $handle = $request->route('user');

                if (! is_string($handle)) {
                    abort(404);
                }

                $historyRow = UsernameHistory::reserved()
                    ->where('username', $handle)
                    ->whereNotNull('user_id')
                    ->with('user')
                    ->first();

                if ($historyRow?->user !== null) {
                    return redirect()->route('users.show', ['user' => $historyRow->user], 301);
                }

                abort(404);
            });

        // Wallet UI (M7). All routes require auth + verified email. The platform user
        // is explicitly 403'd in each controller method — defense in depth on top of
        // the middleware-level gate.
        Route::middleware(['auth', 'verified'])->prefix('wallet')->name('wallet.')->group(function () {
            Route::get('/', [WalletController::class, 'index'])->name('index');
            Route::get('/deposit', [WalletController::class, 'deposit'])->name('deposit');
            Route::get('/withdraw', [WalletController::class, 'withdraw'])->name('withdraw');
            Route::post('/withdraw', [WalletController::class, 'withdrawStore'])->name('withdraw.store');
            Route::get('/history', [WalletController::class, 'history'])->name('history');
        });

        Route::middleware(['auth', 'verified'])->prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/recent', [NotificationController::class, 'recent'])->name('recent');
            Route::post('/seen', [NotificationController::class, 'markSeen'])->name('seen');
            Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('readAll');
            Route::post('/{notification}/read', [NotificationController::class, 'markRead'])
                ->where('notification', '[0-9a-f-]{36}')
                ->name('read');
        });

        require __DIR__.'/settings.php';

        // M15 Phase 2 — FACEIT OAuth link initiation. Stays inside the locale
        // group so `app()->getLocale()` reflects the user's current page when
        // they click "Link FACEIT"; the controller stashes it in session for
        // the locale-agnostic callback to read back. The matching callback
        // route lives OUTSIDE this group (see below).
        Route::middleware(['auth', 'verified'])->prefix('auth/faceit')->name('auth.faceit.')->group(function () {
            Route::get('/redirect', [FaceitLinkController::class, 'redirect'])->name('redirect');
        });

        // M26 — admin-managed CMS pages (About, Privacy, Terms). Registered LAST so
        // the single-segment `/{slug}` doesn't swallow any explicit route above it.
        // The `{locale}` comes from the enclosing group, so the route URI ends up as
        // `/{locale}/{slug}` — unchanged from M26 P1, the only difference is the
        // locale prefix is now centralised at the group level. Slug pattern is
        // permissive enough to match anything Filament's `alphaDash` slug field
        // produces — invalid slugs 404 in the controller.
        Route::get('/{slug}', [PageController::class, 'show'])
            ->where('slug', '[a-z0-9-]+')
            ->name('pages.show');
    });

// M15 Phase 2 — FACEIT OAuth callback. Sits OUTSIDE the `{locale}` prefix
// group because FACEIT only supports a single redirect URI per OAuth app;
// keeping the callback URL stable across locales avoids one app per locale.
// The controller restores the user's locale from session (stashed during
// `auth.faceit.redirect`) and redirects back to `/{locale}/settings/linked-accounts`.
Route::get('/auth/faceit/callback', [FaceitLinkController::class, 'callback'])
    ->middleware(['auth', 'verified'])
    ->name('auth.faceit.callback');

// M15 P4 Slice 4 — FACEIT webhook receiver. Sits OUTSIDE the `{locale}`
// prefix group because FACEIT calls a single configured URL with no locale
// in path. CSRF excluded in `bootstrap/app.php`. Auth = shared-secret
// header verified by `VerifyFaceitWebhook` middleware; IP allowlist added
// once egress IPs land. Per-IP `throttle:60,1` bounds spam from a leaked
// secret while keeping headroom for legit retry storms.
Route::post('/webhooks/faceit', FaceitWebhookController::class)
    ->middleware(['throttle:60,1', VerifyFaceitWebhook::class])
    ->name('webhooks.faceit');
