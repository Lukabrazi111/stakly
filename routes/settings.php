<?php

use App\Http\Controllers\Settings\LinkedAccountController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/linked-accounts', [LinkedAccountController::class, 'edit'])->name('linked-accounts.edit');
    Route::post('settings/linked-accounts', [LinkedAccountController::class, 'store'])->name('linked-accounts.request');
    Route::post('settings/linked-accounts/verify', [LinkedAccountController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('linked-accounts.verify');
    // NOTE: `pending` route MUST be declared before the {provider} route —
    // otherwise the enum binding would try to resolve 'pending' as a provider.
    Route::delete('settings/linked-accounts/pending', [LinkedAccountController::class, 'cancelPending'])->name('linked-accounts.cancel-pending');
    Route::delete('settings/linked-accounts/{provider}', [LinkedAccountController::class, 'destroy'])->name('linked-accounts.unlink');
});
