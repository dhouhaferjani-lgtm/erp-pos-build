<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Providers;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Support\ServiceProvider;

final class CountryDefaultsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CountryAccountingCapabilities::class, CountryAccountingCapabilitiesService::class);
    }
}
