<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Providers;

use App\Modules\Promotion\Application\Services\CartPromotionService;
use App\Modules\Promotion\Application\Services\PromotionPartnerReferenceSource;
use App\Modules\Promotion\Domain\Contracts\PromotionEvaluatorContract;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

final class PromotionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([PromotionPartnerReferenceSource::class], PartnerReferenceSource::class);

        $this->app->bind(PromotionEvaluatorContract::class, CartPromotionService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
