<?php

declare(strict_types=1);

namespace App\Modules\Partner;

use App\Modules\Partner\Application\Contracts\PartnerRepositoryInterface;
use App\Modules\Partner\Infrastructure\Persistence\EloquentPartnerRepository;
use Illuminate\Support\ServiceProvider;

class PartnerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PartnerRepositoryInterface::class, EloquentPartnerRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
