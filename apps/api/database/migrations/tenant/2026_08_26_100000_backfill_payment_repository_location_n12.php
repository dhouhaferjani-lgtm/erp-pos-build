<?php

declare(strict_types=1);

use App\Modules\Treasury\Application\Services\LocationCashRegisterProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign lane N-12 — give every existing tenant the per-branch drawers it
 * should have been provisioned with, WITHOUT stopping a single till.
 *
 * WHAT IS BROKEN. `PaymentRepositorySeeder` used to insert `CASH-01` / `SAFE-01`
 * with `location_id = NULL`, and nothing has ever filled that column since. The
 * Playwright first-tenant campaign measured what it costs the moment a tenant
 * opens a second shop: `CASH-01 in 452.000 (POS01, Main) · in 200.000 (POS02,
 * Ariana)` — two branches' takings commingled in one balance, so no per-branch
 * cash count could reconcile, and the dashboard filed the lot under
 * *Unattributed*. The seeder now attributes the day-one pair, but a seeder only
 * runs for a NEW tenant.
 *
 * ## WHY ATTRIBUTION ALONE WOULD HAVE BEEN A FLEET OUTAGE (gate r1, both lenses)
 *
 * Attributing Main's drawers arms the resolver's tier 1 for Main and, for a
 * branch that owns no drawer, leaves NO candidate at all. The lane's only guard
 * is at `TerminalController::claim()` — and a device that bound its
 * `hardware_identifier` before this lane never re-enters `claim()`. `SESSION_OPEN`
 * is device-authored and `ShiftController::open()` is retired for
 * device-authoritative terminals, so there is no second server gate. The branch
 * would have kept selling while every cash leg threw in `TreasuryReceiptBridge`,
 * burned its retries and dead-lettered — after the customer had paid, with the
 * cashier seeing nothing. `push to origin/dev` auto-runs `tenants:migrate`, so
 * that would have shipped fleet-wide with no human step.
 *
 * This migration therefore does not merely attribute. It leaves NO POS-capable
 * location without a drawer: step 2 mints one through the same
 * {@see LocationCashRegisterProvisioner} the live `pos_enabled` flip uses —
 * attributed, GL-linked to the company's `cash` purpose account, currency
 * defaulted from the company by the model, born at balance 0. Refusal is left
 * where it belongs: NEW claims at a location an operator has not set up.
 *
 * ## STEP 1 — ATTRIBUTE ONLY WHAT IS UNAMBIGUOUS
 *
 * The repositories UI leaves `location_id` NULL on every row an operator adds,
 * so a till someone created FOR A BRANCH is indistinguishable from `CASH-01`.
 * Binding it to Main would move a branch's drawer to the wrong branch, and
 * `down()` cannot tell it back apart. A type is therefore attributed only when
 * the answer cannot be wrong:
 *
 *   - exactly ONE unattributed drawer of that type in the company, or
 *   - the company has exactly one location.
 *
 * Anything else is left NULL — which is the pre-N-12 shape, still fully served
 * by the resolver's tier 2 — and listed in the census line so an operator can
 * attribute them deliberately.
 *
 * ## STEP 2 — NO POS-CAPABLE LOCATION LEFT WITHOUT A DRAWER
 *
 * Runs only for a company whose attribution was unambiguous. On an ambiguous
 * company nothing is armed, every location still resolves through tier 2, and
 * minting per-location drawers there would strand the existing balances in the
 * unattributed rows while new sales went to empty ones. Status quo is the safe
 * answer until a human decides.
 *
 * ## STEP 3 — ONE DRAWER PER LOCATION PER TYPE, ENFORCED
 *
 * `provision()` is read-then-insert, so two concurrent `PATCH /locations/{id}
 * {pos_enabled:true}` calls could mint two drawers for one location; the
 * resolver's `orderBy('id')` tie-break would then silently pick one while the
 * other accumulated nothing. A partial unique index closes it. Self-guarding:
 * if a tenant already holds duplicates the index is skipped and censused rather
 * than failing the migration and blocking every later one.
 */
