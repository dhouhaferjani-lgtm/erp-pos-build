<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptLocationScopeAuthorizationTest extends ReceiptReportingTestCase
{
    public function test_same_company_receipt_outside_location_scope_is_non_enumerating_on_every_direct_read(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);
        $otherReceipt = $this->createReceipt(location: $otherLocation);
        $this->restrictMembershipTo([$this->location->id]);

        $this->getJson('/api/v1/pos/receipts/'.$otherReceipt->id)->assertNotFound();
        $this->get('/api/v1/pos/receipts/'.$otherReceipt->id.'/pdf')->assertNotFound();
        $this->get('/api/v1/pos/receipts/'.$otherReceipt->id.'/pdf/download')->assertNotFound();

        $this->assertDatabaseCount('pos_receipt_prints', 0);
    }

    public function test_unrestricted_membership_can_read_receipts_at_every_company_location(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);
        $otherReceipt = $this->createReceipt(location: $otherLocation);

        $this->getJson('/api/v1/pos/receipts/'.$otherReceipt->id)
            ->assertOk()
            ->assertJsonPath('data.id', $otherReceipt->id);
    }

    public function test_empty_location_scope_denies_direct_receipt_reads(): void
    {
        $receipt = $this->createReceipt();
        $this->restrictMembershipTo([]);

        $this->getJson('/api/v1/pos/receipts/'.$receipt->id)->assertNotFound();
    }

    /** @param list<string> $locationIds */
    private function restrictMembershipTo(array $locationIds): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => $locationIds]);
    }
}
