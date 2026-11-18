<?php

use App\Http\Controllers\Api\V1\StaffAuthController;
use App\Http\Middleware\EnsureStaffBrowserSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/staff/auth')->name('staff.auth.')
    ->middleware(EnsureStaffBrowserSession::class)
    ->group(function (): void {
        // Guest endpoints
        Route::post('/request-otp', [StaffAuthController::class, 'requestOtp'])
            ->middleware('throttle:staff-otp-request')->name('request-otp');
        Route::post('/login', [StaffAuthController::class, 'login'])
            ->middleware('throttle:staff-otp-verify')->name('login');

        // Authenticated endpoints ('staff' group already includes the session check,
        // so only the auth + active checks are added here).
        Route::middleware(['auth:sanctum', 'active.staff'])->group(function (): void {
            Route::get('/details', [StaffAuthController::class, 'me'])->name('me');
            Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
        });
    });