return new class extends Migration
{
    /**
     * Grep token — under `tenants:migrate` every tenant shares one laravel.log,
     * so an unattributed line cannot be acted on.
     */
    private const GATE_TOKEN = 'N-12-REPOSITORY-LOCATION-BACKFILL';

    private const INDEX_NAME = 'payment_repositories_one_drawer_per_location_type';

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
                $this->log($tenantKey, 'skipped', ['reason' => str_replace('_', '-', $table).'-table-absent']);

                return;
            }
        }

        if (! Schema::hasColumn('payment_repositories', 'location_id')) {
            $this->log($tenantKey, 'skipped', ['reason' => 'location-id-column-absent']);

            return;
        }

        $connection = DB::connection($this->getConnection());
        $attributed = 0;
        $provisioned = 0;
        $ambiguousCompanies = 0;

        /** @var list<string> $companyIds */
        $companyIds = $connection->table('payment_repositories')
            ->distinct()
            ->pluck('company_id')
            ->merge($connection->table('locations')->distinct()->pluck('company_id'))
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $provisioner = new LocationCashRegisterProvisioner;

        foreach ($companyIds as $companyId) {
            $tenantId = $connection->table('payment_repositories')
                ->where('company_id', $companyId)
                ->value('tenant_id');

            $defaultLocationId = $this->defaultLocationId($connection, $companyId);
            if (! is_string($defaultLocationId)) {
                continue;
            }

            $locationCount = (int) $connection->table('locations')->where('company_id', $companyId)->count();
            $ambiguous = [];

            // ---- Step 1: attribute, but only when NOTHING is ambiguous ------
            //
            // Ambiguity is judged per type and acted on for the WHOLE company. A
            // partial attribution is worse than none: attributing only the safe
            // would arm tier 1 for the default location off that safe, cut it
            // off from the still-unattributed tills, and route its cash into the
            // safe. Skipping the company leaves every location on tier 2 —
            // exactly today's behaviour — until a human attributes deliberately.
            /** @var array<string, list<string>> $unattributed */
            $unattributed = [];

            foreach (self::DRAWER_TYPES as $type) {
                /** @var list<string> $codes */
                $codes = $connection->table('payment_repositories')
                    ->where('company_id', $companyId)
                    ->whereNull('location_id')
                    ->where('type', $type)
                    ->pluck('code')
                    ->filter(static fn (mixed $code): bool => is_string($code))
                    ->values()
                    ->all();

                if ($codes === []) {
                    continue;
                }

                $unattributed[$type] = $codes;

                if (count($codes) > 1 && $locationCount > 1) {
                    $ambiguous[$type] = $codes;
                }
            }

            if ($ambiguous === []) {
                foreach (array_keys($unattributed) as $type) {
                    $attributed += $connection->table('payment_repositories')
                        ->where('company_id', $companyId)
                        ->whereNull('location_id')
                        ->where('type', $type)
                        ->update([
                            'location_id' => $defaultLocationId,
                            'updated_at' => now(),
                        ]);
                }
            }

            // ---- Step 2: no POS-capable location left without a drawer ------
            if (! is_string($tenantId)) {
                continue;
            }

            foreach ($this->posCapableLocations($connection, $companyId) as $location) {
                /** @var object{id: string, code: string|null} $location */
                if ($provisioner->hasOwnDrawer($tenantId, $companyId, $location->id)) {
                    continue;
                }

                $repositoryId = $provisioner->provision($tenantId, $companyId, $location->id, $location->code);

                if (is_string($repositoryId)) {
                    $provisioned++;

                    continue;
                }

                // The provisioner logs its own reason (no `cash` purpose account
                // on this chart). Censused here too so one grep line tells the
                // whole story for this tenant.
                $this->log($tenantKey, 'provision-failed', [
                    'company_id' => $companyId,
                    'location_id' => $location->id,
                ]);
            }
        }

        // ---- Step 3: one drawer per location per type -----------------------
        $indexStatus = $this->ensureOneDrawerPerLocationIndex($connection, $tenantKey);

        $this->log($tenantKey, 'ok', [
            'attributed' => $attributed,
            'provisioned' => $provisioned,
            'ambiguous_companies' => $ambiguousCompanies,
            'index' => $indexStatus,
        ]);
    }

    /**
     * Irreversible attribution by design: `location_id = NULL` is
     * indistinguishable from a row an operator deliberately unbound afterwards,
     * so a down() would have to guess which rows it had written and could
     * silently un-attribute a drawer the tenant now counts per branch. Nor are
     * the provisioned drawers dropped — by the time anyone rolls back they may
     * hold money and carry movements. The index, which is pure schema, does go.
     */
    public function down(): void
    {
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        if (Schema::hasTable('payment_repositories')) {
            DB::connection($this->getConnection())
                ->statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
        }

        $this->log($tenantKey, 'irreversible', [
            'reason' => 'cannot-distinguish-backfilled-from-operator-unbound; provisioned drawers may hold money',
        ]);
    }

    /**
     * `is_default`, then an active one, then a POS-enabled one, then the oldest
     * — byte-for-byte `PaymentRepositorySeeder::defaultLocationId()`, so a
     * tenant migrated today and a tenant registered today land identically.
     */
    private function defaultLocationId(Connection $connection, string $companyId): ?string
    {
        $locationId = $connection->table('locations')
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderByDesc('pos_enabled')
            ->orderBy('created_at')
            ->value('id');

        return is_string($locationId) ? $locationId : null;
    }

    /**
     * Locations that can take money: POS is enabled there, or a terminal exists
     * there (a terminal claimed before `pos_enabled` became load-bearing keeps
     * selling regardless of the flag — that is the population the gate found).
     *
     * @return list<object{id: string, code: string|null}>
     */
    private function posCapableLocations(Connection $connection, string $companyId): array
    {
        $query = $connection->table('locations')
            ->where('company_id', $companyId)
            ->select(['id', 'code']);

        if (Schema::hasTable('pos_terminals')) {
            $query->where(function ($where) use ($connection, $companyId): void {
                $where->where('pos_enabled', true)
                    ->orWhereIn('id', $connection->table('pos_terminals')
                        ->where('company_id', $companyId)
                        ->distinct()
                        ->pluck('location_id')
                        ->filter(static fn (mixed $id): bool => is_string($id))
                        ->values()
                        ->all());
            });
        } else {
            $query->where('pos_enabled', true);
        }

        /** @var list<object{id: string, code: string|null}> $rows */
        $rows = $query->orderBy('created_at')->get()->all();

        return $rows;
    }

    private function ensureOneDrawerPerLocationIndex(
        Connection $connection,
        string $tenantKey,
    ): string {
        if ($connection->getDriverName() !== 'pgsql') {
            return 'skipped-driver';
        }

        /** @var list<object{company_id: string, location_id: string, type: string, n: int}> $duplicates */
        $duplicates = $connection->table('payment_repositories')
            ->selectRaw('company_id, location_id, type, COUNT(*) AS n')
            ->whereNotNull('location_id')
            ->whereIn('type', self::DRAWER_TYPES)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->groupBy('company_id', 'location_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->all();

        if ($duplicates !== []) {
            $this->log($tenantKey, 'index-skipped-duplicates', [
                'groups' => count($duplicates),
            ]);

            return 'skipped-duplicates';
        }

        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON payment_repositories (company_id, location_id, type) '.
            "WHERE location_id IS NOT NULL AND type IN ('cash_register', 'safe') ".
            'AND is_active AND gl_account_id IS NOT NULL',
            self::INDEX_NAME,
        ));

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $tenantKey, string $status, array $context): void
    {
        Log::warning(sprintf(
            '%s tenant=%s status=%s %s',
            self::GATE_TOKEN,
            $tenantKey,
            $status,
            (string) json_encode($context),
        ));
    }
};
