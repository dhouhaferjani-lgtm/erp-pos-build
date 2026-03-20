<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Product\Infrastructure\Listeners\BroadcastProductEventsListener;
use Illuminate\Support\Facades\Broadcast;
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
        // Register broadcasting auth endpoint under API middleware
        // (default `channels:` in withRouting uses `web` middleware with CSRF,
        // but our frontend authenticates via Bearer token, not session cookies)
        Broadcast::routes(['middleware' => ['api', 'auth:sanctum']]);

        // Load channel authorization definitions
        require base_path('routes/channels.php');

        // Register event subscribers that handle broadcasting
        Event::subscribe(BroadcastProductEventsListener::class);
    }
}
