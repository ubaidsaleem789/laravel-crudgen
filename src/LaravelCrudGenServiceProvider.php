<?php

namespace UbaidSaleem\LaravelCrudGen;

use Illuminate\Support\ServiceProvider;
use UbaidSaleem\LaravelCrudGen\Commands\GenerateApi;
use UbaidSaleem\LaravelCrudGen\Commands\GenerateCrud;
use UbaidSaleem\LaravelCrudGen\Commands\InstallAssets;

class LaravelCrudGenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallAssets::class, GenerateCrud::class, GenerateApi::class]);

            // php artisan vendor:publish --tag=crudgen-stubs
            $this->publishes([__DIR__.'/../stubs' => base_path('stubs/crudgen')], 'crudgen-stubs');
        }
    }
}
