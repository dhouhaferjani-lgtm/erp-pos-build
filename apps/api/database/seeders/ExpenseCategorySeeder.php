<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Domain\ExpenseCategory;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a company's default expense categories, each linked to an existing
 * class-6 GL account of that company's country chart.
 *
 * Register G-3: this used to be a TN-only, demo-only map — France and the
 * generic chart had no expense-category default at all, and two of its
 * intended categories (606 / 6061) pointed at accounts the Tunisian chart did
 * not seed, so they silently fell back to the GeneralExpense account. Both
 * halves are fixed: the 606 family is now seeded by the TN and FR charts, and
 * the map below is country-aware.
 *
 * Country → map:
 *   TN / FR (French-plan charts) → PCN/PCG codes 613/615/616/624/626/6061/6064
 *   anything else (generic chart) → 6130/6170/6250/6256, English names
 *
 * A deliberate `null` catch-all resolves through the GeneralExpense system
 * purpose. A non-null mapping that the chart does not carry is certification
 * drift and fails loudly; it must never silently book to a different account.
 */
class ExpenseCategorySeeder extends Seeder
{
    /**
     * Categories for the French-plan charts (TN PCN and FR PCG share these codes).
     *
     * name => account code, or null to resolve the GeneralExpense purpose.
     *
     * @var array<string, string|null>
     */
    private const FRENCH_PLAN_CATEGORIES = [
        'Loyer' => '613',
        'Entretien & Réparations' => '615',
        'Assurances' => '616',
        'Transport' => '624',
        'Frais postaux & Télécom' => '626',
        'Eau & Électricité' => '6061',
        'Fournitures administratives' => '6064',
        'Fournitures & Divers' => null,
    ];

    /**
     * Categories for the generic international chart.
     *
     * @var array<string, string|null>
     */
    private const GENERIC_CATEGORIES = [
        'Rent' => null,
        'Utilities' => '6130',
        'Office Supplies' => '6170',
        'Travel' => '6250',
        'Meals & Entertainment' => '6256',
        'Other' => null,
    ];

    /**
     * Seed expense categories for a single company.
     *
     * Uses firstOrCreate so the method is fully idempotent: running it
     * twice on the same company produces no duplicates.
     */
    public function seedForCompany(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            if (Account::query()->where('company_id', $company->id)->doesntExist()) {
                throw new DomainException("Company {$company->id} has no chart of accounts for expense categories.");
            }

            $fallback = Account::findByPurpose($company->id, SystemAccountPurpose::GeneralExpense);
            $sort = 0;
            foreach ($this->categoriesForCountry($company->country_code) as $name => $code) {
                if ($code === null) {
                    if (! $fallback instanceof Account) {
                        throw new DomainException("Company {$company->id} has no GeneralExpense account for category {$name}.");
                    }
                    $account = $fallback;
                } else {
                    $account = Account::query()
                        ->where('company_id', $company->id)
                        ->where('code', $code)
                        ->first();
                    if (! $account instanceof Account) {
                        throw new DomainException("Company {$company->id} is missing mapped expense account code {$code} for category {$name}.");
                    }
                }

                ExpenseCategory::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    [
                        'tenant_id' => $company->tenant_id,
                        'account_id' => $account->id,
                        'is_active' => true,
                        'sort_order' => $sort,
                    ],
                );

                $sort++;
            }
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function categoriesForCountry(?string $countryCode): array
    {
        return match (strtoupper((string) $countryCode)) {
            'TN', 'FR' => self::FRENCH_PLAN_CATEGORIES,
            default => self::GENERIC_CATEGORIES,
        };
    }

    /**
     * No-op for the global runner — call seedForCompany() from tenant seeders.
     */
    public function run(): void
    {
        // Intentionally empty: this seeder is company-scoped.
        // Called from TenantInitializationService (live registration path),
        // TunisianParapharmacySeeder and ParapharmacySeeder via seedForCompany().
    }
}
