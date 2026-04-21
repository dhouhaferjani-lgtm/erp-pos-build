<?php

declare(strict_types=1);

namespace App\Modules\Service\Providers;

use App\Modules\Service\Application\Contracts\ServiceRepositoryInterface;
use App\Modules\Service\Infrastructure\Persistence\EloquentServiceRepository;
use Illuminate\Support\ServiceProvider;

class ServiceModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServiceRepositoryInterface::class, EloquentServiceRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
