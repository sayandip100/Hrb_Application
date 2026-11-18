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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('candidate-otp', static function (Request $request): array {
            $phone = $request->input('mobile');
            $phone = is_string($phone) ? $phone : '';
            $ip = (string) $request->ip();
            $key = (string) config('app.key');
            $phoneIdentity = $phone !== ''
                ? hash_hmac('sha256', $phone, $key)
                : hash_hmac('sha256', 'ip:'.$ip, $key);

            return [
                Limit::perMinute(3)->by('candidate-otp:minute:'.hash_hmac('sha256', $phone.'|'.$ip, $key)),
                Limit::perDay(10)->by('candidate-otp:day:'.$phoneIdentity),
            ];
        });

        $candidateIdentity = static function (Request $request): string {
            $candidateId = $request->user('candidate')?->getAuthIdentifier();
            $key = (string) config('app.key');

            if (is_int($candidateId) || (is_string($candidateId) && $candidateId !== '')) {
                return 'candidate:'.hash_hmac('sha256', (string) $candidateId, $key);
            }

            return 'ip:'.hash_hmac('sha256', (string) $request->ip(), $key);
        };

        RateLimiter::for('candidate-submit', static function (Request $request) use ($candidateIdentity): Limit {
            return Limit::perMinute(3)->by('candidate-submit:'.$candidateIdentity($request));
        });

        RateLimiter::for('candidate-payments', static function (Request $request) use ($candidateIdentity): Limit {
            return Limit::perMinute(5)->by('candidate-payments:'.$candidateIdentity($request));
        });

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
