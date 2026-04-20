<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Infrastructure;

use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Contracts\PlatformVehicleResolverInterface;
use App\Modules\Workshop\Bundle\Domain\Contracts\ProductResolverInterface;
use App\Modules\Workshop\Bundle\Domain\Contracts\ServiceResolverInterface;
use App\Modules\Workshop\Bundle\Infrastructure\Persistence\EloquentBundleRepository;
use App\Modules\Workshop\Bundle\Infrastructure\Resolvers\EloquentProductResolver;
use App\Modules\Workshop\Bundle\Infrastructure\Resolvers\EloquentServiceResolver;
use App\Modules\Workshop\Bundle\Infrastructure\Resolvers\PlatformIntegrationVehicleResolver;
use Illuminate\Support\ServiceProvider;

class BundleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BundleRepositoryInterface::class, EloquentBundleRepository::class);
        $this->app->bind(ProductResolverInterface::class, EloquentProductResolver::class);
        $this->app->bind(ServiceResolverInterface::class, EloquentServiceResolver::class);
        $this->app->bind(PlatformVehicleResolverInterface::class, PlatformIntegrationVehicleResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
