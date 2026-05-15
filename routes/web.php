<?php

use App\Http\Controllers\GameMatchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

// Auth-gated mutating routes — must precede the wildcard show route so the
// `/listings/create` static segment doesn't get treated as a {listing} param.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/listings/create', [ListingController::class, 'create'])->name('listings.create');
    Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');
    Route::delete('/listings/{listing}/cancel', [ListingController::class, 'cancel'])->name('listings.cancel');

    // M6 — take a listing creates a match + escrows the taker's stake.
    Route::post('/listings/{listing}/take', [GameMatchController::class, 'take'])->name('listings.take');

    // M6 — match detail page. Participant-only, enforced inside the controller
    // (404 for non-participants, not 403, to avoid leaking match existence).
    Route::get('/matches/{match}', [GameMatchController::class, 'show'])->name('matches.show');

    // M6 Phase 3 — record a player's outcome confirmation. Players can change
    // their claim freely while match is Pending; once both confirm, the match
    // resolves (Settled or Disputed) and further confirms are blocked.
    Route::post('/matches/{match}/confirm', [GameMatchController::class, 'confirm'])->name('matches.confirm');

    // M6 Phase 4 — escalate to game-API resolution. Either participant can
    // open a dispute during Pending; the API winner is authoritative.
    Route::post('/matches/{match}/dispute', [GameMatchController::class, 'openDispute'])->name('matches.openDispute');
});

Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

// Public read-only player profile. Resolved by `username` via User's
// getRouteKeyName override. The platform user is hidden inside the controller.
Route::get('/users/{user:username}', [UserController::class, 'show'])->name('users.show');

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

require __DIR__.'/settings.php';
