<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q1 gate finding I-1 (2026-08-07) —
 * `2026_08_07_100000_backfill_purchase_stamp_duty_account`.
 *
 * `PurchaseStampDuty` (TN/FR 6354, Generic 6350) was added to the three chart
 * seeders on `72517d986` (2026-06-25) with no accompanying backfill. A seeder
 * only runs for NEW companies, so every company whose chart predates
 * 2026-06-25 is missing this account — and once the Q1 lane
 * (`CreditNoteService::applyConfirmEquivalentTotals()`, gate C-1) starts
 * persisting `documents.stamp_duty_amount` on real credit notes, any such
 * company hard-422s on every credit note that carries a timbre
 * (`GlResidualRefusal::NoCreditNoteStampAccount`).
 *
 * Modeled on `BackfillSalesRoundingDifferenceAccountsTest` — differs in that
 * ALL THREE charts (TN, FR, Generic) need this account (no cross-purpose
 * skip: `PurchaseStampDuty` is independent of `SalesStampDutyPayable`) and
 * the preferred code/name/parent vary by country (6354/"Droits
 * d'enregistrement et de timbre" for TN/FR vs 6350/"Purchase Stamp Duty" for
 * everyone else).
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the (empty) schema;
 * these cases build the pre-migration chart shapes by hand and then apply it.
 */
final class BackfillPurchaseStampDutyAccountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Backfill PSD Tenant',
            'slug' => 'backfill-psd-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);
    }

    public function test_it_gives_an_existing_tunisian_company_the_purchase_stamp_duty_account(): void
    {
        $company = $this->company('TN', 'TND');
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        $this->runBackfill();

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($account);
        $this->assertSame('6354', $account->code);
        $this->assertSame(
            Account::query()->where('company_id', $company->id)->where('code', '63')->value('id'),
            $account->parent_id,
        );

        // TN still carries its own SalesStampDutyPayable (4375) — unaffected,
        // no cross-purpose interaction (unlike the rounding-pair migration).
        $this->assertNotNull(Account::findByPurpose($company->id, SystemAccountPurpose::SalesStampDutyPayable));
    }

    public function test_it_gives_an_existing_french_company_the_purchase_stamp_duty_account(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        $this->runBackfill();

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($account);
        $this->assertSame('6354', $account->code);
    }

    public function test_it_gives_an_existing_generic_company_the_purchase_stamp_duty_account(): void
    {
        $company = $this->company('MA', 'MAD');
        (new GenericChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        $this->runBackfill();

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($account);
        $this->assertSame('6350', $account->code, 'Generic uses its own numbering, distinct from TN/FR');
    }

    public function test_it_skips_a_company_with_no_chart_at_all(): void
    {
        $company = $this->company('FR', 'EUR');

        $this->runBackfill();

        $this->assertSame(0, Account::query()->where('company_id', $company->id)->count());
    }

    /**
     * A NEW company (seeded post-72517d986) already carries `PurchaseStampDuty`
     * — the migration must be a true no-op, never a duplicate.
     */
    public function test_it_is_a_no_op_for_a_company_that_already_has_the_account(): void
    {
        $company = $this->company('TN', 'TND');
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $before = Account::query()->where('company_id', $company->id)->count();
        $existingId = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty)?->id;
        $this->assertNotNull($existingId, 'Precondition: the seeder already provides it');

        $this->runBackfill();

        $this->assertSame($before, Account::query()->where('company_id', $company->id)->count());
        $this->assertSame($existingId, Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty)?->id);
    }

    /**
     * A company already owns a user-created account at the preferred code
     * (`accounts_company_code_unique` forbids a duplicate) — the purpose is
     * mapped ONTO it when its type is compatible and it carries no purpose of
     * its own.
     */
    public function test_it_maps_the_purpose_onto_a_user_created_account_at_the_preferred_code(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        $userExpense = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '6354',
            'name' => 'Frais divers (compte utilisateur)',
            'type' => 'expense',
            'is_active' => true,
        ]);

        $this->runBackfill();

        $mapped = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($mapped);
        $this->assertSame($userExpense->id, $mapped->id, 'the existing account is claimed, not duplicated');
        $this->assertSame('Frais divers (compte utilisateur)', $mapped->name, 'the user keeps their name');
        $this->assertSame(1, Account::query()->where('company_id', $company->id)->where('code', '6354')->count());
    }

    /**
     * The preferred code is taken by an account of the WRONG type, or one
     * that already carries another system purpose: the account lands on the
     * next free code in the series instead.
     */
    public function test_it_falls_back_to_the_next_free_code_when_the_preferred_one_is_unusable(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        // Wrong type for an expense purpose.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '6354',
            'name' => 'Mis-typed account',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        $this->runBackfill();

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($account);
        $this->assertSame('6355', $account->code);
        $this->assertSame(AccountType::Expense, $account->type);
    }

    /**
     * `tenants:migrate` runs on every push to `origin/dev`; a second
     * application must change nothing.
     */
    public function test_it_is_idempotent(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripPurchaseStampDuty($company->id);

        $this->runBackfill();
        $afterFirst = Account::query()->where('company_id', $company->id)->count();
        $accountId = Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty)?->id;

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($afterFirst, Account::query()->where('company_id', $company->id)->count());
        $this->assertSame($accountId, Account::findByPurpose($company->id, SystemAccountPurpose::PurchaseStampDuty)?->id);
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php');
        $migration->up();
    }

    /**
     * Recreate the PRE-migration chart: the seeders now emit this account, so
     * it has to be removed to reproduce an existing company's shape.
     */
    private function stripPurchaseStampDuty(string $companyId): void
    {
        DB::table('accounts')
            ->where('company_id', $companyId)
            ->where('system_purpose', SystemAccountPurpose::PurchaseStampDuty->value)
            ->delete();
    }

    private function company(string $countryCode, string $currency): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill PSD Co '.Str::random(5),
            'legal_name' => 'Backfill PSD Co SARL',
            'tax_id' => 'TAX-BF-PSD-'.uniqid(),
            'country_code' => $countryCode,
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => $currency,
            'status' => CompanyStatus::Active,
        ]);
    }
}
