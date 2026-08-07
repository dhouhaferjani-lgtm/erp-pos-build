<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Providers;

use App\Modules\Compliance\Services\AuditService;
use App\Modules\SupportAccess\Application\Services\RequestImpersonationContext;
use App\Modules\SupportAccess\Domain\Repositories\ImpersonationGrantRepository;
use App\Modules\SupportAccess\Domain\Services\SupportAccessConfigurationValidator;
use App\Modules\SupportAccess\Infrastructure\Identity\EloquentTenantSubjectDirectory;
use App\Modules\SupportAccess\Infrastructure\Identity\TenantSubjectTokenAdapter;
use App\Modules\SupportAccess\Infrastructure\Notifications\TenantDatabaseSupportAccessNotifier;
use App\Modules\SupportAccess\Infrastructure\Repositories\EloquentImpersonationGrantRepository;
use App\Modules\SupportAccess\Presentation\Console\VerifyImpersonationAuditCommand;
use App\Services\AdminAuditService;
use App\Shared\Contracts\SupportAccess\AdminImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\Contracts\SupportAccess\SupportAccessNotifier;
use App\Shared\Contracts\SupportAccess\TenantImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\TenantSubjectDirectory;
use App\Shared\Contracts\SupportAccess\TenantSubjectTokenPort;
use Illuminate\Support\ServiceProvider;

final class SupportAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestImpersonationContext::class);
        $this->app->alias(RequestImpersonationContext::class, ImpersonationContextProvider::class);
        $this->app->bind(ImpersonationGrantRepository::class, EloquentImpersonationGrantRepository::class);
        $this->app->bind(TenantSubjectDirectory::class, EloquentTenantSubjectDirectory::class);
        $this->app->bind(SupportAccessNotifier::class, TenantDatabaseSupportAccessNotifier::class);
        $this->app->bind(TenantSubjectTokenPort::class, TenantSubjectTokenAdapter::class);
        $this->app->bind(AdminImpersonationAuditWriter::class, AdminAuditService::class);
        $this->app->bind(TenantImpersonationAuditWriter::class, AuditService::class);
    }

    public function boot(SupportAccessConfigurationValidator $configuration): void
    {
        $configuration->validate();
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyImpersonationAuditCommand::class]);
        }
    }
}
