<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\Services;

use App\Modules\Company\Domain\Company;
use Database\Seeders\UomSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UnitsProvisioningService
{
    /**
     * This is the ⛔ SCOPE-DEPENDENT predicate of spec §4.13.2 and is the single substitution point for OQ-G-25.
     */
    public function visibleActiveUnitCount(Company $company): int
    {
        return DB::table('units')
            ->where('is_active', true)
            ->where(static function ($query) use ($company): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $company->tenant_id);
            })
            ->count();
    }

    public function hasVisibleUnits(Company $company): bool
    {
        return $this->visibleActiveUnitCount($company) > 0;
    }

    /**
     * Provision the canonical set without making company creation depend on seeding.
     *
     * CompanyController already owns the company-creation transaction. This
     * nested transaction limits rollback to units and categories so a seed
     * failure leaves both tables empty, is logged, and the company still
     * commits. Unit-bearing imports then refuse with units_not_seeded until a
     * later provisioning attempt succeeds.
     */
    public function provisionForCompany(Company $company): void
    {
        if ($this->hasVisibleUnits($company)) {
            return;
        }

        $units = DB::table('units')->count();
        $unitCategories = DB::table('unit_categories')->count();

        if ($units === 0 && $unitCategories === 0) {
            try {
                DB::transaction(static function (): void {
                    (new UomSeeder)->run();
                });
            } catch (Throwable $exception) {
                Log::error('units.seed_failed', [
                    'exception' => $exception,
                    'tenant' => $company->tenant_id,
                ]);

                return;
            }

            Log::info('units.provisioned', ['company_id' => $company->id]);

            return;
        }

        Log::warning('units.empty_but_not_seedable', [
            'company_id' => $company->id,
            'units' => $units,
            'unit_categories' => $unitCategories,
        ]);
    }
}
