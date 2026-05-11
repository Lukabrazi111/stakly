<?php

use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

require __DIR__.'/settings.php';
