<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptAuthorizationTest extends ReceiptReportingTestCase
{
    public function test_receipt_reads_and_chain_verification_require_their_exact_permissions(): void
    {
        $receipt = $this->createReceipt();
        $this->user->revokePermissionTo('pos.view_receipts');

        $this->getJson('/api/v1/pos/receipts')->assertForbidden();
        $this->getJson('/api/v1/pos/receipts/'.$receipt->id)->assertForbidden();
        $this->getJson('/api/v1/pos/receipts/filter-options')->assertForbidden();
        $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ])->assertForbidden();
    }

    public function test_frozen_accountant_receipt_grants_reach_reads_and_reports_but_not_terminals(): void
    {
        $receipt = $this->createReceipt();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->syncPermissions([]);
        $this->user->assignRole('accountant');

        $this->getJson('/api/v1/pos/receipts')->assertOk();
        $this->getJson('/api/v1/pos/receipts/'.$receipt->id)->assertOk();
        $this->getJson('/api/v1/pos/receipts/filter-options')->assertOk();
        $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ])->assertOk();
        $this->getJson('/api/v1/pos/terminals')->assertForbidden();
    }
}
