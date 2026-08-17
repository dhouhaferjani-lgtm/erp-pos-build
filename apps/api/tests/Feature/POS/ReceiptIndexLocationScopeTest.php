<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptIndexLocationScopeTest extends ReceiptReportingTestCase
{
    public function test_allowed_and_requested_locations_intersect_and_rows_include_location_snapshot(): void
    {
        $second = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Second']);
        $firstReceipt = $this->createReceipt(location: $this->location);
        $this->createReceipt(location: $second);

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$this->location->id]]);

        $response = $this->getJson('/api/v1/pos/receipts?location_ids[]='.$this->location->id.'&location_ids[]='.$second->id);

        $response->assertOk()->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $firstReceipt->id);
        $response->assertJsonPath('data.data.0.location_id', $this->location->id);
        $response->assertJsonPath('data.data.0.location_name', $this->location->name);
    }

    public function test_explicit_empty_membership_fails_closed_with_empty_collection(): void
    {
        $this->createReceipt();
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => []]);

        $this->getJson('/api/v1/pos/receipts')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }
}
