<?php

declare(strict_types=1);

namespace App\Modules\Partner;

use App\Modules\Partner\Application\Contracts\PartnerRepositoryInterface;
use App\Modules\Partner\Application\Services\PartnerReferenceCounter;
use App\Modules\Partner\Application\Services\PartnerResolver;
use App\Modules\Partner\Infrastructure\Persistence\EloquentPartnerRepository;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use App\Shared\Contracts\PartnerResolverInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class PartnerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PartnerRepositoryInterface::class, EloquentPartnerRepository::class);
        $this->app->bind(PartnerResolverInterface::class, PartnerResolver::class);

        // Partner delete guard (BUG-007 / lane R2-S). The counter itself
        // knows no table names — each owning module tags its own
        // `PartnerReferenceSource` in its provider's `register()`.
        //
        // `tagged()` is called INSIDE the closure, so the set is materialised
        // when the counter is first resolved (during a request), by which
        // point every module provider has registered. Deliberately `bind`
        // and not `singleton`: the sources resolve their DB connection per
        // query anyway, and a fresh instance per resolution keeps the object
        // free of any request-scoped state.
        $this->app->bind(
            PartnerReferenceCounter::class,
            static fn (Application $app): PartnerReferenceCounter => new PartnerReferenceCounter(
                $app->tagged(PartnerReferenceSource::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
