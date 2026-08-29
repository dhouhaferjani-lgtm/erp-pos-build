<?php

declare(strict_types=1);

use Database\Seeders\UomSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Census company-visible active units and backfill only a completely empty
 * provisioned tenant database.
 *
 * A database with no company row is a pre-provisioning migration target, not a
 * tenant that lost reference data. TenantInitializationService provisions that
 * database after its first company exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = '';

        try {
            if (! Schema::hasTable('units')
                || ! Schema::hasTable('unit_categories')
                || ! Schema::hasTable('companies')) {
                return;
            }

            if (! DB::table('companies')->exists()) {
                return;
            }

            $companies = DB::table('companies')->get(['id', 'tenant_id']);
            $emptyCompanyIds = [];

            foreach ($companies as $company) {
                $companyId = (string) $company->id;
                $tenantId = (string) $company->tenant_id;

                // Deliberately inline the historical visibility predicate: a
                // deployed migration must not depend on an application service
                // whose implementation may be replaced later for OQ-G-25.
                $visibleActiveUnits = DB::table('units')
                    ->where('is_active', true)
                    ->where(static function (Builder $query) use ($tenantId): void {
                        $query->whereNull('tenant_id')
                            ->orWhere('tenant_id', $tenantId);
                    })
                    ->count();

                if ($visibleActiveUnits === 0) {
                    $emptyCompanyIds[] = $companyId;
                    Log::warning('units.empty_for_company', ['company' => $companyId]);
                }
            }

            $companyCount = $companies->count();
            $emptyCount = count($emptyCompanyIds);
            Log::info('units.visibility_census', [
                'companies' => $companyCount,
                'empty' => $emptyCount,
            ]);
            echo "units.visibility_census companies={$companyCount} empty={$emptyCount}".PHP_EOL;

            if ($emptyCompanyIds === []) {
                return;
            }

            if (DB::table('units')->exists() || DB::table('unit_categories')->exists()) {
                return;
            }

            try {
                DB::transaction(static function (): void {
                    (new UomSeeder)->run();
                });
            } catch (Throwable $exception) {
                Log::error('units.seed_failed', [
                    'exception' => $exception,
                    'tenant' => $tenantId,
                ]);
                echo "units.visibility_census companies={$companyCount} empty={$emptyCount} seed_failed=1".PHP_EOL;

                return;
            }

            foreach ($emptyCompanyIds as $companyId) {
                $message = 'units-seeded company_id='.$companyId;
                Log::info($message);
                echo $message.PHP_EOL;
            }
        } catch (Throwable $exception) {
            Log::error('units.visibility_migration_failed', [
                'exception' => $exception,
                'tenant' => $tenantId,
            ]);
            echo 'units-visibility-error '.$exception->getMessage().PHP_EOL;
        }
    }

    /**
     * Forward-only logged no-op: removing seeded units could break products.unit_id,
     * and this migration cannot distinguish its rows from operator-owned rows.
     */
    public function down(): void
    {
        Log::info('units.visibility_migration_down_noop');
    }
};
