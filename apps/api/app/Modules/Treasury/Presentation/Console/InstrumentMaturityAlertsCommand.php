<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Notifications\TreasuryAlertNotification;
use App\Modules\Treasury\Application\Services\TreasuryAlertRecipients;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class InstrumentMaturityAlertsCommand extends TenantScopedCommand
{
    private const EVENT_TYPE = 'treasury.instrument.maturity_alert';

    private const OUTBOUND_DUE_EVENT_TYPE = 'treasury.maturity.outbound_due';

    /** @var string */
    protected $signature = 'treasury:instrument-maturity-alerts';

    /** @var string */
    protected $description = 'Raise per-company alerts for instruments requiring remittance or settlement follow-up.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly AuditService $auditService,
        private readonly TreasuryAlertRecipients $alertRecipients,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $companiesChecked = 0;
        $companiesErrored = 0;

        $tenantExit = $this->forEachTenant(function (Tenant $tenant) use (&$companiesChecked, &$companiesErrored): int {
            $tenantErrored = false;

            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();

            foreach ($companies as $company) {
                $companiesChecked++;

                try {
                    $this->alertCompany($company);
                } catch (Throwable $e) {
                    $companiesErrored++;
                    $tenantErrored = true;
                    Log::error('treasury.instrument.maturity_alert_failed', [
                        'tenant_id' => $tenant->id,
                        'company_id' => $company->id,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);
                    $this->error(sprintf(
                        'ERROR alerting company %s (tenant %s): %s',
                        $company->id,
                        $tenant->id,
                        $e->getMessage(),
                    ));
                }
            }

            return $tenantErrored ? self::FAILURE : self::SUCCESS;
        });

        $this->info(sprintf(
            'treasury:instrument-maturity-alerts — checked %d company(ies); %d error(s).',
            $companiesChecked,
            $companiesErrored,
        ));

        return $tenantExit === self::SUCCESS && $companiesErrored === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function alertCompany(Company $company): void
    {
        $windowDays = CountryPaymentSettings::query()
            ->where('country_code', $company->country_code)
            ->value('instrument_alert_days');
        $windowDays = is_numeric($windowDays) ? max(0, (int) $windowDays) : 7;

        $today = CarbonImmutable::today($company->timezone);

        /** @var list<string> $receivedDueIds */
        $receivedDueIds = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('direction', InstrumentDirection::Inbound)
            ->where('status', InstrumentStatus::Received)
            ->whereNotNull('maturity_date')
            ->whereDate('maturity_date', '<=', $today->copy()->addDays($windowDays))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        /** @var list<string> $depositedOverdueIds */
        $depositedOverdueIds = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('direction', InstrumentDirection::Inbound)
            ->where('status', InstrumentStatus::Deposited)
            ->whereNotNull('maturity_date')
            ->whereDate('maturity_date', '<', $today->copy()->subDays($windowDays))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $payload = [
            'as_of_date' => $today->toDateString(),
            'window_days' => $windowDays,
            'received_due_count' => count($receivedDueIds),
            'received_due_ids' => $receivedDueIds,
            'deposited_overdue_count' => count($depositedOverdueIds),
            'deposited_overdue_ids' => $depositedOverdueIds,
        ];

        /** @var list<string> $outboundDueIds */
        $outboundDueIds = PaymentInstrument::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('direction', InstrumentDirection::Outbound)
            ->whereIn('status', [InstrumentStatus::Received, InstrumentStatus::Bounced])
            ->whereNotNull('maturity_date')
            ->whereDate('maturity_date', '<=', $today->copy()->addDays($windowDays))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->auditService->record(
            companyId: $company->id,
            userId: null,
            eventType: self::EVENT_TYPE,
            aggregateType: 'Company',
            aggregateId: $company->id,
            payload: $payload,
            metadata: ['source' => 'treasury:instrument-maturity-alerts'],
        );

        if ($payload['received_due_count'] + $payload['deposited_overdue_count'] > 0) {
            try {
                $recipients = $this->alertRecipients->forCompany($company->tenant_id, $company->id);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new TreasuryAlertNotification(
                        alertType: self::EVENT_TYPE,
                        data: [
                            'company_id' => $company->id,
                            'company_name' => $company->name,
                            'severity' => 'warning',
                            'window_days' => $payload['window_days'],
                            'received_due_count' => $payload['received_due_count'],
                            'deposited_overdue_count' => $payload['deposited_overdue_count'],
                            'deep_link' => '/treasury/instruments?maturing=1',
                        ],
                    ));
                }
            } catch (Throwable $e) {
                Log::error('treasury.instrument.maturity_alert_failed', [
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'channel' => 'notification',
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        if ($outboundDueIds !== []) {
            $outboundPayload = [
                'as_of_date' => $today->toDateString(),
                'window_days' => $windowDays,
                'outbound_due_count' => count($outboundDueIds),
                'outbound_due_ids' => $outboundDueIds,
            ];
            $this->auditService->record(
                companyId: $company->id,
                userId: null,
                eventType: self::OUTBOUND_DUE_EVENT_TYPE,
                aggregateType: 'Company',
                aggregateId: $company->id,
                payload: $outboundPayload,
                metadata: ['source' => 'treasury:instrument-maturity-alerts'],
            );

            try {
                $recipients = $this->alertRecipients->forCompany($company->tenant_id, $company->id);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new TreasuryAlertNotification(
                        alertType: self::OUTBOUND_DUE_EVENT_TYPE,
                        data: [
                            'company_id' => $company->id,
                            'company_name' => $company->name,
                            'severity' => 'warning',
                            'window_days' => $outboundPayload['window_days'],
                            'outbound_due_count' => $outboundPayload['outbound_due_count'],
                            'deep_link' => '/treasury/instruments?maturing=1&direction=outbound',
                        ],
                    ));
                }
            } catch (Throwable $e) {
                Log::error('treasury.instrument.maturity_alert_failed', [
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'channel' => 'outbound_notification',
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }

            Log::warning(self::OUTBOUND_DUE_EVENT_TYPE, [
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                ...$outboundPayload,
            ]);
        }

        Log::warning(self::EVENT_TYPE, [
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            ...$payload,
        ]);
    }
}
