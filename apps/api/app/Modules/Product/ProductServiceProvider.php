<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use App\Modules\Product\Application\Contracts\ProductRepositoryInterface;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Application\Services\DiscountPolicySubjectProvider;
use App\Modules\Product\Application\Services\ProductResolver;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Infrastructure\Persistence\EloquentProductRepository;
use App\Modules\Product\Presentation\Console\RunEnrichmentCommand;
use App\Shared\Contracts\DiscountPolicySubjectProviderInterface;
use App\Shared\Contracts\ProductResolverInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(DiscountPolicySubjectProviderInterface::class, DiscountPolicySubjectProvider::class);
        $this->app->bind(ProductResolverInterface::class, ProductResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Event::listen(EnrichmentWebhookReceived::class, ProcessEnrichmentEventListener::class);

        // Bug 1 — coarse POS catalog refresh signal. Product carries
        // tenant_id / company_id directly, so the observer's default
        // attribute-lookup path is sufficient. Pass the class name (not an
        // instance) — `Model::observe()` re-resolves the observer from the
        // container, so any constructor state on a passed instance would
        // be lost.
        Product::observe(CatalogModelObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunEnrichmentCommand::class,
            ]);
        }
    }
}
