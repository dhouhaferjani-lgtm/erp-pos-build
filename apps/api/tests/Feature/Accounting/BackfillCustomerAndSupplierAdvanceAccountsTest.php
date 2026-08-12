<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
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
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` / task A3 —
 * `2026_08_10_100000_backfill_customer_and_supplier_advance_accounts`.
 *
 * A2 fixed the France seeder, but a seeder only runs for NEW companies, and the
 * France seeder's re-run path promotes `is_system` and **never**
 * `system_purpose` (`FranceChartOfAccountsSeeder.php:46-57`). Every existing
 * French company therefore still cannot post a customer advance — it hard-fails
 * `createCustomerAdvanceJournalEntry()` at `GeneralLedgerService.php:417`.
 * **A3 is mandatory, not optional** (plan A3, A-D12).
 *
 * Guard ladder cloned from
 * `2026_08_07_100000_backfill_purchase_stamp_duty_account.php`. It must be safe
 * on every tenant and every chart shape, run any number of times, because
 * pushing to `origin/dev` auto-runs `tenants:migrate` on staging.
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the (empty) schema;
 * these cases build the pre-migration chart shapes by hand and then apply it.
 */
#[Group('historical-compat')]
final class BackfillCustomerAndSupplierAdvanceAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Backfill Advances Tenant',
            'slug' => 'backfill-adv-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);
    }

    /**
     * The headline case: a French company seeded BEFORE A2. Its 419 and 409 rows
     * exist with the correct types but no purpose, so the migration must MAP the
     * purposes onto them — never create duplicates, which
     * `accounts_company_code_unique` would reject anyway.
     */
    public function test_it_maps_both_purposes_onto_an_existing_french_chart(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripAdvancePurposes($company->id);
        $before = Account::query()->where('company_id', $company->id)->count();

        $this->runBackfill();

        $customerAdvance = Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance);
        $supplierAdvance = Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance);

        $this->assertNotNull($customerAdvance);
        $this->assertNotNull($supplierAdvance);
        $this->assertSame('419', $customerAdvance->code);
        $this->assertSame('409', $supplierAdvance->code);
        $this->assertSame('Clients créditeurs', $customerAdvance->name, 'the existing row is claimed, not replaced');
        $this->assertTrue((bool) $customerAdvance->is_system);
        $this->assertTrue((bool) $supplierAdvance->is_system);
        $this->assertSame(
            $before,
            Account::query()->where('company_id', $company->id)->count(),
            'mapping onto existing rows must not create accounts',
        );
    }

    public function test_it_maps_both_purposes_onto_an_existing_tunisian_chart(): void
    {
        $company = $this->company('TN', 'TND');
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripAdvancePurposes($company->id);

        $this->runBackfill();

        $this->assertSame('419', Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->code);
        $this->assertSame('409', Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance)?->code);
    }

    public function test_it_maps_both_purposes_onto_an_existing_generic_chart(): void
    {
        $company = $this->company('MA', 'MAD');
        (new GenericChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripAdvancePurposes($company->id);

        $this->runBackfill();

        $this->assertSame(
            '4190',
            Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->code,
            'the generic chart uses its own numbering, distinct from TN/FR',
        );
        $this->assertSame('4090', Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance)?->code);
    }

    public function test_it_skips_a_company_with_no_chart_at_all(): void
    {
        $company = $this->company('FR', 'EUR');

        $this->runBackfill();

        $this->assertSame(0, Account::query()->where('company_id', $company->id)->count());
    }

    /**
     * A company seeded AFTER A2 already carries both purposes — a true no-op,
     * and it must respect a chart where an admin mapped a purpose onto an
     * account of their own choosing.
     */
    public function test_it_is_a_no_op_for_a_company_that_already_has_both_purposes(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $before = Account::query()->where('company_id', $company->id)->count();
        $customerId = Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->id;
        $this->assertNotNull($customerId, 'Precondition: post-A2 the seeder provides it');

        $this->runBackfill();

        $this->assertSame($before, Account::query()->where('company_id', $company->id)->count());
        $this->assertSame($customerId, Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->id);
    }

    /**
     * The two purposes are independent: a chart that already maps ONE must still
     * receive the other. A single shared "already done?" check would skip both.
     */
    public function test_it_backfills_only_the_missing_purpose(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        // Strip ONLY the customer side.
        DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::CustomerAdvance->value)
            ->update(['system_purpose' => null, 'is_system' => false]);

        $this->runBackfill();

        $this->assertNotNull(Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance));
        $this->assertNotNull(Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance));
    }

    /**
     * The preferred code is taken by an account of the WRONG type (or one that
     * already carries another purpose): fall back to the next free code rather
     * than mis-typing the purpose or throwing.
     */
    public function test_it_falls_back_to_the_next_free_code_when_the_preferred_one_is_unusable(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripAdvancePurposes($company->id);

        // 419 must be a liability for CustomerAdvance; make it a revenue account.
        DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', '419')
            ->update(['type' => 'revenue']);

        $this->runBackfill();

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance);
        $this->assertNotNull($account, 'an unusable preferred code must not leave the purpose unmapped');
        $this->assertSame('4191', $account->code);
        $this->assertSame('liability', $account->type->value);
    }

    /**
     * A company this cannot place must LOG and CONTINUE, never throw —
     * `tenants:migrate` runs unattended across every tenant on push.
     */
    public function test_it_never_throws_and_keeps_going_when_a_company_cannot_be_placed(): void
    {
        $blocked = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($blocked->id, $this->tenant->id);
        $this->stripAdvancePurposes($blocked->id);

        // Occupy 419 and the whole fallback window with unusable rows.
        DB::table('accounts')->where('company_id', $blocked->id)->where('code', '419')->update(['type' => 'revenue']);
        for ($code = 4191; $code <= 4199; $code++) {
            // The PCG chart already ships some 419x rows; occupy only the free
            // ones, and make any pre-existing row unusable for the purpose.
            $exists = DB::table('accounts')
                ->where('company_id', $blocked->id)
                ->where('code', (string) $code)
                ->exists();

            if ($exists) {
                DB::table('accounts')
                    ->where('company_id', $blocked->id)
                    ->where('code', (string) $code)
                    ->update(['type' => 'revenue', 'system_purpose' => null]);

                continue;
            }

            Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $blocked->id,
                'code' => (string) $code,
                'name' => 'Occupied '.$code,
                'type' => 'revenue',
                'is_active' => true,
            ]);
        }

        $healthy = $this->company('TN', 'TND');
        (new TunisiaChartOfAccountsSeeder)->run($healthy->id, $this->tenant->id);
        $this->stripAdvancePurposes($healthy->id);

        $this->runBackfill();

        $this->assertNull(
            Account::findByPurpose($blocked->id, SystemAccountPurpose::CustomerAdvance),
            'the unplaceable company stays unmapped — but the migration did not throw',
        );
        $this->assertNotNull(
            Account::findByPurpose($healthy->id, SystemAccountPurpose::CustomerAdvance),
            'a company that cannot be placed must not stop the sweep for everyone else',
        );
    }

    /**
     * `tenants:migrate` runs on every push to `origin/dev`; a second and third
     * application must change nothing.
     */
    public function test_it_is_idempotent(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripAdvancePurposes($company->id);

        $this->runBackfill();
        $afterFirst = Account::query()->where('company_id', $company->id)->count();
        $customerId = Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->id;
        $supplierId = Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance)?->id;

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($afterFirst, Account::query()->where('company_id', $company->id)->count());
        $this->assertSame($customerId, Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance)?->id);
        $this->assertSame($supplierId, Account::findByPurpose($company->id, SystemAccountPurpose::SupplierAdvance)?->id);
    }

    private function company(string $countryCode, string $currency): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill Adv Co '.uniqid(),
            'country_code' => $countryCode,
            'currency' => $currency,
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'en_US',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'UTC',
            'status' => CompanyStatus::Active,
        ]);
    }

    /**
     * Reproduce the PRE-A2 chart shape: the advance rows exist with the right
     * types but carry no purpose and no system flag.
     */
    private function stripAdvancePurposes(string $companyId): void
    {
        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::CustomerAdvance->value,
                SystemAccountPurpose::SupplierAdvance->value,
            ])
            ->update(['system_purpose' => null, 'is_system' => false]);
    }

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_08_10_100000_backfill_customer_and_supplier_advance_accounts.php'
        );
        $migration->up();
    }
}
