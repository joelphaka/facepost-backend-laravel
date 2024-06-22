<?php

namespace App\Providers;

use App\Helpers\Utils;
use Illuminate\Support\ServiceProvider;

class DynamicConfigServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $configMapping = [];

        if (!app()->runningInConsole()) {
            $appUrl = Utils::baseUrl();

            $configMapping = array_merge($configMapping, [
                'app.url'                                   => $appUrl,
                'services.facebook.redirect'                => $appUrl . '/login/facebook/callback',
                'services.google.redirect'                  => $appUrl . '/login/google/callback',
                'services.passport.oauth_token_url'         => $appUrl . '/oauth/token',
                'services.passport.oauth_token_refresh_url' => $appUrl . '/oauth/token/refresh',
            ]);
        }

        foreach ($configMapping as $key => $value) {
            config([$key => $value]);
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
