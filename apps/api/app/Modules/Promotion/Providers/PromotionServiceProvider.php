<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Providers;

use App\Modules\Promotion\Application\Services\CartPromotionService;
use App\Modules\Promotion\Domain\Contracts\PromotionEvaluatorContract;
use Illuminate\Support\ServiceProvider;

final class PromotionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromotionEvaluatorContract::class, CartPromotionService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
