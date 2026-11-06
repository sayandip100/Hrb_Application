<?php

namespace App\Providers;

use App\Domain\Identity\PublicId;
use App\Models\AdvertisementDraft;
use App\Models\AdvertisementResubmission;
use App\Models\AgeRelaxation;
use App\Models\BlockMunicipality;
use App\Models\CasteCommunity;
use App\Models\CasteIssuingAuthority;
use App\Models\Discipline;
use App\Models\Experience;
use App\Models\PoliceStation;
use App\Models\Qualification;
use App\Models\Recruitment;
use App\Models\Subdivision;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([
            'resubmission' => [AdvertisementResubmission::class, 'advertisement-resubmission'],
            'advertisementDraft' => [AdvertisementDraft::class, 'advertisement-draft'],
            'casteCommunity' => [CasteCommunity::class, 'caste-community'],
            'recruitment' => [Recruitment::class, 'recruitment'],
            'discipline' => [Discipline::class, 'discipline'],
            'qualification' => [Qualification::class, 'qualification'],
            'experience' => [Experience::class, 'experience'],
            'subdivision' => [Subdivision::class, 'subdivision'],
            'policeStation' => [PoliceStation::class, 'police-station'],
            'blockMunicipality' => [BlockMunicipality::class, 'block-municipality'],
            'casteIssuingAuthority' => [CasteIssuingAuthority::class, 'caste-issuing-authority'],
            'ageRelaxation' => [AgeRelaxation::class, 'age-relaxation'],
        ] as $parameter => [$model, $type]) {
            Route::bind($parameter, function (string $value) use ($model, $type): Model {
                try {
                    $id = PublicId::decode($type, $value);
                } catch (DecryptException) {
                    abort(404);
                }

                $query = $model::query();
                if (in_array($model, [CasteCommunity::class, Subdivision::class, BlockMunicipality::class, PoliceStation::class], true)) {
                    $query->forMasterData();
                }

                return $query->whereKey($id)->firstOrFail();
            });
        }
    }
}
