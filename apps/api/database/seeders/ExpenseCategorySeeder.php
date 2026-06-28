<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Domain\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds TN parapharmacy expense categories, each linked to an existing
 * PCG-TN class-6 GL account (confirmed present in TunisiaChartOfAccountsSeeder).
 *
 * Category → account code map (confirmed codes):
 *   Loyer                        → 613  (Locations)
 *   Entretien & Réparations      → 615  (Entretien et réparations)
 *   Assurances                   → 616  (Primes d'assurances)
 *   Transport                    → 624  (Transport de biens et transport collectif)
 *   Frais postaux & Télécom      → 626  (Frais postaux et frais de télécommunications)
 *   Fournitures & Divers         → null → GeneralExpense fallback (65)
 *
 * Codes 606 and 6061 referenced in the task brief do NOT exist in
 * TunisiaChartOfAccountsSeeder; those categories fall back to the
 * GeneralExpense account (65 — Autres charges de gestion courante).
 */
class ExpenseCategorySeeder extends Seeder
{
    /**
     * name => PCG-TN class-6 account code (confirmed present in TunisiaChartOfAccountsSeeder).
     * null resolves to the GeneralExpense system-purpose account.
     *
     * @var array<string, string|null>
     */
    private const CATEGORY_ACCOUNTS = [
        'Loyer' => '613',
        'Entretien & Réparations' => '615',
        'Assurances' => '616',
        'Transport' => '624',
        'Frais postaux & Télécom' => '626',
        'Fournitures & Divers' => null,
    ];

    /**
     * Seed expense categories for a single company.
     *
     * Uses firstOrCreate so the method is fully idempotent: running it
     * twice on the same company produces no duplicates.
     */
    public function seedForCompany(Company $company): void
    {
        $fallback = Account::findByPurpose($company->id, SystemAccountPurpose::GeneralExpense);

        $sort = 0;
        foreach (self::CATEGORY_ACCOUNTS as $name => $code) {
            $account = $code !== null
                ? Account::query()
                    ->where('company_id', $company->id)
                    ->where('code', $code)
                    ->first()
                : null;

            $account ??= $fallback;

            // Skip rather than insert a NULL account_id (COA was not seeded yet).
            if ($account === null) {
                continue;
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
    }

    /**
     * No-op for the global runner — call seedForCompany() from tenant seeders.
     */
    public function run(): void
    {
        // Intentionally empty: this seeder is company-scoped.
        // Called from TunisianParapharmacySeeder and ParapharmacySeeder
        // via seedForCompany($company).
    }
}
