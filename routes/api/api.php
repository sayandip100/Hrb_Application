<?php

use App\Http\Controllers\Api\V1\StaffAuthController;
use Illuminate\Support\Facades\Route;

foreach ([
    'recruitment', 'discipline', 'qualification', 'experience', 'ageRelaxation',
    'casteCommunity', 'casteIssuingAuthority', 'subdivision', 'blockMunicipality',
    'policeStation', 'advertisement', 'advertisementDraft', 'resubmission',
    'candidate', 'document',
] as $param) {
    Route::pattern($param, '[A-Za-z0-9_-]{1,1024}');
}

// Legacy unversioned alias. Remove once clients move to /v1/staff/auth/details.
Route::get('/user', [StaffAuthController::class, 'me'])->middleware('staff');

require __DIR__.'/public.php';
require __DIR__.'/staff-auth.php';
require __DIR__.'/staff.php';
require __DIR__.'/candidate.php';
