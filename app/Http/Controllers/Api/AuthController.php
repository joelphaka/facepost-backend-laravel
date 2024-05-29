<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthUtils;
use App\Helpers\Utils;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jenssegers\Agent\Agent;
use Laravel\Socialite\Facades\Socialite;
use Stevebauman\Location\Facades\Location;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $userFromEmail = DB::table('users')
            ->select(['is_active'])
            ->where('email', $request->input('email'))
            ->first();

        if ($userFromEmail && !$userFromEmail->is_active) {
            return response()->json(['message' => 'User account not active.'], 401);
        }

        $httpClient = Http::withOptions(['verify' => !config('app.debug')]);

        $httpResponse = $httpClient->post(config('services.passport.oauth_token_url'), [
            'grant_type' => config('services.passport.grant_type'),
            'client_id' => config('services.passport.client_id'),
            'client_secret' => config('services.passport.client_secret'),
            'username' => $request->input('email'),
            'password' => $request->input('password'),
        ]);

        if ($httpResponse->successful()) {
            $tokenData = $httpResponse->json();
            $user = AuthUtils::findByAccessToken($tokenData['access_token']);

            if (!$user) {
                return response()->json(['message' => 'An error occurred'], 500);
            }

            $data = array_merge($tokenData, compact('user'));

            return response()->json($data, 200);
        } else {
            return response()->json(['message' => "Unauthorized"], 401);
        }
    }

    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'username' => AuthUtils::generateUsername($request->input('email')),
            'email' => $request->input('email'),
            'password' => bcrypt($request->input('password')),
        ]);

        return response()->json($user);
    }

    public function user()
    {
        return auth()->user();
    }

    public function logout()
    {
        auth()->user()->token()->revoke();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function redirectToProvider($provider)
    {
        session([
            'platform' => request()->input('platform'),
            'spa_app_url' => request()->input('spa_app_url')
        ]);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function handleProviderCallback($provider)
    {
        $platform = session()->get('platform');
        $spa_app_url = session()->get('spa_app_url');
        session()->forget(['platform', 'spa_app_url']);

        try {
            $externalUser = Socialite::driver($provider)->stateless()->user();

            $nameArr = preg_split('/\s+/', $externalUser->getName());
            $first_name = $nameArr[0];
            $last_name = count($nameArr) > 1 ? implode(' ', array_slice($nameArr, 1)) : null;

            $createdUser = User::firstOrCreate(['email' => $externalUser->getEmail()], [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'username' => AuthUtils::generateUsername($externalUser->getEmail()),
            ]);

            $createdUser->providers()->updateOrCreate([
                    'provider' => $provider,
                    'provider_id' => $externalUser->getId(),
                ],
                ['avatar' => $externalUser->getAvatar()]
            );

            $tokenResult = $createdUser->createToken('auth-token');
            $this->createLoginLog($tokenResult->accessToken, ['external_auth' => true, 'external_auth_provider' => $provider]);

            return view('auth.callback', ['platform' => $platform, 'spa_app_url' => $spa_app_url, 'access_token' => $tokenResult->accessToken]);
        } catch (\Exception $exception) {
            return view('auth.callback', ['platform' => $platform, 'spa_app_url' => $spa_app_url])
                ->withErrors(['auth' => 'Failed to authenticated.']);
        }
    }

    private function createLoginLog($accessToken,  array $additionalData = array())
    {
        if (($user = AuthUtils::findByAccessToken($accessToken))) {
            $loginLog = new LoginLog();
            $loginLog->user_id = $user->id;
            $loginLog->access_token = $accessToken;
            $loginLog->ip = request()->ip();
            $loginLog->user_agent = request()->header('user-agent');
            $loginLog->date = Carbon::now();
            $loginLog->external_auth = !!data_get($additionalData, 'external_auth');

            if (data_get($additionalData, 'external_auth_provider')) {
                $loginLog->external_auth_provider = data_get($additionalData, 'external_auth_provider');
            }

            $agent = new Agent();
            if ($agent->isiOS() || $agent->isiPhone()) $loginLog->device_platform = 'ios';
            else if ($agent->isiPadOS() || $agent->isiPad()) $loginLog->device_platform = 'ipados';
            else if ($agent->isAndroidOS()) $loginLog->device_platform = 'android';
            else if ($agent->iswebOS()) $loginLog->device_platform = 'webos';
            else if (stripos($loginLog->user_agent, 'kaios') !== false) $loginLog->device_platform = 'kaios';
            else if ($agent->isDesktop()) $loginLog->device_platform = 'web';

            $loginLog->save();

            $location = Location::get($loginLog->ip);

            if (!!$location && !$location->isEmpty() && !!$location->countryCode) {
                $loginLog->location = $location->countryName . (!!$location->regionName ? ", {$location->regionName}" : '') . (!!$location->cityName ? ", {$location->cityName}" : '');
                $loginLog->country_code = $location->countryCode;

                if (!!$location->regionCode) $loginLog->region_code = $location->regionCode;
                if (!!$location->areaCode) $loginLog->are_code = $location->areaCode;
                if (!!$location->zipCode) $loginLog->zip_code = $location->zipCode;
                if (!!$location->timezone) $loginLog->timezone = $location->timezone;

                $loginLog->save();
            }
        }
    }
}
