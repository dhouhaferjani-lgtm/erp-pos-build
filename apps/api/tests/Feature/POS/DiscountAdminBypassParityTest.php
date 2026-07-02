<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Services\DiscountCalculationService;
use App\Modules\POS\Domain\Services\DiscountPermissionResolver;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: the read path (GET /pos/discount-permissions, driven by
 * DiscountController) and the authoritative write path
 * (DiscountCalculationService, enforced at receipt/fiscal-event creation)
 * must give an admin/super_admin the SAME answer.
 *
 * The historical bug: the read endpoint granted an admin bypass
 * (can_discount || isAdmin, max 100) while the validator read raw
 * User::can_discount with no bypass and rejected the same admin at
 * checkout — the operator was told "yes" then blocked mid-sale. Both paths
 * now share {@see DiscountPermissionResolver}.
 */
final class DiscountAdminBypassParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parity Shop',
            'legal_name' => 'Parity Shop LLC',
            'tax_id' => 'TAX-PARITY',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Admin whose per-user discount flag is OFF in the DB — the whole point.
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
            'max_discount_percent' => null,
        ]);
        $this->admin->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
            'max_discount_percent' => '30.00',
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    public function test_read_endpoint_grants_admin_bypass(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->withHeaders([
            'X-Company-Id' => $this->company->id,
            'X-Terminal-Code' => $this->terminal->code,
        ])->getJson('/api/v1/pos/discount-permissions');

        $response->assertOk();
        $this->assertTrue($response->json('data.canDiscount'));
        $this->assertTrue($response->json('data.userCanDiscount'));
        $this->assertEquals(100.0, $response->json('data.userMaxDiscountPercent'));
    }

    public function test_validator_grants_admin_bypass_matching_read_endpoint(): void
    {
        // Was the bug: this threw DiscountNotAllowedException for the admin
        // even though the read endpoint said canDiscount = true.
        $service = app(DiscountCalculationService::class);

        $service->validateLineDiscount($this->terminal, $this->admin, '20.00', 'Manager approval');

        // No exception === admin bypass honoured on the enforcement path too.
        $this->addToAssertionCount(1);
    }

    public function test_validator_still_caps_admin_at_terminal_limit(): void
    {
        // Admin bypass grants a personal max of 100%, but the terminal ceiling
        // (30%) is still the binding limit — same as the read endpoint's min().
        $service = app(DiscountCalculationService::class);

        $limit = $service->getEffectiveDiscountLimit($this->terminal, $this->admin);

        $this->assertSame('30.00', $limit['limit']);
        $this->assertSame('terminal', $limit['source']);
    }

    public function test_non_admin_without_permission_rejected_on_both_paths(): void
    {
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
            'max_discount_percent' => '10.00',
        ]);
        $cashier->assignRole('cashier');
        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        // Read path: told "no".
        Sanctum::actingAs($cashier);
        $response = $this->withHeaders([
            'X-Company-Id' => $this->company->id,
            'X-Terminal-Code' => $this->terminal->code,
        ])->getJson('/api/v1/pos/discount-permissions');
        $response->assertOk();
        $this->assertFalse($response->json('data.userCanDiscount'));

        // Write path: enforced with the same answer.
        $service = app(DiscountCalculationService::class);
        $this->expectException(DiscountNotAllowedException::class);
        $service->validateLineDiscount($this->terminal, $cashier, '5.00');
    }
}
