<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Event::listen(EnrichmentWebhookReceived::class, ProcessEnrichmentEventListener::class);
    }
}
