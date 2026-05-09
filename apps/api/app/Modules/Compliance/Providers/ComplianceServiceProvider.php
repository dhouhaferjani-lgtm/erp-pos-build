<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Providers;

use App\Modules\Compliance\Application\Contracts\NotificationDispatcherInterface;
use App\Modules\Compliance\Application\Services\CompanyFraudSettingsService;
use App\Modules\Compliance\Application\Services\ComplianceNotificationDispatcher;
use App\Modules\Compliance\Commands\ExportNf525JetCommand;
use App\Modules\Compliance\Commands\VerifyFiscalChainsCommand;
use App\Modules\Compliance\Listeners\DomainEventSubscriber;
use App\Modules\Compliance\Listeners\OpenFraudAlertForShiftVariance;
use App\Modules\Compliance\Presentation\Controllers\AuditController;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Compliance\Services\FraudAlertNotificationService;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Inventory\Application\Services\FraudTriggeredCountingService;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Shared\Contracts\Company\CompanyVerticalQueryContract;
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

        $this->app->singleton(FraudAlertNotificationService::class, function () {
            return new FraudAlertNotificationService;
        });

        $this->app->singleton(NotificationDispatcherInterface::class, function ($app) {
            return new ComplianceNotificationDispatcher(
                $app->make(FraudAlertNotificationService::class),
            );
        });

        $this->app->singleton(AnomalyDetectionService::class, function ($app) {
            return new AnomalyDetectionService(
                $app->make(AuditService::class),
                $app->make(FraudAlertNotificationService::class),
                $app->make(FraudTriggeredCountingService::class),
            );
        });

        $this->app->singleton(CompanyFraudSettingsService::class, function ($app) {
            return new CompanyFraudSettingsService(
                $app->make(CompanyVerticalQueryContract::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyFiscalChainsCommand::class,
                ExportNf525JetCommand::class,
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

        Event::listen(
            CashCountRecorded::class,
            [OpenFraudAlertForShiftVariance::class, 'handle'],
        );
    }

    private function registerRoutes(): void
    {
        // Load module routes (routes.php defines its own middleware)
        $this->loadRoutesFrom(base_path('app/Modules/Compliance/Presentation/routes.php'));

        // Legacy audit routes — gated to admins/owners with the
        // `compliance.view_reprint_log` permission (semantically a fiscal
        // audit-log read; same set of roles already authorized for the
        // NF525 audit log). Tenant scope: SetPermissionsTeam pins Spatie
        // team_id to the user's tenant; CompanyContextMiddleware (in the
        // global `api` group) verifies the X-Company-Id header against
        // the user's UserCompanyMembership so AuditController can call
        // CompanyContext->requireCompanyId() safely.
        Route::middleware([
            'api',
            'auth:sanctum',
            SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])
            ->prefix('api/v1')
            ->group(function (): void {
                Route::get('/audit/events', [AuditController::class, 'index'])
                    ->middleware('can:compliance.view_reprint_log');
                Route::get('/audit/anomalies', [AuditController::class, 'anomalies'])
                    ->middleware('can:compliance.view_reprint_log');
            });
    }
}
