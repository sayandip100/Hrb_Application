<?php

use App\Http\Controllers\Api\V1\AdvertisementController;
use App\Http\Controllers\Api\V1\AdvertisementListController;
use App\Http\Controllers\Api\V1\AdvertisementResubmissionController;
use App\Http\Controllers\Api\V1\AgeRelaxationController;
use App\Http\Controllers\Api\V1\BlockMunicipalityController;
use App\Http\Controllers\Api\V1\CasteCommunityController;
use App\Http\Controllers\Api\V1\CasteIssuingAuthorityController;
use App\Http\Controllers\Api\V1\DisciplineController;
use App\Http\Controllers\Api\V1\ExperienceController;
use App\Http\Controllers\Api\V1\PoliceStationController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\RecruitmentController;
use App\Http\Controllers\Api\V1\StaffCandidateActionController;
use App\Http\Controllers\Api\V1\StaffCandidateApplicationController;
use App\Http\Controllers\Api\V1\SubdivisionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Master data
|--------------------------------------------------------------------------
| One helper registers the standard lock/unlock CRUD set.
|   $param   route parameter name (pattern is declared once in routes/api.php)
|   $extra   static GET routes, [uri => controllerMethod]; registered BEFORE /{param}
|            so they can never be swallowed by the wildcard
|   $only    restrict the standard set (recruitments have no show/update)
*/
$masterData = function (
    string $slug,
    string $controller,
    string $param,
    array $extra = [],
    array $only = ['index', 'store', 'show', 'update', 'lock', 'unlock'],
): void {
    Route::prefix("v1/master-data/{$slug}")->name("master-data.{$slug}.")
        ->middleware('role:1')
        ->group(function () use ($controller, $param, $extra, $only): void {
            foreach ($extra as $uri => $method) {
                Route::get("/{$uri}", [$controller, $method])->name($uri);
            }

            in_array('index', $only, true) && Route::get('/', [$controller, 'index'])->name('index');
            in_array('store', $only, true) && Route::post('/', [$controller, 'store'])->name('store');
            in_array('show', $only, true) && Route::get("/{{$param}}", [$controller, 'show'])->name('show');
            in_array('update', $only, true) && Route::patch("/{{$param}}", [$controller, 'update'])->name('update');
            in_array('lock', $only, true) && Route::patch("/{{$param}}/lock", [$controller, 'lock'])->name('lock');
            in_array('unlock', $only, true) && Route::patch("/{{$param}}/unlock", [$controller, 'unlock'])->name('unlock');
        });
};

Route::middleware('staff')->group(function () use ($masterData): void {

    $masterData('recruitments', RecruitmentController::class, 'recruitment',
        only: ['index', 'store', 'lock', 'unlock']);
    $masterData('disciplines', DisciplineController::class, 'discipline');
    $masterData('qualifications', QualificationController::class, 'qualification');
    $masterData('experiences', ExperienceController::class, 'experience');
    $masterData('age-relaxations', AgeRelaxationController::class, 'ageRelaxation');
    $masterData('caste-communities', CasteCommunityController::class, 'casteCommunity',
        extra: ['caste-options' => 'casteOptions']);
    $masterData('caste-issuing-authorities', CasteIssuingAuthorityController::class, 'casteIssuingAuthority');
    $masterData('subdivisions', SubdivisionController::class, 'subdivision',
        extra: ['district-options' => 'districtOptions']);
    $masterData('block-municipalities', BlockMunicipalityController::class, 'blockMunicipality',
        extra: [
            'district-options' => 'districtOptions',
            'subdivision-options' => 'subdivisionOptions',
        ]);
    $masterData('police-stations', PoliceStationController::class, 'policeStation',
        extra: ['district-options' => 'districtOptions']);

    /*
    |----------------------------------------------------------------------
    | Advertisements
    |----------------------------------------------------------------------
    */
    Route::prefix('v1/advertisements')->name('advertisements.')
        ->middleware('role:1')
        ->group(function (): void {
            $c = AdvertisementController::class;

            // static routes first
            Route::get('/all-advertisement-list', [AdvertisementListController::class, 'index'])->name('all-advertisement-list');
            Route::get('/form-options', [$c, 'formOptions'])->name('form-options');
            Route::get('/recruitment-options/{recruitment}', [$c, 'recruitmentOptions'])->name('recruitment-options');

            Route::get('/', [$c, 'index'])->name('index');
            Route::post('/', [$c, 'store'])->name('store');

            Route::prefix('{advertisementDraft}')->group(function () use ($c): void {
                Route::get('/', [$c, 'show'])->name('show');
                Route::patch('/', [$c, 'update'])->name('update');
                Route::post('/document', [$c, 'document'])->name('document.store');
                Route::get('/document', [$c, 'downloadDocument'])->name('document.show');
                Route::post('/submit', [$c, 'submit'])->name('submit');
            });
        });

    Route::prefix('v1/advertisement-resubmissions')->name('advertisement-resubmissions.')
        ->middleware('role:1')
        ->group(function (): void {
            $c = AdvertisementResubmissionController::class;

            Route::get('/form-options', [$c, 'formOptions'])->name('form-options');
            Route::post('/preview', [$c, 'preview'])->name('preview');
            Route::get('/', [$c, 'index'])->name('index');
            Route::post('/', [$c, 'store'])->name('store');
            Route::get('/{resubmission}', [$c, 'show'])->name('show');
            Route::post('/{resubmission}/process', [$c, 'process'])->name('process');
        });

    /*
    |----------------------------------------------------------------------
    | Candidate review (staff side)
    |----------------------------------------------------------------------
    | TODO: move to role:verifier, and split StaffCandidateActionController
    | into CandidateReviewController / CandidateDocumentController /
    | single-action controllers for dob, swaps, reject (own FormRequest,
    | policy ability and audit event each; reject also idempotent).
    */
    Route::prefix('v1/candidates/applications')->name('candidates.applications.')
        ->middleware('role:1')
        ->group(function (): void {
            Route::get('/form-options', [StaffCandidateApplicationController::class, 'formOptions'])->name('form-options');
            Route::get('/', [StaffCandidateApplicationController::class, 'index'])->name('index');

            Route::prefix('{candidate}')->group(function (): void {
                $c = StaffCandidateActionController::class;

                Route::get('/', [$c, 'show'])->name('show');
                Route::get('/documents/{document}', [$c, 'document'])->name('document');
                Route::get('/sections', [$c, 'sections'])->name('sections');
                Route::get('/checkings', [$c, 'checks'])->name('checkings');
                Route::get('/checking-history', [$c, 'history'])->name('checking-history');
                Route::get('/marks', [$c, 'marks'])->name('marks');
                Route::get('/swap-options', [$c, 'swapOptions'])->name('swap-options');
                Route::get('/audit', [$c, 'audit'])->name('audit');

                Route::post('/profile', [$c, 'update'])->name('profile');
                Route::patch('/dob', [$c, 'update'])->name('dob');
                Route::patch('/swaps', [$c, 'update'])->name('swaps');
                Route::post('/reject', [$c, 'update'])->name('reject');
            });
        });
});
