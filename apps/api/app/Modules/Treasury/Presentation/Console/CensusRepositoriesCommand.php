<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RepositoryCensusResult;
use App\Modules\Treasury\Application\Services\RepositoryCensusService;
use Throwable;

/**
 * Read-only operator census for legacy payment-repository drift.
 *
 * This deliberately ships without a migration: every origin/dev push runs
 * tenants:migrate unattended, tenant data differs, and repository repair needs
 * an operator-visible decision and a justifying document before treasury state
 * changes. Every statement issued by this command is a SELECT.
 */
final class CensusRepositoriesCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'treasury:census-repositories
        {--tenant= : Restrict the census to one tenant id}
        {--company= : Census one company id}
        {--all-companies : Census every company in the selected tenant scope}
        {--json : Emit one machine-readable JSON document}';

    /** @var string */
    protected $description = 'READ-ONLY census of legacy payment-repository location, safe-count, GL-link and duplicate drift.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly RepositoryCensusService $censusService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $companyId = $this->stringOption('company');
        $allCompanies = (bool) $this->option('all-companies');
        if (($companyId === null) === ! $allCompanies) {
            $this->error('Pass exactly one of --company=<uuid> or --all-companies.');

            return self::FAILURE;
        }

        /** @var list<array{tenant_id: string, company_id: string, company_name: string, findings: list<array{code: string, company_id: string, repository_id: string|null, repository_code: string|null, repository_type: string|null, location_id: string|null, attribution_verdict: string|null, canonical_repository_id: string|null, hint: string}>}> $reports */
        $reports = [];
        $companyFailures = 0;
        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use ($allCompanies, $companyId, &$companyFailures, &$reports): int {
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when(! $allCompanies, static fn ($query) => $query->whereKey($companyId))
                    ->orderBy('id')
                    ->get();

                foreach ($companies as $company) {
                    try {
                        $this->companyContext->setCompanyId($company->id);
                        $result = $this->censusService->census((string) $tenant->id, $company->id);
                        $reports[] = $this->report($company, $result);
                    } catch (Throwable $exception) {
                        $companyFailures++;
                        $this->error(sprintf('Company %s census failed: %s', $company->id, $exception->getMessage()));
                    }
                }

                return self::SUCCESS;
            },
        );

        $reason = $this->incompletenessReason($exit, count($reports), $companyFailures);
        $payload = [
            'command' => 'treasury:census-repositories',
            'generated_at' => now()->toIso8601String(),
            'complete' => $reason === null,
            'reason' => $reason,
            'totals' => [
                'tenants_visited' => count($this->visitedTenantIds()),
                'tenants_skipped' => count($this->skippedTenantIds()),
                'companies_visited' => count($reports),
                'companies_failed' => $companyFailures,
                'findings' => array_sum(array_map(
                    static fn (array $report): int => count($report['findings']),
                    $reports,
                )),
            ],
            'companies' => $reports,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderHuman($reports, $reason);
        }

        return $reason === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{tenant_id: string, company_id: string, company_name: string, findings: list<array{code: string, company_id: string, repository_id: string|null, repository_code: string|null, repository_type: string|null, location_id: string|null, attribution_verdict: string|null, canonical_repository_id: string|null, hint: string}>}
     */
    private function report(Company $company, RepositoryCensusResult $result): array
    {
        return [
            'tenant_id' => $result->tenantId,
            'company_id' => $result->companyId,
            'company_name' => $company->name,
            'findings' => array_map(
                static fn ($finding): array => $finding->toArray(),
                $result->findings,
            ),
        ];
    }

    private function incompletenessReason(int $exit, int $companiesVisited, int $companyFailures): ?string
    {
        if ($this->skippedTenantIds() !== []) {
            return 'tenants_skipped';
        }

        if ($this->visitedTenantIds() === []) {
            return 'no_tenants_in_directory';
        }

        if ($companyFailures > 0) {
            return 'company_iteration_failed';
        }

        if ($exit !== self::SUCCESS) {
            return 'tenant_iteration_failed';
        }

        if ($companiesVisited === 0) {
            return 'no_companies_visited';
        }

        return null;
    }

    /**
     * @param  list<array{tenant_id: string, company_id: string, company_name: string, findings: list<array{code: string, company_id: string, repository_id: string|null, repository_code: string|null, repository_type: string|null, location_id: string|null, attribution_verdict: string|null, canonical_repository_id: string|null, hint: string}>}>  $reports
     */
    private function renderHuman(array $reports, ?string $reason): void
    {
        foreach ($reports as $report) {
            if ($report['findings'] === []) {
                $this->line(sprintf('COMPANY %s CLEAN', $report['company_id']));

                continue;
            }

            foreach ($report['findings'] as $finding) {
                $this->line(sprintf(
                    'COMPANY %s %s repository=%s verdict=%s — %s',
                    $report['company_id'],
                    $finding['code'],
                    $finding['repository_code'] ?? '-',
                    $finding['attribution_verdict'] ?? '-',
                    $finding['hint'],
                ));
            }
        }

        if ($reason !== null) {
            $this->error(sprintf('CENSUS INCOMPLETE (%s).', $reason));
        }
    }
}
