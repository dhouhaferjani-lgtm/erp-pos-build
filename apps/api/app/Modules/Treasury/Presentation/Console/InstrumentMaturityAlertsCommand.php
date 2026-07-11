<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InstrumentMaturityAlertsCommand extends TenantScopedCommand
{
    private const EVENT_TYPE = 'treasury.instrument.maturity_alert';

    /** @var string */
    protected $signature = 'treasury:instrument-maturity-alerts';

    /** @var string */
    protected $description = 'Raise per-company alerts for instruments requiring remittance or settlement follow-up.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly AuditService $auditService,
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

        $today = today();

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

        $this->auditService->record(
            companyId: $company->id,
            userId: null,
            eventType: self::EVENT_TYPE,
            aggregateType: 'Company',
            aggregateId: $company->id,
            payload: $payload,
            metadata: ['source' => 'treasury:instrument-maturity-alerts'],
        );

        Log::warning(self::EVENT_TYPE, [
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            ...$payload,
        ]);
    }
}
