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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\TenantCollection;
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
    /**
     * How many tenants get an individual ERROR line when the database-existence
     * probe THROWS, before {@see self::forEachTenant()} collapses the rest into
     * one aggregate line (M3, 2026-08-05 review). The faults this covers are
     * global, so without a cap one central outage emits a line and a Sentry
     * event per tenant per tick, per scheduled command.
     */
    private const MAX_PROBE_FAULT_LOG_LINES = 3;

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
     * Those probe faults are GLOBAL, so they throw for every tenant. Individual
     * ERROR lines are therefore capped at
     * {@see self::MAX_PROBE_FAULT_LOG_LINES} and the remainder collapses into a
     * single aggregate ERROR at the end of the run (M3, 2026-08-05 review) —
     * the exit code, the skipped list and the `onFailure()` hook are unaffected.
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
        return $this->forEachTenantNarrowed(null, $fn);
    }

    /**
     * {@see self::forEachTenant()} with the directory itself narrowed to a
     * single tenant when `$tenantFilter` is supplied — probe, `initialize()`,
     * closure and bookkeeping all happen for that tenant ONLY.
     *
     * **Why the narrowing is in the QUERY and not in the closure (2026-08-05
     * wave-2 review, tenancy R1 / fiscal R3).** The first shape of this helper
     * filtered inside the iteration closure, which meant a `--tenant=X` run
     * still probed and `tenancy()->initialize()`d every directory row before
     * short-circuiting. Two consequences, both wrong:
     *
     *   - a probe throw or an `initialize()` throw on an UNRELATED tenant Y
     *     degraded the aggregate, so an operator who asked about X was told X's
     *     run failed — and for commands whose exit-code contract distinguishes
     *     "validation error" from "transient failure", Y's infra fault was
     *     reported as X's validation error;
     *   - O(fleet) database opens to answer a question about one tenant.
     *
     * The visited/skipped bookkeeping behind
     * {@see self::failIfTenantFilterUnvisited()} stays authoritative because
     * the target tenant still occupies its own slot: it is recorded as visited
     * when its closure runs, as skipped when its probe answers "no database" or
     * throws, and as neither when the directory does not hold it at all — which
     * is exactly the three-way distinction that method reports on.
     *
     * A filter that is not a well-formed UUID selects nothing rather than
     * reaching the database: `tenants.id` is a PG `uuid` column and a malformed
     * comparand raises a driver error instead of returning no rows. Selecting
     * nothing lands on the "not found in the central tenant directory" branch,
     * which is the honest answer.
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachTenantNarrowed(?string $tenantFilter, callable $fn): int
    {
        $aggregate = self::SUCCESS;
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        $this->visitedTenantIds = [];
        $this->skippedTenantIds = [];
        $probeFaults = 0;

        foreach ($this->directoryTenants($tenantFilter) as $tenant) {
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
                    $probeFaults++;

                    // Log-storm guard (M3, 2026-08-05 review): the two faults
                    // this branch exists for are GLOBAL, so they throw for every
                    // tenant — N error lines and N Sentry events per tick, per
                    // scheduled command, drowning the alert channel exactly when
                    // it matters. The first few tenants carry the actionable
                    // detail; the rest are counted and reported once below.
                    // Nothing about the FAILURE itself is suppressed.
                    if ($probeFaults <= self::MAX_PROBE_FAULT_LOG_LINES) {
                        Log::error('TenantScopedCommand::forEachTenant could not probe tenant database existence; failing this tenant.', [
                            'tenant_id' => $tenant->id,
                            'tenant_status' => $tenant->status->value,
                            'command' => static::class,
                            'exception_class' => $e::class,
                            'exception_message' => $e->getMessage(),
                        ]);
                    }

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

        if ($probeFaults > self::MAX_PROBE_FAULT_LOG_LINES) {
            Log::error('TenantScopedCommand::forEachTenant could not probe tenant database existence for MOST OF THE FLEET; this is a central-connection or driver-registration fault, not per-tenant data. Per-tenant lines above are capped — see skippedTenantIds for the full set.', [
                'command' => static::class,
                'tenants_failed_probe' => $probeFaults,
                'suppressed_log_lines' => $probeFaults - self::MAX_PROBE_FAULT_LOG_LINES,
            ]);
        }

        return $aggregate;
    }

    /**
     * The directory rows this run will iterate.
     */
    private function directoryTenants(?string $tenantFilter): TenantCollection
    {
        if ($tenantFilter === null) {
            return Tenant::all();
        }

        if (! Str::isUuid($tenantFilter)) {
            return Tenant::query()->whereRaw('1 = 0')->get();
        }

        return Tenant::query()->whereKey($tenantFilter)->get();
    }

    /**
     * {@see self::forEachTenant()} narrowed by an optional operator-supplied
     * `--tenant=<uuid>` filter, with the unvisited-target check already wired.
     *
     * This is the cat-(b) wave-2 conversion idiom for FLEET-DEFAULT commands
     * (the fiscal verifiers): no `--tenant` means "every tenant", a `--tenant`
     * means "that one tenant, and fail loudly if it was never reached". The
     * narrowing happens in the directory query — see
     * {@see self::forEachTenantNarrowed()} for why it must not happen inside
     * the closure.
     *
     * Returns the aggregate exit code, or the unvisited-filter exit code when
     * the operator's `--tenant` never opened an iteration slot. Note that the
     * latter can be {@see self::INVALID} (2); a command whose own exit-code
     * contract already assigns a meaning to 2 must remap it (see
     * `VerifyEventChainCommand`, where 2 means "transient failure").
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachTenantFiltered(?string $tenantFilter, callable $fn): int
    {
        $exit = $this->forEachTenantNarrowed($tenantFilter, $fn);

        $miss = $this->failIfTenantFilterUnvisited($tenantFilter);

        return $miss ?? $exit;
    }

    /**
     * The cat-(b) wave-2 conversion idiom for ONE-SHOT BACKFILLS: a fleet-wide
     * run must be asked for explicitly.
     *
     * Before the 2026-05-28 database-per-tenant flip these commands enumerated
     * `companies` / their own tenant tables from the console's central
     * connection and genuinely swept the fleet. After the flip that query
     * either raises 42P01 or — where a `Schema::hasTable()` guard sits in front
     * of it — reports "nothing to do". Making the fleet run implicit again
     * would recreate the B1 failure class: a one-time backfill that silently
     * skips a tenant leaves permanently wrong data behind with no signal. So
     * the caller must name its scope:
     *
     *   - `--tenant=<uuid>` — process exactly that tenant, and fail if the
     *     tenant is absent from the directory or its database cannot be opened
     *     ({@see self::failIfTenantFilterUnvisited()}).
     *   - `--all-tenants` — deliberate fleet run over every tenant whose
     *     database can be opened; a tenant whose closure throws degrades the
     *     aggregate exit but never aborts the remaining tenants.
     *
     * Neither (or both) is a usage error — {@see self::INVALID}, nothing is
     * processed. The subclass MUST declare both `{--tenant=}` and
     * `{--all-tenants}` in its signature and READ them itself: larastan's
     * `console.undefinedOption` rule resolves `$this->option('all-tenants')`
     * against every concrete subclass of the declaring class, so the base
     * cannot touch an option only some subclasses declare.
     *
     * @param  callable(Tenant): int  $fn
     */
    protected function forEachExplicitlySelectedTenant(?string $tenantFilter, bool $allTenants, callable $fn): int
    {
        if ($tenantFilter === null && ! $allTenants) {
            $this->error(
                'Refusing to run without an explicit scope: pass --tenant=<uuid> to process one tenant, '.
                'or --all-tenants for a deliberate fleet-wide run. Nothing was processed.',
            );

            return self::INVALID;
        }

        if ($tenantFilter !== null && $allTenants) {
            $this->error('--tenant and --all-tenants are mutually exclusive. Nothing was processed.');

            return self::INVALID;
        }

        return $this->forEachTenantFiltered($tenantFilter, $fn);
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
     * Print one COVERAGE line for every tenant the last iteration touched, and
     * return the ids that produced no verdict at all.
     *
     * **Why a verifier needs this (2026-08-05 wave-2 fiscal review, B3/C3).**
     * `forEachTenant()`'s two silent outcomes are invisible to the caller: a
     * tenant whose database could not be opened is skipped with a `Log::warning`
     * and no console output, and a tenant whose closure THREW is turned into a
     * FAILURE by the base with only a `Log::error`. Neither touches the
     * caller's own counters. So a verifier that summarised its own counters
     * could report "all chains verified" for a fleet where N tenants were never
     * opened and M tenants exploded — and those summary strings are what the
     * launch checklist ticks as PASS.
     *
     * The block is also the E-7 evidence artifact: a reviewer must be able to
     * see that EVERY directory tenant was accounted for, including the ones
     * that simply had nothing to verify.
     *
     * @param  array<string, string>  $verdicts  tenant id => verdict label, for every
     *                                           tenant whose closure ran to completion
     * @return list<string> tenant ids that were visited but produced no verdict
     */
    protected function reportTenantCoverage(array $verdicts): array
    {
        $this->newLine();
        $this->line('TENANT COVERAGE:');

        $unaccounted = [];

        foreach ($this->visitedTenantIds() as $tenantId) {
            if (array_key_exists($tenantId, $verdicts)) {
                $this->line(sprintf('  TENANT %s: %s', $tenantId, $verdicts[$tenantId]));

                continue;
            }

            $unaccounted[] = $tenantId;
            $this->error(sprintf(
                '  TENANT %s: ERRORED - the per-tenant run threw and nothing was verified for it (see the log).',
                $tenantId,
            ));
        }

        foreach ($this->skippedTenantIds() as $tenantId) {
            $this->error(sprintf(
                '  TENANT %s: SKIPPED - its per-tenant database does not exist or could not be opened; nothing was verified for it.',
                $tenantId,
            ));
        }

        if ($this->visitedTenantIds() === [] && $this->skippedTenantIds() === []) {
            $this->line('  (no tenant in the central directory matched this run)');
        }

        return $unaccounted;
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

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
