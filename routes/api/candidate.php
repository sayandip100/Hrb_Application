<?php

// routes/api/candidate.php

use App\Http\Controllers\Api\V1\CandidateApplicationController;
use App\Http\Controllers\Api\V1\CandidateAuthController;
use App\Http\Middleware\EnsureStaffBrowserSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Candidate auth
|--------------------------------------------------------------------------
| OTP request gets its own limiter (SMS cost + abuse target); me/logout
| no longer share the 20/min bucket with login/register.
*/
Route::prefix('v1/candidate/auth')->name('candidate.auth.')
    ->middleware(EnsureStaffBrowserSession::class) // TODO: rename to EnsureBrowserSession
    ->group(function (): void {
        $c = CandidateAuthController::class;

        Route::post('/request-registration-otp', [$c, 'requestOtp'])
            ->middleware('throttle:candidate-otp')->name('request-otp');
        Route::post('/register', [$c, 'register'])
            ->middleware('throttle:20,1')->name('register');
        Route::post('/login', [$c, 'login'])
            ->middleware('throttle:20,1')->name('login');

        Route::get('/me', [$c, 'me'])
            ->middleware(['auth:candidate', 'active.candidate'])->name('me');
        Route::post('/logout', [$c, 'logout'])
            ->middleware('auth:candidate')->name('logout');
    });

/*
|--------------------------------------------------------------------------
| Candidate application
|--------------------------------------------------------------------------
| 'candidate' group = session check + auth:candidate + active candidate.
*/
Route::prefix('v1/candidate/application')->name('candidate.application.')
    ->middleware('candidate')
    ->group(function (): void {
        $c = CandidateApplicationController::class;

        // reads
        Route::get('/', [$c, 'show'])->name('show');
        Route::get('/form-options', [$c, 'options'])->name('options');
        Route::get('/locations', [$c, 'locations'])->name('locations');
        Route::get('/preview', [$c, 'preview'])->name('preview');
        Route::get('/fee-summary', [$c, 'preview'])->name('fee'); // duplicate of /preview; pick one
        Route::get('/status', [$c, 'status'])->name('status');
        Route::get('/receipt', [$c, 'receipt'])->name('receipt');

        // money / one-time actions
        // TODO: add an 'idempotent' middleware (Idempotency-Key) to both, tighten submit's limit,
        // and make submit return 409 when already submitted.
        Route::post('/submit', [$c, 'submit'])->middleware('throttle:candidate-submit')->name('submit');
        Route::post('/payments', [$c, 'payments'])->middleware('throttle:candidate-payments')->name('payments');

        // documents
        Route::post('/documents', [$c, 'upload'])->middleware('throttle:30,1')->name('documents.store');
        Route::get('/documents/{document}', [$c, 'download'])->name('documents.show');
        Route::delete('/documents/{document}', [$c, 'destroyDocument'])->name('documents.destroy');

        // section saves (keep last: wildcard)
        Route::patch('/{section}', [$c, 'save'])
            ->where('section', 'post|personal-details|category-relaxations|qualifications|experiences')
            ->name('save');
    });
