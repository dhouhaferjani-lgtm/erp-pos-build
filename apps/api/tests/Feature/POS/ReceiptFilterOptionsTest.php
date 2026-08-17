<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\POS\Domain\Terminal;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptFilterOptionsTest extends ReceiptReportingTestCase
{
    public function test_options_are_location_scoped_include_inactive_terminals_and_latest_cashier_snapshot(): void
    {
        $second = Location::factory()->create(['company_id' => $this->company->id]);
        $inactive = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'A-INACTIVE',
            'is_active' => false,
        ]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $second->id,
            'code' => 'Z-HIDDEN',
        ]);
        $old = $this->createReceipt(postedAt: '2026-08-16 08:00:00 UTC');
        $old->forceFill(['cashier_name' => 'Old snapshot'])->saveQuietly();
        $latest = $this->createReceipt(postedAt: '2026-08-17 08:00:00 UTC');
        $latest->forceFill(['cashier_name' => 'Latest snapshot'])->saveQuietly();

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$this->location->id]]);

        $response = $this->getJson('/api/v1/pos/receipts/filter-options');

        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['terminals', 'cashiers'], array_keys($response->json('data')));
        $this->assertContains($inactive->id, array_column($response->json('data.terminals'), 'id'));
        $this->assertNotContains('Z-HIDDEN', array_column($response->json('data.terminals'), 'code'));
        $response->assertJsonFragment([
            'id' => $this->user->id,
            'name' => 'Latest snapshot',
        ]);
    }

    public function test_empty_effective_scope_returns_empty_arrays(): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => []]);

        $this->getJson('/api/v1/pos/receipts/filter-options')
            ->assertOk()
            ->assertExactJson(['data' => ['terminals' => [], 'cashiers' => []]]);
    }
}
