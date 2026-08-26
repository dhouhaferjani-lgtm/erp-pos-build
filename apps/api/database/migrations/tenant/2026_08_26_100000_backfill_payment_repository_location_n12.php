<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign lane N-12 — attribute the day-one drawers every EXISTING tenant has.
 *
 * WHAT IS BROKEN. `PaymentRepositorySeeder` used to insert `CASH-01` / `SAFE-01`
 * with `location_id = NULL`, and nothing has ever filled that column since. The
 * Playwright first-tenant campaign measured what it costs the moment a tenant
 * opens a second shop: `CASH-01 in 452.000 (POS01, Main) · in 200.000 (POS02,
 * Ariana)` — two branches' takings commingled in one balance, so no per-branch
 * cash count could reconcile against anything, and the dashboard filed the lot
 * under *Unattributed*.
 *
 * The seeder now attributes the pair, but a seeder only runs for a NEW tenant.
 * Without this migration every tenant already in the fleet keeps the NULL pair
 * forever — and {@see \App\Modules\Treasury\Application\Services\TenderRepositoryResolver}
 * deliberately keeps serving those (tier 2) so the code change alone is
 * behaviour-preserving. Attribution is what actually arms tier 1 for an
 * existing tenant, which is what stops a second branch drawing on the first
 * one's till.
 *
 * ## THE PREDICATE — as narrow as the defect
 *
 * A repository is attributed when, and only when, ALL of the following hold:
 *
 *   1. `location_id IS NULL`. An attributed row is never moved — not the ones
 *      the new seeder wrote, not the ones an operator bound by hand, not on a
 *      re-run. That is what makes this idempotent AND safe to re-run after a
 *      deliberate re-attribution.
 *   2. `type IN ('cash_register', 'safe')` — a DRAWER. A `bank_account` or
 *      `virtual` repository carries no branch identity: the seeder never mints
 *      one, the repositories UI leaves `location_id` null on the ones an
 *      operator adds, and a card tender at any branch settles into the same
 *      company account. Binding one to Main would make the resolver refuse it
 *      at every OTHER branch, which is the opposite of the intent.
 *   3. The company has a location to attribute to. A company with none keeps
 *      the pre-N-12 shape, which the resolver still serves in full.
 *
 * ## WHICH LOCATION
 *
 * `is_default` first, then a POS-enabled one, then the oldest — the exact order
 * `PaymentRepositorySeeder::defaultLocationId()` uses, so a tenant migrated
 * today and a tenant registered today end up in the same shape. Active
 * locations are preferred over deactivated ones for the same reason: a drawer
 * hanging off a location nobody operates is not an improvement.
 *
 * ## WHAT AN OPERATOR MAY SEE AFTERWARDS, AND WHY IT IS THE POINT
 *
 * On a multi-branch tenant, the branch that never had a drawer of its own stops
 * resolving to Main's till. That is the lane's ruling — refuse rather than
 * borrow — and it surfaces BEFORE any money exists: `TerminalController`
 * answers a claim at a drawer-less location with a typed 422
 * `LOCATION_HAS_NO_CASH_REGISTER`, and toggling `pos_enabled` on that location
 * provisions its own cash register (`LocationCashRegisterProvisioner`). A
 * refusal is replayable the moment the drawer exists; a receipt booked into
 * another branch's balance is unreconcilable forever.
 */
return new class extends Migration
{
    /**
     * Grep token — under `tenants:migrate` every tenant shares one laravel.log,
     * so an unattributed line cannot be acted on.
     */
    private const GATE_TOKEN = 'N-12-REPOSITORY-LOCATION-BACKFILL';

    /**
     * The repository types that BELONG to a location. Mirrors
     * `TenderRepositoryResolver::DRAWER_TYPES` — kept as literals because a
     * migration must keep describing the schema as it was on the day it ran,
     * even if the enum is later extended.
     *
     * @var list<string>
     */
    private const DRAWER_TYPES = ['cash_register', 'safe'];

    public function up(): void
    {
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        foreach (['payment_repositories', 'locations'] as $table) {
            if (! Schema::hasTable($table)) {
                Log::warning(sprintf(
                    '%s tenant=%s status=skipped reason=%s-table-absent.',
                    self::GATE_TOKEN,
                    $tenantKey,
                    str_replace('_', '-', $table),
                ));

                return;
            }
        }

        if (! Schema::hasColumn('payment_repositories', 'location_id')) {
            Log::warning(sprintf(
                '%s tenant=%s status=skipped reason=location-id-column-absent.',
                self::GATE_TOKEN,
                $tenantKey,
            ));

            return;
        }

        $connection = DB::connection($this->getConnection());
        $attributed = 0;
        $companies = 0;

        $connection->transaction(function () use ($connection, &$attributed, &$companies): void {
            /** @var list<string> $companyIds */
            $companyIds = $connection->table('payment_repositories')
                ->whereNull('location_id')
                ->whereIn('type', self::DRAWER_TYPES)
                ->distinct()
                ->pluck('company_id')
                ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
                ->values()
                ->all();

            foreach ($companyIds as $companyId) {
                $locationId = $connection->table('locations')
                    ->where('company_id', $companyId)
                    ->orderByDesc('is_default')
                    ->orderByDesc('is_active')
                    ->orderByDesc('pos_enabled')
                    ->orderBy('created_at')
                    ->value('id');

                if (! is_string($locationId) || $locationId === '') {
                    continue;
                }

                $companies++;
                $attributed += $connection->table('payment_repositories')
                    ->where('company_id', $companyId)
                    ->whereNull('location_id')
                    ->whereIn('type', self::DRAWER_TYPES)
                    ->update([
                        'location_id' => $locationId,
                        'updated_at' => now(),
                    ]);
            }
        });

        Log::warning(sprintf(
            '%s tenant=%s status=ok companies=%d attributed=%d.',
            self::GATE_TOKEN,
            $tenantKey,
            $companies,
            $attributed,
        ));
    }

    /**
     * Irreversible by design: `location_id = NULL` is indistinguishable from a
     * row an operator deliberately unbound afterwards, so a down() would have to
     * guess which rows it had written and could silently un-attribute a drawer
     * the tenant now counts per branch. Re-running `up()` is the recovery path.
     */
    public function down(): void
    {
        Log::warning(sprintf(
            '%s tenant=%s status=irreversible reason=cannot-distinguish-backfilled-from-operator-unbound.',
            self::GATE_TOKEN,
            (string) (tenant()?->getTenantKey() ?? 'unknown'),
        ));
    }
};
