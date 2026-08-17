<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccountantReceiptPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_receives_only_the_frozen_receipt_lane_grants(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $grants = Role::findByName('accountant', 'sanctum')
            ->permissions
            ->pluck('name')
            ->all();

        $this->assertContains('pos.view_receipts', $grants);
        $this->assertContains('pos.view_reports', $grants);
        $this->assertContains('deliveries.view', $grants);
        $this->assertNotContains('dashboard.owner', $grants);
        $this->assertNotContains('pos.operate_terminal', $grants);
        $this->assertNotContains('pos.manage_terminals', $grants);
        $this->assertNotContains('pos.manage_shifts', $grants);
        $this->assertNotContains('pos.manage_tables', $grants);
        $this->assertNotContains('pos.process_returns', $grants);
    }
}
