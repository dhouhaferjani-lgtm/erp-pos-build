<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Product\Infrastructure\Listeners\BroadcastProductEventsListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Broadcast Service Provider
 *
 * Registers event subscribers that bridge domain events to broadcast events.
 * This maintains separation of concerns by keeping broadcasting logic in the infrastructure layer.
 */
class BroadcastServiceProvider extends ServiceProvider
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
        // Register event subscribers that handle broadcasting
        Event::subscribe(BroadcastProductEventsListener::class);
    }
}
