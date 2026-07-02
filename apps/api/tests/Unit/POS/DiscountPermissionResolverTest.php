<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Services\DiscountPermissionResolver;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Unit tests for {@see DiscountPermissionResolver}.
 *
 * This class is the single source of truth shared by the read path
 * (DiscountController / PosAuthController — what the UI is told) and the
 * authoritative write path (DiscountCalculationService — enforced at
 * receipt/fiscal-event creation). Both must agree.
 */
final class DiscountPermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private DiscountPermissionResolver $resolver;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DiscountPermissionResolver;

        $this->tenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_bypasses_can_discount_flag(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
            'max_discount_percent' => null,
        ]);
        $admin->assignRole('admin');

        $this->assertTrue($this->resolver->isAdmin($admin));
        $this->assertTrue($this->resolver->canDiscount($admin));
    }

    public function test_admin_effective_max_percent_is_100_regardless_of_stored_value(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
            'max_discount_percent' => '5.00',
        ]);
        $admin->assignRole('admin');

        $this->assertSame('100.00', $this->resolver->effectiveMaxPercent($admin));
    }

    public function test_non_admin_with_can_discount_true_may_discount(): void
    {
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => true,
            'max_discount_percent' => '15.00',
        ]);
        $cashier->assignRole('cashier');

        $this->assertFalse($this->resolver->isAdmin($cashier));
        $this->assertTrue($this->resolver->canDiscount($cashier));
        // decimal:2 cast returns a scale-2 string
        $this->assertSame('15.00', $this->resolver->effectiveMaxPercent($cashier));
    }

    public function test_non_admin_without_can_discount_may_not_discount(): void
    {
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => false,
            'max_discount_percent' => '10.00',
        ]);
        $cashier->assignRole('cashier');

        $this->assertFalse($this->resolver->canDiscount($cashier));
    }

    public function test_non_admin_null_max_returns_null(): void
    {
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'can_discount' => true,
            'max_discount_percent' => null,
        ]);
        $cashier->assignRole('cashier');

        $this->assertNull($this->resolver->effectiveMaxPercent($cashier));
    }
}
