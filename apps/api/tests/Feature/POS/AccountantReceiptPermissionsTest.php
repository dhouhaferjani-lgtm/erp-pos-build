<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Database\Seeders\RolesAndPermissionsSeeder;
use PHPUnit\Framework\TestCase;

final class AccountantReceiptPermissionsTest extends TestCase
{
    public function test_accountant_receives_only_the_frozen_receipt_lane_grants(): void
    {
        $grants = RolesAndPermissionsSeeder::rolePermissionGrants()['accountant'];

        $this->assertContains('pos.view_receipts', $grants);
        $this->assertContains('pos.view_reports', $grants);
        $this->assertContains('deliveries.view', $grants);
        $this->assertNotContains('dashboard.owner', $grants);
        $this->assertNotContains('pos.operate_terminal', $grants);
        $this->assertNotContains('pos.manage_terminals', $grants);
        $this->assertNotContains('pos.manage_shifts', $grants);
        $this->assertNotContains('pos.manage_tables', $grants);
    }
}
