<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

// Auth-gated mutating routes — must precede the wildcard show route so the
// `/listings/create` static segment doesn't get treated as a {listing} param.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/listings/create', [ListingController::class, 'create'])->name('listings.create');
    Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');
    Route::delete('/listings/{listing}/cancel', [ListingController::class, 'cancel'])->name('listings.cancel');
});

Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

// Public read-only player profile. Resolved by `username` via User's
// getRouteKeyName override. The platform user is hidden inside the controller.
Route::get('/users/{user:username}', [UserController::class, 'show'])->name('users.show');

require __DIR__.'/settings.php';
