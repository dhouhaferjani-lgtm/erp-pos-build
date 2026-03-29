<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Listeners\SendEnrichmentNotificationListener;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes.php');

        Event::listen(EnrichmentResultReadyEvent::class, SendEnrichmentNotificationListener::class);
    }
}
