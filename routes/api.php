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
use App\Http\Controllers\Api\V1\StaffAuthController;
use App\Http\Controllers\Api\V1\SubdivisionController;
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
        Route::patch('/{recruitment}/lock', [RecruitmentController::class, 'lock'])->where('recruitment', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{recruitment}/unlock', [RecruitmentController::class, 'unlock'])->where('recruitment', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/disciplines')->name('master-data.disciplines.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [DisciplineController::class, 'index'])->name('index');
        Route::post('/', [DisciplineController::class, 'store'])->name('store');
        Route::get('/{discipline}', [DisciplineController::class, 'show'])->where('discipline', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{discipline}', [DisciplineController::class, 'update'])->where('discipline', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{discipline}/lock', [DisciplineController::class, 'lock'])->where('discipline', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{discipline}/unlock', [DisciplineController::class, 'unlock'])->where('discipline', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/qualifications')->name('master-data.qualifications.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [QualificationController::class, 'index'])->name('index');
        Route::post('/', [QualificationController::class, 'store'])->name('store');
        Route::get('/{qualification}', [QualificationController::class, 'show'])->where('qualification', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{qualification}', [QualificationController::class, 'update'])->where('qualification', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{qualification}/lock', [QualificationController::class, 'lock'])->where('qualification', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{qualification}/unlock', [QualificationController::class, 'unlock'])->where('qualification', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/experiences')->name('master-data.experiences.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [ExperienceController::class, 'index'])->name('index');
        Route::post('/', [ExperienceController::class, 'store'])->name('store');
        Route::get('/{experience}', [ExperienceController::class, 'show'])->where('experience', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{experience}', [ExperienceController::class, 'update'])->where('experience', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{experience}/lock', [ExperienceController::class, 'lock'])->where('experience', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{experience}/unlock', [ExperienceController::class, 'unlock'])->where('experience', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/age-relaxations')->name('master-data.age-relaxations.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [AgeRelaxationController::class, 'index'])->name('index');
        Route::post('/', [AgeRelaxationController::class, 'store'])->name('store');
        Route::get('/{ageRelaxation}', [AgeRelaxationController::class, 'show'])->where('ageRelaxation', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{ageRelaxation}', [AgeRelaxationController::class, 'update'])->where('ageRelaxation', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{ageRelaxation}/lock', [AgeRelaxationController::class, 'lock'])->where('ageRelaxation', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{ageRelaxation}/unlock', [AgeRelaxationController::class, 'unlock'])->where('ageRelaxation', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/caste-communities')->name('master-data.caste-communities.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [CasteCommunityController::class, 'index'])->name('index');
        Route::get('/caste-options', [CasteCommunityController::class, 'casteOptions'])->name('caste-options');
        Route::post('/', [CasteCommunityController::class, 'store'])->name('store');
        Route::get('/{casteCommunity}', [CasteCommunityController::class, 'show'])->where('casteCommunity', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{casteCommunity}', [CasteCommunityController::class, 'update'])->where('casteCommunity', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{casteCommunity}/lock', [CasteCommunityController::class, 'lock'])->where('casteCommunity', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{casteCommunity}/unlock', [CasteCommunityController::class, 'unlock'])->where('casteCommunity', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/caste-issuing-authorities')->name('master-data.caste-issuing-authorities.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [CasteIssuingAuthorityController::class, 'index'])->name('index');
        Route::post('/', [CasteIssuingAuthorityController::class, 'store'])->name('store');
        Route::get('/{casteIssuingAuthority}', [CasteIssuingAuthorityController::class, 'show'])->where('casteIssuingAuthority', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{casteIssuingAuthority}', [CasteIssuingAuthorityController::class, 'update'])->where('casteIssuingAuthority', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{casteIssuingAuthority}/lock', [CasteIssuingAuthorityController::class, 'lock'])->where('casteIssuingAuthority', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{casteIssuingAuthority}/unlock', [CasteIssuingAuthorityController::class, 'unlock'])->where('casteIssuingAuthority', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/subdivisions')->name('master-data.subdivisions.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [SubdivisionController::class, 'index'])->name('index');
        Route::get('/district-options', [SubdivisionController::class, 'districtOptions'])->name('district-options');
        Route::post('/', [SubdivisionController::class, 'store'])->name('store');
        Route::get('/{subdivision}', [SubdivisionController::class, 'show'])->where('subdivision', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{subdivision}', [SubdivisionController::class, 'update'])->where('subdivision', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{subdivision}/lock', [SubdivisionController::class, 'lock'])->where('subdivision', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{subdivision}/unlock', [SubdivisionController::class, 'unlock'])->where('subdivision', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/block-municipalities')->name('master-data.block-municipalities.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [BlockMunicipalityController::class, 'index'])->name('index');
        Route::get('/district-options', [BlockMunicipalityController::class, 'districtOptions'])->name('district-options');
        Route::get('/subdivision-options', [BlockMunicipalityController::class, 'subdivisionOptions'])->name('subdivision-options');
        Route::post('/', [BlockMunicipalityController::class, 'store'])->name('store');
        Route::get('/{blockMunicipality}', [BlockMunicipalityController::class, 'show'])->where('blockMunicipality', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{blockMunicipality}', [BlockMunicipalityController::class, 'update'])->where('blockMunicipality', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{blockMunicipality}/lock', [BlockMunicipalityController::class, 'lock'])->where('blockMunicipality', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{blockMunicipality}/unlock', [BlockMunicipalityController::class, 'unlock'])->where('blockMunicipality', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/master-data/police-stations')->name('master-data.police-stations.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->group(function (): void {
        Route::get('/', [PoliceStationController::class, 'index'])->name('index');
        Route::get('/district-options', [PoliceStationController::class, 'districtOptions'])->name('district-options');
        Route::post('/', [PoliceStationController::class, 'store'])->name('store');
        Route::get('/{policeStation}', [PoliceStationController::class, 'show'])->where('policeStation', '[A-Za-z0-9_-]{1,1024}')->name('show');
        Route::patch('/{policeStation}', [PoliceStationController::class, 'update'])->where('policeStation', '[A-Za-z0-9_-]{1,1024}')->name('update');
        Route::patch('/{policeStation}/lock', [PoliceStationController::class, 'lock'])->where('policeStation', '[A-Za-z0-9_-]{1,1024}')->name('lock');
        Route::patch('/{policeStation}/unlock', [PoliceStationController::class, 'unlock'])->where('policeStation', '[A-Za-z0-9_-]{1,1024}')->name('unlock');
    });

Route::prefix('v1/advertisements')->name('advertisements.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->where(['advertisementDraft' => '[A-Za-z0-9_-]{1,1024}'])
    ->group(function (): void {
        $controller = AdvertisementController::class;
        Route::get('/all-advertisement-list', [AdvertisementListController::class, 'index'])->name('all-advertisement-list');
        Route::get('/form-options', [$controller, 'formOptions'])->name('form-options');
        Route::get('/recruitment-options/{recruitment}', [$controller, 'recruitmentOptions'])->where('recruitment', '[A-Za-z0-9_-]{1,1024}')->name('recruitment-options');
        Route::get('/', [$controller, 'index'])->name('index');
        Route::post('/', [$controller, 'store'])->name('store');
        Route::get('/{advertisementDraft}', [$controller, 'show'])->name('show');
        Route::patch('/{advertisementDraft}', [$controller, 'update'])->name('update');
        Route::post('/{advertisementDraft}/document', [$controller, 'document'])->name('document.store');
        Route::get('/{advertisementDraft}/document', [$controller, 'downloadDocument'])->name('document.show');
        Route::post('/{advertisementDraft}/submit', [$controller, 'submit'])->name('submit');
    });

Route::prefix('v1/advertisement-resubmissions')->name('advertisement-resubmissions.')
    ->middleware([EnsureStaffBrowserSession::class, 'auth:sanctum', EnsureActiveStaff::class, RequireStaffRole::class.':1'])
    ->where(['resubmission' => '[A-Za-z0-9_-]{1,1024}'])
    ->group(function (): void {
        $controller = AdvertisementResubmissionController::class;
        Route::get('/form-options', [$controller, 'formOptions'])->name('form-options');
        Route::post('/preview', [$controller, 'preview'])->name('preview');
        Route::get('/', [$controller, 'index'])->name('index');
        Route::post('/', [$controller, 'store'])->name('store');
        Route::get('/{resubmission}', [$controller, 'show'])->name('show');
        Route::post('/{resubmission}/process', [$controller, 'process'])->name('process');
    });
