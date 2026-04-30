<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for CustomerHistorySearchAuditController.
 *
 * Covers: happy path, cashier_id filter, was_rejected filter, tenant isolation,
 * and 403 without the required permission.
 */
final class CustomerHistorySearchAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    private Terminal $terminal;

    private Tenant $otherTenant;

    private Company $otherCompany;

    private User $otherManager;

    private Terminal $otherTerminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager->givePermissionTo('pos.search_customer_full_history');

        // Second tenant for isolation tests
        $this->otherTenant = Tenant::factory()->create();
        $this->otherCompany = Company::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $this->otherCompany->id]);
        $this->otherManager = User::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $this->otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'company_id' => $this->otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->otherManager->id,
            'company_id' => $this->otherCompany->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->otherTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->otherManager->givePermissionTo('pos.search_customer_full_history');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_returns_paginated_list_with_meta(): void
    {
        Sanctum::actingAs($this->manager);

        $this->insertSearchRow([]);
        $this->insertSearchRow([]);
        $this->insertSearchRow([]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customer-history-searches');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'total', 'per_page', 'rejected_total'],
        ]);
        $response->assertJsonPath('meta.total', 3);
    }

    // -------------------------------------------------------------------------
    // Meta: rejected_total
    // -------------------------------------------------------------------------

    public function test_meta_rejected_total_counts_full_filtered_dataset(): void
    {
        Sanctum::actingAs($this->manager);

        $this->insertSearchRow(['was_rejected' => true, 'rejection_reason' => 'rate_limit_exceeded']);
        $this->insertSearchRow(['was_rejected' => true, 'rejection_reason' => 'input_not_specific']);
        $this->insertSearchRow(['was_rejected' => false]);
        $this->insertSearchRow(['was_rejected' => false]);
        $this->insertSearchRow(['was_rejected' => false]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customer-history-searches?per_page=2');

        $response->assertOk();
        // First page has only 2 rows, but rejected_total must reflect the full dataset (2)
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.rejected_total', 2);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_filter_by_cashier_id(): void
    {
        Sanctum::actingAs($this->manager);

        $cashierA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashierB = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->insertSearchRow(['cashier_id' => $cashierA->id]);
        $this->insertSearchRow(['cashier_id' => $cashierA->id]);
        $this->insertSearchRow(['cashier_id' => $cashierB->id]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/pos/customer-history-searches?cashier_id={$cashierA->id}");

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_filter_by_was_rejected_true_returns_only_rejected_rows(): void
    {
        Sanctum::actingAs($this->manager);

        $this->insertSearchRow(['was_rejected' => true, 'rejection_reason' => 'input_not_specific']);
        $this->insertSearchRow(['was_rejected' => true, 'rejection_reason' => 'rate_limit_exceeded']);
        $this->insertSearchRow(['was_rejected' => false]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customer-history-searches?was_rejected=true');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    // -------------------------------------------------------------------------
    // Tenant isolation
    // -------------------------------------------------------------------------

    public function test_tenant_isolation(): void
    {
        Sanctum::actingAs($this->manager);

        // Row for our tenant
        $this->insertSearchRow([]);

        // Row for the other tenant — must NOT appear
        DB::table('customer_history_searches')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->otherTenant->id,
            'company_id' => $this->otherCompany->id,
            'cashier_id' => $this->otherManager->id,
            'terminal_id' => $this->otherTerminal->id,
            'partner_id' => null,
            'search_terms_hash' => hash('sha256', 'other@example.com'),
            'result_count' => 0,
            'was_rejected' => false,
            'rejection_reason' => null,
            'created_at' => now(),
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customer-history-searches');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    // -------------------------------------------------------------------------
    // Permission enforcement
    // -------------------------------------------------------------------------

    public function test_403_without_pos_search_customer_full_history(): void
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($cashier);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/customer-history-searches');

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a customer_history_searches row with sensible defaults.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertSearchRow(array $overrides = []): void
    {
        DB::table('customer_history_searches')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cashier_id' => $this->manager->id,
            'terminal_id' => $this->terminal->id,
            'partner_id' => null,
            'search_terms_hash' => hash('sha256', 'customer@example.com'),
            'result_count' => 1,
            'was_rejected' => false,
            'rejection_reason' => null,
            'created_at' => now(),
        ], $overrides));
    }
}
