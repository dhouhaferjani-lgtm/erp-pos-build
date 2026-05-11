<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianCertificationRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianCertificationExpiring;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Daily scheduled command that fires `TechnicianCertificationExpiring` for each
 * certification expiring within the configured horizon (default 30 days).
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Iterates every tenant via
 * {@see TenantScopedCommand::forEachTenant()} and queries
 * {@see TechnicianCertificationRepositoryInterface::findExpiringWithin()}
 * with the tenant id, so the underlying SQL is always tenant-scoped per
 * master plan §14 invariant 2.
 *
 * Downstream listeners (notifications, dashboards) react independently — this
 * command emits the signal, it does not deliver notifications itself.
 */
final class CheckExpiringCertifications extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'workshop:check-expiring-certifications {--days=30}';

    /** @var string */
    protected $description = 'Emit TechnicianCertificationExpiring events for techs whose certs expire within N days (default 30).';

    public function __construct(
        CompanyContext $companyContext,
        private readonly TechnicianCertificationRepositoryInterface $certifications,
        private readonly Dispatcher $events,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        /** @var int $days */
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be a positive integer.');

            return self::INVALID;
        }

        $totalDispatched = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use ($days, &$totalDispatched): int {
            $expiring = $this->certifications->findExpiringWithin($days, $tenant->id);

            foreach ($expiring as $cert) {
                if ($cert->expires_at === null) {
                    continue;
                }

                $this->events->dispatch(new TechnicianCertificationExpiring(
                    certification_id: $cert->id,
                    technician_profile_id: $cert->technician_profile_id,
                    expires_at: $cert->expires_at->toDateTimeImmutable(),
                ));
                $totalDispatched++;
            }

            return self::SUCCESS;
        });

        $this->info(sprintf('Dispatched %d expiring-certification events.', $totalDispatched));

        return $exit;
    }
}
