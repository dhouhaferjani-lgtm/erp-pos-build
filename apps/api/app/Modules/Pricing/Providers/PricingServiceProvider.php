<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use App\Modules\Pricing\Domain\Services\DatabaseRegulatoryFloorResolver;
use App\Modules\Pricing\Domain\Services\DiscountPolicyService;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\Contracts\RegulatoryFloorResolverInterface;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DiscountPolicyInterface::class, DiscountPolicyService::class);
        $this->app->bind(RegulatoryFloorResolverInterface::class, DatabaseRegulatoryFloorResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
