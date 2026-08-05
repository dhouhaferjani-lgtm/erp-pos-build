<?php

declare(strict_types=1);

namespace App\Console;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Application\Services\TenancyResolver;
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
 * tenant in the central directory whose per-tenant database can actually be
 * opened (see {@see self::forEachTenant()} — lifecycle STATUS is deliberately
 * NOT a filter). Subclass calls {@see self::forEachTenant()} from
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
    /** @var list<string> */
    private array $visitedTenantIds = [];

    /** @var list<string> */
    private array $skippedTenantIds = [];

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
     * **Database-existence probe, NOT a status filter (2026-08-05, staging
     * follow-up A7 — revised after adversarial review):** the failure this
     * guard exists to stop is "the per-tenant database is missing/unreachable,
     * so `tenancy()->initialize()` throws on every scheduler tick and the
     * continue-on-throw handler below emits an unactionable ERROR". The
     * predicate for that is database existence, not lifecycle label, so under
     * db-per-tenant mode this probes
     * `$tenant->database()->manager()->databaseExists(...)` exactly the way
     * {@see TenancyResolver::initializeIfProvisioned()}
     * does before initializing, and SKIPS the tenant with a WARNING log line
     * (a tenant row with no database is genuinely broken, so it is worth an
     * operator's attention — but it is NOT a failure and never degrades the
     * aggregate exit code).
     *
     * **The probe fails CLOSED when it cannot be answered (2026-08-05 re-gate,
     * N1):** a probe that RETURNS false means "there is no database" ⇒ skip +
     * WARNING (above). A probe that THROWS means the question could not be
     * asked — central connection lost mid-run, or no database manager
     * registered for the driver — and is recorded as that tenant's FAILURE
     * (ERROR log, non-zero aggregate exit), exactly the way
     * {@see TenancyResolver::initializeIfProvisioned()} raises
     * `TenantUnavailableException` on the same fault. Without this a transient
     * central outage skipped every tenant at WARNING with exit 0 and no
     * scheduler `onFailure()` hook ever fired.
     *
     * Lifecycle status is deliberately NOT consulted. Pending tenants are
     * live and transacting at request time (`ResolveTenancy` and
     * `AuthController` only reject Suspended/Archived, and `tenants.status`
     * defaults to `pending`), and Suspended is a REVERSIBLE state whose
     * database is preserved on purpose — silently disabling every batch
     * control (cash-drift freeze, fiscal dead-letter recovery, batch-expiry
     * alerts, one-time backfills) on either of those is the wrong default for
     * a compliance-oriented ERP. Archived / failed-provision rows are exactly
     * the ones with no database, so the probe covers them.
     *
     * In single-schema compat mode (`tenancy_resolver.db_per_tenant=false`)
     * there are no per-tenant databases at all and `initialize()` is a no-op,
     * so no probe runs and every directory row is visited.
     *
     * **Visited / skipped surface:** the tenant ids that ran the closure and
     * the ones the probe skipped are recorded for the duration of the call
     * ({@see self::visitedTenantIds()}, {@see self::skippedTenantIds()}) so an
     * operator-supplied `--tenant` filter applied INSIDE `$fn` can fail loudly
     * when its target was never reached — see
     * {@see self::failIfTenantFilterUnvisited()}. Both lists are reset at the
     * start of every `forEachTenant()` call.
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachTenant(callable $fn): int
    {
        $aggregate = self::SUCCESS;
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        $this->visitedTenantIds = [];
        $this->skippedTenantIds = [];

        foreach (Tenant::all() as $tenant) {
            /** @var Tenant $tenant */
            if ($dbPerTenant) {
                try {
                    $databaseExists = $this->tenantDatabaseExists($tenant);
                } catch (Throwable $e) {
                    // The probe could not be ANSWERED (central connection lost,
                    // no database manager registered for the driver). That is an
                    // infra fault, not evidence of a missing database: fail
                    // closed for this tenant so the aggregate exit is non-zero
                    // and the scheduler's onFailure() hook fires.
                    $this->skippedTenantIds[] = (string) $tenant->id;

                    Log::error('TenantScopedCommand::forEachTenant could not probe tenant database existence; failing this tenant.', [
                        'tenant_id' => $tenant->id,
                        'tenant_status' => $tenant->status->value,
                        'command' => static::class,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);

                    if ($aggregate === self::SUCCESS) {
                        $aggregate = self::FAILURE;
                    }

                    continue;
                }

                if (! $databaseExists) {
                    $this->skippedTenantIds[] = (string) $tenant->id;

                    Log::warning('TenantScopedCommand::forEachTenant skipping tenant whose database is not provisioned.', [
                        'tenant_id' => $tenant->id,
                        'tenant_status' => $tenant->status->value,
                        'command' => static::class,
                    ]);

                    continue;
                }
            }

            $this->visitedTenantIds[] = (string) $tenant->id;

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

    /**
     * Tenant ids whose closure actually ran during the last
     * {@see self::forEachTenant()} call (a tenant whose closure THREW counts
     * as visited — that failure is already loud in the aggregate exit code).
     *
     * @return list<string>
     */
    protected function visitedTenantIds(): array
    {
        return $this->visitedTenantIds;
    }

    /**
     * Tenant ids whose closure did NOT run during the last
     * {@see self::forEachTenant()} call because the database-existence probe
     * either answered "no database" (skip + WARNING, aggregate untouched) or
     * could not be answered at all (FAILURE + ERROR). Both belong here: what
     * {@see self::failIfTenantFilterUnvisited()} needs to tell an operator is
     * that nothing was processed for the tenant, and the wording it emits
     * ("does not exist or could not be opened") covers both.
     *
     * @return list<string>
     */
    protected function skippedTenantIds(): array
    {
        return $this->skippedTenantIds;
    }

    /**
     * Fail loudly when an operator supplied `--tenant=<id>` and that tenant was
     * never reached by the preceding {@see self::forEachTenant()} call.
     *
     * Commands that implement `--tenant` as a filter INSIDE the iteration
     * closure (treasury:reconcile, fiscal:retry-projections and the two
     * one-time backfills) would otherwise exit SUCCESS having done literally
     * nothing when the target tenant is absent from the directory or was
     * skipped by the database probe — the exact scenario an operator running a
     * repair command is least able to detect. Call this immediately after
     * `forEachTenant()`:
     *
     * `if (($miss = $this->failIfTenantFilterUnvisited($tenantFilter)) !== null) return $miss;`
     *
     * Returns null when there is nothing to report (no filter, or the tenant
     * was visited), otherwise the exit code to return after printing to stderr.
     */
    protected function failIfTenantFilterUnvisited(?string $tenantFilter): ?int
    {
        if ($tenantFilter === null || in_array($tenantFilter, $this->visitedTenantIds, true)) {
            return null;
        }

        if (in_array($tenantFilter, $this->skippedTenantIds, true)) {
            $this->error(sprintf(
                'Tenant %s was skipped: its per-tenant database does not exist or could not be opened. '.
                'Nothing was processed for it — re-run once the database is restored.',
                $tenantFilter,
            ));

            return self::FAILURE;
        }

        $this->error(sprintf(
            'Tenant %s was not found in the central tenant directory. Nothing was processed.',
            $tenantFilter,
        ));

        return self::INVALID;
    }

    /**
     * Mirror of {@see TenancyResolver}'s pre-initialize probe.
     *
     * **Deliberately does NOT swallow.** A throwing probe (central connection
     * down, no database manager registered for the driver) means the question
     * could not be ASKED — it is not an answer of "no database". The caller
     * turns a throw into that tenant's FAILURE (see the loop above), matching
     * {@see TenancyResolver::initializeIfProvisioned()}, which raises
     * `TenantUnavailableException` on the same fault under db-per-tenant.
     * Swallowing it here made a single central-connection blip skip 100% of the
     * fleet at WARNING with exit 0, silencing every scheduler `onFailure()`
     * hook (2026-08-05 re-gate, N1).
     */
    private function tenantDatabaseExists(Tenant $tenant): bool
    {
        return $tenant->database()->manager()->databaseExists($tenant->getDatabaseName());
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
