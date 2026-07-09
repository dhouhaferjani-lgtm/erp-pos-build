<?php

declare(strict_types=1);

namespace App\Console;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Abstract base for Artisan commands that touch multi-tenant resources.
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md §14.
 *
 * Two sub-shapes are supported by the same base:
 *
 * **(a-singleshot)** — single command run binds to one tenant + one company.
 * Subclass declares `--tenant=<uuid>` + `--company=<uuid>` options in its
 * signature and calls {@see self::bindTenantAndCompanyFromOptions()} as the
 * first line of {@see self::executeCommand()}. The base validates both
 * arguments via `ScopedExists::tenant('companies', $tenantId)` and binds
 * `CompanyContext` for the run.
 *
 * **(a-per-tenant-iter)** — scheduler / batch command that iterates every
 * tenant. Subclass calls {@see self::forEachTenant()} from
 * {@see self::executeCommand()} and supplies a closure that does the
 * per-tenant work under the bound tenant. The closure receives the
 * {@see Tenant} model and returns an exit code; the base aggregates them.
 *
 * Per master plan §14 invariant 2, schedulers MUST iterate explicitly per
 * tenant; they MUST NOT issue cross-tenant queries from the command body.
 *
 * The base enforces a template-method contract: {@see self::handle()} is
 * `final` and just calls {@see self::executeCommand()}. Subclasses
 * implement `executeCommand()` instead of overriding `handle()` directly.
 *
 * Architecture-test enforcement lives at
 * `tests/Architecture/ConsoleCommandTenantContextTest.php` (added in Step 7
 * of the api.console-commands cluster).
 */
abstract class TenantScopedCommand extends Command
{
    public function __construct(
        protected readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    final public function handle(): int
    {
        return $this->executeCommand();
    }

    abstract protected function executeCommand(): int;

    /**
     * Validate `--tenant` + `--company`, ensure the company belongs to the
     * tenant, and bind `CompanyContext`. Returns null on success, or the
     * exit code to return on failure (after printing the error to stderr).
     *
     * Single-shot commands call this as the first line of `executeCommand()`:
     * `if (($code = $this->bindTenantAndCompanyFromOptions()) !== null) return $code;`
     */
    protected function bindTenantAndCompanyFromOptions(): ?int
    {
        $tenantId = $this->stringOption('tenant');
        $companyId = $this->stringOption('company');

        if ($tenantId === null) {
            $this->error('The --tenant option is required (UUID of the parent tenant).');

            return self::INVALID;
        }
        if ($companyId === null) {
            $this->error('The --company option is required (UUID of the company; must belong to --tenant).');

            return self::INVALID;
        }

        $validator = Validator::make(
            ['tenant' => $tenantId, 'company' => $companyId],
            [
                'tenant' => ['required', 'uuid', Rule::exists('tenants', 'id')],
                'company' => ['required', 'uuid', ScopedExists::tenant('companies', $tenantId)],
            ],
            [
                'tenant.exists' => "Tenant {$tenantId} does not exist.",
                'company.exists' => "Company {$companyId} does not belong to tenant {$tenantId}.",
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $err) {
                $this->error($err);
            }

            return self::INVALID;
        }

        $this->companyContext->setCompanyId($companyId);

        return null;
    }

    /**
     * Iterate every tenant, running `$fn($tenant)` for each one. The closure
     * returns an exit code per tenant; the aggregate exit is SUCCESS only if
     * every tenant returned SUCCESS, otherwise the first non-SUCCESS code is
     * returned.
     *
     * Used by cat-(a-per-tenant-iter) scheduler commands. Per master plan §14
     * invariant 2, this is the canonical way to drive a per-tenant iteration
     * from a scheduler.
     *
     * **Per-tenant failure isolation** (2026-07-09 audit finding N1): a
     * `\Throwable` escaping `$fn($tenant)` for one tenant is caught here,
     * logged with the tenant id, and recorded as a FAILURE for that tenant's
     * slot in the aggregate — it does NOT abort iteration for the remaining
     * tenants. Every current caller — treasury:reconcile
     * (ReconcileTreasuryCommand), accounting:check-subledger-reconciliation
     * (CheckSubledgerReconciliationCommand), workshop:check-expiring-
     * certifications (CheckExpiringCertifications), scheduling:schedule-
     * appointment-reminders (ScheduleAppointmentReminders),
     * fiscal:retry-projections (RetryFiscalProjectionsCommand), and
     * pos:expire-held-orders (ExpireHeldOrdersCommand) — is a
     * scheduled/operator-invoked batch or maintenance job where partial
     * per-tenant progress is strictly more valuable than an all-or-nothing
     * abort. None of them require (nor did any existing test assert)
     * abort-on-first-tenant-failure, so this widens to unconditional
     * continue-on-throw rather than an opt-in flag (surveyed 2026-07-09,
     * audit-fix-1).
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachTenant(callable $fn): int
    {
        $aggregate = self::SUCCESS;
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        foreach (Tenant::all() as $tenant) {
            /** @var Tenant $tenant */
            $initialized = false;
            $exit = self::SUCCESS;

            try {
                if ($dbPerTenant) {
                    tenancy()->initialize($tenant);
                    $initialized = true;
                }

                $exit = $fn($tenant);
            } catch (Throwable $e) {
                Log::error('TenantScopedCommand::forEachTenant tenant iteration failed; continuing with remaining tenants.', [
                    'tenant_id' => $tenant->id,
                    'command' => static::class,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
                $exit = self::FAILURE;
            } finally {
                if ($initialized && tenancy()->initialized) {
                    tenancy()->end();
                }
            }

            if ($exit !== self::SUCCESS && $aggregate === self::SUCCESS) {
                $aggregate = $exit;
            }
        }

        return $aggregate;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
