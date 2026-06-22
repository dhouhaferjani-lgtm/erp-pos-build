<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use ValueError;

/**
 * Scheduled subledger-control reconciliation alert.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). The command iterates tenants via
 * TenantScopedCommand::forEachTenant(), then queries companies scoped to the
 * current tenant before reconciling configured subledger purposes.
 */
final class CheckSubledgerReconciliationCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'accounting:check-subledger-reconciliation {--purpose=* : Optional SystemAccountPurpose values to scan}';

    /** @var string */
    protected $description = 'Report partner subledger/control-account discrepancies for scheduled alerting.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly PartnerBalanceService $partnerBalanceService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $purposes = $this->purposesFromOptions();
        if ($purposes === []) {
            return self::INVALID;
        }

        $discrepancies = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use ($purposes, &$discrepancies): int {
            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', CompanyStatus::Active)
                ->get();

            foreach ($companies as $company) {
                foreach ($purposes as $purpose) {
                    try {
                        $result = $this->partnerBalanceService->reconcileSubledger($company->id, $purpose);
                    } catch (ModelNotFoundException) {
                        continue;
                    }

                    if ($result['is_balanced'] && $result['entries_without_partner'] === 0) {
                        continue;
                    }

                    $discrepancies++;
                    $context = [
                        'tenant_id' => $tenant->id,
                        'company_id' => $company->id,
                        'purpose' => $purpose->value,
                        'entries_without_partner' => $result['entries_without_partner'],
                        'difference' => $result['difference'],
                        'control_account_balance' => $result['control_account_balance'],
                        'subledger_total' => $result['subledger_total'],
                        'account_code' => $result['account_code'],
                        'account_name' => $result['account_name'],
                    ];

                    Log::warning('Partner subledger reconciliation discrepancy detected.', $context);

                    $this->line(sprintf(
                        'Subledger discrepancy tenant=%s company=%s purpose=%s entries_without_partner=%d difference=%s control=%s subledger=%s account=%s',
                        $tenant->id,
                        $company->id,
                        $purpose->value,
                        $result['entries_without_partner'],
                        $result['difference'],
                        $result['control_account_balance'],
                        $result['subledger_total'],
                        $result['account_code'],
                    ));
                }
            }

            return self::SUCCESS;
        });

        if ($discrepancies > 0) {
            $this->error(sprintf('Detected %d subledger reconciliation discrepancies.', $discrepancies));

            return self::FAILURE;
        }

        $this->info('No subledger reconciliation discrepancies detected.');

        return $exit;
    }

    /**
     * @return list<SystemAccountPurpose>
     */
    private function purposesFromOptions(): array
    {
        $values = $this->option('purpose');
        if ($values === []) {
            return [
                SystemAccountPurpose::CustomerReceivable,
                SystemAccountPurpose::CustomerAdvance,
                SystemAccountPurpose::SupplierPayable,
            ];
        }

        $purposes = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $purposes[] = SystemAccountPurpose::from($value);
            } catch (ValueError) {
                $this->error("Invalid --purpose value: {$value}");

                return [];
            }
        }

        return array_values(array_unique($purposes, SORT_REGULAR));
    }
}
