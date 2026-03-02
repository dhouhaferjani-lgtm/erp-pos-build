<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Providers;

use App\Modules\Coupon\Application\Services\CouponApplicationService;
use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use Illuminate\Support\ServiceProvider;

final class CouponServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CouponValidatorContract::class, CouponApplicationService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
