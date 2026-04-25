<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Compliance\Application\Contracts\NotificationDispatcherInterface;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Compliance\Infrastructure\Repositories\CompanyFraudSettingsRepository;
use App\Modules\Compliance\Infrastructure\Repositories\FraudAlertRepository;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Shared\Domain\Enums\VarianceSeverity;

final class OpenFraudAlertForShiftVariance
{
    public function __construct(
        private readonly FraudAlertRepository $alerts,
        private readonly CompanyFraudSettingsRepository $settingsRepository,
        private readonly NotificationDispatcherInterface $notifier,
    ) {}

    public function handle(CashCountRecorded $event): void
    {
        if ($event->aggregateVariance->isZero()) {
            return;
        }

        $description = sprintf(
            '%s: variance %s %s %s',
            strtoupper($event->severity->value),
            $event->varianceDirection->value,
            $event->aggregateVariance->amount,
            $event->currencyCode,
        );

        $alert = $this->alerts->firstOrCreateByZReport(
            zReportId: $event->zReportId,
            alertType: 'SHIFT_CLOSE_VARIANCE',
            attributes: [
                'company_id' => $event->companyId,
                'tenant_id' => $event->tenantId,
                'user_id' => $event->cashierId,
                'severity' => $event->severity->value,
                'description' => $description,
                'description_code' => $event->descriptionCode,
                'description_params' => $event->descriptionParams,
                'detected_at' => now(),
                'metadata' => [
                    'z_report_id' => $event->zReportId,
                    'shift_id' => $event->shiftId,
                    'terminal_id' => $event->terminalId,
                    'cashier_id' => $event->cashierId,
                    'manager_id' => $event->managerOverrideBy,
                    'blind_count_used' => $event->blindCountUsed,
                    'variance_direction' => $event->varianceDirection->value,
                    'variance_amount' => $event->aggregateVariance->amount,
                    'currency_code' => $event->currencyCode,
                    'tender_breakdown' => array_map(
                        static fn ($dto) => $dto->toArray(),
                        $event->tenderBreakdown,
                    ),
                ],
                'status' => 'open',
            ],
        );

        $emailSeverity = $this->resolveEmailSeverity($event->companyId);
        if (VarianceSeverity::shouldEmailAt($emailSeverity, $event->severity)) {
            $this->notifier->dispatchToFraudEmails($alert);
        }
    }

    private function resolveEmailSeverity(string $companyId): string
    {
        $row = $this->settingsRepository->findByCompany($companyId);
        if ($row !== null) {
            return (string) $row->cash_variance_email_severity;
        }
        $defaults = CompanyFraudSettings::getDefaults();

        return (string) $defaults['cash_variance_email_severity'];
    }
}
