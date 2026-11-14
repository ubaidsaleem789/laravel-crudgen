<?php

namespace UbaidCrudGen\LaravelCrudGen;

use Illuminate\Support\ServiceProvider;
use UbaidCrudGen\LaravelCrudGen\Commands\GenerateApi;
use UbaidCrudGen\LaravelCrudGen\Commands\GenerateCrud;
use UbaidCrudGen\LaravelCrudGen\Commands\InstallAssets;

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
