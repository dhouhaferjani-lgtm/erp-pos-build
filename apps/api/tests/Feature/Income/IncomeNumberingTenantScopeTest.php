<?php

declare(strict_types=1);

namespace Tests\Feature\Income;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LEDGER C-27 (Session B2 lane B2-1) — the Income twin of the journal-entry defect.
 *
 * `IncomeService::generateIncomeNumber()` allocated with a COMPANY-scoped max+1
 * scan while the only unique index on the column is
 * `documents_tenant_id_type_document_number_unique` on
 * `(tenant_id, type, document_number)` — and it took NO lock at all. In a tenant
 * with two companies, the second company's first income post minted
 * `INC-YYYY-000001`, which the first company already held.
 */
final class IncomeNumberingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T4 — the reproduction. Two companies of one tenant, two income posts, two
     * distinct tenant-wide numbers.
     */
    public function test_income_numbers_do_not_collide_across_two_companies_in_the_same_tenant(): void
    {
        [$user, $companyA] = $this->makeUserWithPermissions(['income.post', 'income.view', 'income.create']);
        $companyB = $this->makeSiblingCompany($user, 'Second Company');

        $numberA = $this->postIncomeForCompany($user, $companyA, '120.000');
        $numberB = $this->postIncomeForCompany($user, $companyB, '140.000');

        $year = date('Y');
        self::assertSame(sprintf('INC-%s-%06d', $year, 1), $numberA);
        self::assertSame(
            sprintf('INC-%s-%06d', $year, 2),
            $numberB,
            'Income numbers must be unique tenant-wide — the unique index is (tenant_id, type, document_number).'
        );
    }

    /**
     * T4b — the max+1 read must be serialised by a transaction-scoped advisory
     * lock keyed on the SAME scope the scan uses (the tenant). Before the fix
     * there was no lock at all.
     */
    public function test_income_number_allocation_takes_the_tenant_advisory_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('pg_advisory_xact_lock is observable on PostgreSQL only.');
        }

        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view', 'income.create']);

        DB::enableQueryLog();
        $this->postIncomeForCompany($user, $company, '90.000');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $locks = array_values(array_filter(
            $log,
            static fn (array $entry): bool => str_contains((string) $entry['query'], 'pg_advisory_xact_lock(hashtextextended')
                && in_array(
                    "income_number:{$user->tenant_id}",
                    array_map(static fn (mixed $value): string => (string) $value, $entry['bindings']),
                    true
                )
        ));

        self::assertNotSame(
            [],
            $locks,
            'Expected a pg_advisory_xact_lock keyed income_number:{tenantId} during income-number allocation.'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Post one income for the given company and return the allocated number.
     */
    private function postIncomeForCompany(User $user, Company $company, string $total): string
    {
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $income = $this->makeIncome($company, $user, ['total' => $total, 'currency' => 'TND']);

        IncomeMetadata::create([
            'document_id' => $income->id,
            'is_received' => true,
            'payment_repository_id' => null,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson("/api/v1/income/{$income->id}/post")
            ->assertOk();

        $number = $income->fresh()?->document_number;
        self::assertIsString($number);

        return $number;
    }

    /**
     * Create a second company under the same tenant, with the user a member of it.
     */
    private function makeSiblingCompany(User $user, string $name): Company
    {
        $company = Company::create([
            'tenant_id' => $user->tenant_id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX'.uniqid(),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIncome(Company $company, User $user, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'type' => DocumentType::Income,
            'status' => DocumentStatus::Draft,
            'document_number' => null,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '0.000',
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
