<?php

namespace UbaidCrudGen\LaravelCrudGen;

use Illuminate\Support\ServiceProvider;
use UbaidCrudGen\LaravelCrudGen\Commands\GenerateCrud;
use UbaidCrudGen\LaravelCrudGen\Commands\InstallAssets;

class LaravelCrudGenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // if ($this->app->runningInConsole()) {
        //     $this->commands([
        //         GenerateCrud::class,
        //     ]);
        // }
    }

    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallAssets::class,
                GenerateCrud::class, // This line registers the GenerateCrud command
            ]);
        }
    }
}
