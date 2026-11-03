<?php

use App\Http\Controllers\Api\V1\DisciplineController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\RecruitmentController;
use App\Http\Controllers\Api\V1\StaffAuthController;
use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\EnsureStaffBrowserSession;
use App\Http\Middleware\RequireStaffRole;
use Illuminate\Support\Facades\Route;

Route::get('/user', [StaffAuthController::class, 'me'])
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class]);

Route::get('/v1/status', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'HRB API',
    ]);
});

Route::prefix('v1/staff/auth')->name('staff.auth.')
    ->middleware(EnsureStaffBrowserSession::class)
    ->group(function (): void {
        Route::post('/request-otp', [StaffAuthController::class, 'requestOtp'])
            ->middleware('throttle:staff-otp-request')->name('request-otp');
        Route::post('/login', [StaffAuthController::class, 'login'])
            ->middleware('throttle:staff-otp-verify')->name('login');

        Route::middleware(['auth:sanctum', EnsureActiveStaff::class])->group(function (): void {
            Route::get('/details', [StaffAuthController::class, 'me'])->name('me');
            Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
        });
    });

Route::prefix('v1/master-data/recruitments')->name('master-data.recruitments.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [RecruitmentController::class, 'index'])->name('index');
        Route::post('/', [RecruitmentController::class, 'store'])->name('store');
        Route::patch('/{recruitment}/lock', [RecruitmentController::class, 'lock'])->whereNumber('recruitment')->name('lock');
        Route::patch('/{recruitment}/unlock', [RecruitmentController::class, 'unlock'])->whereNumber('recruitment')->name('unlock');
    });

Route::prefix('v1/master-data/disciplines')->name('master-data.disciplines.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [DisciplineController::class, 'index'])->name('index');
        Route::post('/', [DisciplineController::class, 'store'])->name('store');
        Route::get('/{discipline}', [DisciplineController::class, 'show'])->whereNumber('discipline')->name('show');
        Route::patch('/{discipline}', [DisciplineController::class, 'update'])->whereNumber('discipline')->name('update');
        Route::patch('/{discipline}/lock', [DisciplineController::class, 'lock'])->whereNumber('discipline')->name('lock');
        Route::patch('/{discipline}/unlock', [DisciplineController::class, 'unlock'])->whereNumber('discipline')->name('unlock');
    });

Route::prefix('v1/master-data/qualifications')->name('master-data.qualifications.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [QualificationController::class, 'index'])->name('index');
        Route::post('/', [QualificationController::class, 'store'])->name('store');
        Route::get('/{qualification}', [QualificationController::class, 'show'])->whereNumber('qualification')->name('show');
        Route::patch('/{qualification}', [QualificationController::class, 'update'])->whereNumber('qualification')->name('update');
        Route::patch('/{qualification}/lock', [QualificationController::class, 'lock'])->whereNumber('qualification')->name('lock');
        Route::patch('/{qualification}/unlock', [QualificationController::class, 'unlock'])->whereNumber('qualification')->name('unlock');
    });
