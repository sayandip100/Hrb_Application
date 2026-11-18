<?php

// routes/api/public.php  (no authentication)

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublicAdvertisementController;
use Illuminate\Support\Facades\Route;

// Controller instead of a closure so `php artisan route:cache` works.
Route::get('/v1/status', HealthController::class)->name('status');

Route::prefix('v1/public/advertisements')->name('public.advertisements.')
    ->middleware('throttle:60,1')
    ->group(function (): void {
        Route::get('/', [PublicAdvertisementController::class, 'index'])->name('index');
        Route::get('/{advertisement}', [PublicAdvertisementController::class, 'show'])->name('show');
    });
