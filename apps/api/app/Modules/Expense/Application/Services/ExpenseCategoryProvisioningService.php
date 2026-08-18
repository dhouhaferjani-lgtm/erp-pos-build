<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Modules\Company\Domain\Company;
use Database\Seeders\ExpenseCategorySeeder;

/**
 * Provisions a company's country-default expense categories (register G-3).
 *
 * The seam exists so PRESENTATION never imports `Database\Seeders` directly
 * (gate finding N-3): the two sibling provisioning steps in the same controller
 * action already go through Application services — the chart via
 * `Accounting\Application\Services\ChartOfAccountsService`, tax via
 * `Taxation\Application\Services\CompanyTaxProvisioningService` — and both of
 * THOSE wrap the seeders. This class puts expense categories on the same
 * footing instead of making the controller the first Presentation-layer
 * importer of a seeder.
 *
 * Ordering contract: run AFTER the chart of accounts, because every category
 * links to a class-6 account (or resolves the `GeneralExpense` purpose).
 * Idempotent — {@see ExpenseCategorySeeder::seedForCompany} uses `firstOrCreate`.
 * A missing chart or certified mapped code is a provisioning failure and throws.
 */
final class ExpenseCategoryProvisioningService
{
    public function __construct(private readonly ExpenseCategorySeeder $seeder) {}

    public function provisionForCompany(Company $company): void
    {
        $this->seeder->seedForCompany($company);
    }
}
