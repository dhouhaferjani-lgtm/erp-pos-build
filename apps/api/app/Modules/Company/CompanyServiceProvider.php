<?php

declare(strict_types=1);

namespace App\Modules\Company;

use App\Modules\Company\Infrastructure\Services\CompanyVerticalQueryService;
use App\Shared\Contracts\Company\CompanyVerticalQueryContract;
use Illuminate\Support\ServiceProvider;

class CompanyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CompanyVerticalQueryContract::class,
            CompanyVerticalQueryService::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
