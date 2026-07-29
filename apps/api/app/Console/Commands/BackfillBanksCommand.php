<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\Bank;
use Database\Seeders\BanksSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Productized replacement for the Phase-2 / bank-directory ad-hoc tinker backfill
 * (docs/handoff/bank-directory-deploy-checklist.md §2). Seeds the canonical bank
 * directory onto every company of the CURRENT tenant by delegating to
 * {@see BanksSeeder} — the single source of truth for the directory rows and their
 * idempotency contract (row existence matched on tenant/country/rib_bank_code, name
 * for null-code banks; canonical rows refresh only bic/position/city; custom banks
 * are never touched).
 *
 * ## Fleet invocation
 *
 * Runs per tenant via stancl `tenants:run`. The bare `--dry-run` flag is NOT valid
 * under `tenants:run` (it errors); pass options with the `--option=` form:
 *
 *   php artisan tenants:run treasury:backfill-banks                     # apply, all tenants
 *   php artisan tenants:run treasury:backfill-banks --option=dry-run=1  # preview, all tenants
 *   php artisan tenants:run treasury:backfill-banks --tenants=<uuid>    # scope to one tenant
 *
 * Direct (already inside a bound tenant context): `php artisan treasury:backfill-banks [--dry-run]`.
 *
 * ## Operational contract
 *
 * - **Exit codes:** exit 1 ONLY on unavailable tenant tables or a delegate (seeder)
 *   throw; every other outcome — including "no companies" and "country has no directory
 *   file" — is exit 0 by design. `tenants:run` discards child exit codes (vendor
 *   behavior), so deploy verification greps stdout for the stable markers below.
 * - **Grep-gate markers (stdout):**
 *   - success/summary: `Bank directory backfill:`
 *   - dry-run: `[DRY-RUN]`
 *   - legitimate skip (only TN ships a directory today; non-TN companies are skipped): `skipped (no directory)`
 *   - abort/failure: `No further companies were processed.` (paired log literal
 *     `treasury:backfill-banks failed for a company; aborting.`)
 * - **Preview accuracy:** the directory is tenant-scoped, so the whole company batch is
 *   previewed inside ONE rolled-back transaction; per-company counts are computed
 *   cumulatively (a second company sharing the tenant+country reports 0 created).
 * - **FK ordering:** assumes the fleet is already past
 *   `2026_07_13_090000_add_bank_foreign_key_to_payment_instruments`; on a tenant still
 *   catching up across that boundary, run this backfill before that migration
 *   (checklist §1).
 *
 * Iterates explicit {@see Company} Eloquent instances rather than
 * `db:seed --class=BanksSeeder`, which silently no-ops because the container resolves
 * the seeder's `?Company` parameter to an empty Company (checklist §2 trap).
 *
 * @cross-tenant-by-design Backfill run per tenant via `tenants:run`; iterates every company of the bound tenant to seed the shared bank directory.
 */
final class BackfillBanksCommand extends Command
{
    protected $signature = 'treasury:backfill-banks
                            {--dry-run : Report the directory rows that would be created/updated without writing them}';

    protected $description = 'Backfill the canonical bank directory for every company in the current tenant.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly BanksSeeder $seeder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('banks')) {
            $this->error(
                'Tenant tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $companies = Company::query()->orderBy('id')->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        /** @var array{company: Company, exception: Throwable}|null $failure */
        $failure = null;

        // The bank directory is tenant-scoped, so a single preview transaction lets
        // per-company counts accumulate correctly across companies that share a tenant.
        if ($dryRun) {
            $this->database->beginTransaction();
        }

        try {
            foreach ($companies as $company) {
                $countryCode = strtoupper($company->country_code);
                $directory = database_path("data/banks/{$countryCode}.json");

                if (! is_file($directory)) {
                    $this->warn(sprintf(
                        'Company %s (%s): no bank directory ships for country %s; skipped (no directory).',
                        $company->id,
                        $company->name,
                        $countryCode,
                    ));
                    $skipped++;

                    continue;
                }

                $before = $this->snapshot((string) $company->tenant_id);

                try {
                    $this->seeder->run($company);
                } catch (Throwable $exception) {
                    $failure = ['company' => $company, 'exception' => $exception];

                    break;
                }

                [$companyCreated, $companyUpdated] = $this->diff($before, $this->snapshot((string) $company->tenant_id));
                $created += $companyCreated;
                $updated += $companyUpdated;

                $this->line(sprintf(
                    '%sCompany %s (%s): %d created, %d updated.',
                    $dryRun ? '[DRY-RUN] ' : '',
                    $company->id,
                    $countryCode,
                    $companyCreated,
                    $companyUpdated,
                ));
            }
        } finally {
            // The snapshot reads and the per-company reporting sit OUTSIDE the delegate
            // try/catch, so a query failure there would otherwise bypass the rollback and
            // hand `tenants:run` back a connection with an open (on PostgreSQL, aborted)
            // transaction that poisons every later tenant in the fleet loop.
            if ($dryRun) {
                $this->database->rollBack();
            }
        }

        if ($failure !== null) {
            Log::error('treasury:backfill-banks failed for a company; aborting.', [
                'tenant_id' => $failure['company']->tenant_id,
                'company_id' => $failure['company']->id,
                'country_code' => strtoupper($failure['company']->country_code),
                'exception_class' => $failure['exception']::class,
                'exception_message' => $failure['exception']->getMessage(),
            ]);
            $this->error(sprintf(
                'Company %s: bank backfill failed (%s). No further companies were processed.',
                $failure['company']->id,
                $failure['exception']->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sBank directory backfill: %d created, %d updated across %d company/companies; %d skipped (no directory).',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $updated,
            $companies->count() - $skipped,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Snapshot the directory-owned fields BanksSeeder refreshes, keyed by bank id, so
     * creations and bic/position/city updates can be counted honestly.
     *
     * Values are kept as a TYPED tuple rather than a joined string: `bic` and `city` are
     * nullable, and flattening them (e.g. `$bank->bic ?? ''`) would make the signature
     * non-injective — a seeder rewrite from `''` to NULL (or back) mutates the row while
     * producing an identical signature, so the command would under-report updates in
     * exactly the honest-counting path this snapshot exists to serve.
     *
     * @return array<string, array{bic: string|null, position: int, city: string|null}>
     */
    private function snapshot(string $tenantId): array
    {
        /** @var array<string, array{bic: string|null, position: int, city: string|null}> $rows */
        $rows = [];
        foreach (Bank::query()->where('tenant_id', $tenantId)->get(['id', 'bic', 'position', 'city']) as $bank) {
            $rows[(string) $bank->id] = [
                'bic' => $bank->bic,
                'position' => $bank->position,
                'city' => $bank->city,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{bic: string|null, position: int, city: string|null}>  $before
     * @param  array<string, array{bic: string|null, position: int, city: string|null}>  $after
     * @return array{int, int} [created, updated]
     */
    private function diff(array $before, array $after): array
    {
        $created = 0;
        $updated = 0;

        foreach ($after as $id => $signature) {
            if (! array_key_exists($id, $before)) {
                $created++;

                continue;
            }

            if ($before[$id] !== $signature) {
                $updated++;
            }
        }

        return [$created, $updated];
    }
}
