<?php

declare(strict_types=1);

namespace App\Console;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
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
 * ACTIVE tenant ({@see TenantStatus::Active}; see
 * {@see self::forEachTenant()} for why non-active statuses are skipped).
 * Subclass calls {@see self::forEachTenant()} from
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
     * **Contract (continue-on-throw, explicit as of 2026-07-10):** a
     * per-tenant `\Throwable` is caught, logged, and that tenant's slot is
     * aggregated as FAILURE — the loop then CONTINUES to the remaining
     * tenants. It never aborts the batch. A caller that requires
     * abort-on-first-failure semantics must implement that itself inside
     * `$fn` (e.g. by checking a flag before starting the next tenant's work
     * and returning early) — `forEachTenant()` itself has no opt-in/opt-out
     * for this behavior.
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
     * **Active-only iteration (2026-08-05, staging follow-up A7):** only
     * tenants whose central `status` is {@see TenantStatus::Active} run the
     * closure. Every other lifecycle status — Suspended (database preserved
     * but access cut off at request time by the same check
     * `ResolveTenancy`/`AuthController` apply), Pending (provisioning never
     * completed, so the per-tenant database may not exist yet) and Archived —
     * is SKIPPED with a single INFO log line per tenant per run. Before this
     * filter, `Tenant::all()` was unfiltered and every scheduler tick called
     * `tenancy()->initialize()` on a missing/closed database, so the
     * continue-on-throw handler below emitted an ERROR per tenant per tick:
     * unactionable alert noise for a deliberate lifecycle state. A skipped
     * tenant is NOT a failure — it never degrades the aggregate exit code.
     * Commands that must reach non-active tenants (deprovisioning,
     * re-provisioning, lifecycle repair) are cat-(a-singleshot) and take an
     * explicit `--tenant` instead; they never route through here.
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachTenant(callable $fn): int
    {
        $aggregate = self::SUCCESS;
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        foreach (Tenant::all() as $tenant) {
            /** @var Tenant $tenant */
            if (! $tenant->isActive()) {
                Log::info('TenantScopedCommand::forEachTenant skipping non-active tenant.', [
                    'tenant_id' => $tenant->id,
                    'tenant_status' => $tenant->status->value,
                    'command' => static::class,
                ]);

                continue;
            }

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
