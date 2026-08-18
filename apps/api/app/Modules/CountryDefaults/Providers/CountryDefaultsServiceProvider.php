<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Providers;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use App\Modules\CountryDefaults\Presentation\Console\VerifyCountryDefaultsCommand;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Support\ServiceProvider;

final class CountryDefaultsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CountryAccountingCapabilities::class, CountryAccountingCapabilitiesService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyCountryDefaultsCommand::class]);
        }
    }
}
