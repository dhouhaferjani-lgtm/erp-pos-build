<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RepositoryCensusFinding;
use App\Modules\Treasury\Application\DTOs\RepositoryCensusResult;
use App\Modules\Treasury\Application\DTOs\RepositoryNormalisationActionData;
use App\Modules\Treasury\Application\Services\RepositoryCensusService;
use App\Modules\Treasury\Domain\Enums\RepositoryCensusCode;
use App\Modules\Treasury\Domain\Enums\RepositoryNormalisationAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Guarded operator normalisation for pre-G-3c safe catalogue drift.
 *
 * No migration is shipped deliberately: every origin/dev push runs
 * tenants:migrate fleet-wide without an operator, while repository data and
 * money-bearing references vary by tenant. This command is dry-run-first,
 * company-scoped and refuses anything that needs a treasury document. It never
 * writes balance or location_id and never creates a movement or journal entry.
 * PostgreSQL apply runs hold a company advisory transaction lock and recheck
 * every deactivation candidate immediately before its metadata update. Direct
 * reference writers do not yet take that advisory lock, so a residual window
 * remains between the final reference query and the guarded update.
 */
final class NormaliseRepositoriesCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'treasury:normalise-repositories
        {--tenant= : Restrict the run to one tenant id}
        {--company= : Normalise one company id}
        {--all-companies : Normalise every company in the selected tenant scope}
        {--apply : Apply the printed metadata-only actions}';

    /** @var string */
    protected $description = 'Dry-run-first normalisation of clean duplicate safes and canonical safe GL links; refuses money-bearing rows.';

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

        $apply = (bool) $this->option('apply');
        $companiesVisited = 0;
        $companyFailures = 0;
        $refusals = 0;
        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use (
                $allCompanies,
                $apply,
                $companyId,
                &$companiesVisited,
                &$companyFailures,
                &$refusals,
            ): int {
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when(! $allCompanies, static fn ($query) => $query->whereKey($companyId))
                    ->orderBy('id')
                    ->get();

                foreach ($companies as $company) {
                    $companiesVisited++;
                    $this->companyContext->setCompanyId($company->id);

                    try {
                        $companyRefused = $apply
                            ? DB::transaction(fn (): bool => $this->normaliseCompany((string) $tenant->id, $company->id, true))
                            : $this->normaliseCompany((string) $tenant->id, $company->id, false);
                        if ($companyRefused) {
                            $refusals++;
                        }
                    } catch (Throwable $exception) {
                        $companyFailures++;
                        $this->error(sprintf('Company %s normalisation failed: %s', $company->id, $exception->getMessage()));
                    }
                }

                return self::SUCCESS;
            },
        );

        if ($this->skippedTenantIds() !== []
            || $this->visitedTenantIds() === []
            || $exit !== self::SUCCESS
            || $companiesVisited === 0
            || $companyFailures > 0
            || $refusals > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function normaliseCompany(string $tenantId, string $companyId, bool $apply): bool
    {
        if ($apply) {
            $this->acquireCompanyAdvisoryLock($companyId);
            DB::table('payment_repositories')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

        $result = $this->censusService->census($tenantId, $companyId);
        $prefix = $apply ? '[APPLY]' : '[DRY-RUN]';
        $skippedCodes = [];

        foreach ($result->findingsFor(RepositoryCensusCode::CashLocationNull) as $finding) {
            $skippedCodes[] = $finding->code->value;
            $this->line(sprintf('%s COMPANY %s NO ACTION %s — %s', $prefix, $companyId, $finding->code->value, $finding->hint));
        }

        $refusedFindings = $result->findingsFor(RepositoryCensusCode::DuplicateMoneyBearing);
        foreach ($refusedFindings as $finding) {
            $this->error(sprintf(
                '%s COMPANY %s REFUSED %s repository=%s — %s',
                $prefix,
                $companyId,
                $finding->code->value,
                $finding->repositoryCode ?? $finding->repositoryId ?? '-',
                $finding->hint,
            ));
        }

        if ($result->hasCode(RepositoryCensusCode::DuplicatePerLocationType)) {
            foreach ($result->findingsFor(RepositoryCensusCode::DuplicatePerLocationType) as $finding) {
                $skippedCodes[] = $finding->code->value;
                $this->line(sprintf('%s COMPANY %s NO ACTION %s — %s', $prefix, $companyId, $finding->code->value, $finding->hint));
            }

            if ($apply) {
                $this->logApplyRun($companyId, [], $refusedFindings, $skippedCodes);
            }

            return $refusedFindings !== [];
        }

        $actions = $this->plannedActions($result, $companyId, $skippedCodes);
        $appliedActions = [];
        $initialMoneyBearing = [];
        if ($apply) {
            foreach ($actions as $action) {
                $initialMoneyBearing[$action->repositoryId] = $action->action === RepositoryNormalisationAction::DeactivateSurplusSafe
                    ? false
                    : $this->censusService->isMoneyBearingRepository($tenantId, $companyId, $action->repositoryId);
            }
        }
        foreach ($actions as $action) {
            if ($apply) {
                $moneyBearingNow = $this->censusService->isMoneyBearingRepository(
                    $tenantId,
                    $companyId,
                    $action->repositoryId,
                );
                if (! $initialMoneyBearing[$action->repositoryId] && $moneyBearingNow) {
                    $finding = new RepositoryCensusFinding(
                        code: RepositoryCensusCode::DuplicateMoneyBearing,
                        companyId: $companyId,
                        repositoryId: $action->repositoryId,
                        hint: 'post a `RepositoryTransfer` (`RepositoryTransferService`) from this safe to the canonical one, then re-run',
                    );
                    $refusedFindings[] = $finding;
                    $this->error(sprintf(
                        '%s COMPANY %s REFUSED %s repository=%s — %s',
                        $prefix,
                        $companyId,
                        $finding->code->value,
                        $finding->repositoryId,
                        $finding->hint,
                    ));

                    continue;
                }
            }

            $this->line(sprintf(
                '%s COMPANY %s %s repository=%s%s',
                $prefix,
                $companyId,
                $action->action->value,
                $action->repositoryId,
                $action->glAccountId === null ? '' : ' gl_account='.$action->glAccountId,
            ));

            if ($apply) {
                if ($this->applyAction($tenantId, $companyId, $action)) {
                    $appliedActions[] = $action;
                }
            }
        }

        if ($apply) {
            $this->logApplyRun($companyId, $appliedActions, $refusedFindings, $skippedCodes);
        }

        return $refusedFindings !== [];
    }

    /**
     * @param  list<string>  $skippedCodes
     * @return list<RepositoryNormalisationActionData>
     */
    private function plannedActions(RepositoryCensusResult $result, string $companyId, array &$skippedCodes): array
    {
        $actions = [];
        foreach ($result->findingsFor(RepositoryCensusCode::SafeDuplicateClean) as $finding) {
            if ($finding->repositoryId !== null) {
                $actions[] = new RepositoryNormalisationActionData(
                    RepositoryNormalisationAction::DeactivateSurplusSafe,
                    $finding->repositoryId,
                );
            }
        }

        $canonicalFinding = $this->canonicalUnlinkedFinding($result);
        if ($canonicalFinding === null || $result->canonicalSafeId === null) {
            return $actions;
        }

        $cashAccount = Account::findByPurpose($companyId, SystemAccountPurpose::Cash);
        if (! $cashAccount instanceof Account) {
            $skippedCodes[] = RepositoryCensusCode::SafeGlUnlinked->value;
            $this->warn(sprintf(
                'COMPANY %s SKIP %s repository=%s — no cash-purpose account exists; repair the chart and re-run.',
                $companyId,
                RepositoryCensusCode::SafeGlUnlinked->value,
                $result->canonicalSafeId,
            ));

            return $actions;
        }

        $actions[] = new RepositoryNormalisationActionData(
            RepositoryNormalisationAction::LinkCanonicalSafe,
            $result->canonicalSafeId,
            $cashAccount->id,
        );

        return $actions;
    }

    private function canonicalUnlinkedFinding(RepositoryCensusResult $result): ?RepositoryCensusFinding
    {
        foreach ($result->findingsFor(RepositoryCensusCode::SafeGlUnlinked) as $finding) {
            if ($finding->repositoryId === $result->canonicalSafeId) {
                return $finding;
            }
        }

        return null;
    }

    private function applyAction(string $tenantId, string $companyId, RepositoryNormalisationActionData $action): bool
    {
        $updated = match ($action->action) {
            RepositoryNormalisationAction::DeactivateSurplusSafe => DB::table('payment_repositories')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('id', $action->repositoryId)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]),
            RepositoryNormalisationAction::LinkCanonicalSafe => DB::table('payment_repositories')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('id', $action->repositoryId)
                ->whereNull('gl_account_id')
                ->update(['gl_account_id' => $action->glAccountId, 'updated_at' => now()]),
        };

        return $updated === 1;
    }

    private function acquireCompanyAdvisoryLock(string $companyId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$companyId]);
        }
    }

    /**
     * @param  list<RepositoryNormalisationActionData>  $actions
     * @param  list<RepositoryCensusFinding>  $refusedFindings
     * @param  list<string>  $skippedCodes
     */
    private function logApplyRun(
        string $companyId,
        array $actions,
        array $refusedFindings,
        array $skippedCodes,
    ): void {
        $refusedCodes = array_map(
            static fn (RepositoryCensusFinding $finding): string => $finding->code->value,
            $refusedFindings,
        );

        Log::info('repositories.normalised', [
            'company_id' => $companyId,
            'actions' => array_map(
                static fn (RepositoryNormalisationActionData $action): array => $action->toArray(),
                $actions,
            ),
            'refused_codes' => $refusedCodes,
            'refused_count' => count($refusedCodes),
            'skipped_codes' => $skippedCodes,
            'skipped_count' => count($skippedCodes),
        ]);
    }
}
