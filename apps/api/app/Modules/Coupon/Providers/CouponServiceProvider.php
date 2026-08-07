<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Providers;

use App\Modules\Coupon\Application\Services\CouponApplicationService;
use App\Modules\Coupon\Application\Services\CouponPartnerReferenceSource;
use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

final class CouponServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([CouponPartnerReferenceSource::class], PartnerReferenceSource::class);

        $this->app->bind(CouponValidatorContract::class, CouponApplicationService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
