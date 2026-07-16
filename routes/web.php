<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::livewire('library', 'pages::library')
    ->middleware('auth')
    ->name('library');

Route::livewire('library/{mediaItem}', 'pages::library-show')
    ->middleware('auth')
    ->name('library.show');

Route::livewire('wishlist', 'pages::wishlist')
    ->middleware('auth')
    ->name('wishlist');

require __DIR__.'/settings.php';
