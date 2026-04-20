<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Commands;

use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianCertificationRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianCertificationExpiring;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Daily scheduled command that fires `TechnicianCertificationExpiring` for each
 * certification expiring within the configured horizon (default 30 days).
 *
 * Downstream listeners (notifications, dashboards) react independently — this
 * command emits the signal, it does not deliver notifications itself.
 */
final class CheckExpiringCertifications extends Command
{
    /** @var string */
    protected $signature = 'workshop:check-expiring-certifications {--days=30}';

    /** @var string */
    protected $description = 'Emit TechnicianCertificationExpiring events for techs whose certs expire within N days (default 30).';

    public function __construct(
        private readonly TechnicianCertificationRepositoryInterface $certifications,
        private readonly Dispatcher $events,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var int $days */
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be a positive integer.');

            return self::INVALID;
        }

        $expiring = $this->certifications->findExpiringWithin($days);

        foreach ($expiring as $cert) {
            if ($cert->expires_at === null) {
                continue;
            }

            $this->events->dispatch(new TechnicianCertificationExpiring(
                certification_id: $cert->id,
                technician_profile_id: $cert->technician_profile_id,
                expires_at: $cert->expires_at->toDateTimeImmutable(),
            ));
        }

        $this->info(sprintf('Dispatched %d expiring-certification events.', $expiring->count()));

        return self::SUCCESS;
    }
}
