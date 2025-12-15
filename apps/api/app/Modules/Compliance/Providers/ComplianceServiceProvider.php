<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Providers;

use App\Modules\Compliance\Commands\VerifyFiscalChainsCommand;
use App\Modules\Compliance\Listeners\DomainEventSubscriber;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Compliance\Services\FiscalHashService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FiscalHashService::class, function () {
            return new FiscalHashService;
        });

        $this->app->singleton(AuditService::class, function () {
            return new AuditService;
        });

        $this->app->singleton(AnomalyDetectionService::class, function ($app) {
            return new AnomalyDetectionService($app->make(AuditService::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyFiscalChainsCommand::class,
            ]);
        }

        $this->registerRoutes();
        $this->registerEventSubscribers();
    }

    /**
     * Register domain event subscribers for audit logging.
     */
    private function registerEventSubscribers(): void
    {
        Event::subscribe(DomainEventSubscriber::class);
    }

    private function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api/v1')
            ->group(function (): void {
                Route::get('/audit/events', [\App\Modules\Compliance\Presentation\Controllers\AuditController::class, 'index']);
                Route::get('/audit/anomalies', [\App\Modules\Compliance\Presentation\Controllers\AuditController::class, 'anomalies']);
            });
    }
}
