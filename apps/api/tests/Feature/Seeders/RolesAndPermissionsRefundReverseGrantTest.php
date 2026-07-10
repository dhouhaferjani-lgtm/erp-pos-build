<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner decision (ratified 2026-07-10): payments.refund is granted to manager
 * and accountant (real money out, now idempotency-protected). payments.reverse
 * stays admin-only — the endpoint currently moves no cash and posts no GL
 * (bookkeeping-level undo), so it is deliberately withheld from every named
 * role until it gets spine treatment.
 */
final class RolesAndPermissionsRefundReverseGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_accountant_get_payments_refund_but_no_role_gets_payments_reverse(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = Role::findByName('manager', 'sanctum');
        $accountant = Role::findByName('accountant', 'sanctum');
        $cashier = Role::findByName('cashier', 'sanctum');

        $this->assertTrue($manager->hasPermissionTo('payments.refund', 'sanctum'));
        $this->assertTrue($accountant->hasPermissionTo('payments.refund', 'sanctum'));

        $this->assertFalse($manager->hasPermissionTo('payments.reverse', 'sanctum'));
        $this->assertFalse($accountant->hasPermissionTo('payments.reverse', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('payments.reverse', 'sanctum'));
    }
}
