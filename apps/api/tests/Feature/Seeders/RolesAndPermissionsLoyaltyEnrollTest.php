<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RolesAndPermissionsLoyaltyEnrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_and_manager_get_loyalty_enroll_but_not_manage_for_cashier(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cashier = Role::findByName('cashier', 'sanctum');
        $manager = Role::findByName('manager', 'sanctum');

        $this->assertTrue($cashier->hasPermissionTo('loyalty.enroll', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('loyalty.manage', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('loyalty.view', 'sanctum'));
        $this->assertTrue($manager->hasPermissionTo('loyalty.enroll', 'sanctum'));
    }
}
