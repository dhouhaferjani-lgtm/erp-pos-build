<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The counting list "lying filter" regression: the web dashboard cards link to
 * ?status=active / ?overdue=true, but index() applied a raw
 * `where('status', $input)` with no vocabulary. `active` matched no row, so the
 * list came back empty while the UI showed "all statuses".
 *
 * The index now speaks the same vocabulary as the dashboard: the `active` alias
 * resolves to scopeActive(), `overdue=true` to active + past scheduled_end, exact
 * statuses filter verbatim, and any unknown value is tolerated (ignored → all)
 * so no existing caller is broken.
 */
class CountingIndexStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Counting Filter Tenant',
            'slug' => 'counting-filter-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counting Filter Co',
            'legal_name' => 'Counting Filter Co LLC',
            'tax_id' => 'TAXCF',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin CF',
            'email' => 'admin-cf@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function makeCounting(
        CountingStatus $status,
        ?string $scheduledEnd = null,
        ?Company $company = null,
    ): InventoryCounting {
        return InventoryCounting::create([
            'company_id' => ($company ?? $this->company)->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => $status,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'scheduled_end' => $scheduledEnd,
        ]);
    }

    /** @return array<string> */
    private function idsFor(string $query): array
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/inventory/countings?{$query}");

        $response->assertStatus(200);

        /** @var array<int, array{id: string}> $rows */
        $rows = $response->json('data');

        return array_map(static fn (array $row): string => $row['id'], $rows);
    }

    public function test_active_alias_returns_only_in_progress_countings(): void
    {
        $inProgress = $this->makeCounting(CountingStatus::Count1InProgress);
        $draft = $this->makeCounting(CountingStatus::Draft);
        $finalized = $this->makeCounting(CountingStatus::Finalized);

        $ids = $this->idsFor('status=active');

        $this->assertContains($inProgress->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($finalized->id, $ids);
    }

    public function test_active_alias_covers_all_three_in_progress_statuses(): void
    {
        $c1 = $this->makeCounting(CountingStatus::Count1InProgress);
        $c2 = $this->makeCounting(CountingStatus::Count2InProgress);
        $c3 = $this->makeCounting(CountingStatus::Count3InProgress);
        $completed = $this->makeCounting(CountingStatus::Count1Completed);

        $ids = $this->idsFor('status=active');

        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertContains($c3->id, $ids);
        $this->assertNotContains($completed->id, $ids);
    }

    public function test_overdue_returns_active_countings_past_scheduled_end(): void
    {
        $overdue = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->subDay()->toDateTimeString(),
        );
        $onTime = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->addDay()->toDateTimeString(),
        );
        // Past scheduled_end but NOT active → not overdue.
        $finishedButLate = $this->makeCounting(
            CountingStatus::Finalized,
            now()->subDay()->toDateTimeString(),
        );

        $ids = $this->idsFor('overdue=true');

        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($onTime->id, $ids);
        $this->assertNotContains($finishedButLate->id, $ids);
    }

    public function test_overdue_alias_via_status_param_matches_overdue_boolean(): void
    {
        // The `status=overdue` alias form must resolve through the same branch as
        // `overdue=true` ($statusInput === 'overdue'), so both queries return the
        // exact same rows: active + past scheduled_end, nothing else.
        $overdue = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->subDay()->toDateTimeString(),
        );
        $onTime = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->addDay()->toDateTimeString(),
        );
        $finishedButLate = $this->makeCounting(
            CountingStatus::Finalized,
            now()->subDay()->toDateTimeString(),
        );

        $viaStatusAlias = $this->idsFor('status=overdue');
        $viaBoolean = $this->idsFor('overdue=true');

        sort($viaStatusAlias);
        sort($viaBoolean);
        $this->assertSame($viaBoolean, $viaStatusAlias);

        $this->assertContains($overdue->id, $viaStatusAlias);
        $this->assertNotContains($onTime->id, $viaStatusAlias);
        $this->assertNotContains($finishedButLate->id, $viaStatusAlias);
    }

    public function test_overdue_wins_precedence_over_exact_status(): void
    {
        // When `status=<x>` and `overdue=true` arrive together, overdue wins: the
        // exact status is ignored, so a finalized-but-late counting does NOT show
        // and only the active-and-overdue one does.
        $activeOverdue = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->subDay()->toDateTimeString(),
        );
        $finalizedLate = $this->makeCounting(
            CountingStatus::Finalized,
            now()->subDay()->toDateTimeString(),
        );

        $ids = $this->idsFor('status=finalized&overdue=true');

        $this->assertContains($activeOverdue->id, $ids);
        $this->assertNotContains($finalizedLate->id, $ids);
    }

    public function test_exact_status_still_filters_verbatim(): void
    {
        $finalized = $this->makeCounting(CountingStatus::Finalized);
        $draft = $this->makeCounting(CountingStatus::Draft);

        $ids = $this->idsFor('status=finalized');

        $this->assertContains($finalized->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    public function test_unknown_status_is_tolerated_and_returns_all(): void
    {
        $draft = $this->makeCounting(CountingStatus::Draft);
        $finalized = $this->makeCounting(CountingStatus::Finalized);

        // The old bug: `bogus` matched nothing → empty list. Now it is ignored,
        // so the caller sees every counting rather than a silent void.
        $ids = $this->idsFor('status=bogus');

        $this->assertContains($draft->id, $ids);
        $this->assertContains($finalized->id, $ids);
    }

    /**
     * Second-of-everything (docs/conventions/09-SECOND-OF-EVERYTHING.md, rule 1):
     * a list in company A never returns company B's row. The index now has three
     * distinct query branches (overdue / active alias / exact status) plus the
     * tolerated-unknown fall-through; every one of them must stay inside
     * forCompany(). Company B lives in the SAME tenant and the acting user is a
     * member of both, so the only thing keeping B's rows out is the company scope.
     */
    public function test_second_company_rows_never_leak_on_any_branch(): void
    {
        $companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counting Filter Co B',
            'legal_name' => 'Counting Filter Co B LLC',
            'tax_id' => 'TAXCFB',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $companyB->id,
            'role' => 'admin',
        ]);

        // Company A rows: one that matches `active` AND `overdue`, one finalized.
        $aActiveOverdue = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->subDay()->toDateTimeString(),
        );
        $aFinalized = $this->makeCounting(CountingStatus::Finalized);

        // Company B rows shaped to match EVERY branch if the scope were missing.
        $bActiveOverdue = $this->makeCounting(
            CountingStatus::Count1InProgress,
            now()->subDay()->toDateTimeString(),
            $companyB,
        );
        $bFinalized = $this->makeCounting(CountingStatus::Finalized, null, $companyB);

        // Context is company A (setUp). Every branch returns A's rows, never B's.
        $queries = [
            'status=active',
            'overdue=true',
            'status=overdue',
            'status=finalized',
            'status=bogus',
            '',
        ];

        foreach ($queries as $query) {
            $ids = $this->idsFor($query);

            $this->assertNotContains($bActiveOverdue->id, $ids, "company B leaked on '{$query}'");
            $this->assertNotContains($bFinalized->id, $ids, "company B leaked on '{$query}'");
        }

        // Positive control: company A's matching rows ARE present on each branch.
        $this->assertContains($aActiveOverdue->id, $this->idsFor('status=active'));
        $this->assertContains($aActiveOverdue->id, $this->idsFor('overdue=true'));
        $this->assertContains($aActiveOverdue->id, $this->idsFor('status=overdue'));
        $this->assertContains($aFinalized->id, $this->idsFor('status=finalized'));
        $this->assertContains($aFinalized->id, $this->idsFor('status=bogus'));
        $this->assertContains($aActiveOverdue->id, $this->idsFor(''));

        // Sanity: company B's rows exist in the tenant DB — the scope, not a
        // missing fixture, is what keeps them out of company A's list.
        $this->assertSame(
            2,
            InventoryCounting::forCompany($companyB->id)->count(),
        );
    }
}
